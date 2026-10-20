<div class="space-y-6">
    @livewire('port-opener.server-settings', ['serverId' => $this->getOwnerRecord()->id], key('port-opener-settings-'.$this->getOwnerRecord()->id))
    {{ $this->table }}
    <x-filament::button type="button" wire:click="$dispatchTo('port-opener.server-settings', 'port-opener-save-forwarding.{{ $this->getOwnerRecord()->id }}')" wire:loading.attr="disabled">Save forwarding settings</x-filament::button>
</div>
