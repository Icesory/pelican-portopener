<?php
/** Fresh verification container only; all injected secrets are synthetic sentinels. */
use PortOpener\Services\NodeDiagnostics;
$diagnostics = app(NodeDiagnostics::class);
$secret = 'TEST_SECRET_'.bin2hex(random_bytes(24));
$now = now()->toIso8601String();
$event = ['timestamp'=>$now,'event'=>'mapping_state','level'=>'error','port'=>2456,'protocol'=>'UDP',
    'state'=>'error','error_code'=>'upnp_606','message'=>$secret,'authorization'=>$secret,'raw_request'=>['token'=>$secret]];
$input = ['schema_version'=>1,'service_version'=>'0.6.0','python_version'=>'3.12.15','debug_active'=>false,
    'debug_until'=>null,'storage_ok'=>true,'credential'=>$secret,'config'=>['token'=>$secret], 'events'=>[$event]];
$report = ['status'=>['schema_version'=>1,'generation'=>$policy->fresh()->desired_generation,'mappings'=>[]], 'diagnostics'=>$input];
$check($send('POST','report',$report,$credentials['credential'])->getStatusCode() === 200,'real authenticated route accepts diagnostics');
$saved = $policy->fresh()->service_diagnostics;
$check(!str_contains(json_encode($saved),$secret),'received diagnostics drop injected secrets before persistence');
$check($saved['events'][0]['error_code'] === 'upnp_606','safe UPnP code survives sanitization');
$check(isset($saved['received_at']),'receipt timestamp is supplied by panel');
foreach (['service_version','python_version','debug_until'] as $key) {
    $poisoned = $input; $poisoned[$key] = $secret;
    $check(!str_contains(json_encode(NodeDiagnostics::sanitize($poisoned)),$secret),'root diagnostic field cannot carry arbitrary secrets: '.$key);
}
foreach (array_merge(['event','level','timestamp','error_code'],array_keys($event),['router_service','outcome','phase','duration_ms','generation','family']) as $key) {
    $poisoned = $input; $poisoned['events'][0][$key] = $secret;
    $check(!str_contains(json_encode(NodeDiagnostics::sanitize($poisoned)),$secret),'event field cannot carry arbitrary secrets: '.$key);
}
$large = $input; $large['events'] = array_fill(0,129,$event);
$check($send('POST','report',['status'=>$report['status'],'diagnostics'=>$large],$credentials['credential'])->getStatusCode() === 422,'route bounds event count');
$check(count(NodeDiagnostics::sanitize($large)['events']) === 128,'export sanitization also bounds old/corrupted event history');
$diagnostics->enable($node->id);
$check($policy->fresh()->debug_until->diffInSeconds(now(),absolute:true) <= 900,'debug request is bounded to fifteen minutes');
$desired = json_decode($send('GET','desired-state',[],$credentials['credential'])->getContent(),true);
$check($desired['diagnostics_control']['debug_until'] !== null,'debug request uses authenticated outbound response');
$check(!array_key_exists('debug_until',$desired['configuration']),'old strict configuration readers remain compatible');
$diagnostics->disable($node->id);
$desired = json_decode($send('GET','desired-state',[],$credentials['credential'])->getContent(),true);
$check($desired['diagnostics_control']['debug_until'] === null,'manual stop clears debug request');
$policy->refresh()->forceFill(['debug_until'=>now()->subSecond()])->saveQuietly();
$desired = json_decode($send('GET','desired-state',[],$credentials['credential'])->getContent(),true);
$check($desired['diagnostics_control']['debug_until'] === null,'expired debug request is not delivered again');

// Poison stored input too: downloads must not trust an earlier sanitizer or arbitrary model strings.
$beforePolicy = $policy->fresh()->getAttributes();
$policy->forceFill(['router_url'=>'http://192.168.178.1:49000/igd2desc.xml','service_url'=>'https://'.$secret.'.invalid',
    'service_error'=>$secret,'service_diagnostics'=>$input,
    'last_report'=>['generation'=>1,'router_url'=>$secret,'router_service'=>$secret,'message'=>$secret,
        'mappings'=>[['port'=>2456,'protocol'=>'UDP','state'=>'error','error_code'=>$secret,'message'=>$secret,'mapping_id'=>$secret]]]])->saveQuietly();
