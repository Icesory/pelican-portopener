<?php
/** Isolated Laravel/SQLite tests. Pelican creation/import and Wings are simulated; no live node is touched.
 * Run: php tests/ipv4_installation.php /path/to/laravel/vendor/autoload.php
 */
namespace {
    require $argv[1] ?? __DIR__.'/../runtime/ipv4-tests/vendor/autoload.php';
    spl_autoload_register(function ($class) {
        if (str_starts_with($class, 'PortOpener\\')) {
            $path = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, 11)).'.php';
            if (is_file($path)) require $path;
        }
    });
}
namespace App\Enums {
    enum EggFormat { case JSON; }
    enum ServerState: string { case Installing = 'installing'; }
}
namespace App\Models\Objects { class DeploymentObject {} }
namespace App\Models {
    class User extends \Illuminate\Foundation\Auth\User {
        protected $guarded = []; public function isRootAdmin(): bool { return (bool) $this->root_admin; }
    }
    class Egg extends \Illuminate\Database\Eloquent\Model {
        protected $guarded = [];
        protected $casts = ['docker_images' => 'array', 'startup_commands' => 'array'];
        public function variables() { return $this->hasMany(EggVariable::class); }
    }
    class EggVariable extends \Illuminate\Database\Eloquent\Model { protected $guarded = []; }
    class Server extends \Illuminate\Database\Eloquent\Model {
        protected $guarded = [];
        public function egg() { return $this->belongsTo(Egg::class); }
        public function serverVariables() { return $this->hasMany(ServerVariable::class); }
        public function subusers() { return $this->hasMany(Subuser::class); }
    }
    class ServerVariable extends \Illuminate\Database\Eloquent\Model { protected $guarded = []; }
    class Subuser extends \Illuminate\Database\Eloquent\Model { protected $guarded = []; }
}
namespace App\Services\Eggs\Sharing {
    class EggImporterService {
        public function fromContent(string $content, $format): \App\Models\Egg {
            $data = json_decode($content, true);
            $egg = \App\Models\Egg::updateOrCreate(['uuid' => $data['uuid']], [
                'docker_images' => $data['docker_images'], 'startup_commands' => ['Default' => $data['startup']],
            ]);
            foreach ($data['variables'] as $variable) $egg->variables()->updateOrCreate(['env_variable' => $variable['env_variable']], []);
            return $egg;
        }
    }
}
namespace App\Services\Servers {
    class ServerCreationService {
        public int $creates = 0;
        public function handle(array $data, ?\App\Models\Objects\DeploymentObject $deployment = null): \App\Models\Server {
            $this->creates++;
            if ($data['start_on_completion'] !== false) throw new \RuntimeException('Must not start before bootstrap.');
            $server = \App\Models\Server::create(array_intersect_key($data, array_flip(['node_id', 'external_id', 'owner_id', 'egg_id']))
                + ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'installing']);
            app(\PortOpener\Repositories\ManagedIpv4DaemonServerRepository::class)->setServer($server)->create(false);
            return $server;
        }
    }
}
namespace App\Repositories\Daemon {
    class DaemonServerRepository {
        protected ?\App\Models\Server $server;
        public static int $creates = 0;
        public function setServer(\App\Models\Server $server): self { $this->server = $server; return $this; }
        public function create(bool $startOnCompletion = true): void { self::$creates++; }
        public function getHttpClient() {
            return \Illuminate\Support\Facades\Http::baseUrl('https://wings.example.com')->withToken('synthetic-wings-token')->throwIf(true);
        }
    }
}
namespace {
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\{DB, Schema};
    use PortOpener\Models\{Ipv4Installation, NodePolicy, NodeCredential, Pairing};
    use PortOpener\Services\{Ipv4Installer, Ipv4Release, Ipv4Wings};
    use App\Models\{User, Server};

