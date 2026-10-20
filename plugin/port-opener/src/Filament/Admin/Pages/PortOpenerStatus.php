<?php

namespace PortOpener\Filament\Admin\Pages;

use App\Enums\TablerIcon;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;
use PortOpener\Services\StatusOverviewService;
use PortOpener\Services\SyncService;

class PortOpenerStatus extends Page
{
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Activity;
    protected static ?string $navigationLabel = 'Port Opener status';
    protected static ?string $title = 'Port Opener status';
    protected string $view = 'port-opener::admin.status';
    protected static bool $shouldRegisterNavigation = false;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->redirect(PortOpenerHub::getUrl(['view' => 'ports-info']));
    }

    public string $search = '';
    public string $nodeFilter = '';
    public string $protocolFilter = '';
    public string $stateFilter = '';
    public ?array $testResult = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isRootAdmin() ?? false;
    }

    private function authorizeAdministrator(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function refreshStatus(): void
    {
        $this->authorizeAdministrator();
        // Rendering reads the cache; it does not contact a router or reconcile mappings.
    }

    public function testNode(int $nodeId): void
    {
        $this->authorizeAdministrator();
        $node = collect(app(StatusOverviewService::class)->overview()['nodes'])->firstWhere('node_id', $nodeId);
        abort_unless($node && $node['configured'] && $node['router_url'] && (($node['connection_mode'] ?? 'legacy_local') === 'outbound' || $nodeId === (int) config('port-opener.node_id')), 403);
        $this->resetValidation('router_test');
        $this->testResult = null;
        try {
            $this->testResult = ['node_name' => $node['node_name']] + app(SyncService::class)->testRouterForNode($nodeId);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['router_test' => $exception->getMessage()]);
        }
    }

    protected function getViewData(): array
    {
        $this->authorizeAdministrator();
        $overview = app(StatusOverviewService::class)->overview();
        $ports = collect($overview['ports'])->filter(function (array $port): bool {
            if ($this->nodeFilter !== '' && (string) $port['node_id'] !== $this->nodeFilter) return false;
            if ($this->protocolFilter !== '' && $port['protocol'] !== $this->protocolFilter) return false;
            if ($this->stateFilter !== '' && $port['state'] !== $this->stateFilter) return false;
            $text = implode(' ', [$port['node_name'], $port['server_name'], $port['server_uuid'], $port['allocation_ip'], $port['port']]);
            return $this->search === '' || str_contains(mb_strtolower($text), mb_strtolower($this->search));
        })->values();
        return ['nodes' => $overview['nodes'], 'ports' => $ports, 'configuredNodeId' => (int) config('port-opener.node_id'), 'states' => collect($overview['ports'])->pluck('state')->unique()->sort()->values()];
    }
}
