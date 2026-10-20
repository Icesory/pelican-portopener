<x-filament-panels::page>
    <x-filament::tabs aria-label="PortOpener">
        <x-filament::tabs.item :active="$activeTab !== 'ports-info'" wire:click="$set('activeTab', 'overview')" icon="tabler-server">Overview</x-filament::tabs.item>
        <x-filament::tabs.item :active="$activeTab === 'ports-info'" wire:click="$set('activeTab', 'ports-info')" icon="tabler-network">Ports info</x-filament::tabs.item>
    </x-filament::tabs>
    @if($activeTab === 'ports-info')
        @livewire('port-opener.ports-info')
    @else
        {{ $this->table }}
        <p class="text-sm text-gray-500 dark:text-gray-400">State reflects the latest service and router checks. Connected does not confirm external game connectivity.</p>
    @endif
    <x-filament-actions::modals />
</x-filament-panels::page>