    $app = new Illuminate\Foundation\Application(__DIR__.'/../runtime/ipv4-tests');
    $app->instance('config', new Illuminate\Config\Repository([
        'app' => ['url' => 'https://panel.example.com', 'key' => 'test'],
        'queue' => ['default' => 'database'], 'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
    ]));
    Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    $capsule = new Capsule($app);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setEventDispatcher(new Illuminate\Events\Dispatcher($app));
    $capsule->setAsGlobal(); $capsule->bootEloquent();
    $app->instance('db', $capsule->getDatabaseManager());
    $app->instance('db.schema', $capsule->schema());
    $app->instance('db.transactions', new Illuminate\Database\DatabaseTransactionsManager());
    $capsule->getConnection()->setTransactionManager($app['db.transactions']);
    $app->instance('cache', new Illuminate\Cache\CacheManager($app));
    $app->instance(Illuminate\Contracts\Bus\Dispatcher::class, new Illuminate\Support\Testing\Fakes\BusFake(new Illuminate\Bus\Dispatcher($app)));
    $auth = new class implements Illuminate\Contracts\Auth\Guard {
        public ?User $current = null;
        public function user() { return $this->current; }
        public function id() { return $this->current?->id; }
        public function check() { return $this->current !== null; }
        public function guest() { return !$this->check(); }
        public function hasUser() { return $this->check(); }
        public function validate(array $credentials = []) { return false; }
        public function setUser(Illuminate\Contracts\Auth\Authenticatable $user) { $this->current = $user; return $this; }
    };
    $app->instance('auth', $auth);

    foreach (['users', 'eggs', 'egg_variables', 'servers', 'server_variables', 'subusers'] as $table) {
        Schema::create($table, function (Blueprint $t) use ($table) {
            $t->id(); $t->timestamps();
            match ($table) {
                'users' => $t->boolean('root_admin'),
                'eggs' => [$t->uuid('uuid')->unique(), $t->json('docker_images'), $t->json('startup_commands')],
                'egg_variables' => [$t->unsignedBigInteger('egg_id'), $t->string('env_variable')],
                'servers' => [$t->uuid('uuid'), $t->unsignedBigInteger('node_id'), $t->unsignedBigInteger('owner_id'), $t->unsignedBigInteger('egg_id'), $t->string('external_id'), $t->string('status')->nullable()],
                'server_variables' => [$t->unsignedBigInteger('server_id'), $t->unsignedBigInteger('variable_id'), $t->text('variable_value')],
                'subusers' => $t->unsignedBigInteger('server_id'),
            };
        });
    }
    Schema::create('port_opener_node_policies', function (Blueprint $t) {
        $t->id(); $t->timestamps(); $t->unsignedBigInteger('node_id')->unique(); $t->string('connection_mode');
        $t->uuid('installation_id'); $t->boolean('service_enabled'); $t->boolean('ipv6_enabled'); $t->string('router_url');
        $t->json('last_report')->nullable(); $t->timestamp('last_contact_at')->nullable();
    });
    foreach (['port_opener_pairings', 'port_opener_node_credentials'] as $table) {
        Schema::create($table, function (Blueprint $t) use ($table) {
            $t->id(); $t->timestamps(); $t->unsignedBigInteger('node_id'); $t->timestamp('expires_at');
            if ($table === 'port_opener_pairings') { $t->string('code_hash')->unique(); $t->timestamp('used_at')->nullable(); }
            else { $t->string('token_hash')->unique(); $t->timestamp('revoked_at')->nullable(); $t->timestamp('renewed_at')->nullable(); }
        });
    }
    (require __DIR__.'/../database/migrations/2026_10_20_000010_managed_ipv4_installations.php')->up();

    $wings = new class extends Ipv4Wings {
        public string $currentState = 'offline'; public array $data = []; public int $bootstraps = 0; public int $reinstalls = 0;
        public array $createdUuids = []; public bool $loseCreateResponse = false;
        public function ensureCreated(Server $server): void {
            $this->createdUuids[$server->uuid] = true;
            if ($this->loseCreateResponse) { $this->loseCreateResponse = false; throw new RuntimeException('Simulated response loss'); }
        }
        public function state(Server $server): string { return $this->currentState; }
        public function power(Server $server, string $action): void { $this->currentState = $action === 'start' ? 'running' : 'offline'; }
        public function reinstall(Server $server): void { $this->reinstalls++; }
        public function sync(Server $server): void {}
        public function files(Server $server, string $directory): array { return array_keys($this->data); }
        public function bootstrap(Server $server, array $body): void { $this->bootstraps++; $this->data['bootstrap.json'] = json_encode($body); }
    };
    $app->instance(Ipv4Wings::class, $wings);
    $app->singleton(Ipv4Installer::class);
    $inner = new App\Services\Servers\ServerCreationService();
    $app->instance(App\Services\Servers\ServerCreationService::class, new PortOpener\Services\PortOpenerServerCreation($inner));
    $admin = User::create(['root_admin' => true]); $owner = User::create(['root_admin' => false]);
    $auth->current = $admin;
    $policy = NodePolicy::create(['node_id' => 1, 'connection_mode' => 'outbound', 'service_enabled' => true,
        'ipv6_enabled' => false, 'router_url' => 'http://192.168.178.1:49000/igddesc.xml', 'installation_id' => (string) Illuminate\Support\Str::uuid()]);
    $installer = app(Ipv4Installer::class);
    $checks = 0;
    $check = function ($value, $message) use (&$checks) { if (!$value) throw new RuntimeException('FAIL: '.$message); $checks++; };
    $reject = function ($callback, $message) use ($check) {
        try { $callback(); } catch (Throwable $e) { $check(true, $message); return; }
        throw new RuntimeException('FAIL: '.$message);
    };

