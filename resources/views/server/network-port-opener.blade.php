<x-filament-widgets::widget>
    <x-filament::section heading="PortOpenerService">
        <div wire:poll.15s class="space-y-4">
            <div class="flex flex-wrap items-center gap-3">
                <x-filament::badge :color="$enabled ? 'info' : 'gray'">{{ $enabled ? 'Public access requested' : 'Public access off' }}</x-filament::badge>
                @if($enabled)<span class="text-sm">{{ $savedMode === 'running_only' ? 'Only while running' : 'Always exposed' }}</span>@endif
                @if($simulated)<x-filament::badge color="info">Simulation</x-filament::badge>@elseif($dryRun)<x-filament::badge color="warning">Test mode</x-filament::badge>@endif
            </div>
            @if(count($problems))
                <p class="text-sm text-gray-500 dark:text-gray-400">An administrator can review this server's PortOpener configuration.</p>
            @endif
            {{ $this->table }}
            <p class="text-sm text-gray-500 dark:text-gray-400">Last service report: {{ $lastReconciled ?? 'Not available' }}. Router state does not confirm external connectivity.</p>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
