<div class="space-y-4" wire:poll.15s>
    <div {{ (new \Filament\Support\View\ComponentAttributeBag)->grid(['default' => 1, 'md' => 4])->class('gap-4') }}>
        <x-filament::input.wrapper><x-filament::input type="search" wire:model.live.debounce.300ms="search" placeholder="Search server, node, address or port" aria-label="Search ports" /></x-filament::input.wrapper>
        <x-filament::input.wrapper><x-filament::input.select wire:model.live="nodeFilter" aria-label="Filter by node"><option value="">All nodes</option>@foreach($nodes as $node)<option value="{{ $node['node_id'] }}">{{ $node['node_name'] }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
        <x-filament::input.wrapper><x-filament::input.select wire:model.live="protocolFilter" aria-label="Filter by protocol"><option value="">All protocols</option><option>TCP</option><option>UDP</option><option value="unknown">Not managed</option></x-filament::input.select></x-filament::input.wrapper>
        <x-filament::input.wrapper><x-filament::input.select wire:model.live="stateFilter" aria-label="Filter by state"><option value="">All states</option>@foreach($states as $state)<option value="{{ $state }}">{{ ucfirst($state) }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
    </div>
    @php
        $showNodeColumn = $servers->contains('multiple_nodes', true);
        $columnWidths = $showNodeColumn ? [12, 7, 11, 20, 7, 18, 25] : [7, 12, 24, 7, 21, 29];
    @endphp
    @forelse($servers as $server)
        <x-filament::section :heading="$server['name']" :description="$server['nodes']" :has-content-el="false" wire:key="server-ports-{{ $server['key'] }}">
            <x-slot name="afterHeader">
                @if($server['edit_url'])
                    <x-filament::icon-button tag="a" :href="$server['edit_url']" icon="tabler-pencil" :label="'Edit ports for '.$server['name']" :tooltip="'Edit ports for '.$server['name']" />
                @endif
            </x-slot>
            <div class="overflow-x-auto">
                <table class="fi-ta-table" style="width: 100%; min-width: {{ $showNodeColumn ? '76rem' : '64rem' }}; table-layout: fixed" aria-label="{{ $server['name'] }} ports">
                    <colgroup>
                        @foreach($columnWidths as $width)<col style="width: {{ $width }}%">@endforeach
                    </colgroup>
                    <thead><tr>
                        @foreach(array_merge($showNodeColumn ? ['Node'] : [], ['Port', 'Protocol', 'Address', 'Family', 'Forwarding', 'Details']) as $heading)
                            <th class="fi-ta-header-cell" scope="col">{{ $heading }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                        @foreach($server['ports'] as $port)
                            @php
                                $state = !empty($port['simulation']) ? 'Simulated: '.ucfirst($port['state']) : (($port['error_code'] ?? null) === 'ipv6_verification_denied' ? 'Requested (unverified)' : ucfirst($port['state']).(($port['freshness'] ?? '') === 'stale' ? ' (stale)' : ''));
                                $color = match ($port['state']) { 'mapped' => 'success', 'error', 'conflict', 'blocked' => 'danger', 'pending', 'removing' => 'warning', default => 'gray' };
                            @endphp
                            <tr class="fi-ta-row" wire:key="{{ $server['key'] }}-{{ $loop->index }}-{{ $port['address_family'] ?? 'IPv4' }}-{{ $port['mapping_id'] ?? $port['allocation_id'] }}">
                                @if($showNodeColumn)<td class="fi-ta-cell"><div class="fi-ta-text-item px-3 py-3 whitespace-normal" style="overflow-wrap: anywhere">{{ $port['node_name'] }}</div></td>@endif
                                <td class="fi-ta-cell"><div class="fi-ta-text-item px-3 py-3">{{ $port['port'] ?? 'Unknown' }}</div></td>
                                <td class="fi-ta-cell"><div class="px-3 py-3"><x-filament::badge color="gray">{{ $port['protocol'] === 'unknown' ? 'Not managed' : $port['protocol'] }}</x-filament::badge></div></td>
                                <td class="fi-ta-cell"><div class="fi-ta-text-item px-3 py-3 whitespace-normal" style="overflow-wrap: anywhere">{{ $port['allocation_ip'] ?? 'Unknown' }}</div></td>
                                <td class="fi-ta-cell"><div class="fi-ta-text-item px-3 py-3">{{ $port['address_family'] ?? 'IPv4' }}</div></td>
                                <td class="fi-ta-cell"><div class="px-3 py-3"><x-filament::badge :color="$color">{{ $state }}</x-filament::badge></div></td>
                                <td class="fi-ta-cell"><div class="fi-ta-text-item px-3 py-3 whitespace-normal" style="overflow-wrap: anywhere">{{ $port['message'] ?? '—' }}</div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @empty
        <x-filament::section heading="No matching servers" description="Try another search or clear the filters. Servers appear here when they have allocated or cached ports." />
    @endforelse
    <p class="text-sm text-gray-500 dark:text-gray-400">Cached service reports. Mapped confirms a router rule; external connectivity has not been tested.</p>
</div>
