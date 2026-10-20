<?php
namespace PortOpener\Services;
use PortOpener\Models\{NodePolicy,SyncState};use Illuminate\Support\Facades\DB;use Illuminate\Support\Str;
class OutboundPublisher {
public function publish(NodePolicy $policy):array{$result=DB::transaction(function()use($policy){
$policy=NodePolicy::whereKey($policy->id)->lockForUpdate()->firstOrFail();if(!$policy->installation_id)$policy->forceFill(['installation_id'=>(string)Str::uuid()])->saveQuietly();
$state=SyncState::firstOrCreate(['installation_id'=>$policy->installation_id]);
$snapshot=$policy->enabled && $policy->service_enabled ? app(SnapshotCollector::class)->collect((int)$policy->node_id) : ['mappings'=>[],'blocked'=>[]];
$unknown=!empty($snapshot['runtime_unknown']);
if($unknown){
    if(!$policy->desired_snapshot){$policy->forceFill(['service_error'=>'Runtime state unknown; no previous desired snapshot exists.'])->saveQuietly();return [];}
    $allowed=collect($snapshot['authorized'] ?? [])->keyBy('mapping_id');
    $snapshot['mappings']=collect($policy->desired_snapshot['mappings'] ?? [])->filter(function($row)use($allowed){$current=$allowed->get($row['mapping_id']);return $current && $current===$row;})->values()->all();
}
$hash=hash('sha256',json_encode(['node_id'=>(int)$policy->node_id,'mappings'=>$snapshot['mappings']],JSON_THROW_ON_ERROR));
$generation=max(1,(int)$state->generation+($state->desired_hash!==$hash?1:0));
$body=['schema_version'=>1,'installation_id'=>$policy->installation_id,'generation'=>$generation,'node_id'=>(int)$policy->node_id,'mappings'=>$snapshot['mappings']];
$policy->ipv6_mapping_ids=$this->ipv6Ids($policy,$body);$state->update(['generation'=>$generation,'desired_hash'=>$hash]);$policy->forceFill(['desired_snapshot'=>$body,'desired_generation'=>$generation,'snapshot_hash'=>$hash,'service_error'=>$unknown?'Runtime state unknown; only previously requested, still-approved ports retained.':null])->saveQuietly();return $body;
});if(!$result)abort(503,'Runtime state unavailable; no complete snapshot published.');return $result;}
public function ipv6Ids(NodePolicy $policy,array $body):array{
if(!$policy->ipv6_enabled)return [];
$ids=\PortOpener\Models\Exposure::where('ipv6_enabled',true)->where('enabled',true)->pluck('server_id');
$uuids=\App\Models\Server::where('node_id',$policy->node_id)->whereIn('id',$ids)->pluck('uuid')->all();
return collect($body['mappings'] ?? [])->filter(fn($row)=>in_array($row['server_uuid'],$uuids,true))->pluck('mapping_id')->values()->all();
}
public function configuration(NodePolicy $policy):array{
if($policy->lease_seconds<120 || $policy->lease_seconds>86400 || $policy->reconcile_seconds<15 || $policy->reconcile_seconds>300 || $policy->reconcile_seconds>$policy->lease_seconds/3)throw \Illuminate\Validation\ValidationException::withMessages(['service_settings'=>'Lease/reconciliation settings are outside the supported bounds.']);
return ['ipv6_enabled'=>(bool)$policy->ipv6_enabled,'ipv6_interface'=>$policy->ipv6_interface,'node_id'=>(int)$policy->node_id,'forwarding_type'=>$policy->forwarding_type,'router_url'=>$policy->router_url,'internal_address'=>$policy->internal_address,'min_port'=>$policy->min_port,'max_port'=>$policy->max_port,'dry_run'=>(bool)$policy->service_dry_run,'lease_seconds'=>(int)$policy->lease_seconds,'reconcile_seconds'=>(int)$policy->reconcile_seconds];}
}
