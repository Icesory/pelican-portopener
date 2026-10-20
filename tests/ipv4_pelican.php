<?php
/** Real Pelican services and schema; synthetic users/nodes, mocked Wings transport, no live database. */
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('app.name') !== 'PortOpener Verification' || config('database.default') !== 'sqlite'
    || config('database.connections.sqlite.database') !== '/tmp/portopener-verification.sqlite') {
    throw new RuntimeException('Fresh verification container only.');
}

use App\Models\{Egg, Node, Server};
use Illuminate\Support\Facades\{Artisan, Http, Queue};
use PortOpener\Models\{Ipv4Installation, NodePolicy};
use PortOpener\Services\{Ipv4Installer, Ipv4Release};

try {
    Http::preventStrayRequests();
    Queue::fake();
    config(['panel.email.send_account_created_notification' => false]);
    Artisan::call('migrate', ['--force' => true]);
    Artisan::call('migrate', ['--path' => 'plugins/port-opener/database/migrations', '--force' => true]);
    $checks = 0;
    $check = function ($condition, $message) use (&$checks) {
        if (!$condition) throw new RuntimeException('FAIL: '.$message);
        $checks++;
    };
    $admin = app(\App\Services\Users\UserCreationService::class)->handle([
        'username' => 'verification-admin', 'email' => 'verification@example.invalid',
        'password' => bin2hex(random_bytes(24)), 'root_admin' => true,
    ]);
    auth()->setUser($admin);
    $node = Node::create(['name' => 'Verification node', 'fqdn' => 'wings.example.com', 'scheme' => 'https']);
    $policy = NodePolicy::create(['node_id' => $node->id, 'connection_mode' => 'outbound',
        'service_enabled' => true, 'enabled' => true, 'service_dry_run' => true, 'ipv6_enabled' => false,
        'router_url' => 'http://192.168.178.1:49000/igddesc.xml', 'internal_address' => '192.168.178.50',
        'min_port' => 1024, 'max_port' => 65535, 'lease_seconds' => 3600, 'reconcile_seconds' => 60]);

    $check(app(\App\Services\Servers\ServerCreationService::class) instanceof \PortOpener\Services\PortOpenerServerCreation,
        'real plugin provider decorates real Pelican creation service');
    $installer = app(Ipv4Installer::class);
    $installation = $installer->request($node->id);
    $check($installer->request($node->id)->id === $installation->id, 'repeated clicks reuse installation');
    $installer->advance($node->id, $installation->operation_id);
    $server = Server::findOrFail($installation->fresh()->server_id);
    $check($server->allocation_id === null && $server->owner_id === $admin->id, 'real server creation needs no allocation');
    $check($server->egg->uuid === Ipv4Release::EGG_UUID && $server->serverVariables()->count() === 3, 'real Egg importer and environment validation');
    $check($installation->fresh()->status === 'creating', 'managed UUID persists before Wings call');
    $check(Http::recorded()->isEmpty(), 'managed creation defers all Wings effects');

    $state = 'offline';
    $existing = false;
    $files = [];
    Http::fake(function ($request) use (&$state, &$existing, &$files, $node) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $headers = ['User-Agent' => 'Pelican Wings/v1.0.0 (id:'.$node->daemon_token_id.')'];
        if ($request->method() === 'GET' && !str_contains($path, '/files/')) {
            return Http::response(['state' => $state], $existing ? 200 : 404, $headers);
        }
        if ($request->method() === 'GET' && str_contains($path, '/files/')) {
            return Http::response(array_map(fn ($name) => ['name' => $name], $files), 200, $headers);
        }
        if ($path === '/api/servers' && $request->method() === 'POST') $existing = true;
        if (str_ends_with($path, '/power')) $state = $request['action'] === 'start' ? 'running' : 'offline';
        return Http::response([], 204, $headers);
    });
    $installer->advance($node->id, $installation->operation_id);
    $check($installation->fresh()->status === 'installing', 'real authenticated Wings client creates committed UUID');
    $server->forceFill(['status' => null])->saveQuietly();
    $installer->advance($node->id, $installation->operation_id);
    $check($installation->fresh()->status === 'pairing', 'real install callback state advances provisioning');
    $installer->advance($node->id, $installation->operation_id);
    $check($installation->fresh()->status === 'starting' && $state === 'running', 'private Wings bootstrap then power start');
    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $send = function (string $method, string $path, array $body = [], ?string $token = null, bool $tls = true) use ($kernel) {
        $request = \Illuminate\Http\Request::create(($tls ? 'https' : 'http').'://panel.example.com/api/port-opener/v1/'.$path,
            $method, [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '192.0.2.123'], json_encode($body));
        if ($token) $request->headers->set('Authorization', 'Bearer '.$token);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        return $response;
    };
    $writes = Http::recorded(fn ($request) => str_contains($request->url(), '/files/write'));
    $bootstrap = json_decode($writes->first()[0]->body(), true);
    $paired = $send('POST', 'pair', ['pairing_code' => $bootstrap['pairing_code']]);
    $check($paired->getStatusCode() === 200, 'Wings bootstrap code exchanges through actual panel HTTPS route');
    $credentials = json_decode($paired->getContent(), true);
    $check($credentials['node_id'] === $node->id && $credentials['installation_id'] === $policy->installation_id, 'real pairing response preserves bootstrap node scope');
    $check($send('POST', 'pair', ['pairing_code' => $bootstrap['pairing_code']])->getStatusCode() === 401, 'managed bootstrap code cannot be replayed');
    \Illuminate\Support\Carbon::setTestNow(now()->addSecond());
    $check($send('GET', 'desired-state', [], $credentials['credential'])->getStatusCode() === 200, 'paired service fetches real desired state');
    $installer->advance($node->id, $installation->operation_id);
    $check($installation->fresh()->status === 'ready', 'fresh contact completes provisioning');

    $report = ['status' => ['schema_version' => 1, 'generation' => $policy->fresh()->desired_generation,
        'mappings' => [], 'router_service' => 'urn:schemas-upnp-org:service:WANIPConnection:2']];
    $check($send('POST', 'report', $report, $credentials['credential'])->getStatusCode() === 200,
        'authenticated report accepts selected IGD2 service');
    $check($policy->fresh()->last_report['router_service'] === $report['status']['router_service'],
        'selected router interface survives report sanitization');
    $report['status']['router_service'] = 'urn:unknown:service:1';
    $check($send('POST', 'report', $report, $credentials['credential'])->getStatusCode() === 422,
        'router interface report rejects unknown service identifiers');
    unset($report['status']['router_service']);
    $check($send('POST', 'report', $report, $credentials['credential'])->getStatusCode() === 200,
        'older services without selected-interface reporting remain compatible');

    $files = ['credentials.json', 'service.json', 'state.json'];
    $bootstrapWrites = fn () => Http::recorded(fn ($request) => str_contains($request->url(), '/files/write'))->count();
    $beforeWrites = $bootstrapWrites();
    $reinstall = $installer->request($node->id, true);
    $policy->forceFill(['last_contact_at' => $reinstall->started_at])->saveQuietly();
    $installer->advance($node->id, $reinstall->operation_id); // queued -> stopping
    $installer->advance($node->id, $reinstall->operation_id); // stop
    $installer->advance($node->id, $reinstall->operation_id); // persist installing marker
    $installer->advance($node->id, $reinstall->operation_id); // reinstall
    $server->refresh()->forceFill(['status' => null])->saveQuietly();
    $installer->advance($node->id, $reinstall->operation_id);
    \Illuminate\Support\Carbon::setTestNow(now()->addSeconds(30));
    $installer->advance($node->id, $reinstall->operation_id);
    $check(!$installer->advance($node->id, $reinstall->operation_id), 'heartbeat from before restart cannot mark reinstall ready');
    \Illuminate\Support\Carbon::setTestNow(now()->addSecond());
    $check($send('GET', 'desired-state', [], $credentials['credential'])->getStatusCode() === 200, 'preserved credential works after reinstall');
    $check($installer->advance($node->id, $reinstall->operation_id), 'new heartbeat after restart completes reinstall');
    $check($bootstrapWrites() === $beforeWrites, 'reinstall never rewrites existing credentials or bootstrap');
    $check(Server::count() === 1 && Egg::where('uuid', Ipv4Release::EGG_UUID)->count() === 1, 'real importer and reinstall never duplicate server or Egg');

    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
    $html = \Livewire\Livewire::test(\PortOpener\Filament\Admin\Pages\PortOpenerHub::class)->html();
    $check(str_contains($html, 'IPv4 service') && str_contains($html, 'Reinstall IPv4 service'), 'managed actions render in native Pelican UI');
    require __DIR__.'/diagnostics.php';
    require __DIR__.'/ports_grouping.php';
    foreach (['Find-PortOpenerRouter.ps1', 'find_portopener_router.py'] as $helper) {
        $response = $send('GET', 'router-discovery/'.$helper);
        $check($response->getStatusCode() === 200 && $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse, 'HTTPS route serves bundled discovery helper '.$helper);
    }
    $check($send('GET', 'router-discovery/credentials.json')->getStatusCode() === 404, 'discovery route cannot select private files');
    $check($send('GET', 'router-discovery/find_portopener_router.py', [], null, false)->getStatusCode() === 400, 'helper download requires HTTPS');
    $help = view('port-opener::admin.router-discovery')->render();
    $check(str_contains($help, 'same home network') && str_contains($help, 'Download PowerShell helper') && str_contains($help, 'Download Python helper'), 'native discovery popup explains local-network workflow and both platforms');
    $download = 'downloads/'.app(Ipv4Release::class)->manifest()['filename'];
    $response = $send('GET', $download);
    $check($response->getStatusCode() === 200 && $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse, 'real HTTPS route serves bundled service ZIP');
    $check($send('GET', $download, [], null, false)->getStatusCode() === 400, 'download route rejects plain HTTP');
    echo "$checks real Pelican IPv4 integration checks passed (Wings HTTP simulated).\n";
} catch (Throwable $exception) {
    echo get_class($exception).': '.$exception->getMessage().PHP_EOL.$exception->getTraceAsString().PHP_EOL;
    exit(1);
}
