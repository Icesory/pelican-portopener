<?php

namespace PortOpener\Services;

use PortOpener\Models\NodePolicy;

class NodeModes
{
    public static function current(?NodePolicy $policy): string
    {
        return $policy?->pending_mode ?? (!$policy?->enabled ? 'paused' : ($policy->connection_mode === 'outbound' && $policy->service_dry_run ? 'preview' : 'active'));
    }

    public static function prepare(array $data, ?NodePolicy $old, string $target): array
    {
        $data['service_enabled'] = true;
        $data['enabled'] = $target !== 'paused';
        $data['service_dry_run'] = $target === 'preview';
        // Keep real cleanup enabled until the latest empty snapshot is acknowledged.
        $reported = array_merge($old?->last_report['mappings'] ?? [], $old?->last_report['ipv6']['mappings'] ?? []);
        $mayOwnRules = ($old?->last_report['backend'] ?? '') === 'upnp' && collect($reported)->contains(fn ($row) => ($row['state'] ?? '') !== 'removed');
        if ($target !== 'active' && $old && (!$old->service_dry_run || $old->pending_mode || $mayOwnRules)) {
            $data['enabled'] = false;
            $data['service_dry_run'] = false;
            $data['pending_mode'] = $target;
        } else $data['pending_mode'] = null;
        return $data;
    }

    public static function finish(NodePolicy $policy): void
    {
        if (!$policy->pending_mode || $policy->connection_mode !== 'outbound') return;
        $report = $policy->last_report ?? [];
        if (!$policy->desired_snapshot || !empty($policy->desired_snapshot['mappings'])
            || ($report['generation'] ?? -1) !== (int) $policy->desired_generation
            || empty($report['last_reconciled_at']) || ($report['dry_run'] ?? true)
            || !empty($report['renewal_paused']) || !($report['router_reachable'] ?? false)) return;
        foreach (array_merge($report['mappings'] ?? [], $report['ipv6']['mappings'] ?? []) as $row) {
            if (($row['state'] ?? '') !== 'removed') return;
        }
        $preview = $policy->pending_mode === 'preview';
        $policy->forceFill(['enabled' => $preview, 'service_dry_run' => $preview, 'pending_mode' => null])->saveQuietly();
    }
}