    $auth->current = $owner; $reject(fn () => $installer->request(1), 'owner cannot install'); $auth->current = $admin;
    $policy->update(['ipv6_enabled' => true]); $reject(fn () => $installer->request(1), 'IPv6 policy rejected'); $policy->update(['ipv6_enabled' => false]);
    NodeCredential::create(['node_id' => 1, 'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addDay()]);
    $reject(fn () => $installer->request(1), 'existing host service not duplicated'); NodeCredential::query()->delete();
    $installation = $installer->request(1); $operation = $installation->operation_id;
    $check($installer->request(1)->id === $installation->id && Ipv4Installation::count() === 1, 'repeated request reuses unique node slot');
    $duplicate = $installation->getAttributes(); unset($duplicate['id']);
    $reject(fn () => Ipv4Installation::create($duplicate), 'database uniqueness rejects duplicate node');
    $installer->advance(1, $operation);
    $installation->refresh(); $server = Server::findOrFail($installation->server_id);
    $check(Server::count() === 1 && $inner->creates === 1, 'one server created');
    $check($installation->status === 'creating' && !$wings->createdUuids, 'server identity committed before Wings creation');
    $check(App\Repositories\Daemon\DaemonServerRepository::$creates === 0, 'managed creation defers the original Wings request');
    app(PortOpener\Repositories\ManagedIpv4DaemonServerRepository::class)->setServer($server)->create(false);
    $check(App\Repositories\Daemon\DaemonServerRepository::$creates === 1, 'ordinary creation still delegates to the stock repository');
    $wings->loseCreateResponse = true;
    $reject(fn () => $installer->advance(1, $operation), 'lost Wings creation response can fail safely');
    $installer->failed(1, $operation); $installation = $installer->request(1); $operation = $installation->operation_id;
    $check($installation->status === 'creating' && $installation->server_id === $server->id, 'response-loss recovery preserves saved server');
    $installer->advance(1, $operation);
    $check(count($wings->createdUuids) === 1 && Server::count() === 1, 'response-loss retry uses exactly one Wings UUID');
    $installer->advance(1, $operation); $check($installation->fresh()->status === 'installing' && $wings->bootstraps === 0, 'waits for installer callback');
    $reject(fn () => app(App\Services\Servers\ServerCreationService::class)->handle(['node_id' => 1, 'egg_id' => $server->egg_id]), 'manual duplicate service creation rejected');
    $server->update(['status' => null]); $installer->advance(1, $operation); $installer->advance(1, $operation);
    $bootstrap = json_decode($wings->data['bootstrap.json'], true);
    $check($bootstrap['node_id'] === 1 && $bootstrap['installation_id'] === $policy->installation_id && strlen($bootstrap['pairing_code']) === 64, 'private bootstrap scoped to node and identity');
    $check(!Pairing::where('code_hash', $bootstrap['pairing_code'])->exists(), 'only bootstrap code hash stored in database');
    $check(!$installer->advance(1, $operation), 'running alone does not mean paired');
    $policy->update(['last_contact_at' => now()->addSecond()]); $check($installer->advance(1, $operation), 'fresh service report finishes provisioning');
    $check($installer->request(1)->operation_id === $operation && Server::count() === 1, 'install on ready server is idempotent');

    $wings->data = ['credentials.json' => 'secret unchanged', 'service.json' => 'config unchanged', 'state.json' => 'ownership unchanged']; $before = $wings->data;
    $reinstall = $installer->request(1, true); $newOperation = $reinstall->operation_id;
    $check($installer->advance(1, $operation) && $reinstall->fresh()->status === 'queued', 'old queued job cannot mutate new operation');
    $installer->advance(1, $newOperation); $installer->advance(1, $newOperation); $installer->advance(1, $newOperation);
    $check($reinstall->fresh()->status === 'reinstalling' && $server->fresh()->status === 'installing', 'reinstall marker committed before Wings request');
    $installer->advance(1, $newOperation);
    $server->refresh()->update(['status' => null]); $installer->advance(1, $newOperation); $installer->advance(1, $newOperation);
    $check($wings->reinstalls === 1 && $wings->data === $before && $wings->bootstraps === 1, 'reinstall preserves all data without new pairing');
    $check(Server::count() === 1 && $inner->creates === 1, 'reinstall reuses original server');
    $installer->failed(1, $newOperation); $retry = $installer->request(1);
    $check($retry->server_id === $server->id, 'failed setup resumes same server');
    $installer->advance(1, $retry->operation_id); $installer->advance(1, $retry->operation_id); $installer->advance(1, $retry->operation_id);
    $installer->advance(1, $retry->operation_id);
    unset($wings->data['credentials.json']);
    $reject(fn () => $installer->advance(1, $retry->operation_id), 'missing credentials with ownership state does not create new identity');
    $check($wings->bootstraps === 1, 'recovery failure does not issue replacement bootstrap');
    $installer->failed(1, $retry->operation_id);
    $server->update(['node_id' => 2]); $moved = $installer->request(1);
    $reject(fn () => $installer->advance(1, $moved->operation_id), 'moved server cannot receive bootstrap');
    $check(Server::count() === 1, 'moved server failure never creates another server');
    $release = app(Ipv4Release::class);
    $reject(fn () => $release->path('../manifest.json'), 'download traversal rejected');
    $reject(fn () => $release->path('credentials.json'), 'private filenames never served');
    $check(hash_file('sha256', $release->path($release->manifest()['filename'])) === $release->manifest()['sha256'], 'bundled release integrity');

    // Exercise the real transport with Laravel HTTP fakes, including clients which auto-throw on 404/409.
    $app->instance(Illuminate\Http\Client\Factory::class, new Illuminate\Http\Client\Factory());
    $http = Illuminate\Support\Facades\Http::getFacadeRoot();
    $http->fake(['*' => $http->sequence()->push([], 404)->push([], 409)->push(['state' => 'offline'], 200)]);
    $transport = new Ipv4Wings();
    $transport->ensureCreated($server);
    $requests = $http->recorded();
    $check($requests->count() === 3 && $requests[1][0]['uuid'] === $server->uuid && $requests[1][0]['start_on_completion'] === false, 'transport recovers a duplicate-create response using the saved UUID');
    $check($requests[0][0]->hasHeader('Authorization', 'Bearer synthetic-wings-token'), 'Wings transport uses authenticated requests');
    $app->instance(Illuminate\Http\Client\Factory::class, new Illuminate\Http\Client\Factory());
    Illuminate\Support\Facades\Http::clearResolvedInstance(Illuminate\Http\Client\Factory::class);
    Illuminate\Support\Facades\Http::fake(['*' => Illuminate\Support\Facades\Http::response([], 404)]);
    $check($transport->files($server, '/data') === [], 'absent data directory handled with auto-throwing Wings client');
    $app->instance(Illuminate\Http\Client\Factory::class, new Illuminate\Http\Client\Factory());
    Illuminate\Support\Facades\Http::clearResolvedInstance(Illuminate\Http\Client\Factory::class);
    $http = Illuminate\Support\Facades\Http::getFacadeRoot();
    $http->fake(['*' => $http->sequence()->push([['name' => 'data']], 200)->push([], 204)->push([], 204)
        ->push([], 204)->push([['name' => 'bootstrap.json']], 200)->push([], 204)->push([], 204)]);
    $transport->bootstrap($server, ['pairing_code' => 'synthetic-code']);
    $requests = $http->recorded();
    $check($requests->count() === 7 && $requests[5][0]['files'] === ['bootstrap.json'], 'obsolete bootstrap removed before rename without deleting durable data');
    $check($requests[3][0]['files'][0]['mode'] === '600' && $requests[6][0]['files'][0]['to'] === 'bootstrap.json', 'bootstrap permission set before final rename');
    $patterns = $release->egg()['file_denylist'];
    $denied = fn ($name) => (bool) array_filter($patterns, fn ($pattern) => fnmatch($pattern, $name));
    $check($denied('data/credentials.json') && $denied('data/state.json') && !$denied('data/bootstrap.json') && !$denied('data/bootstrap.json.pending'), 'Wings denylist protects persistent data while allowing bootstrap API writes');
    echo "$checks IPv4 installation checks passed (Laravel/SQLite with simulated Wings).\n";
}
