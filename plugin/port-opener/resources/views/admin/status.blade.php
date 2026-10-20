<x-filament-panels::page>
    @include('port-opener::partials.form-colors')
    @php($formatTime = fn ($time) => $time ? \Carbon\Carbon::parse($time)->utc()->format('d M H:i') . ' UTC' : null)
    <div wire:poll.15s="refreshStatus" class="space-y-6">
        <x-filament::section heading="Cached router and allocation overview">
            <p>This page lists assigned allocations and configured protocols. An allocation does not prove that a process is listening on that port. Router mapping status does not prove external connectivity.</p>
            <p class="mt-2">Updates every 15 seconds from cached results. Refresh does not contact the router or change mappings. Test UPnP checks the saved connection without opening ports.</p>
            <x-filament::button class="mt-3" wire:click="refreshStatus" wire:loading.attr="disabled">Refresh cached status</x-filament::button>
        </x-filament::section>
        <x-filament::section heading="UPnP status by Wings node">
            @error('router_test')<p role="alert" class="mb-3 text-danger-600">{{ $message }}</p>@enderror
            @if($testResult)<p role="status" class="mb-3">{{ $testResult['node_name'] }}: {{ ($testResult['queued'] ?? false) ? 'Connection test queued.' : (($testResult['backend'] ?? null) === 'fake' ? 'Simulation only — no real router checked.' : (($testResult['router_reachable'] ?? false) ? 'Connection succeeded.' : 'Connection failed.')) }} {{ $testResult['message'] ?? '' }}</p>
@endif

            <div class="overflow-x-auto"><table class="w-full text-left"><caption class="sr-only">Cached UPnP observations and connection tests for each Wings node</caption>
                <thead><tr><th scope="col" class="p-2">Node / router</th><th scope="col" class="p-2">Connection</th><th scope="col" class="p-2">Last check</th><th scope="col" class="p-2">Synchronization</th><th scope="col" class="p-2">Mappings</th><th scope="col" class="p-2">Details / test</th></tr></thead>
                <tbody>@forelse($nodes as $node)<tr>
                    <td class="p-2" style="min-width:180px;max-width:220px;overflow-wrap:anywhere"><strong>{{ $node['node_name'] }}</strong><br>{{ ($node['forwarding_type'] ?? 'upnp') === 'upnp' ? 'UPnP' : $node['forwarding_type'] }}<br>{{ $node['router_url'] ?? 'Router URL not configured' }}<br>Host: {{ $node['internal_address'] ?? 'Not configured' }}</td>
                    <td class="p-2">@if($node['simulation'])<strong>Simulation — fake router</strong><br>No real ports opened<br>
@endif

                        @php($checkLabels = ['unconfigured' => 'Not configured', 'simulation' => 'Simulated connection only', 'working' => 'Working at last observation', 'failed' => 'Connection check failed', 'stale' => 'Stale cached status — test again', 'not_checked' => 'Not checked'])
                        {{ $node['simulation'] ? '' : ($checkLabels[$node['router_check_state'] ?? 'not_checked'] ?? 'Not checked') }}
                        @if($node['configuration_pending'] ?? false)<br>Saved router URL awaiting connection test
@endif

                        <br>{{ $node['service_connected'] ? 'Service connected' : 'Service unavailable at last synchronization' }}<br>{{ $node['enabled'] ? 'Exposure policy enabled' : 'Exposure policy disabled' }}</td>
                    <td class="p-2" style="min-width:120px">{{ $formatTime($node['last_tested_at']) ?? 'Never tested' }}<br>@if($node['last_test']){{ !($node['test_current'] ?? true) ? 'Previous router — test current settings' : (($node['last_test']['backend'] ?? null) === 'fake' ? 'Simulation only' : (($node['last_test']['router_reachable'] ?? false) ? 'Last test succeeded' : 'Last test failed')) }}@if(!($node['last_test_fresh'] ?? true))<br>Stale test result — test again
@endif

