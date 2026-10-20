<?php

namespace PortOpener\Services;

use App\Models\{Allocation, Server, User};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\{DB, Log};
use Illuminate\Validation\ValidationException;
use PortOpener\Models\{AllocationProtocol, EggRule};

class AllocationProtocols
{
    public const OPTIONS = ['none' => 'Not managed', 'tcp' => 'TCP', 'udp' => 'UDP', 'both' => 'TCP + UDP'];

    public static function protocols(string $value): array
    {
        return match ($value) { 'tcp' => ['TCP'], 'udp' => ['UDP'], 'both' => ['TCP', 'UDP'], default => [] };
    }

    public function forAllocation(Allocation $allocation): array
    {
        $rule = AllocationProtocol::where('allocation_id', $allocation->id)->first();
        return $rule && $this->matches($rule, $allocation) ? $rule->protocols : [];
    }

    public function choice(Allocation $allocation): string
    {
        $protocols = $this->forAllocation($allocation);
        return count($protocols) === 2 ? 'both' : strtolower($protocols[0] ?? 'none');
    }

    private function matches(AllocationProtocol $rule, Allocation $allocation): bool
    {
        return $allocation->server_id && (int) $rule->server_id === (int) $allocation->server_id
            && (int) $rule->node_id === (int) $allocation->node_id && (int) $rule->port === (int) $allocation->port
            && $rule->ip === $allocation->ip;
    }

    public function set(Server $server, User $actor, int $allocationId, string $choice): void
    {
        if (!User::findOrFail($actor->id)->isRootAdmin()) throw new AuthorizationException('Only administrators can define forwarding protocols.');
        if (!array_key_exists($choice, self::OPTIONS)) throw ValidationException::withMessages(['protocol' => 'Choose a supported forwarding protocol.']);
        DB::transaction(function () use ($server, $actor, $allocationId, $choice) {
            $fresh = Server::whereKey($server->id)->lockForUpdate()->firstOrFail();
            $allocation = Allocation::whereKey($allocationId)->where('server_id', $fresh->id)->where('node_id', $fresh->node_id)->lockForUpdate()->firstOrFail();
            $this->store($allocation, self::protocols($choice));
            Log::info('PortOpener allocation protocols changed', ['server_id' => $fresh->id, 'allocation_id' => $allocation->id, 'protocols' => self::protocols($choice), 'user_id' => $actor->id]);
            DB::afterCommit(fn () => SyncService::queue());
        });
    }

    private function store(Allocation $allocation, array $protocols): void
    {
        AllocationProtocol::updateOrCreate(['allocation_id' => $allocation->id], [
            'server_id' => $allocation->server_id, 'node_id' => $allocation->node_id, 'ip' => $allocation->ip,
            'port' => $allocation->port, 'protocols' => array_values(array_unique($protocols)),
        ]);
    }

    /** Migration only: convert explicit existing approvals; do not infer a protocol from a port. */
    public function migrateEggRules(): void
    {
        foreach (Server::with('allocations')->cursor() as $server) {
            $variables = $server->variables()->get()->mapWithKeys(fn ($v) => [$v->env_variable => $v->server_value ?? $v->default_value])->all();
            $rules = EggRule::where('egg_id', $server->egg_id)->get()->toArray();
            $input = $server->toArray(); $input['status'] = null;
            try {
                $resolved = app(AllocationResolver::class)->resolve($input, $server->allocations->toArray(), $variables, $rules,
                    ['enabled' => true, 'node_id' => $server->node_id, 'internal_address' => '127.0.0.1', 'min_port' => 1, 'max_port' => 65535]);
            } catch (ValidationException | \TypeError $exception) { $resolved = []; }
            foreach ($server->allocations as $allocation) {
                if (AllocationProtocol::where('allocation_id', $allocation->id)->exists()) continue;
                $protocols = [];
                foreach ($resolved as $row) if ((int) $row['allocation_id'] === (int) $allocation->id) $protocols[] = $row['protocol'];
                $this->store($allocation, $protocols);
            }
        }
    }

    /** Also catches Pelican's bulk assignment/release queries, which bypass Eloquent events. */
    public function purgeReassigned(): void
    {
        AllocationProtocol::whereNotExists(function ($query) {
            $query->selectRaw('1')->from('allocations')->whereColumn('allocations.id', 'port_opener_allocation_protocols.allocation_id')
                ->whereColumn('allocations.server_id', 'port_opener_allocation_protocols.server_id')
                ->whereColumn('allocations.node_id', 'port_opener_allocation_protocols.node_id')
                ->whereColumn('allocations.port', 'port_opener_allocation_protocols.port')
                ->whereColumn('allocations.ip', 'port_opener_allocation_protocols.ip');
        })->delete();
    }

    public function resolve(Server $server, array $policy): array
    {
        $fail = fn ($message) => throw ValidationException::withMessages(['port_opener' => $message]);
        if (!($policy['enabled'] ?? false)) $fail('Port forwarding is paused for this node.');
        if (!filter_var($policy['internal_address'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $fail('Node requires a valid IPv4 destination.');
        if ($server->status !== null) $fail('Server must be installed, available and not suspended.');
        if ((int) $server->node_id !== (int) $policy['node_id']) $fail('Server node does not match policy.');
        $rows = [];
        foreach ($server->allocations()->get() as $allocation) {
            if ((int) $allocation->node_id !== (int) $server->node_id) continue;
            foreach ($this->forAllocation($allocation) as $protocol) {
                if (!in_array($protocol, ['TCP', 'UDP'], true)) $fail('An allocation has an invalid protocol.');
                if ($allocation->port < max(1, (int) $policy['min_port']) || $allocation->port > min(65535, (int) $policy['max_port'])) $fail('Allocation is outside the approved node range.');
                $id = $server->uuid . ':' . $allocation->id . ':' . strtolower($protocol);
                $rows[$id] = ['mapping_id' => $id, 'server_uuid' => $server->uuid, 'allocation_id' => (int) $allocation->id,
                    'internal_address' => $policy['internal_address'], 'port' => (int) $allocation->port, 'protocol' => $protocol];
            }
        }
        if (!$rows) $fail('No forwarding protocols are configured. An administrator can configure allocations in the server PortOpener tab.');
        ksort($rows);
        return array_values($rows);
    }
}
