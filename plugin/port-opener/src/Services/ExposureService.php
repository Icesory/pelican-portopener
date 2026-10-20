<?php
namespace PortOpener\Services;
use App\Models\Server;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PortOpener\Models\{EggRule, Exposure, NodePolicy};
class ExposureService {
    public function __construct(private AllocationResolver $resolver) {}
    public function setIpv6Enabled(Server $server,User $user,bool $enabled): void {
        DB::transaction(function()use($server,$user,$enabled){
            $fresh=Server::whereKey($server->id)->lockForUpdate()->firstOrFail();
            $actor=User::findOrFail($user->id);
            if(!$actor->isRootAdmin())throw new AuthorizationException('Only administrators can change IPv6 exposure.');
            if($enabled){
                $node=NodePolicy::where('node_id',$fresh->node_id)->first();
                if(!$node?->ipv6_enabled || $node->connection_mode!=='outbound')throw ValidationException::withMessages(['port_opener'=>'An administrator must enable IPv6 on this outbound node first.']);
                $this->preview($fresh);
            }
            Exposure::updateOrCreate(['server_id'=>$fresh->id],['ipv6_enabled'=>$enabled]);
            Log::info('PortOpener IPv6 approval changed',['server_id'=>$fresh->id,'user_id'=>$actor->id,'enabled'=>$enabled]);
            DB::afterCommit(fn()=>SyncService::queue());
        });
    }
    public function setMode(Server $server,User $user,string $mode):void {
        if(!in_array($mode,['always','running_only'],true))throw ValidationException::withMessages(['port_opener'=>'Unknown exposure lifecycle mode.']);
        DB::transaction(function()use($server,$user,$mode){
            $fresh=Server::whereKey($server->id)->lockForUpdate()->firstOrFail();$actor=User::findOrFail($user->id);
            if(!$actor->isRootAdmin())throw new AuthorizationException('Only administrators can change exposure policy.');
            Exposure::updateOrCreate(['server_id'=>$fresh->id],['mode'=>$mode,'offline_since'=>null,'last_runtime_state'=>null,'last_confirmed_runtime_state'=>null,'last_runtime_checked_at'=>null]);
            Log::info('PortOpener exposure policy changed',['server_id'=>$fresh->id,'user_id'=>$actor->id,'mode'=>$mode]);
            DB::afterCommit(fn()=>SyncService::queue());
        });
    }
    public function preview(Server $server): array {
        $nodePolicy=NodePolicy::where('node_id',$server->node_id)->first();
        if ((int)$server->node_id !== (int)config('port-opener.node_id') && !($nodePolicy?->connection_mode==='outbound' && $nodePolicy->service_enabled)) throw ValidationException::withMessages(['port_opener'=>'This node is not configured for PortOpenerService.']);
        if ($server->node->maintenance_mode || $server->transfer()->exists()) throw ValidationException::withMessages(['port_opener'=>'Server node is unavailable or server is transferring.']);
        $policy = NodePolicy::where('node_id',$server->node_id)->first();
        if (!$policy) throw ValidationException::withMessages(['port_opener'=>'No node policy exists.']);
        return app(AllocationProtocols::class)->resolve($server, $policy->toArray());
    }
    public function setEnabled(Server $server, User $user, bool $enabled): void {
        DB::transaction(function () use ($server,$user,$enabled) {
            $fresh = Server::whereKey($server->id)->lockForUpdate()->firstOrFail();
            $actor = User::findOrFail($user->id);
            if (!$actor->isRootAdmin()) throw new AuthorizationException('Only administrators can change exposure.');
            if ($enabled) $this->preview($fresh);
            Exposure::updateOrCreate(['server_id'=>$fresh->id], ['enabled'=>$enabled]);
            Log::info('PortOpener exposure changed', ['server_id'=>$fresh->id,'user_id'=>$actor->id,'enabled'=>$enabled]);
            DB::afterCommit(fn () => SyncService::queue());
        });
    }

    public function configure(Server $server, User $actor, string $mode, bool $ipv6): void
    {
        if (!in_array($mode, ['off', 'always', 'running_only'], true)) throw ValidationException::withMessages(['port_opener' => 'Choose a forwarding mode.']);
        DB::transaction(function () use ($server, $actor, $mode, $ipv6) {
            if (!User::findOrFail($actor->id)->isRootAdmin()) throw new AuthorizationException('Only administrators can configure forwarding.');
            $fresh = Server::whereKey($server->id)->lockForUpdate()->firstOrFail();
            if ($mode !== 'off') $this->preview($fresh);
            $policy = NodePolicy::where('node_id', $fresh->node_id)->first();
            if ($ipv6 && (!$policy?->ipv6_enabled || $policy->connection_mode !== 'outbound')) throw ValidationException::withMessages(['port_opener' => 'Enable IPv6 on the node before approving it for a server.']);
            $current = Exposure::where('server_id', $fresh->id)->first();
            Exposure::updateOrCreate(['server_id' => $fresh->id], ['enabled' => $mode !== 'off', 'mode' => $mode === 'off' ? ($current?->mode ?? 'running_only') : $mode,
                'ipv6_enabled' => $ipv6, 'offline_since' => null, 'last_runtime_state' => null, 'last_confirmed_runtime_state' => null, 'last_runtime_checked_at' => null]);
            Log::info('PortOpener server configuration changed', ['server_id' => $fresh->id, 'user_id' => $actor->id, 'mode' => $mode, 'ipv6' => $ipv6]);
            DB::afterCommit(fn () => SyncService::queue());
        });
    }
}
