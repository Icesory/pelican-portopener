<?php
namespace PortOpener\Services;
use App\Models\{Allocation,Node,Server};
use PortOpener\Models\{EggRule,Exposure,NodePolicy,SyncState};
class StatusOverviewService {
    public function overview(): array {
        $configured=(int)config('port-opener.node_id');
        $sync=SyncState::where('installation_id',config('port-opener.installation_id'))->first();
        $status=$sync?->last_status ?? [];
        $fresh=$sync?->synced_at && $sync->synced_at->gt(now()->subSeconds(180));
        $policies=NodePolicy::all()->keyBy('node_id');
        $servers=Server::with('allocations','node')->get()->keyBy('uuid');
        $nodes=Node::all()->keyBy('id');
        $exposures=Exposure::all()->keyBy('server_id');
        $observed=collect($status['mappings'] ?? [])->map(fn($row)=>array_merge($row,['_node_id'=>$configured]))->keyBy(fn($row)=>$configured.':'.$row['mapping_id']);
        foreach($policies as $policy)if($policy->connection_mode==='outbound')foreach($policy->last_report['mappings'] ?? [] as $row)$observed->put($policy->node_id.':'.$row['mapping_id'],array_merge($row,['_node_id'=>(int)$policy->node_id]));
        $ports=[]; $seen=[];
        foreach ($servers as $server) {
            $policy=$policies->get($server->node_id);
            $metadataProblem=null;
            foreach ($server->allocations as $allocation) {
                $protocols=array_fill_keys(app(AllocationProtocols::class)->forAllocation($allocation),true);
                foreach ($protocols ? array_keys($protocols) : ['unknown'] as $protocol) {
                    $id=$protocol==='unknown' ? null : $server->uuid.':'.$allocation->id.':'.strtolower($protocol);
                    $enabled=(bool)$exposures->get($server->id)?->enabled;
                    $message=null; $state=$protocol==='unknown' ? 'unknown' : ($enabled ? 'pending' : 'disabled');
                    if ($protocol==='unknown') $message='Not managed. An administrator can choose a protocol in the server PortOpener tab.';
                    elseif ($enabled) {
                        if ($metadataProblem) $message=$metadataProblem;
                        elseif (($server->node_id!==$configured && $policy?->connection_mode!=='outbound') || !$policy?->enabled) $message='Node is not enabled for the configured service.';
                        elseif ($server->status!==null || $server->node->maintenance_mode || $server->transfer()->exists()) $message='Server is unavailable, suspended or transferring.';
                        elseif ($allocation->port<$policy->min_port || $allocation->port>$policy->max_port) $message='Allocation is outside the approved range.';
                        if ($message) $state='blocked';
                    }
                    $observedKey=$server->node_id.':'.$id;
                    $old=$id ? $observed->get($observedKey) : null;if($old && (int)$old['_node_id']!==(int)$server->node_id)$old=null;
                    $rowFresh=$policy?->connection_mode==='outbound' ? ($policy->last_contact_at && $policy->last_contact_at->gt(now()->subSeconds(180))) : $fresh;
                    if ($old && (int)($old['port'] ?? 0)===(int)$allocation->port && ($old['protocol'] ?? null)===$protocol) {
                        $seen[$observedKey]=true;
                        // Actual cached state remains visible while removal is pending after disable.
                        $state=$old['state'] ?? $state;
                        if (is_string($old['message'] ?? null) && $old['message']!=='') $message=trim(($message ? $message.' ' : '').$old['message']);
                        if (!$rowFresh) $message='Cached mapping observation is stale; router state needs refresh.';
                        elseif (!$enabled && $state!=='removed') $message='Exposure disabled; cached mapping awaits removal.';
                    }
                    $ports[]=['node_id'=>$server->node_id,'node_name'=>$server->node->name,'server_id'=>$server->id,'server_uuid'=>$server->uuid,'server_name'=>$server->name,
                        'allocation_id'=>$allocation->id,'allocation_ip'=>$allocation->ip,'port'=>$allocation->port,'protocol'=>$protocol,'exposure'=>$enabled,'state'=>$state,'message'=>$message,'mapping_id'=>$id,'orphan'=>false,'error_code'=>$old['error_code'] ?? null];
                }
            }
        }
        foreach ($observed as $observedKey=>$row) {
            if (isset($seen[$observedKey])) continue;
            $id=$row['mapping_id'];
            $uuid=explode(':',(string)$id)[0]; $server=$servers->get($uuid);
            $allocationId=(int)(explode(':',(string)$id)[1] ?? 0);
            $stillAssigned=$server && $server->allocations->contains(fn($a)=>$a->id===$allocationId && $a->port===(int)($row['port'] ?? 0));
            $sourceNode=(int)($row['_node_id'] ?? $configured);
            $state=!$server ? 'deleted' : ($server->node_id!==$sourceNode ? 'moved' : ($stillAssigned ? 'metadata_changed' : 'reassigned'));
            $ports[]=['node_id'=>$sourceNode,'node_name'=>$nodes->get($sourceNode)?->name ?? 'Deleted node','server_id'=>$server?->id,'server_uuid'=>$uuid,
                'server_name'=>$server?->name ?? 'Deleted server','allocation_id'=>null,'allocation_ip'=>null,'port'=>$row['port'] ?? null,'protocol'=>$row['protocol'] ?? 'unknown',
                'exposure'=>false,'state'=>$state,'message'=>'Previous service result no longer matches a current allocation; cached router state: '.($row['state'] ?? 'unknown').(isset($row['message']) ? '. '.$row['message'] : ''), 'mapping_id'=>$id,'orphan'=>true,'error_code'=>$row['error_code'] ?? null];
        }
        foreach($policies as $policy)foreach($policy->last_report['ipv6']['mappings'] ?? [] as $row){
            $uuid=explode(':',$row['mapping_id'])[0];$server=$servers->get($uuid);
            $allocationId=(int)(explode(':',$row['mapping_id'])[1] ?? 0);
            $assigned=$server && (int)$server->node_id===(int)$policy->node_id && $server->allocations->contains(fn($allocation)=>(int)$allocation->id===$allocationId && (int)$allocation->port===(int)$row['port']);
            $ports[]=['address_family'=>'IPv6','node_id'=>$policy->node_id,'node_name'=>$nodes->get($policy->node_id)?->name ?? 'Deleted node',
                'server_id'=>$server?->id,'server_uuid'=>$uuid,'server_name'=>$server?->name ?? 'Deleted server','allocation_id'=>$allocationId,
                'allocation_ip'=>$row['address'],'port'=>$row['port'],'protocol'=>$row['protocol'],'exposure'=>(bool)$exposures->get($server?->id)?->ipv6_enabled,
                'state'=>$row['state'],'message'=>$row['error_code'] ?? ($row['state']==='removing'?'IPv6 renewal stopped; finite lease is expiring.':'IPv6 firewall rule reported; external connectivity is untested.'),
                'mapping_id'=>$row['mapping_id'],'orphan'=>!$assigned,'error_code'=>$row['error_code'] ?? null];
        }
        $nodeRows=[];
        foreach ($nodes as $node) {
            $policy=$policies->get($node->id); $outbound=$policy?->connection_mode==='outbound';$local=$node->id===$configured || $outbound;
            $status=$outbound ? ($policy->last_report ?? []) : ($sync?->last_status ?? []);
            $synced=$outbound ? $policy->last_contact_at : $sync?->synced_at;
            $fresh=$synced && $synced->gt(now()->subSeconds(180));$error=$outbound ? $policy->service_error : $sync?->last_error;
            $simulation=$local && ($status['backend'] ?? '')==='fake';
            $configurationPending=$local && $policy?->router_url && ($status['router_url'] ?? null)!==$policy->router_url;
            $lastTest=$policy?->last_router_test;
            $testCurrent=$lastTest && ($lastTest['router_url'] ?? null)===$policy?->router_url && ($lastTest['forwarding_type'] ?? null)===$policy?->forwarding_type;
            if ($lastTest && !$testCurrent) $lastTest=['backend'=>'unknown','router_reachable'=>false,'message'=>'Not checked for the current router settings.'];
            $testFresh=$testCurrent && $policy?->router_tested_at && $policy->router_tested_at->gt(now()->subSeconds(180));
            $mappingCount=$local ? $observed->where('_node_id',$node->id)->where('state','mapped')->count() : 0;
            $testedWorking=$testFresh && ($lastTest['backend'] ?? '')==='upnp' && ($lastTest['router_reachable'] ?? false);
            $reconcileFresh=$fresh;
            if($outbound)$reconcileFresh=isset($status['last_reconciled_at']) && \Illuminate\Support\Carbon::parse($status['last_reconciled_at'])->gt(now()->subSeconds(180));
            $observedWorking=$reconcileFresh && !$configurationPending && ($status['backend'] ?? '')==='upnp' && ($status['router_reachable'] ?? false) && ($mappingCount>0 || !empty($status['last_reconciled_at']));
            if ($testFresh && !($lastTest['router_reachable'] ?? false) && (!$synced || $policy->router_tested_at->gte($synced))) $observedWorking=false;
            $routerWorking=$local && !$simulation && ($testedWorking || $observedWorking);
            $checkState=!$local ? 'unconfigured' : ($simulation ? 'simulation' : ($routerWorking ? 'working' : ($testFresh && !($lastTest['router_reachable'] ?? false) ? 'failed' : (($mappingCount>0 || $testCurrent) && !$fresh && !$testFresh ? 'stale' : 'not_checked'))));
            $nodeRows[]=['node_id'=>$node->id,'node_name'=>$node->name,'forwarding_type'=>$policy?->forwarding_type ?? 'upnp','router_url'=>$policy?->router_url,
                'router_service'=>$status['router_service'] ?? null,
                'ipv6_state'=>$status['ipv6']['state'] ?? 'disabled','ipv6_address'=>$status['ipv6']['address'] ?? null,'ipv6_error'=>$status['ipv6']['error_code'] ?? null,
                'ipv6_mapping_count'=>collect($status['ipv6']['mappings'] ?? [])->where('state','mapped')->count(),
                'internal_address'=>$policy?->internal_address,'enabled'=>(bool)$policy?->enabled,'configured'=>$local,
                'service_connected'=>$local && (($fresh && ($outbound || !$error)) || ($testFresh && ($lastTest['backend'] ?? 'unknown')!=='unknown')),'router_reachable'=>(bool)$routerWorking,'router_check_state'=>$checkState,
                'last_sync'=>$local ? $synced?->toIso8601String() : null,
                'freshness'=>!$local ? 'unconfigured' : (!$synced ? 'never' : ($fresh ? 'fresh' : 'stale')),
                'error'=>$local && $error ? 'Last synchronization failed; check service connectivity and configuration.' : null,
                'mapping_count'=>$mappingCount,'simulation'=>$simulation,
                'last_test'=>$lastTest,'last_tested_at'=>$policy?->router_tested_at?->toIso8601String(), 'configuration_pending'=>(bool)$configurationPending,'config_pending'=>(bool)$configurationPending,'test_current'=>(bool)$testCurrent,'last_test_fresh'=>(bool)$testFresh,
                'name'=>$node->name,'backend'=>$local ? ($status['backend'] ?? 'unknown') : 'unconfigured',
                'checked_at'=>$policy?->router_tested_at?->toIso8601String(), 'synced_at'=>$local ? $synced?->toIso8601String() : null,
                'connection_mode'=>$policy?->connection_mode ?? 'legacy_local','credential_expires_at'=>$policy?->credential_expires_at,
                'last_error'=>$local && $error ? 'Last synchronization failed; check service connectivity and configuration.' : null];
        }
        foreach ($ports as &$row) {
            $nodeSummary=collect($nodeRows)->firstWhere('node_id',$row['node_id']);
            $row['simulation']=(bool)($nodeSummary['simulation'] ?? false);
            $row['freshness']=$nodeSummary['freshness'] ?? 'never';
            $row['exposure_enabled']=$row['exposure'];
            $exposure=$row['server_id'] ? $exposures->get($row['server_id']) : null;
            $row['mode']=$exposure?->mode ?? 'always';$row['runtime_state']=$exposure?->last_runtime_state;
            $row['runtime_checked_at']=$exposure?->last_runtime_checked_at?->toIso8601String();$row['offline_since']=$exposure?->offline_since?->toIso8601String();
            $row['runtime_policy_state']='always';
            if($row['mode']==='running_only') {
                $decision=app(RuntimePolicy::class)->evaluate($exposure?->last_runtime_state ?? 'unknown',$exposure?->last_confirmed_runtime_state,$exposure?->offline_since,now());
                $row['runtime_policy_state']=$decision['policy_state'];
                $note=match($decision['policy_state']){'unknown'=>'Runtime unknown; previous mappings retained.','waiting'=>'Running-only policy is waiting for the server.','grace'=>'Runtime transition grace period (120 seconds).',default=>null};
                if($note)$row['message']=trim(($row['message'] ?? '').' '.$note);
            }
        }
        unset($row);
        return ['nodes'=>$nodeRows,'ports'=>$ports];
    }
}
