<?php
namespace PortOpener\Concerns;
use App\Models\Server;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use PortOpener\Models\Exposure;
use PortOpener\Services\ExposureService;
use PortOpener\Services\SyncService;

trait InteractsWithPortExposure
{
    public string $mode = 'always';
    public bool $ipv6Enabled = false;

    public function mount(): void
    {
        $server = $this->server();
        $this->mode = Exposure::where('server_id', $server->id)->value('mode') ?? 'always';
        $this->ipv6Enabled = (bool) Exposure::where('server_id', $server->id)->value('ipv6_enabled');
    }

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();
        return $server instanceof Server && (auth()->user()?->canAccessTenant($server) ?? false)
            && (auth()->user()?->can(\App\Enums\SubuserPermission::AllocationRead, $server) ?? false);
    }

    private function server(): Server
    {
        abort_unless(static::canAccess(), 403);
        return Filament::getTenant();
    }

    public function canManage(): bool
    {
        $server = $this->server();
        return false; // This client component is read-only, including for administrators.
    }

    public function setExposure(bool $enabled): void
    {
        abort_unless($this->canManage(), 403);
        app(ExposureService::class)->setEnabled($this->server(), auth()->user(), $enabled);
        Notification::make()->success()->title($enabled ? 'Exposure requested' : 'Removal requested')->send();
    }

    public function saveMode(): void
    {
        abort_unless($this->canManage(), 403);
        $this->validate(['mode' => 'required|in:always,running_only']);
        app(ExposureService::class)->setMode($this->server(), auth()->user(), $this->mode);
        Notification::make()->success()->title('Exposure mode saved')->body('Public access remains ' . (Exposure::where('server_id', $this->server()->id)->value('enabled') ? 'enabled.' : 'disabled.'))->send();
    }

    public function saveIpv6(): void
    {
        abort_unless($this->canManage(), 403);
        app(ExposureService::class)->setIpv6Enabled($this->server(), auth()->user(), $this->ipv6Enabled);
        Notification::make()->success()->title('IPv6 approval saved')->body('Public access must also be enabled.')->send();
    }

    public function ipv6Detail(?string $code): string
    {
        return match($code){
            'ipv6_verification_denied'=>'Rule requested; router denied verification.',
            'ipv6_listener_missing'=>'No IPv6 listener on this port.',
            'authentication_paused'=>'Service authentication is paused.',
            'ipv6_address_unavailable'=>'No usable global IPv6 address.',
            'ipv6_address_ambiguous'=>'Multiple eligible addresses; select a network interface.',
            'ipv6_unsupported'=>'Router does not support IPv6 firewall control.',
            'ipv6_router_mismatch'=>'IPv6 gateway differs from the configured router.',
            'ipv6_gateway_unavailable'=>'IPv6 default gateway unavailable.',
            null=>'External connectivity has not been tested.',
            default=>'IPv6 router or discovery check failed.',
        };
    }

    protected function getViewData(): array
    {
        $server = $this->server();
        $ports = [];
        $problems = [];
        try {
            $ports = app(ExposureService::class)->preview($server);
        } catch (ValidationException $exception) {
            $problems = array_merge(...array_values($exception->errors()));
            foreach ($server->allocations()->get() as $allocation) {
                foreach (app(\PortOpener\Services\AllocationProtocols::class)->forAllocation($allocation) as $protocol) {
                    $ports[] = ['mapping_id' => $server->uuid.':'.$allocation->id.':'.strtolower($protocol), 'allocation_id' => $allocation->id, 'port' => $allocation->port, 'protocol' => $protocol];
                }
            }
        }
        $policy = \PortOpener\Models\NodePolicy::where('node_id', $server->node_id)->first();
        $status = $policy?->connection_mode === 'outbound'
            ? ($policy->last_report ?? [])
            : ((int) config('port-opener.node_id') === (int) $server->node_id ? app(SyncService::class)->status() : []);
        $allocations = $server->allocations->keyBy('id');
        foreach ($ports as &$port) $port['display_port'] = $allocations->get($port['allocation_id'])?->show_port ? $port['port'] : 'Hidden';
        unset($port);
        $exposure = Exposure::where('server_id', $server->id)->first();
        $runtimePolicyState = ($exposure?->mode ?? 'always') === 'running_only'
            ? app(\PortOpener\Services\RuntimePolicy::class)->evaluate($exposure?->last_runtime_state ?? 'unknown', $exposure?->last_confirmed_runtime_state, $exposure?->offline_since, now())['policy_state']
            : 'always';
        $graceUntil = $exposure?->offline_since?->copy()->addSeconds(120);
        // Never expose another tenant's mapping or service messages to this page.
        $allowed = array_column($ports, 'mapping_id');
        $observed = collect($status['mappings'] ?? [])->filter(fn (array $row) => in_array($row['mapping_id'] ?? '', $allowed, true))->keyBy('mapping_id');
        $ipv6 = $status['ipv6'] ?? [];
        $reportFresh = false;
        if (!empty($status['last_reconciled_at'])) {
            try { $reportFresh = \Illuminate\Support\Carbon::parse($status['last_reconciled_at'])->gt(now()->subSeconds(180)); }
            catch (\Throwable $exception) {}
        }
        $ipv6Observed = collect($ipv6['mappings'] ?? [])->filter(fn($row)=>in_array($row['mapping_id'] ?? '', $allowed, true))
            ->groupBy('mapping_id')->map(fn($group)=>$group->first(fn($row)=>($row['address'] ?? null)===($ipv6['address'] ?? null) && $row['state']!=='removing') ?? $group->first());
        return [
            'ipv6NodeEnabled' => (bool) $policy?->ipv6_enabled,
            'reportFresh' => $reportFresh,
            'ipv6Approved' => (bool) $exposure?->ipv6_enabled,
            'ipv6' => $ipv6,
            'ipv6Observed' => $ipv6Observed,
            'enabled' => (bool) $exposure?->enabled,
            'savedMode' => $exposure?->mode ?? 'always',
            'runtimeState' => $exposure?->last_runtime_state ?? 'unknown',
            'runtimePolicyState' => $runtimePolicyState,
            'runtimeCheckedAt' => $exposure?->last_runtime_checked_at?->toIso8601String(),
            'graceUntil' => $graceUntil?->toIso8601String(),
            'graceActive' => $graceUntil?->isFuture() ?? false,
            'ports' => $ports,
            'problems' => $problems,
            'observed' => $observed,
            'lastReconciled' => $status['last_reconciled_at'] ?? null,
            'dryRun' => $status['dry_run'] ?? true,
            'simulated' => ($status['backend'] ?? null) === 'fake',
        ];
    }
}
