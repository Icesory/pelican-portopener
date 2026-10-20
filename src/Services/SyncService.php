<?php
namespace PortOpener\Services;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PortOpener\Jobs\SyncPortMappings;
use PortOpener\Models\SyncState;
use PortOpener\Models\Exposure;
use RuntimeException;
class SyncService {
    public function __construct(private SnapshotCollector $collector, private PortOpenerServiceClient $service) {}
    public static function queue(): void { SyncPortMappings::dispatch()->delay(now()->addSeconds(2)); }
    public function testRouterForNode(int $nodeId): array {
        $outbound=\PortOpener\Models\NodePolicy::where('node_id',$nodeId)->first();
        if($outbound?->connection_mode==='outbound'){
            if(!$outbound->service_enabled || !RouterConfiguration::validateUrl((string)$outbound->router_url))throw new RuntimeException('Enable the node service and save a valid router URL before testing.');
            $outbound->forceFill(['test_request_id'=>(string)Str::uuid(),'test_request_configuration'=>['router_url'=>$outbound->router_url,'forwarding_type'=>$outbound->forwarding_type]])->saveQuietly();return ['backend'=>'outbound','queued'=>true,'router_reachable'=>false,'message'=>'Router test queued; wait for the service report.'];
        }
        $payload=RouterConfiguration::payload($nodeId,true);
        $policy=\PortOpener\Models\NodePolicy::where('node_id',$nodeId)->firstOrFail();
        try { $response=$this->service->request('POST','/v1/router/test',$payload); }
        catch (RuntimeException $e) {
            $policy->forceFill(['last_router_test'=>['backend'=>'unknown','router_reachable'=>false,'message'=>'Router test failed. Check service connectivity and saved router settings.','router_url'=>$payload['router_url'],'forwarding_type'=>$payload['forwarding_type']],'router_tested_at'=>now()])->saveQuietly();
            throw $e;
        }
        $reachable=($response['router_reachable'] ?? false)===true;
        // Do not echo arbitrary router/service diagnostics into browser output or persist them.
        $backend=($response['backend'] ?? '')==='fake' ? 'fake' : 'upnp';
        $result=['backend'=>$backend,'router_reachable'=>$reachable,
            'message'=>$backend==='fake' ? 'Simulation completed; no router was contacted.' : ($reachable ? 'UPnP router discovered successfully.' : 'UPnP router discovery failed.'),
            'service_type'=>in_array($response['service_type'] ?? '',['WANIPConnection:1','WANIPConnection:2','WANPPPConnection:1'],true) ? $response['service_type'] : null];
        $policy->forceFill(['last_router_test'=>array_merge($result,['router_url'=>$payload['router_url'],'forwarding_type'=>$payload['forwarding_type']]),'router_tested_at'=>now()])->saveQuietly();
        return $result;
    }
    public function status(): array {
        $s = SyncState::where('installation_id',config('port-opener.installation_id'))->first();
        return array_merge($s?->last_status ?? ['schema_version'=>1,'mappings'=>[],'router_reachable'=>false], ['last_error'=>$s?->last_error,'synced_at'=>$s?->synced_at?->toIso8601String()]);
    }
    public function sync(bool $dryRun = false, bool $cleanup = false): array {
        $outboundPolicies=\PortOpener\Models\NodePolicy::where('connection_mode','outbound')->get();
        if($cleanup)Exposure::query()->update(['enabled'=>false]);
        if(!$dryRun)foreach($outboundPolicies as $policy)app(OutboundPublisher::class)->publish($policy);
        $installation = (string)config('port-opener.installation_id');
        $node = (int)config('port-opener.node_id');
        if(($node<1 && $outboundPolicies->isNotEmpty()) || \PortOpener\Models\NodePolicy::where('node_id',$node)->value('connection_mode')==='outbound')return ['outbound'=>true,'dry_run'=>$dryRun,'message'=>$cleanup?'Empty snapshots published; await confirmed service cleanup.':'Desired snapshots published for outbound services.'];
        if (!Str::isUuid($installation) || $node < 1) throw new RuntimeException('Configure installation UUID and node ID before synchronizing.');
        return Cache::lock('port-opener:sync:'.$installation,600)->block(5, function () use ($installation,$node,$dryRun,$cleanup) {
            // Collect everything before touching generation or PortOpenerService. Unexpected DB failures abort.
            if ($cleanup) Exposure::query()->update(['enabled'=>false]);
            $legacyPolicy=\PortOpener\Models\NodePolicy::where('node_id',$node)->first();
            $snapshot = ($cleanup || ($legacyPolicy && !$legacyPolicy->service_enabled)) ? ['mappings'=>[],'blocked'=>[]] : DB::transaction(fn () => $this->collector->collect($node));
            $state = SyncState::firstOrCreate(['installation_id'=>$installation]);
            if(!empty($snapshot['runtime_unknown'])) {
                $message='Wings runtime state could not be confirmed; previous port mappings retained.';
                $previous=collect($state->last_status['mappings'] ?? [])->filter(fn($row)=>($row['state'] ?? '')!=='removed');
                $allowed=collect($snapshot['authorized'] ?? [])->keyBy('mapping_id');
                $retained=$previous->map(function($row)use($allowed){$current=$allowed->get($row['mapping_id']);return $current && $current['port']===(int)$row['port'] && $current['protocol']===$row['protocol'] ? $current : null;})->filter()->values()->all();
                if(count($retained)===$previous->count()){$state->update(['last_error'=>$message]);throw new RuntimeException($message);}
                $snapshot['mappings']=$retained;
            }
            $hash = hash('sha256',json_encode(['node_id'=>$node,'mappings'=>$snapshot['mappings']], JSON_THROW_ON_ERROR));
            $generation = $state->generation + ($hash !== $state->desired_hash ? 1 : 0);
            $body = ['schema_version'=>1,'installation_id'=>$installation,'generation'=>max(1,$generation),'node_id'=>$node,'mappings'=>$snapshot['mappings']];
            if ($dryRun || (!config('port-opener.enabled') && !$cleanup)) return ['dry_run'=>true,'desired_state'=>$body,'blocked'=>$snapshot['blocked']];
            // Persist generation/hash before sending: lost acknowledgements safely retry identical snapshots.
            $state->update(['generation'=>$body['generation'],'desired_hash'=>$hash]);
            try {
                // Cleanup must use the currently active router, even if the saved candidate changed.
                $routerConfiguration=$cleanup ? null : RouterConfiguration::payload($node);
                if ($routerConfiguration) $this->service->request('PUT','/v1/router/configuration',$routerConfiguration);
                $this->service->request('PUT','/v1/desired-state',$body);
                $status = $this->service->request('GET','/v1/status');
                if (($status['schema_version'] ?? null) !== 1 || !is_array($status['mappings'] ?? null)) throw new RuntimeException('PortOpener PortOpenerService returned invalid status schema.');
                $status['blocked'] = $snapshot['blocked'];
                $state->update(['last_status'=>$status,'last_error'=>null,'synced_at'=>now()]);
                return $status;
            } catch (\Throwable $e) {
                $state->update(['last_error'=>$e->getMessage()]); throw $e;
            }
        });
    }
}
