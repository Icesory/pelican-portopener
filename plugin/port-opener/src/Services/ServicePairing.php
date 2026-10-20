<?php
namespace PortOpener\Services;
use Illuminate\Support\Facades\DB;use Illuminate\Support\Str;use Illuminate\Auth\Access\AuthorizationException;use PortOpener\Models\{Pairing,NodePolicy,NodeCredential};
class ServicePairing {
public function generateForInstallation(\PortOpener\Models\Ipv4Installation $installation):array {
return DB::transaction(function()use($installation){
$current=\PortOpener\Models\Ipv4Installation::whereKey($installation->id)->lockForUpdate()->firstOrFail();
$server=\App\Models\Server::findOrFail($current->server_id);
if($current->operation_id!==$installation->operation_id || $current->status!=='pairing' || $server->node_id!=$current->node_id || $server->egg->uuid!==Ipv4Release::EGG_UUID || !\App\Models\User::find($server->owner_id)?->isRootAdmin())throw new \RuntimeException('Managed installation scope mismatch.');
$policy=NodePolicy::where('node_id',$current->node_id)->lockForUpdate()->firstOrFail();
if($policy->connection_mode!=='outbound' || !$policy->service_enabled || $policy->ipv6_enabled)throw new \RuntimeException('Managed IPv4 node is not enabled.');
if(!$policy->installation_id)$policy->forceFill(['installation_id'=>(string)Str::uuid()])->saveQuietly();
Pairing::where('node_id',$current->node_id)->whereNull('used_at')->update(['used_at'=>now()]);
$code=bin2hex(random_bytes(32));$expiry=now()->addMinutes(10);
Pairing::create(['node_id'=>$current->node_id,'code_hash'=>hash('sha256',$code),'expires_at'=>$expiry]);
return ['pairing_code'=>$code,'expires_at'=>$expiry->toIso8601String()];
});
}
private function administrator():void{if(!auth()->user()?->isRootAdmin())throw new AuthorizationException('Root administrator required.');}
public function generate(int $nodeId):array{$this->administrator();return DB::transaction(function()use($nodeId){$policy=NodePolicy::where('node_id',$nodeId)->lockForUpdate()->firstOrFail();if($policy->connection_mode!=='outbound')throw new \RuntimeException('Choose outbound connection mode before pairing.');if(!$policy->installation_id)$policy->forceFill(['installation_id'=>(string)Str::uuid()])->saveQuietly();Pairing::where('node_id',$nodeId)->whereNull('used_at')->update(['used_at'=>now()]);$code=bin2hex(random_bytes(32));$expiry=now()->addMinutes(10);Pairing::create(['node_id'=>$nodeId,'code_hash'=>hash('sha256',$code),'expires_at'=>$expiry]);return ['pairing_code'=>$code,'expires_at'=>$expiry->toIso8601String()];});}
public function revoke(int $nodeId):void{$this->administrator();DB::transaction(function()use($nodeId){NodeCredential::where('node_id',$nodeId)->whereNull('revoked_at')->update(['revoked_at'=>now()]);Pairing::where('node_id',$nodeId)->whereNull('used_at')->update(['used_at'=>now()]);});}
public function rotate(int $nodeId):array{$this->revoke($nodeId);return $this->generate($nodeId);}
public function exchange(string $code):array{return DB::transaction(function()use($code){$pair=Pairing::where('code_hash',hash('sha256',$code))->lockForUpdate()->first();abort_unless($pair && !$pair->used_at && $pair->expires_at->isFuture(),401,'Invalid or expired pairing code.');$policy=NodePolicy::where('node_id',$pair->node_id)->lockForUpdate()->firstOrFail();abort_unless($policy->connection_mode==='outbound' && $policy->service_enabled,403,'Node service is disabled.');$pair->forceFill(['used_at'=>now()])->save();return $this->issue($policy);});}
public function issue(NodePolicy $policy):array{$token=bin2hex(random_bytes(32));$expires=now()->addDays(30);NodeCredential::create(['node_id'=>$policy->node_id,'token_hash'=>hash('sha256',$token),'expires_at'=>$expires]);return ['credential'=>$token,'expires_at'=>$expires->toIso8601String(),'node_id'=>$policy->node_id,'installation_id'=>$policy->installation_id];}
public function renew(NodeCredential $credential):array{return DB::transaction(function()use($credential){$current=NodeCredential::whereKey($credential->id)->lockForUpdate()->firstOrFail();abort_unless(!$current->renewed_at && !$current->revoked_at && $current->expires_at->isFuture(),409,'Credential already renewed or unavailable.');$policy=NodePolicy::where('node_id',$current->node_id)->firstOrFail();$current->forceFill(['expires_at'=>now()->addMinutes(5),'renewed_at'=>now()])->save();return $this->issue($policy);});}
}
