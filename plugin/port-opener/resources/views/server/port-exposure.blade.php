<x-filament-panels::page>
    @include('port-opener::partials.form-colors')
    <div wire:poll.15s class="space-y-6">
        @if($simulated)
            <x-filament::section heading="Simulation — fake router">
                <p role="status">These mappings are simulated. No real router ports are opened, even when the status says mapped.</p>
            </x-filament::section>
        @endif
        <x-filament::section heading="Public access">
            <p>Only the approved game ports below can be forwarded. A router mapping does not confirm that your game is reachable from the internet.</p>
            <p class="mt-2">Exposure: <strong>{{ $enabled ? 'Requested' : 'Disabled' }}</strong></p>
            <p class="mt-2">Exposure mode: {{ $savedMode === 'running_only' ? 'Only while running' : 'Always exposed' }}</p>
            @if($dryRun)<p class="mt-2">Test mode: the router is not changed.</p>@endif
            <p class="mt-2">Last reconciliation: {{ $lastReconciled ?? 'Not yet reconciled' }}</p>
            @if($this->canManage())
                <div class="mt-4 flex gap-3">
                    @if(!$enabled)
                        <x-filament::button wire:click="setExposure(true)" wire:confirm="Expose the approved game ports listed below?" wire:loading.attr="disabled" :disabled="count($problems) > 0 || count($ports) === 0">Enable public access</x-filament::button>
                    @else
                        <x-filament::button color="danger" wire:click="setExposure(false)" wire:confirm="Remove public access for this server's managed ports?" wire:loading.attr="disabled">Disable public access</x-filament::button>
                    @endif
                </div>
            @else
                <p class="mt-4">Only the server owner or a root administrator can change public access.</p>
            @endif
            @error('port_opener')<p role="alert" class="mt-3 text-danger-600">{{ $message }}</p>@enderror
        </x-filament::section>
        <x-filament::section heading="Exposure mode">
            @if($this->canManage())
                <form wire:submit="saveMode" class="space-y-3">
                    <label class="block">When to keep ports exposed
                        <select wire:model="mode" class="port-opener-select block w-full rounded border p-2">
                            <option value="always">Always exposed</option>
                            <option value="running_only">Only while running</option>
                        </select>
                    </label>
                    @error('mode')<p role="alert" class="text-danger-600">{{ $message }}</p>@enderror
                    @error('port_opener')<p role="alert" class="text-danger-600">{{ $message }}</p>@enderror
                    <x-filament::button type="submit" wire:loading.attr="disabled">Save exposure mode</x-filament::button>
                </form>
            @endif
            <p class="mt-3">Changing the mode does not enable public access. Always exposed keeps approved ports mapped while exposure is enabled.</p>
            <p class="mt-2">Only while running removes managed mappings after 120 seconds of confirmed offline time. The one-minute synchronization schedule provides a restart grace period; cleanup occurs on the next reconciliation after that grace expires. Unknown runtime state retains the previous mappings.</p>
            <p class="mt-3">Last runtime observation: <strong>{{ ucfirst($runtimeState) }}</strong>. Checked: {{ $runtimeCheckedAt ?? 'Not yet checked' }}.</p>
            @if($savedMode === 'running_only')
                @if(!$enabled)<p class="mt-2">Public access is disabled.</p>
                @elseif($runtimeState === 'unknown')<p class="mt-2">Waiting for a confirmed runtime state. Existing mappings are retained.</p>
                @elseif($runtimeState === 'offline' && $graceUntil)<p class="mt-2">{{ $graceActive ? 'Restart grace period until ' . $graceUntil . '. Existing mappings are retained.' : 'Offline grace has expired. Waiting for synchronization to confirm cleanup.' }}</p>
                @elseif($runtimeState === 'offline')<p class="mt-2">Offline observed. Waiting for synchronization to confirm the grace period.</p>
                @else<p class="mt-2">The server is {{ $runtimeState }}. See the router status below for the actual mapping result.</p>@endif
            @endif
        </x-filament::section>
        @if(count($problems))
            <x-filament::section heading="Configuration required">
                <ul class="list-disc pl-5">@foreach($problems as $problem)<li>{{ $problem }}</li>@endforeach</ul>
                <p class="mt-2">Ask an administrator to review the egg rules and node policy.</p>
            </x-filament::section>
        @endif
        <x-filament::section heading="Approved ports">
            <div class="overflow-x-auto">
                <table class="w-full text-left"><caption class="sr-only">Approved mappings and router status for this server</caption>
                    <thead><tr><th scope="col" class="p-2">Port</th><th scope="col" class="p-2">Protocol</th><th scope="col" class="p-2">Router status</th><th scope="col" class="p-2">Details</th></tr></thead>
                    <tbody>@forelse($ports as $port)
                        @php($row = $observed->get($port['mapping_id']))
                        <tr><td class="p-2">{{ $port['port'] }}</td><td class="p-2">{{ $port['protocol'] }}</td><td class="p-2">{{ ucfirst($row['state'] ?? (!$enabled ? 'disabled' : (in_array($runtimePolicyState, ['waiting', 'unknown'], true) ? $runtimePolicyState : 'pending'))) }}</td><td class="p-2">{{ $row['message'] ?? 'External connectivity has not been tested.' }}</td></tr>
                    @empty<tr><td colspan="4" class="p-2">No approved ports are available.</td></tr>@endforelse</tbody>
                </table>
            </div>
            <p class="mt-3 text-sm">External connectivity: not tested. Confirm using a game client outside your home network.</p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
