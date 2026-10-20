<?php

namespace PortOpener\Services;

use PortOpener\Models\NodePolicy;

class NodeHealth
{
    /** Count the same healthy services shown in the overview, independent of table search. */
    public static function functionalServiceCount(): int
    {
        return count(array_filter(self::rows(), fn (array $node) => $node['health']['label'] === 'Connected'));
    }

    public static function state(array $node): array
    {
        if (!($node['has_policy'] ?? false)) return ['label' => 'Not configured', 'color' => 'gray'];
        if (array_key_exists('credential_available', $node) && !$node['credential_available']) return ['label' => 'Pairing required', 'color' => 'warning'];
        if (!empty($node['pending_mode'])) return ['label' => 'Closing ports', 'color' => 'warning'];
        if (!($node['service_connected'] ?? false) || (isset($node['freshness']) && $node['freshness'] !== 'fresh')) return ['label' => empty($node['last_sync']) ? 'Awaiting connection' : 'Offline', 'color' => 'gray'];
        if (!empty($node['simulation'])) return ['label' => 'Simulation', 'color' => 'info'];
        if (!empty($node['error']) || !empty($node['configuration_pending']) || !empty($node['mapping_problem']) || !($node['router_reachable'] ?? false)) return ['label' => 'Needs attention', 'color' => 'warning'];
        return ['label' => 'Connected', 'color' => 'success'];
    }

    public static function rows(): array
    {
        $overview = app(StatusOverviewService::class)->overview();
        $policies = NodePolicy::all()->keyBy('node_id');
        return array_map(function ($node) use ($policies, $overview) {
            $policy = $policies->get($node['node_id']);
            $node['has_policy'] = (bool) $policy;
            $node['pending_mode'] = $policy?->pending_mode;
            $node['credential_available'] = !$policy || $policy->connection_mode !== 'outbound' || !empty($node['credential_expires_at']);
            $node['mapping_problem'] = collect($overview['ports'])->where('node_id', $node['node_id'])->contains(fn ($row) => in_array($row['state'], ['error', 'conflict', 'blocked', 'reassigned', 'moved', 'deleted'], true));
            $node['health'] = self::state($node);
            $node['mode_label'] = $policy ? (NodeModes::current($policy) === 'preview' ? 'Test mode' : ucfirst(NodeModes::current($policy))) : 'Not configured';
            if ($policy?->connection_mode === 'outbound' && $policy->enabled && !$policy->service_dry_run && !$policy->pending_mode && ($policy->last_report['dry_run'] ?? false)) $node['mode_label'] = 'Test mode (service override)';
            return $node;
        }, $overview['nodes']);
    }
}
