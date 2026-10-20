<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->instance('request',\Illuminate\Http\Request::create('https://panel.invalid/'));$kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);$kernel->bootstrap();
if(config('app.name')!=='PortOpener Development'||!str_contains((string)config('database.connections.sqlite.database'),'/pelican-data/'))throw new RuntimeException('Development panel only.');
config(['cache.default'=>'array','cache.limiter'=>'array']);
\Illuminate\Support\Facades\Queue::fake();
$oldLimiter=\Illuminate\Support\Facades\RateLimiter::getFacadeRoot();$testLimiter=new \Illuminate\Cache\RateLimiter(app('cache')->store('array'));
foreach(['port-opener-api','port-opener-pair','port-opener-renew'] as $name)$testLimiter->for($name,$oldLimiter->limiter($name));
\Illuminate\Support\Facades\RateLimiter::swap($testLimiter);
use Illuminate\Http\Request;use Illuminate\Support\Facades\DB;use App\Models\{User,Server};use PortOpener\Models\{NodePolicy,Exposure,SyncState};use PortOpener\Services\ServicePairing;
$checks=0;$check=function($ok,$name)use(&$checks){if(!$ok)throw new RuntimeException('FAIL: '.$name);$checks++;};
$send=function(string $method,string $path,array $body=[],?string $token=null,bool $tls=true)use($kernel){$request=Request::create(($tls?'https':'http').'://panel.invalid/api/port-opener/v1/'.$path,$method,[],[],[],['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json','REMOTE_ADDR'=>'192.0.2.123'],json_encode($body));if($token)$request->headers->set('Authorization','Bearer '.$token);$response=$kernel->handle($request);$kernel->terminate($request,$response);return $response;};
$server=Server::where('name','PortOpener Synthetic Server')->firstOrFail();$policy=NodePolicy::where('node_id',$server->node_id)->firstOrFail();
// Pelican's exception handler rolls back open transactions, so routed rejection tests use explicit restoration.
$policyOriginal=$policy->getAttributes();$exposureOriginal=Exposure::where('server_id',$server->id)->first()?->getAttributes();$syncOriginal=SyncState::where('installation_id',$policy->installation_id)->first()?->getAttributes();
$pairingIds=\PortOpener\Models\Pairing::pluck('id')->all();$credentialIds=\PortOpener\Models\NodeCredential::pluck('id')->all();
$secondPolicy=null;
try {
$policy->forceFill(['connection_mode'=>'outbound','service_enabled'=>true,'enabled'=>true,'service_dry_run'=>true])->save();
Exposure::updateOrCreate(['server_id'=>$server->id],['enabled'=>true,'mode'=>'always']);auth()->setUser(User::where('username','dev-admin')->firstOrFail());
$pair=app(ServicePairing::class)->generate($policy->node_id);
$check($send('POST','pair',['pairing_code'=>$pair['pairing_code']],null,false)->getStatusCode()===400,'routed HTTP rejected');
$check($send('GET','desired-state')->getStatusCode()===401,'routed missing bearer rejected');
$storedPair=\PortOpener\Models\Pairing::where('code_hash',hash('sha256',$pair['pairing_code']))->first();
$check($storedPair!==null,'pairing row remains present');$check(!$storedPair->used_at,'pairing remains unused before secure exchange');$check($storedPair->expires_at->isFuture(),'pairing remains unexpired');
$paired=$send('POST','pair',['pairing_code'=>$pair['pairing_code']]);$pairJson=json_decode($paired->getContent(),true);$pairError=$pairJson['message'] ?? $pairJson['errors'][0]['detail'] ?? '';
$safePairError=in_array($pairError,['Invalid or expired pairing code.','Unauthenticated.','Service authentication required.','Service authentication failed.'],true)?$pairError:'unexpected';
$check($paired->getStatusCode()===200,'routed HTTPS pair succeeds (HTTP '.$paired->getStatusCode().', '.$safePairError.')');$credential=json_decode($paired->getContent(),true)['credential'];
$replay=$send('POST','pair',['pairing_code'=>$pair['pairing_code']]);$check($replay->getStatusCode()===401,'routed pair replay rejected (HTTP '.$replay->getStatusCode().')');
$response=$send('GET','desired-state',[],$credential);$check($response->getStatusCode()===200,'routed authenticated snapshot');$snapshot=json_decode($response->getContent(),true);
$check($snapshot['desired_state']['node_id']===$server->node_id && $snapshot['configuration']['dry_run']===true,'routed node-scoped configuration');
$secondPolicy=NodePolicy::create(['node_id'=>$server->node_id+10000,'connection_mode'=>'outbound','service_enabled'=>true,'enabled'=>true,'internal_address'=>'192.168.10.50','router_url'=>'http://192.168.10.1/desc.xml','forwarding_type'=>'upnp','min_port'=>54000,'max_port'=>54999,'lease_seconds'=>3600,'reconcile_seconds'=>60]);
auth()->setUser(User::where('username','dev-admin')->firstOrFail());$secondCode=app(ServicePairing::class)->generate($secondPolicy->node_id);$secondPair=$send('POST','pair',['pairing_code'=>$secondCode['pairing_code']]);
$check($secondPair->getStatusCode()===200,'second node pairs independently');$secondToken=json_decode($secondPair->getContent(),true)['credential'];
$secondResponse=$send('GET','desired-state',[],$secondToken);$secondSnapshot=json_decode($secondResponse->getContent(),true);
$check($secondResponse->getStatusCode()===200 && $secondSnapshot['desired_state']['node_id']===$secondPolicy->node_id && !$secondSnapshot['desired_state']['mappings'],'second node cannot receive first node allocations');
$rows=array_map(fn($row)=>array_merge($row,['state'=>'mapped']),$snapshot['desired_state']['mappings']);
$reportedTime=now()->subSeconds(5)->toIso8601String();
$status=['schema_version'=>1,'generation'=>$snapshot['desired_state']['generation'],'backend'=>'fake','dry_run'=>false,'renewal_paused'=>true,'router_reachable'=>true,'router_url'=>$policy->router_url,'last_reconciled_at'=>$reportedTime,'mappings'=>$rows];
$check($send('POST','report',['status'=>$status],$credential)->getStatusCode()===200,'routed accepted bound report');
$saved=$policy->fresh()->last_report;
$check($saved['backend']==='fake' && $saved['dry_run']===false && $saved['router_reachable']===true && $saved['renewal_paused']===true,'routed backend and explicit status booleans preserved');
$check($saved['router_url']===$status['router_url'] && $saved['last_reconciled_at']===$reportedTime,'routed router URL and actual reconciliation time preserved');
$invalidTime=$status;$invalidTime['last_reconciled_at']='not-a-date';$check($send('POST','report',['status'=>$invalidTime],$credential)->getStatusCode()===200 && $policy->fresh()->last_report['last_reconciled_at']===null,'invalid reconciliation time sanitized without inventing a timestamp');
$sanitized=$status;$sanitized['mappings'][0]['state']='conflict';$sanitized['mappings'][0]['error_code']='port_in_use';$sanitized['mappings'][0]['message']='private router response must not be echoed';
$check($send('POST','report',['status'=>$sanitized],$credential)->getStatusCode()===200 && $policy->fresh()->last_report['mappings'][0]['error_code']==='port_in_use' && $policy->fresh()->last_report['mappings'][0]['message']==='External port is already in use.','mapping error code retained and raw diagnostic sanitized');
app(\PortOpener\Services\SyncService::class)->testRouterForNode($policy->node_id);$policy->refresh();
$testReport=['status'=>$status,'test_request_id'=>$policy->test_request_id,'test_result'=>['backend'=>'fake','router_reachable'=>true,'message'=>'private test diagnostic']];
$check($send('POST','report',$testReport,$credential)->getStatusCode()===200 && $policy->fresh()->last_router_test['backend']==='fake' && !str_contains($policy->fresh()->last_router_test['message'],'private'),'nested test result backend preserved and message sanitized');
$policy->forceFill(['ipv6_enabled'=>true])->saveQuietly();
Exposure::where('server_id',$server->id)->update(['ipv6_enabled'=>true]);
$ipv6Snapshot=json_decode($send('GET','desired-state',[],$credential)->getContent(),true);
$check(in_array($status['mappings'][0]['mapping_id'],$ipv6Snapshot['ipv6_mapping_ids'],true),'IPv6 approval resolves only assigned desired IDs');
$emptyIpv6=$status;$emptyIpv6['ipv6']=['enabled'=>false,'state'=>'disabled','address'=>null,'interface'=>null,'error_code'=>null,'mappings'=>[]];
$check($send('POST','report',['status'=>$emptyIpv6],$credential)->getStatusCode()===200 && $policy->fresh()->last_report['ipv6']['mappings']===[],'disabled IPv6 empty report accepted');
$v6=$status;$v6['ipv6']=['enabled'=>true,'state'=>'available','address'=>'2001:4860:ffff:1::1234','interface'=>'eth0','error_code'=>null,'mappings'=>[
    ['mapping_id'=>$status['mappings'][0]['mapping_id'],'port'=>$status['mappings'][0]['port'],'protocol'=>$status['mappings'][0]['protocol'],'address'=>'2001:4860:ffff:1::1234','state'=>'pending','error_code'=>'ipv6_verification_denied'],
]];
$check($send('POST','report',['status'=>$v6],$credential)->getStatusCode()===200 && $policy->fresh()->last_report['ipv6']['mappings'][0]['state']==='pending','IPv6 report preserves unverified state');
$v6['ipv6']['mappings'][0]['mapping_id']='cccccccc-cccc-4ccc-cccc-cccccccccccc:999:tcp';
$check($send('POST','report',['status'=>$v6],$credential)->getStatusCode()===422,'foreign IPv6 mapping rejected');
Exposure::where('server_id',$server->id)->update(['ipv6_enabled'=>false]);
$disabledIpv6=json_decode($send('GET','desired-state',[],$credential)->getContent(),true);
$check($disabledIpv6['ipv6_mapping_ids']===[],'IPv6 approval disable requests cleanup');
$policy->forceFill(['ipv6_enabled'=>false])->saveQuietly();
$forged=$status;$forged['mappings']=[['mapping_id'=>'cccccccc-cccc-4ccc-cccc-cccccccccccc:999:tcp','port'=>54321,'protocol'=>'TCP','state'=>'mapped']];
$check($send('POST','report',['status'=>$forged],$credential)->getStatusCode()===422,'routed foreign mapping rejected');
$foreignReport=$status;$foreignReport['generation']=$secondSnapshot['desired_state']['generation'];
$check($send('POST','report',['status'=>$foreignReport],$secondToken)->getStatusCode()===422,'second node cannot report first node mapping IDs');
$future=$status;$future['generation']+=100;$check($send('POST','report',['status'=>$future],$credential)->getStatusCode()===409,'routed unpublished generation rejected');
$check($send('POST','report',['status'=>['schema_version'=>1]],$credential)->getStatusCode()===422,'routed schema validation');
$policy->forceFill(['service_enabled'=>false])->saveQuietly();$check($send('GET','desired-state',[],$credential)->getStatusCode()===403,'routed disabled node rejected');$policy->forceFill(['service_enabled'=>true])->saveQuietly();
$renew=$send('POST','renew',[],$credential);$check($renew->getStatusCode()===200,'routed credential renewal');
$newCredential=json_decode($renew->getContent(),true)['credential'];$check($send('GET','desired-state',[],$newCredential)->getStatusCode()===200,'routed renewed bearer accepted');
$check($send('POST','renew',[],$credential)->getStatusCode()===409,'routed repeated old renewal rejected');
for($i=0;$i<6;$i++)$throttled=$send('POST','pair',['pairing_code'=>str_repeat('a',64)]);
$check($throttled->getStatusCode()===429,'routed pairing rate limit enforced');
echo "$checks outbound routed API checks passed\n";
}finally{
\PortOpener\Models\Pairing::whereNotIn('id',$pairingIds)->delete();\PortOpener\Models\NodeCredential::whereNotIn('id',$credentialIds)->delete();
if($secondPolicy){SyncState::where('installation_id',$secondPolicy->installation_id)->delete();$secondPolicy->delete();}
$policy->setRawAttributes($policyOriginal)->saveQuietly();
if($exposureOriginal){$record=Exposure::where('server_id',$server->id)->first();$record->setRawAttributes($exposureOriginal)->saveQuietly();}else Exposure::where('server_id',$server->id)->delete();
if($syncOriginal){$record=SyncState::where('installation_id',$policy->installation_id)->first();$record->setRawAttributes($syncOriginal)->saveQuietly();}else SyncState::where('installation_id',$policy->installation_id)->delete();
}
