<?php

namespace PortOpener\Filament\Admin\Pages;

use App\Models\Node;
use App\Filament\Admin\Resources\Nodes\NodeResource;
use Filament\Actions\Action;
use Filament\Forms\Components\{Hidden, Select, TextInput, Toggle};
use Filament\Schemas\Components\{Actions, Section, Text};
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use PortOpener\Models\NodePolicy;
use PortOpener\Models\Ipv4Installation;
use PortOpener\Services\Ipv4Installer;
use Filament\Notifications\Notification;
use PortOpener\Services\{NodeHealth, NodeModes, NodeDiagnostics};

/** Reuse the existing pairing/validation boundary, with native Filament tables and actions. */
class PortOpenerHub extends PortOpenerSettings implements HasTable
{
    use InteractsWithTable;
    protected static ?string $slug = 'port-opener-settings';
    protected static ?string $navigationLabel = 'PortOpener';
    protected static ?string $title = 'PortOpener';
    protected string $view = 'port-opener::admin.hub';
    #[Url(as: 'view')] public string $activeTab = 'overview';

    public function mount(): void { abort_unless(static::canAccess(), 403); }
    public function hydrate(): void { abort_unless(static::canAccess(), 403); }

    public static function getNavigationGroup(): ?string
    {
        return NodeResource::getNavigationGroup();
    }

    public static function getNavigationBadge(): ?string
    {
        return static::canAccess() ? (string) NodeHealth::functionalServiceCount() : null;
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'Connected services with a working router';
    }

    private function addNodeAction(): Action
    {
        return Action::make('addNode')->label('Add node')->icon('tabler-plus')->iconButton()->tooltip('Add node')->modalHeading('Add PortOpenerService node')
            ->modalDescription('Choose an existing Wings node. Save its configuration, then open Configure to pair the service.')->modalSubmitActionLabel('Save configuration')
            ->fillForm(function () { $this->editingNodeId = null; $this->pairing = null; return $this->defaults(); })
            ->schema(fn () => $this->nodeSchema(true))->action(fn (array $data) => $this->saveNodeForm($data, true));
    }

    public function table(Table $table): Table
    {
        return $table->records(fn (?string $search) => collect(NodeHealth::rows())->where('has_policy', true)
            ->when(filled(trim($search ?? '')), fn ($rows) => $rows->filter(fn (array $row) => mb_stripos($row['node_name'], trim($search)) !== false))
            ->keyBy('node_id')->all())
            ->searchable()->toolbarActions([$this->addNodeAction()])
            ->paginated(false)->poll('15s')->columns([
                TextColumn::make('node_name')->label('Node'),
                TextColumn::make('health.label')->label('State')->badge()->color(fn (array $record) => $record['health']['color'])
                    ->description(fn (array $record) => $record['error'] ?? ($record['configuration_pending'] ? 'Router check pending' : ($record['router_reachable'] ? 'Router reachable'.match ($record['router_service'] ?? null) {
                        'urn:schemas-upnp-org:service:WANIPConnection:2' => ' · IGD2',
                        'urn:schemas-upnp-org:service:WANIPConnection:1', 'urn:schemas-upnp-org:service:WANPPPConnection:1' => ' · IGD1',
                        default => '',
                    } : 'Router not verified'))),
                TextColumn::make('mode_label')->label('Forwarding mode')->badge(),
                TextColumn::make('mapping_count')->label('Mapped IPv4'),
                TextColumn::make('ipv6_mapping_count')->label('Mapped IPv6')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_sync')->label('Last contact')->since()->placeholder('Never'),
                TextColumn::make('ipv4_installation')->label('IPv4 service')->state(fn (array $record) => Ipv4Installation::where('node_id', $record['node_id'])->first()?->status ?? 'Not installed')
                    ->description(fn (array $record) => Ipv4Installation::where('node_id', $record['node_id'])->first()?->last_error),
            ])->recordActions([
                Action::make('installIpv4')->label(fn (array $record) => Ipv4Installation::where('node_id', $record['node_id'])->exists() ? 'Resume IPv4 setup' : 'Install IPv4 service')
                    ->icon('tabler-download')->visible(fn (array $record) => !in_array(Ipv4Installation::where('node_id', $record['node_id'])->first()?->status, ['ready'], true))
                    ->action(fn (array $record) => $this->queueIpv4((int) $record['node_id'])),
                Action::make('reinstallIpv4')->label('Reinstall IPv4 service')->icon('tabler-refresh')->requiresConfirmation()
                    ->modalDescription('Stops and reinstalls the existing service server. Pairing credentials, configuration and mapping ownership state are retained.')
                    ->visible(fn (array $record) => (bool) Ipv4Installation::where('node_id', $record['node_id'])->first()?->server_id)
                    ->action(fn (array $record) => $this->queueIpv4((int) $record['node_id'], true)),
                Action::make('configure')->label('Configure')->icon('tabler-pencil')->iconButton()->tooltip('Configure')->modalHeading(fn (array $record) => $record['node_name'].' · PortOpenerService')
                    ->fillForm(function (array $record) {
                        $this->editingNodeId = (int) $record['node_id'];
                        $this->loadPolicy();
                        return $this->policy + ['forwarding_mode' => NodeModes::current(NodePolicy::where('node_id', $this->editingNodeId)->first())];
                    })->modalSubmitActionLabel('Save configuration')->schema(fn () => $this->nodeSchema(false))->action(fn (array $data) => $this->saveNodeForm($data, false)),
            ])->emptyStateHeading(fn () => filled(trim($this->getTableSearch() ?? '')) ? 'No matching nodes' : 'No PortOpenerService nodes configured')
            ->emptyStateDescription(fn () => filled(trim($this->getTableSearch() ?? '')) ? 'Try another node name or clear the search.' : 'Use Add node to configure an existing Wings node.');
    }

