<?php
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config('app.name')!=='PortOpener Development' || !str_contains((string)config('database.connections.sqlite.database'),'/pelican-data/')) throw new RuntimeException('Development panel only.');
use App\Models\{Server,Node};
use PortOpener\Models\{NodePolicy,SyncState,EggRule};
use PortOpener\Services\StatusOverviewService;
use Illuminate\Support\Facades\{DB,Http};
$server=Server::where('name','PortOpener Synthetic Server')->firstOrFail();
config(['port-opener.node_id'=>$server->node_id,'port-opener.installation_id'=>'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb']);
$checks=0;$check=function($ok,$name)use(&$checks){if(!$ok)throw new RuntimeException('FAIL: '.$name);$checks++;};
DB::beginTransaction();
try {
    $policy=NodePolicy::where('node_id',$server->node_id)->firstOrFail();
    $policy->forceFill(['router_url'=>'http://192.168.178.1/desc.xml','forwarding_type'=>'upnp',
        'last_router_test'=>['backend'=>'upnp','router_reachable'=>true,'router_url'=>'http://192.168.178.1/desc.xml','forwarding_type'=>'upnp'],'router_tested_at'=>now()])->saveQuietly();
    $allocation=$server->allocations->first();$id=$server->uuid.':'.$allocation->id.':tcp';
    $status=['backend'=>'upnp','router_reachable'=>true,'router_url'=>$policy->router_url,'mappings'=>[['mapping_id'=>$id,'port'=>$allocation->port,'protocol'=>'TCP','state'=>'mapped'],
        ['mapping_id'=>'cccccccc-cccc-4ccc-cccc-cccccccccccc:999:udp','port'=>54499,'protocol'=>'UDP','state'=>'mapped']]];
    $sync=SyncState::updateOrCreate(['installation_id'=>config('port-opener.installation_id')],['last_status'=>$status,'synced_at'=>now(),'last_error'=>null]);
    Http::swap(new \Illuminate\Http\Client\Factory());Http::fake(fn()=>throw new RuntimeException('Overview must not use HTTP.'));
    $service=app(StatusOverviewService::class);$overview=$service->overview();$node=collect($overview['nodes'])->firstWhere('node_id',$server->node_id);
    $check($node['router_reachable'] && $node['freshness']==='fresh','fresh real observation');
    $orphan=collect($overview['ports'])->firstWhere('mapping_id','cccccccc-cccc-4ccc-cccc-cccccccccccc:999:udp');
    $check($orphan['orphan'] && $orphan['server_id']===null && $orphan['state']==='deleted','deleted UUID never assigned to another server');
    $sync->forceFill(['synced_at'=>now()->subSeconds(181)])->saveQuietly();
    $policy->forceFill(['router_tested_at'=>now()->subSeconds(181)])->saveQuietly();
    $node=collect($service->overview()['nodes'])->firstWhere('node_id',$server->node_id);
    $check(!$node['router_reachable'] && $node['freshness']==='stale','stale observation cannot mean healthy');
    $status['backend']='fake';$sync->forceFill(['last_status'=>$status,'synced_at'=>now()])->saveQuietly();
    $node=collect($service->overview()['nodes'])->firstWhere('node_id',$server->node_id);
    $check($node['simulation'] && !$node['router_reachable'],'fake simulation cannot mean working UPnP');
    $policy->forceFill(['router_url'=>'http://192.168.178.2/desc.xml'])->saveQuietly();
    $node=collect($service->overview()['nodes'])->firstWhere('node_id',$server->node_id);
    $check($node['configuration_pending'] && !$node['last_test']['router_reachable'],'new URL invalidates previous router test');
    \PortOpener\Models\AllocationProtocol::where('server_id',$server->id)->delete();
    $ports=$service->overview()['ports'];
    $check(collect($ports)->contains(fn($p)=>$p['server_uuid']===$server->uuid && $p['protocol']==='unknown' && !$p['orphan']),'missing allocation protocol remains unmanaged');
    $old=collect($ports)->firstWhere('mapping_id',$id);
    $check($old['orphan'] && $old['state']==='metadata_changed','old mapping survives metadata removal in status');
    $status['backend']='upnp';$status['router_url']=$policy->router_url;$status['mappings']=[];
    $sync->forceFill(['last_status'=>$status,'synced_at'=>now()])->saveQuietly();
    $node=collect($service->overview()['nodes'])->firstWhere('node_id',$server->node_id);
    $check(!$node['router_reachable'] && $node['router_check_state']==='not_checked','empty snapshot cannot prove router was contacted');
    config(['port-opener.node_id'=>$server->node_id+999]);
    $node=collect($service->overview()['nodes'])->firstWhere('node_id',$server->node_id);
    $check(!$node['service_connected'] && !$node['router_reachable'] && $node['freshness']==='unconfigured','other node cannot inherit global health');
    echo "$checks status overview checks passed\n";
} finally { DB::rollBack(); }
