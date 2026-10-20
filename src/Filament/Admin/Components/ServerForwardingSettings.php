<?php

namespace PortOpener\Filament\Admin\Components;

use App\Models\Server;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\{Select, Toggle};
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use PortOpener\Models\{Exposure, NodePolicy};
use PortOpener\Services\ExposureService;

class ServerForwardingSettings extends Component implements HasSchemas, HasActions
{
    use InteractsWithSchemas, InteractsWithActions;
    #[Locked] public int $serverId;
    public ?array $data = [];

    private function server(): Server
    {
        abort_unless(auth()->user()?->isRootAdmin(), 403);
        return Server::findOrFail($this->serverId);
    }

    public function mount(int $serverId): void
    {
        $this->serverId = $serverId;
        $server = $this->server();
        $exposure = Exposure::where('server_id', $server->id)->first();
        $this->form->fill(['mode' => $exposure?->enabled ? $exposure->mode : 'off', 'ipv6' => (bool) $exposure?->ipv6_enabled]);
    }

    public function hydrate(): void { $this->server(); }

    public function form(Schema $schema): Schema
    {
        $policy = NodePolicy::where('node_id', $this->server()->node_id)->first();
        return $schema->statePath('data')->components([
            Section::make('PortOpenerService')->description('Protocol choices are configured in the allocation table below. Changes are applied by the next service synchronization.')
                ->columns(2)->schema([
                    Select::make('mode')->label('Public access')->options(['off' => 'Off', 'running_only' => 'Only while running', 'always' => 'Always'])
                        ->required()->in(['off', 'running_only', 'always'])->native(false),
                    Toggle::make('ipv6')->label('Also allow IPv6')->disabled(!$policy?->ipv6_enabled || $policy?->connection_mode !== 'outbound')
                        ->helperText($policy?->ipv6_enabled ? 'Requires an IPv6 listener. Router verification may be unavailable.' : 'IPv6 is disabled on this node.'),
                ]),
        ]);
    }

    #[On('port-opener-save-forwarding.{serverId}')]
    public function save(): void
    {
        $data = $this->form->getState();
        app(ExposureService::class)->configure($this->server(), auth()->user(), $data['mode'], (bool) ($data['ipv6'] ?? false));
        Notification::make()->success()->title($data['mode'] === 'off' ? 'Port removal requested' : 'Forwarding changes queued')
            ->body('The service report confirms when router changes are complete.')->send();
    }

    public function render() { $this->server(); return view('port-opener::admin.server-forwarding-settings'); }
}