    private function defaults(): array
    {
        return ['node_id' => null, 'forwarding_mode' => 'preview', 'connection_mode' => 'outbound', 'router_url' => null, 'internal_address' => null,
            'min_port' => 1024, 'max_port' => 65535, 'lease_seconds' => 3600, 'reconcile_seconds' => 60, 'ipv6_enabled' => false, 'ipv6_interface' => null];
    }

    private function queueIpv4(int $nodeId, bool $reinstall = false): void
    {
        abort_unless(static::canAccess(), 403);
        try {
            app(Ipv4Installer::class)->request($nodeId, $reinstall);
            Notification::make()->title($reinstall ? 'IPv4 service reinstall queued' : 'IPv4 service setup queued')
                ->body('The node status updates automatically.')->success()->send();
        } catch (ValidationException $exception) {
            Notification::make()->title('IPv4 service setup needs configuration')
                ->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
        } catch (\Throwable $exception) {
            Notification::make()->title('IPv4 service setup could not be queued')
                ->body('Check the bundled release, panel HTTPS URL, migrations and queue configuration.')->danger()->send();
        }
    }

    private function nodeSchema(bool $adding): array
    {
        return [
            $adding ? Select::make('node_id')->label('Wings node')->required()->searchable()->preload()
                ->options(fn () => Node::whereNotIn('id', NodePolicy::select('node_id'))->orderBy('name')->pluck('name', 'id')) : Hidden::make('node_id'),
            Section::make('Forwarding')->columns(2)->schema([
                Select::make('forwarding_mode')->label('Mode')->options(['paused' => 'Paused', 'preview' => 'Test mode', 'active' => 'Active'])
                    ->required()->in(['paused', 'preview', 'active'])->native(false)
                    ->helperText('Test mode checks approved ports and router conflicts without opening or renewing ports. Active ports are closed first.'),
                Text::make('method')->content('Method: UPnP'),
                TextInput::make('router_url')->label('Router UPnP URL')->placeholder('http://192.168.178.1:49000/igd2desc.xml')->required()->maxLength(2048)
                    ->hintAction(Action::make('findRouterUrl')->label('Find router URL')->icon('tabler-search')
                        ->modalHeading('Find the router UPnP URL')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                        ->modalContent(fn () => view('port-opener::admin.router-discovery'))),
                TextInput::make('internal_address')->label('Wings host LAN IPv4')->ip()->required(),
            ]),
            Section::make('Optional IPv6')->collapsed()->schema([
                Toggle::make('ipv6_enabled')->label('Allow servers to opt in to IPv6')->helperText('Each server needs separate approval. DNS is managed externally.'),
                TextInput::make('ipv6_interface')->label('Network interface')->placeholder('Automatic')->maxLength(32),
            ]),
            Section::make('Advanced configuration')->collapsed()->columns(2)->schema([
                TextInput::make('min_port')->label('First allowed port')->numeric()->minValue(1)->maxValue(65535)->required(),
                TextInput::make('max_port')->label('Last allowed port')->numeric()->minValue(1)->maxValue(65535)->required(),
                TextInput::make('lease_seconds')->label('IPv4 lease (seconds)')->numeric()->minValue(120)->maxValue(86400)->required(),
                TextInput::make('reconcile_seconds')->label('Reconcile interval (seconds)')->numeric()->minValue(15)->maxValue(300)->required(),
                Select::make('connection_mode')->label('Service connection')->options(['outbound' => 'Paired outbound HTTPS', 'legacy_local' => 'Legacy local API'])->required()->live()->native(false),
                TextInput::make('service_url')->label('Legacy endpoint override')->visible(fn ($get) => $get('connection_mode') === 'legacy_local')->maxLength(2048),
                TextInput::make('new_token')->label('Replacement legacy token')->password()->autocomplete('new-password')->visible(fn ($get) => $get('connection_mode') === 'legacy_local'),
                Toggle::make('clear_token')->label('Clear legacy token override')->visible(fn ($get) => $get('connection_mode') === 'legacy_local'),
                Text::make('limits')->content('Local service limits remain authoritative. Clean up mappings before changing router or destination. Legacy test mode is controlled on the service.')->columnSpanFull(),
            ]),
            Section::make('Service connection and maintenance')->collapsed()->visible(!$adding)->schema([
                Text::make('connection')->content(fn () => 'Last contact: '.(NodePolicy::where('node_id', $this->editingNodeId)->first()?->last_contact_at?->diffForHumans() ?? 'Not connected')),
                Actions::make([
                    Action::make('checkRouter')->label('Check connection')->action(function () { $this->testConnection(); }),
                    Action::make('pairService')->label('Generate pairing code')->action(function () { $this->generatePairing(); }),
                    Action::make('rotateService')->label('Reconnect service')->color('warning')->requiresConfirmation()->modalDescription('Existing credentials will be revoked. Keep the service ownership state and pair it again.')->action(function () { $this->rotatePairing(); }),
                    Action::make('revokeService')->label('Revoke credentials')->color('danger')->requiresConfirmation()->modalDescription('Authentication stops renewal. Existing mappings can remain until their leases expire. Pause forwarding and verify cleanup first when closure is needed.')->action(function () { $this->revokePairing(); }),
                ]),
                Text::make('test_result')->content(fn () => $this->routerTest ? (($this->routerTest['queued'] ?? false) ? 'Connection check queued.' : ($this->routerTest['message'] ?? 'Check requested.')) : ''),
                Text::make('pairing_code')->content(fn () => $this->pairing ? new HtmlString('<p>One-time pairing code: <code>'.e($this->pairing['pairing_code']).'</code></p><p>Expires: '.e($this->pairing['expires_at']).'</p>') : ''),
                Text::make('pairing_command')->content(fn () => new HtmlString('<p>On the Wings host, run this command and enter the code in its private prompt:</p><pre class="overflow-x-auto">'.e('python3 port_opener.py pair --panel '.escapeshellarg((string) config('app.url')).' --credentials /protected/credentials.json --config /protected/service.json').'</pre>')),
            ]),
            Section::make('Diagnostics')->collapsed()->visible(!$adding)->schema([
                Text::make('diagnostics_status')->content(fn () => $this->editingNodeId ? app(NodeDiagnostics::class)->summary($this->editingNodeId) : ''),
                Text::make('diagnostics_privacy')->content('Download a JSON report of cached status and sanitized service events. Addresses, URLs, names, tokens and raw configuration are excluded. Review the download before sharing it. Debug mode adds per-port checks for 15 minutes and expires on the service even if the panel is unavailable.'),
                Actions::make([
                    Action::make('downloadDiagnostics')->label('Download diagnostics')->icon('tabler-download')
                        ->action(fn () => app(NodeDiagnostics::class)->download((int)$this->editingNodeId)),
                    Action::make('enableDebug')->label('Enable debug (15 minutes)')->icon('tabler-bug')
                        ->action(function () {
                            app(NodeDiagnostics::class)->enable((int)$this->editingNodeId);
                            Notification::make()->title('Debug mode requested for 15 minutes')->body('Applies on the next service poll.')->success()->send();
                        }),
                    Action::make('disableDebug')->label('Stop debug')->icon('tabler-player-stop')
                        ->action(function () {
                            app(NodeDiagnostics::class)->disable((int)$this->editingNodeId);
                            Notification::make()->title('Debug stop requested')->body('Applies on the next service poll.')->success()->send();
                        }),
                ]),
            ]),
        ];
    }

