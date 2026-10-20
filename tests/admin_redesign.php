<?php
/** Isolated development only: native rendering, authorization, migration and ownership. */
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('app.name') !== 'PortOpener Development') throw new RuntimeException('Development only.');

use App\Models\{Server, User, Allocation};
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{DB, Queue};
use Livewire\Livewire;
use PortOpener\Filament\Admin\{Pages\PortOpenerHub, Components\PortOpenerAllocations, Components\ServerForwardingSettings, Components\PortsInfo};
use PortOpener\Models\{AllocationProtocol, NodePolicy};
use PortOpener\Services\{AllocationProtocols, ExposureService, NodeModes, NodeHealth};

Queue::fake();
$server = Server::where('name', 'PortOpener Synthetic Server')->firstOrFail();
$admin = User::all()->first(fn ($u) => $u->isRootAdmin());
$owner = User::findOrFail($server->owner_id);
auth()->login($admin);
config(['port-opener.node_id' => $server->node_id]);
$checks = 0;
$check = function ($condition, $message) use (&$checks) { if (!$condition) throw new RuntimeException('FAIL: '.$message); $checks++; };
DB::beginTransaction();
try {
    $service = app(AllocationProtocols::class);
    $check(count(app(ExposureService::class)->preview($server)) === 3, 'existing approvals preserve all three fixture tuples');
    $allocation = $server->allocations()->firstOrFail();
    $service->set($server, $admin, $allocation->id, 'udp');
    $check($service->forAllocation($allocation) === ['UDP'], 'explicit protocol edit');
    foreach ([fn () => $service->set($server, $owner, $allocation->id, 'both'),
        fn () => app(ExposureService::class)->setEnabled($server, $owner, false),
        fn () => app(ExposureService::class)->setMode($server, $owner, 'always'),
        fn () => app(ExposureService::class)->setIpv6Enabled($server, $owner, false),
        fn () => app(ExposureService::class)->configure($server, $owner, 'off', false)] as $change) {
        try { $change(); throw new RuntimeException('Owner mutation allowed.'); }
        catch (\Illuminate\Auth\Access\AuthorizationException $e) { $checks++; }
    }
    // Native bulk release followed immediately by reassign must not resurrect approval.
    Allocation::whereKey($allocation->id)->update(['server_id' => null]);
    $check(!AllocationProtocol::where('allocation_id', $allocation->id)->exists(), 'bulk release clears approval immediately');
    Allocation::whereKey($allocation->id)->update(['server_id' => $server->id]);
    $check($service->forAllocation($allocation->fresh()) === [], 'reassignment cannot resurrect approval');
    $service->set($server, $admin, $allocation->id, 'both');
    $allocation->forceFill(['notes' => 'Edited note', 'show_port' => false])->saveQuietly();
    $check(count($service->forAllocation($allocation)) === 2, 'notes and visibility preserve approval');

    $policy = NodePolicy::where('node_id', $server->node_id)->firstOrFail();
    $policy->forceFill(['connection_mode' => 'outbound', 'service_enabled' => true, 'enabled' => true])->saveQuietly();
    \PortOpener\Models\Exposure::updateOrCreate(['server_id' => $server->id], ['enabled' => true, 'mode' => 'always']);
    $publisher = app(\PortOpener\Services\OutboundPublisher::class);
    $before = $publisher->publish($policy);
    app()->instance(\PortOpener\Services\RuntimeStateProvider::class, new class extends \PortOpener\Services\RuntimeStateProvider {
        public function state(Server $server): string { return 'unknown'; }
    });
    app(ExposureService::class)->setMode($server, $admin, 'running_only');
    $held = $publisher->publish($policy->fresh());
    $check($held['mappings'] === $before['mappings'], 'unknown runtime retains unchanged approved tuples');
    $service->set($server, $admin, $allocation->id, 'none');
    $pruned = $publisher->publish($policy->fresh());
    $check(!collect($pruned['mappings'])->contains(fn ($row) => $row['allocation_id'] === $allocation->id), 'protocol revocation prunes retained unknown-runtime mappings');
    $check($pruned['generation'] > $held['generation'], 'revocation advances snapshot generation');
    $service->set($server, $admin, $allocation->id, 'both');
    app(ExposureService::class)->setMode($server, $admin, 'always');

    $policy = NodePolicy::where('node_id', $server->node_id)->firstOrFail();
    $old = clone $policy; $old->service_dry_run = false;
    $prepared = NodeModes::prepare($policy->toArray(), $old, 'preview');
    $check(!$prepared['enabled'] && !$prepared['service_dry_run'] && $prepared['pending_mode'] === 'preview', 'active to preview drains using real cleanup');
    $policy->forceFill($prepared + [])->forceFill(['connection_mode' => 'outbound', 'desired_generation' => 12,
        'desired_snapshot' => ['generation' => 12, 'mappings' => []], 'last_report' => ['generation' => 11, 'dry_run' => false, 'router_reachable' => true, 'last_reconciled_at' => now()->toIso8601String(), 'mappings' => []]])->saveQuietly();
    NodeModes::finish($policy); $check($policy->fresh()->pending_mode === 'preview', 'old report cannot finish cleanup');
    $report = $policy->last_report; $report['generation'] = 12; $report['ipv6']['mappings'] = [['state' => 'removing']];
    $policy->forceFill(['last_report' => $report])->saveQuietly(); NodeModes::finish($policy);
    $check($policy->fresh()->pending_mode === 'preview', 'IPv6 lease removal blocks completion');
    $report['ipv6']['mappings'] = []; $policy->forceFill(['last_report' => $report])->saveQuietly(); NodeModes::finish($policy);
    $check($policy->fresh()->service_dry_run && $policy->fresh()->enabled && !$policy->fresh()->pending_mode, 'latest clean report enables preview');
    $check(NodeHealth::state(['has_policy' => true, 'service_connected' => true, 'router_reachable' => false])['label'] === 'Needs attention', 'connection does not hide router failure');
    $check(NodeHealth::state(['has_policy' => true, 'service_connected' => true, 'router_reachable' => true, 'credential_available' => false])['label'] === 'Pairing required', 'revoked credentials cannot look connected');

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $hub = Livewire::test(PortOpenerHub::class);
    foreach (['Overview', 'Ports info', 'Add node'] as $needle) $check(str_contains($hub->html(), $needle), 'hub renders '.$needle);
    $check(PortOpenerHub::getNavigationLabel() === 'PortOpener' && $hub->instance()->getTitle() === 'PortOpener', 'page and navigation use PortOpener');
    $check(PortOpenerHub::getNavigationGroup() === \App\Filament\Admin\Resources\Nodes\NodeResource::getNavigationGroup(), 'navigation follows native Nodes group');
    $check($hub->instance()->getTable()->isSearchable(), 'native nodes search is enabled');
    $check(count($hub->instance()->getTable()->getToolbarActions()) === 1, 'add node is in table toolbar');
    $editAction = $hub->instance()->getTable()->getAction('configure');
    $check($editAction->isIconButton() && $editAction->getIcon() === 'tabler-pencil', 'configure uses native pencil icon button');
    $hub->set('tableSearch', mb_strtoupper($server->node->name));
    $check($hub->instance()->getTableRecords()->has($server->node_id), 'node search matches names without case sensitivity');
    $hub->set('tableSearch', 'no-such-portopener-node');
    $check($hub->instance()->getTableRecords()->isEmpty(), 'node search hides nonmatching rows');
    $check(str_contains($hub->html(), 'No matching nodes'), 'empty search result does not suggest nodes are unconfigured');
    $hub->set('tableSearch', '   ');
    $check($hub->instance()->getTableRecords()->has($server->node_id), 'blank search restores nodes');
    $check(!str_contains($hub->html(), 'Egg port definitions'), 'egg editor removed');
    $hub->call('mountAction', 'addNode', [], ['table' => true]);
    $modal = (new ReflectionMethod($hub->instance(), 'getMountedActionSchema'))->invoke($hub->instance())->toHtml();
    foreach (['Wings node', 'Advanced configuration'] as $needle) $check(str_contains($modal, $needle), 'node modal renders '.$needle);

    // Sidebar badges use the same health boundary as the overview, regardless of search.
    $realOverview = app(\PortOpener\Services\StatusOverviewService::class);
    $healthy = ['node_id' => $server->node_id, 'node_name' => $server->node->name, 'service_connected' => true,
        'router_reachable' => true, 'freshness' => 'fresh', 'last_sync' => now()->toIso8601String(), 'credential_expires_at' => now()->addDay()->toIso8601String()];
    foreach ([['changes' => [], 'count' => '1'],
        ['changes' => ['service_connected' => false], 'count' => '0'],
        ['changes' => ['freshness' => 'stale'], 'count' => '0'],
        ['changes' => ['router_reachable' => false], 'count' => '0'],
        ['changes' => ['simulation' => true], 'count' => '0'],
        ['changes' => ['configuration_pending' => true], 'count' => '0'],
        ['changes' => ['error' => 'Check failed'], 'count' => '0'],
        ['changes' => ['credential_expires_at' => null], 'count' => '0']] as $case) {
        app()->instance(\PortOpener\Services\StatusOverviewService::class, new class(array_replace($healthy, $case['changes'])) extends \PortOpener\Services\StatusOverviewService {
            public function __construct(private array $node) {}
            public function overview(): array { return ['nodes' => [$this->node], 'ports' => []]; }
        });
        $check(PortOpenerHub::getNavigationBadge() === $case['count'], 'badge counts only healthy connected services: '.json_encode($case['changes']));
    }
    app()->instance(\PortOpener\Services\StatusOverviewService::class, $realOverview);
    $ports = Livewire::test(PortsInfo::class)->html();
    foreach (['Forwarding', $server->name] as $needle) $check(str_contains($ports, $needle), 'ports info renders '.$needle);
    $filtered = Livewire::test(PortsInfo::class)->set('nodeFilter', '999999')->instance()->serverGroups();
    $check($filtered->count() === 0, 'ports info node filter');
    $allocationPage = Livewire::test(PortOpenerAllocations::class, ['ownerRecord' => $server, 'pageClass' => \App\Filament\Admin\Resources\Servers\Pages\EditServer::class])
        ->html();
    foreach (['Forwarding protocol', 'Show port', 'Alias', 'Notes', 'Locked'] as $needle) $check(str_contains($allocationPage, $needle), 'native allocation table renders '.$needle);
    $check(str_contains($allocationPage, 'Save forwarding settings'), 'forwarding save button belongs to the allocation page');
    Livewire::test(ServerForwardingSettings::class, ['serverId' => $server->id])->set('data.mode', 'off')->dispatch('port-opener-save-forwarding.'.$server->id);
    $check(!\PortOpener\Models\Exposure::where('server_id', $server->id)->value('enabled'), 'native forwarding save requests removal');
    $edit = Livewire::test(\App\Filament\Admin\Resources\Servers\Pages\EditServer::class, ['record' => $server->id])->html();
    $check(str_contains($edit, 'PortOpener') && str_contains($edit, 'Forwarding protocol'), 'actual server edit page includes PortOpener tab');
    $check(app(\App\Services\Servers\ServerCreationService::class) instanceof \PortOpener\Services\PortOpenerServerCreation, 'server creation decorator registered');
    $creation = Livewire::test(\App\Filament\Admin\Resources\Servers\Pages\CreateServer::class)->html();
    $check(str_contains($creation, 'PortOpener'), 'actual server creation wizard includes PortOpener');

    auth()->login($owner);
    Filament::setCurrentPanel(Filament::getPanel('server')); Filament::setTenant($server->fresh());
    $html = Livewire::mount(\PortOpener\Filament\Server\Widgets\PortExposureWidget::class);
    $check(str_contains($html, 'Hidden'), 'owner status respects hidden ports');
    $check(!str_contains($html, 'Save forwarding') && !str_contains($html, 'setExposure('), 'owner view has no forwarding controls');
    $component = new \PortOpener\Filament\Server\Widgets\PortExposureWidget();
    try { $component->setExposure(true); throw new RuntimeException('Owner widget action allowed.'); }
    catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $check($e->getStatusCode() === 403, 'owner widget rejects direct mutation'); }
    echo "$checks admin redesign checks passed\n";
} finally { DB::rollBack(); }
