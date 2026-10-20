<?php
namespace PortOpener\Services;
use App\Models\Server;
use Illuminate\Validation\ValidationException;
use PortOpener\Models\Exposure;
use RuntimeException;
class SnapshotCollector {
    public function __construct(private ExposureService $exposure) {}
    public function collect(int $nodeId): array {
        $mappings = []; $blocked = []; $tuples = []; $runtimeUnknown=[]; $authorized=[];
        foreach (Server::where('node_id',$nodeId)->whereIn('id',Exposure::where('enabled',true)->select('server_id'))->orderBy('id')->get() as $server) {
            try { $rows = $this->exposure->preview($server); }
            catch (ValidationException $e) { $blocked[] = ['server_uuid'=>$server->uuid, 'state'=>'blocked', 'error_code'=>'invalid_metadata', 'message'=>implode(' ', $e->errors()['port_opener'] ?? ['Invalid port metadata.'])]; continue; }
            $policy=Exposure::where('server_id',$server->id)->firstOrFail();
            if($policy->mode==='running_only') {
                $runtime=app(RuntimeStateProvider::class)->state($server);
                $decision=app(RuntimePolicy::class)->evaluate($runtime,$policy->last_confirmed_runtime_state ?? $policy->last_runtime_state,$policy->offline_since,now());
                $policy->forceFill(['last_runtime_state'=>$runtime,'last_confirmed_runtime_state'=>$runtime==='unknown' ? $policy->last_confirmed_runtime_state : $runtime,'last_runtime_checked_at'=>now(),'offline_since'=>$decision['offline_since']])->saveQuietly();
                if($decision['include']===null){$runtimeUnknown[]=$server->uuid;array_push($authorized,...$rows);continue;}
                if(!$decision['include']) {$blocked[]=['server_uuid'=>$server->uuid,'state'=>'waiting','error_code'=>'runtime_idle','message'=>'Running-only exposure waits for confirmed running.'];continue;}
            }
            array_push($authorized,...$rows);
            foreach ($rows as $row) {
                $tuple = $row['protocol'].':'.$row['port'];
                if (isset($tuples[$tuple])) throw new RuntimeException('Multiple servers request the same external port and protocol; snapshot not submitted.');
                $tuples[$tuple] = true; $mappings[] = $row;
            }
        }
        usort($mappings, fn ($a,$b) => strcmp($a['mapping_id'],$b['mapping_id']));
        return ['mappings'=>$mappings,'blocked'=>$blocked,'runtime_unknown'=>$runtimeUnknown,'authorized'=>$authorized];
    }
}