$snapshot = $diagnostics->snapshot($node->id);
$download = $diagnostics->download($node->id);
ob_start(); $download->sendContent(); $body = ob_get_clean();
$check(!str_contains($body,$secret),'download rebuilds poisoned database values and excludes all sentinels');
$check(!str_contains($body,'192.168.178.1') && !str_contains($body,$policy->installation_id),'export omits router addresses and installation identity');
$check(json_decode($body,true)['node_id'] === $node->id,'download is scoped to chosen node');
$check(str_contains($download->headers->get('Content-Disposition'),'portopener-node-'.$node->id),'download has a node-specific filename');
$check(str_contains($download->headers->get('Cache-Control'),'no-store'),'download disables response caching');
$check($snapshot['status']['ports'][0]['error_code'] === 'internal_error','unknown cached error strings become a safe code');
$policy->setRawAttributes($beforePolicy)->saveQuietly();

$hub = \Livewire\Livewire::test(\PortOpener\Filament\Admin\Pages\PortOpenerHub::class);
$hub->call('mountAction','configure',[],['table'=>true,'recordKey'=>(string)$node->id]);
$modal = (new ReflectionMethod($hub->instance(),'getMountedActionSchema'))->invoke($hub->instance())->toHtml();
foreach (['Download diagnostics','Enable debug (15 minutes)','Stop debug','Debug mode adds'] as $label) {
    $check(str_contains($modal,$label),'native node dialog exposes '.$label);
}
$schema = (new ReflectionMethod($hub->instance(),'getMountedActionSchema'))->invoke($hub->instance());
$actions = collect($schema->getFlatComponents())->filter(fn ($component) => $component instanceof \Filament\Actions\Action)->keyBy(fn ($action) => $action->getName());
$check($actions->has('downloadDiagnostics') && $actions->has('enableDebug') && $actions->has('disableDebug'), 'native modal resolves all diagnostics action contexts');
$hub->call('mountAction','downloadDiagnostics',[],$actions['downloadDiagnostics']->getContext());
$effect = $hub->effects['download'] ?? [];
$check(($effect['name'] ?? null) === 'portopener-node-'.$node->id.'-'.now()->utc()->format('Ymd-His').'.json', 'actual native modal action delivers the diagnostic download');
$check(json_decode(base64_decode($effect['content'] ?? ''), true)['node_id'] === $node->id, 'native download effect contains the selected node JSON');
$hub->call('mountAction','enableDebug',[],$actions['enableDebug']->getContext());
$check($policy->fresh()->debug_until?->isFuture(),'actual native modal action enables debug');
$hub->call('mountAction','disableDebug',[],$actions['disableDebug']->getContext());
$check($policy->fresh()->debug_until === null,'actual native modal action stops debug');

$reader = app(\App\Services\Users\UserCreationService::class)->handle(['username'=>'diagnostics-owner','email'=>'diagnostics-owner@example.invalid',
    'password'=>bin2hex(random_bytes(24)),'root_admin'=>false]);
auth()->setUser($reader);
foreach (['snapshot','download','enable','disable','summary'] as $method) {
    try { $diagnostics->$method($node->id); throw new RuntimeException('Owner diagnostics access allowed'); }
    catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) { $check($exception->getStatusCode() === 403,'owner denied diagnostics '.$method); }
}
auth()->setUser($admin);
$policy->refresh()->forceFill(['service_diagnostics'=>null,'debug_until'=>null])->saveQuietly();
try { $diagnostics->enable($node->id); throw new RuntimeException('Unsupported service accepted debug'); }
catch (\Illuminate\Validation\ValidationException) { $check(true,'older service gets an actionable update requirement'); }
$check($diagnostics->snapshot($node->id)['service'] === null,'older/offline service still permits panel-only status export');