    private function saveNodeForm(array $data, bool $adding): void
    {
        abort_unless(static::canAccess(), 403);
        $nodeId = $adding ? (int) ($data['node_id'] ?? 0) : $this->editingNodeId;
        Node::findOrFail($nodeId);
        if ($adding && NodePolicy::where('node_id', $nodeId)->exists()) throw ValidationException::withMessages(['node_id' => 'This node is already configured.']);
        $old = NodePolicy::where('node_id', $nodeId)->first();
        if ($old && ($old->router_url !== $data['router_url'] || $old->internal_address !== $data['internal_address']) && collect(array_merge($old->last_report['mappings'] ?? [], $old->last_report['ipv6']['mappings'] ?? []))->contains(fn ($row) => ($row['state'] ?? '') !== 'removed')) {
            throw ValidationException::withMessages(['router_url' => 'Pause forwarding and wait for cleanup before changing the router.']);
        }
        $this->editingNodeId = $nodeId;
        $this->loadPolicy();
        $prepared = NodeModes::prepare(array_replace($this->policy, array_intersect_key($data, $this->policy)), $old, $data['forwarding_mode']);
        if (($prepared['connection_mode'] ?? '') === 'legacy_local') {
            if ($data['forwarding_mode'] === 'preview') throw ValidationException::withMessages(['forwarding_mode' => 'Legacy test mode is controlled by the local service. Choose Paused or Active here.']);
            $prepared['pending_mode'] = null;
            $prepared['service_dry_run'] = $old?->service_dry_run ?? true;
        }
        $this->policy = $prepared;
        $this->newServiceToken = $data['new_token'] ?? '';
        $this->clearServiceToken = (bool) ($data['clear_token'] ?? false);
        DB::transaction(function () use ($prepared) {
            $this->savePolicy();
            NodePolicy::where('node_id', $this->editingNodeId)->update(['pending_mode' => $prepared['pending_mode']]);
        });
    }

    protected function getViewData(): array { abort_unless(static::canAccess(), 403); return []; }
}
