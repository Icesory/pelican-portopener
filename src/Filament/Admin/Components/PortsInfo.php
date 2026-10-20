<?php

namespace PortOpener\Filament\Admin\Components;

use App\Filament\Admin\Resources\Servers\ServerResource;
use Illuminate\Support\Collection;
use Livewire\Component;
use PortOpener\Services\StatusOverviewService;

class PortsInfo extends Component
{
    public string $search = '';
    public string $nodeFilter = '';
    public string $protocolFilter = '';
    public string $stateFilter = '';

    public function mount(): void { abort_unless(auth()->user()?->isRootAdmin(), 403); }
    public function hydrate(): void { abort_unless(auth()->user()?->isRootAdmin(), 403); }

    /** Search selects servers; their other ports remain visible for context. */
    public function serverGroups(?array $overview = null): Collection
    {
        abort_unless(auth()->user()?->isRootAdmin(), 403);
        $search = mb_strtolower(trim($this->search));
        return collect(($overview ?? app(StatusOverviewService::class)->overview())['ports'])
            ->groupBy(fn (array $row) => $row['server_uuid'] ?? 'server:'.$row['server_id'])
            ->map(function (Collection $rows, string $key) use ($search) {
                $visible = $rows->filter(fn (array $row) =>
                    ($this->nodeFilter === '' || (string) $row['node_id'] === $this->nodeFilter)
                    && ($this->protocolFilter === '' || $row['protocol'] === $this->protocolFilter)
                    && ($this->stateFilter === '' || $row['state'] === $this->stateFilter));
                if ($visible->isEmpty() || ($search !== '' && !$visible->contains(fn (array $row) =>
                    str_contains(mb_strtolower(implode(' ', [$row['server_name'], $row['node_name'], $row['allocation_ip'], $row['port']])), $search)))) return null;
                $first = $rows->first();
                return ['key' => $key, 'name' => $first['server_name'],
                    'nodes' => $visible->pluck('node_name')->unique()->implode(', '),
                    'multiple_nodes' => $visible->pluck('node_id')->unique()->count() > 1,
                    'edit_url' => !empty($first['server_id']) ? ServerResource::getUrl('edit',
                        ['record' => $first['server_id'], 'tab' => 'portopener::data::tab'], panel: 'admin') : null,
                    'ports' => $visible->sortBy(fn (array $row) => sprintf('%05d:%s:%s', $row['port'] ?? 0, $row['protocol'], $row['address_family'] ?? 'IPv4'))->values()->all()];
            })->filter()->values();
    }

    public function render()
    {
        abort_unless(auth()->user()?->isRootAdmin(), 403);
        $overview = app(StatusOverviewService::class)->overview();
        return view('port-opener::admin.ports-info', ['servers' => $this->serverGroups($overview),
            'nodes' => $overview['nodes'], 'states' => collect($overview['ports'])->pluck('state')->unique()->sort()]);
    }
}
