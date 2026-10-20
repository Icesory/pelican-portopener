<?php
// Uses the synthetic fixture only, transactions roll back all changes. No router requests.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('app.name') !== 'PortOpener Development' || !str_contains((string)config('database.connections.sqlite.database'), '/pelican-data/')) throw new RuntimeException('Refusing tests outside isolated development panel.');
use App\Models\{Server,User,Allocation};
use Illuminate\Support\Facades\{DB,Http,Queue};
use PortOpener\Models\{Exposure,EggRule,NodePolicy,SyncState};
use PortOpener\Services\{ExposureService,SyncService,SnapshotCollector};
Queue::fake();
$server = Server::where('name','PortOpener Synthetic Server')->firstOrFail();
$owner = User::findOrFail($server->owner_id);
$other = User::where('username','dev-other')->firstOrFail();
$admin = User::where('username','dev-admin')->firstOrFail();
config(['port-opener.node_id'=>$server->node_id,'port-opener.installation_id'=>'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa',
    'port-opener.endpoint'=>'http://port-opener-service:8787','port-opener.token'=>'synthetic-test-token','port-opener.enabled'=>true]);
$checks=0;
$check=function ($condition,$name) use (&$checks) { if (!$condition) throw new RuntimeException('FAIL: '.$name); $checks++; };
$expect=function (callable $fn,string $type,string $name) use ($check) {
    try {$fn();} catch (Throwable $e) { if ($e instanceof $type) {$check(true,$name);return;} throw $e; }
    $check(false,$name);
};
DB::beginTransaction();
try {
    Exposure::updateOrCreate(['server_id'=>$server->id],['enabled'=>false]);
    SyncState::where('installation_id',config('port-opener.installation_id'))->delete();
    $exposure = app(ExposureService::class); $sync = app(SyncService::class);
    $policy=NodePolicy::where('node_id',$server->node_id)->firstOrFail();
    $policy->update(['forwarding_type'=>'upnp','router_url'=>'http://192.168.178.1:49000/igddesc.xml']);
    $otherPolicy=NodePolicy::updateOrCreate(['node_id'=>$server->node_id+1000],['enabled'=>false,'internal_address'=>'192.168.10.50','min_port'=>54000,'max_port'=>54999,
        'forwarding_type'=>'upnp','router_url'=>'http://192.168.10.1:49000/igddesc.xml']);
    $check($policy->fresh()->router_url!==$otherPolicy->fresh()->router_url,'router settings isolated by node');
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake(fn ()=>Http::response(['backend'=>'fake','router_reachable'=>true,'message'=>'arbitrary response text'],200));
    $routerTest=$sync->testRouterForNode($server->node_id);
    $check($routerTest['backend']==='fake' && !str_contains($routerTest['message'],'arbitrary'),'router test sanitized simulation');
    $check(Http::recorded(fn ($request)=>$request->method()==='POST' && $request->data()['node_id']===$server->node_id
        && $request->data()['router_url']==='http://192.168.178.1:49000/igddesc.xml')->count()===1,'router test sends saved selected-node URL');
    $expect(fn ()=>$sync->testRouterForNode($otherPolicy->node_id),RuntimeException::class,'unconfigured node router test refused');
    $policy->update(['router_url'=>null]);
    $expect(fn ()=>$sync->testRouterForNode($server->node_id),RuntimeException::class,'missing saved router URL refused');
    $check(count($exposure->preview($server))===3,'primary TCP UDP and variable UDP');
    $expect(fn ()=>$exposure->setEnabled($server,$other,true),\Illuminate\Auth\Access\AuthorizationException::class,'second user forbidden');
    $check(!Exposure::where('server_id',$server->id)->value('enabled'),'denied mutation leaves exposure disabled');
    \App\Models\Subuser::firstOrCreate(['server_id'=>$server->id,'user_id'=>$other->id],['permissions'=>['control.console','control.start']]);
    $expect(fn ()=>$exposure->setEnabled($server,$other,true),\Illuminate\Auth\Access\AuthorizationException::class,'subuser mutation forbidden');
    $exposure->setEnabled($server,$admin,true);
    $check((bool)Exposure::where('server_id',$server->id)->value('enabled'),'administrator enable');
    $exposure->setEnabled($server,$admin,false);
    $check(!Exposure::where('server_id',$server->id)->value('enabled'),'admin disable');
    $exposure->setEnabled($server,$admin,true);
    $generation=0; $accepted=[]; $puts=0; $configurations=[];
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake(function ($request) use (&$generation,&$accepted,&$puts,&$configurations) {
        if (str_ends_with($request->url(),'/v1/router/configuration')) {
            $configurations[]=$request->data(); return Http::response(['configured'=>true],200);
        }
        if ($request->method()==='PUT') {
            $body=$request->data(); $generation=$body['generation']; $accepted=$body['mappings']; $puts++;
            return Http::response(['generation'=>$generation],200);
        }
        return Http::response(['schema_version'=>1,'generation'=>$generation,'dry_run'=>true,'router_reachable'=>true,'backend'=>'fake',
            'mappings'=>array_map(fn ($row)=>array_merge($row,['state'=>'mapped','error_code'=>null,'message'=>'Fake mapping']),$accepted)],200);
    });
    $policy->update(['router_url'=>'http://192.168.178.1:49000/igddesc.xml']);
    $first=$sync->sync(); $check($generation===1 && count($accepted)===3,'complete initial snapshot');
    $check(count($configurations)===1 && $configurations[0]['node_id']===$server->node_id
        && $configurations[0]['router_url']!==$otherPolicy->router_url,'sync applies only configured node router');
    $sync->sync(); $check($generation===1,'unchanged snapshot generation stable');
    $server->forceFill(['status'=>'suspended'])->saveQuietly();
    $sync->sync(); $check($generation===2 && !$accepted,'suspended cleanup empty');
    $server->forceFill(['status'=>null])->saveQuietly();
    $sync->sync(); $check($generation===3 && count($accepted)===3,'restore new generation');
    $before=$puts; $sync->sync(true); $check($puts===$before,'panel dry run never sends');
    // A collection failure must never submit an empty replacement.
    $failing=new class(app(ExposureService::class)) extends SnapshotCollector {
        public function collect(int $nodeId): array { throw new RuntimeException('synthetic DB failure'); }
    };
    $broken=new SyncService($failing,app(\PortOpener\Services\PortOpenerServiceClient::class));
    $expect(fn ()=>$broken->sync(),RuntimeException::class,'failed collection aborts');
    $check($puts===$before,'failed collection leaves service untouched');
    $configurationCount=count($configurations);
    $sync->sync(false,true); $check(!$accepted && !Exposure::where('enabled',true)->exists(),'cleanup disables and removes');
    $check(count($configurations)===$configurationCount,'cleanup retains old router configuration');
    $exposure->setEnabled($server,$admin,true);
    \PortOpener\Models\AllocationProtocol::where('server_id',$server->id)->update(['protocols'=>'[]']);
    $expect(fn ()=>$exposure->setEnabled($server,$admin,true),\Illuminate\Validation\ValidationException::class,'invalid metadata prevents enable');
    $blocked=app(SnapshotCollector::class)->collect($server->node_id);
    $check(!$blocked['mappings'] && count($blocked['blocked'])===1,'invalid existing metadata fails closed');
    // Transport exceptions must not leak response content or bearer material.
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake(fn ()=>Http::response(['message'=>'secret response text'],503));
    try { app(\PortOpener\Services\PortOpenerServiceClient::class)->request('GET','/v1/status'); }
    catch (RuntimeException $e) { $check(!str_contains($e->getMessage(),'secret') && str_contains($e->getMessage(),'503'),'transport error sanitized'); }
    echo "$checks backend checks passed\n";
} finally { DB::rollBack(); }
