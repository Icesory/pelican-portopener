<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config('app.name')!=='PortOpener Development'||!str_contains((string)config('database.connections.sqlite.database'),'/pelican-data/'))throw new RuntimeException('Development panel only.');
use App\Models\{Server,User};use PortOpener\Models\{Exposure,SyncState};use PortOpener\Services\{RuntimeStateProvider,ExposureService,SnapshotCollector,SyncService};use Illuminate\Support\Facades\{DB,Http,Queue};
$server=Server::where('name','PortOpener Synthetic Server')->firstOrFail();config(['port-opener.node_id'=>$server->node_id,'port-opener.installation_id'=>'dddddddd-dddd-4ddd-dddd-dddddddddddd','port-opener.enabled'=>true]);Queue::fake();
$fake=new class extends RuntimeStateProvider{public string $value='running';public int $calls=0;public function state(Server $server):string{$this->calls++;return $this->value;}};
app()->instance(RuntimeStateProvider::class,$fake);$checks=0;$check=function($ok,$name)use(&$checks){if(!$ok)throw new RuntimeException('FAIL: '.$name);$checks++;};
DB::beginTransaction();try{
 $exposure=Exposure::updateOrCreate(['server_id'=>$server->id],['enabled'=>true,'mode'=>'always','offline_since'=>null,'last_runtime_state'=>null]);
 $collector=app(SnapshotCollector::class);$rows=$collector->collect($server->node_id);$check($fake->calls===0,'always mode never polls Wings');
 $service=app(ExposureService::class);$other=User::where('username','dev-other')->firstOrFail();
 try{$service->setMode($server,$other,'running_only');throw new RuntimeException('FAIL permission');}catch(\Illuminate\Auth\Access\AuthorizationException $e){$check($exposure->fresh()->mode==='always','unauthorized mode unchanged');}
 $service->setMode($server,User::all()->first(fn($u)=>$u->isRootAdmin()),'running_only');
 $rows=$collector->collect($server->node_id);$check(count($rows['mappings'])===3 && $exposure->fresh()->last_runtime_state==='running','running includes allocations');
 $fake->value='offline';$rows=$collector->collect($server->node_id);$check(count($rows['mappings'])===3 && $exposure->fresh()->offline_since!==null,'confirmed stop enters grace');
 $exposure->fresh()->forceFill(['offline_since'=>now()->subSeconds(121)])->saveQuietly();
 $rows=$collector->collect($server->node_id);$check(!$rows['mappings'],'expired grace removes desired mappings');
 $fake->value='running';$rows=$collector->collect($server->node_id);$check(count($rows['mappings'])===3 && $exposure->fresh()->offline_since===null,'restart reopens and resets grace');
 $fake->value='stopping';$rows=$collector->collect($server->node_id);$check(count($rows['mappings'])===3,'stopping does not drop immediately');
 $service->setMode($server,User::all()->first(fn($u)=>$u->isRootAdmin()),'running_only');$fake->value='offline';$rows=$collector->collect($server->node_id);
 $check(!$rows['mappings'] && $exposure->fresh()->offline_since===null,'initial offline never opens grace');
 $fake->value='starting';$rows=$collector->collect($server->node_id);$check(!$rows['mappings'],'initial starting waits for confirmed running');
 $fake->value='running';$collector->collect($server->node_id);$fake->value='starting';$rows=$collector->collect($server->node_id);$check(count($rows['mappings'])===3,'running restart receives bounded grace');
 $exposure->fresh()->forceFill(['offline_since'=>now()->subSeconds(121)])->saveQuietly();$rows=$collector->collect($server->node_id);$check(!$rows['mappings'],'stuck starting grace expires');
 $fake->value='unknown';Http::swap(new \Illuminate\Http\Client\Factory());Http::fake(fn()=>throw new RuntimeException('HTTP must not occur on unknown runtime'));
 try{app(SyncService::class)->sync();throw new RuntimeException('FAIL unknown did not abort');}catch(RuntimeException $e){$check(str_contains($e->getMessage(),'runtime state could not be confirmed'),'unknown aborts synchronization');}
 $check($exposure->fresh()->last_runtime_state==='unknown' && SyncState::where('installation_id',config('port-opener.installation_id'))->value('generation')===0,'unknown persisted without generation advance');
 $check(str_contains((string)SyncState::where('installation_id',config('port-opener.installation_id'))->value('last_error'),'runtime state could not be confirmed'),'unknown error persists after collection transaction');
 $check(Http::recorded()->count()===0,'unknown never sends replacement state');
 $fake->value='starting';$rows=$collector->collect($server->node_id);$check(!$rows['mappings'],'unknown then starting cannot reset expired grace');
 $fake->value='running';$collector->collect($server->node_id);$fake->value='unknown';$collector->collect($server->node_id);
 $fake->value='starting';$rows=$collector->collect($server->node_id);$check(count($rows['mappings'])===3,'unknown preserves confirmed running evidence for restart grace');
 echo "$checks runtime lifecycle checks passed\n";
}finally{DB::rollBack();}
