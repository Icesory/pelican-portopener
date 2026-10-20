<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config('app.name')!=='PortOpener Development'||!str_contains((string)config('database.connections.sqlite.database'),'/pelican-data/'))throw new RuntimeException('Development panel only.');
use PortOpener\Models\{NodePolicy,NodeCredential,Pairing};use PortOpener\Services\ServicePairing;use PortOpener\Http\Middleware\{RequireServiceHttps,AuthenticateNodeService};use Illuminate\Support\Facades\DB;use Illuminate\Http\Request;use App\Models\User;
$checks=0;$check=function($ok,$name)use(&$checks){if(!$ok)throw new RuntimeException('FAIL: '.$name);$checks++;};
$reject=function(callable $fn,int $code,string $name)use($check){try{$fn();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===$code,$name);return;}throw new RuntimeException('FAIL: '.$name);};
DB::beginTransaction();try {
auth()->setUser(User::where('username','dev-admin')->firstOrFail());
$policy=NodePolicy::firstOrFail();$policy->forceFill(['connection_mode'=>'outbound','service_enabled'=>true,'service_token'=>'synthetic legacy token'])->save();
$check(!array_key_exists('service_token',$policy->toArray()) && !str_contains($policy->getRawOriginal('service_token'),'synthetic'),'legacy token encrypted and hidden');
$service=app(ServicePairing::class);$pair=$service->generate($policy->node_id);$credential=$service->exchange($pair['pairing_code']);
$check(strlen($credential['credential'])===64 && $credential['node_id']===$policy->node_id,'pairing binds strong credential to node');
$check(!NodeCredential::where('token_hash',$credential['credential'])->exists(),'only credential hash stored');
$fixture=\App\Models\Server::where('name','PortOpener Synthetic Server')->firstOrFail();
if($fixture->node_id===$policy->node_id){
\PortOpener\Models\Exposure::updateOrCreate(['server_id'=>$fixture->id],['enabled'=>true,'mode'=>'always']);$policy->forceFill(['enabled'=>true])->saveQuietly();
$publisher=app(\PortOpener\Services\OutboundPublisher::class);$first=$publisher->publish($policy);
\PortOpener\Models\Exposure::where('server_id',$fixture->id)->update(['mode'=>'running_only']);
app()->instance(\PortOpener\Services\RuntimeStateProvider::class,new class extends \PortOpener\Services\RuntimeStateProvider{public function state(\App\Models\Server $server):string{return 'unknown';}});
$held=$publisher->publish($policy);$check($held===$first && $policy->fresh()->desired_generation===$first['generation'],'outbound unknown retains full snapshot and generation');
$check(str_contains((string)$policy->fresh()->service_error,'Runtime state unknown'),'outbound unknown state error persists');
}
$reject(fn()=>$service->exchange($pair['pairing_code']),401,'pairing code cannot replay');
$expired=$service->generate($policy->node_id);Pairing::where('code_hash',hash('sha256',$expired['pairing_code']))->update(['expires_at'=>now()->subSecond()]);$reject(fn()=>$service->exchange($expired['pairing_code']),401,'expired code rejected');
$request=Request::create('https://panel.invalid/api/port-opener/v1/desired-state');$request->headers->set('Authorization','Bearer '.$credential['credential']);
$middleware=new AuthenticateNodeService;$middleware->handle($request,fn($r)=>true);$check($request->attributes->get('port_opener_policy')->node_id===$policy->node_id,'bearer resolves only assigned node');
$old=$request->attributes->get('port_opener_credential');$renewed=$service->renew($old);$old->refresh();$check($old->renewed_at!==null && $old->expires_at->lte(now()->addMinutes(5)),'renewal bounds overlap to five minutes');
$reject(fn()=>$service->renew($old),409,'renewed token cannot repeatedly renew');
$middleware->handle($request,fn($r)=>true);$check(true,'old token accepted during bounded overlap');
$old->forceFill(['expires_at'=>now()->subSecond()])->save();$reject(fn()=>$middleware->handle($request,fn($r)=>true),401,'old token rejected after overlap');
$service->revoke($policy->node_id);$newRequest=Request::create('https://panel.invalid/api/port-opener/v1/report');$newRequest->headers->set('Authorization','Bearer '.$renewed['credential']);$reject(fn()=>$middleware->handle($newRequest,fn($r)=>true),401,'revocation rejects rotated credentials');
$reject(fn()=>(new RequireServiceHttps)->handle(Request::create('http://panel.invalid/api/port-opener/v1/pair'),fn()=>true),400,'insecure HTTP rejected');
auth()->setUser(User::where('username','dev-other')->firstOrFail());try{$service->generate($policy->node_id);throw new RuntimeException('FAIL root guard');}catch(\Illuminate\Auth\Access\AuthorizationException $e){$check(true,'nonadmin cannot generate pairing');}
echo "$checks outbound security checks passed\n";
}finally{DB::rollBack();}