@endif
</td>
                    <td class="p-2" style="min-width:120px">{{ $formatTime($node['last_sync']) ?? 'Never synchronized' }}<br>{{ ucfirst($node['freshness']) }}</td>
                    <td class="p-2">{{ $node['mapping_count'] }} IPv4 / {{ $node['ipv6_mapping_count'] ?? 0 }} IPv6</td>
                    <td class="p-2">{{ $node['error'] ?? 'No cached error' }}<br>IPv6: {{ ucfirst($node['ipv6_state'] ?? 'disabled') }} {{ $node['ipv6_address'] ?? '' }} {{ $node['ipv6_error'] ?? '' }}@if($node['configured'] && $node['router_url'] && (($node['connection_mode'] ?? 'legacy_local') === 'outbound' || (int) $node['node_id'] === $configuredNodeId))<br><x-filament::button size="sm" wire:click="testNode({{ $node['node_id'] }})" wire:loading.attr="disabled">Test UPnP</x-filament::button>
@endif
</td>
                </tr>@empty<tr><td colspan="6" class="p-2">No Wings nodes exist.</td></tr>@endforelse</tbody>
            </table></div>
        </x-filament::section>
        <x-filament::section heading="Server ports and protocols">
            <div class="mb-4 flex flex-wrap gap-4">
                <label>Search server, node, IP or port<input type="search" wire:model.live.debounce.300ms="search" class="block rounded border p-2"></label>
                <label>Node<select wire:model.live="nodeFilter" class="port-opener-select block rounded border p-2"><option value="">All nodes</option>@foreach($nodes as $node)<option value="{{ $node['node_id'] }}">{{ $node['node_name'] }}</option>@endforeach</select></label>
                <label>Protocol<select wire:model.live="protocolFilter" class="port-opener-select block rounded border p-2"><option value="">All protocols</option><option value="TCP">TCP</option><option value="UDP">UDP</option><option value="unknown">Unknown</option></select></label>
                <label>Mapping state<select wire:model.live="stateFilter" class="port-opener-select block rounded border p-2"><option value="">All states</option>@foreach($states as $state)<option value="{{ $state }}">{{ ucfirst($state) }}</option>@endforeach</select></label>
            </div>
            <div class="overflow-x-auto"><table class="w-full text-left"><caption class="sr-only">Assigned server allocations, explicit protocol definitions and cached managed mapping status</caption><thead><tr><th scope="col" class="p-2">Node</th><th scope="col" class="p-2">Server</th><th scope="col" class="p-2">Allocation</th><th scope="col" class="p-2">Protocol</th><th scope="col" class="p-2">Exposure</th><th scope="col" class="p-2">Runtime policy</th><th scope="col" class="p-2">Mapping state</th><th scope="col" class="p-2">Details</th></tr></thead>
                <tbody>@forelse($ports as $port)<tr><td class="p-2">{{ $port['node_name'] }}</td><td class="p-2">{{ $port['server_name'] }}@if($port['orphan'])<br><strong>Orphaned service mapping</strong>
@endif
<br><span class="text-sm" title="{{ $port['server_uuid'] }}">{{ substr($port['server_uuid'], 0, 8) }}</span></td><td class="p-2">{{ $port['address_family'] ?? 'IPv4' }} {{ $port['allocation_ip'] ?? 'Unknown address' }}:{{ $port['port'] }}</td><td class="p-2">{{ $port['protocol'] === 'unknown' ? 'Unknown — no approved definition' : $port['protocol'] }}</td><td class="p-2">{{ is_bool($port['exposure']) ? ($port['exposure'] ? 'Requested' : 'Disabled') : ucfirst($port['exposure'] ?? 'unknown') }}</td>
<td class="p-2">{{ ($port['mode'] ?? 'always') === 'running_only' ? 'Only while running' : 'Always exposed' }}<br>{{ ucfirst($port['runtime_state'] ?? 'unknown') }}<br>{{ match($port['runtime_policy_state'] ?? 'always') { 'grace' => 'Restart grace period', 'waiting' => 'Offline — awaiting reconciliation', 'unknown' => 'Unknown — previous mappings retained', 'active' => 'Runtime permits exposure', default => 'Independent of runtime' } }}<br><span class="text-sm">Checked: {{ $port['runtime_checked_at'] ?? 'Not yet checked' }}</span></td>
<td class="p-2">{{ ucfirst($port['state']) }}</td><td class="p-2">{{ $port['message'] ?? 'No cached details' }}</td></tr>
                @empty<tr><td colspan="8" class="p-2">No allocations or managed mappings match these filters.</td></tr>@endforelse</tbody>
            </table></div>
            <p class="mt-3">Unknown protocols are never inferred from port numbers. Orphaned rows describe cached service rules for deleted, moved or reassigned servers.</p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
