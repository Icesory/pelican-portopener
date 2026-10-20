<?php

namespace PortOpener\Filament\Admin\Pages;

use App\Enums\TablerIcon;
use BackedEnum;
use App\Models\Egg;
use App\Models\Node;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;
use PortOpener\Models\EggRule;
use PortOpener\Models\NodePolicy;
use PortOpener\Services\SyncService;
use PortOpener\Services\RouterConfiguration;
use PortOpener\Services\ServicePairing;
use PortOpener\Services\ServiceConfiguration;
use Livewire\Attributes\Locked;

class PortOpenerSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Router;
    protected string $view = 'port-opener::admin.settings';
    protected static ?string $navigationLabel = 'Port Opener';
    protected static ?string $title = 'Port Opener';

    public array $policy = ['ipv6_enabled' => false, 'ipv6_interface' => null, 'node_id' => '', 'enabled' => false, 'connection_mode' => 'outbound', 'service_url' => null, 'service_enabled' => false, 'service_dry_run' => true, 'lease_seconds' => 3600, 'reconcile_seconds' => 60, 'forwarding_type' => 'upnp', 'router_url' => null, 'internal_address' => '', 'min_port' => 1024, 'max_port' => 65535];
    #[Locked]
    public ?int $editingNodeId = null;
    public ?array $pairing = null;
    public string $newServiceToken = '';
    public bool $clearServiceToken = false;
    public ?array $routerTest = null;
    public array $rule = ['egg_id' => '', 'name' => '', 'source' => 'primary_allocation', 'variable_name' => '', 'allocation_alias' => '', 'protocols' => [], 'public' => false];

    public static function canAccess(): bool
    {
        return auth()->user()?->isRootAdmin() ?? false;
    }

    private function authorizeAdministrator(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function loadPolicy(): void
    {
        $this->authorizeAdministrator();
        abort_unless($this->editingNodeId !== null, 422);
        $row = NodePolicy::where('node_id', $this->editingNodeId)->first();
        $this->policy = [
            'ipv6_enabled' => (bool) ($row?->ipv6_enabled ?? false), 'ipv6_interface' => $row?->ipv6_interface, 'node_id' => $this->editingNodeId, 'enabled' => (bool) ($row?->enabled ?? false),
            'connection_mode' => $row?->connection_mode ?? 'outbound',
            'service_url' => $row?->service_url,
            'service_enabled' => (bool) ($row?->service_enabled ?? false), 'service_dry_run' => (bool) ($row?->service_dry_run ?? true),
            'lease_seconds' => $row?->lease_seconds ?? 3600, 'reconcile_seconds' => $row?->reconcile_seconds ?? 60,
            'forwarding_type' => $row?->forwarding_type ?? 'upnp', 'router_url' => $row?->router_url,
            'internal_address' => $row?->internal_address ?? '', 'min_port' => $row?->min_port ?? 1024, 'max_port' => $row?->max_port ?? 65535,
        ];
        $this->routerTest = null;
        $this->pairing = null;
        $this->newServiceToken = '';
        $this->clearServiceToken = false;
        $this->resetValidation();
    }

    public function editNode(int $nodeId): void
    {
        $this->authorizeAdministrator();
        Node::findOrFail($nodeId);
        $this->editingNodeId = $nodeId;
        $this->loadPolicy();
        $this->dispatch('open-modal', id: 'port-opener-node-config');
    }

    public function closeNode(): void
    {
        $this->authorizeAdministrator();
        $this->pairing = null;
        $this->routerTest = null;
        $this->editingNodeId = null;
        $this->newServiceToken = '';
        $this->clearServiceToken = false;
        $this->dispatch('close-modal', id: 'port-opener-node-config');
    }

    private function pairingOriginConfigured(): bool
    {
        $url = (string) config('app.url');
        if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
        $parts = parse_url($url);
        return $parts && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment'])
            && in_array($parts['path'] ?? '', ['', '/'], true);
    }

    private function persistedOutboundNode(bool $requireHttps = false): int
    {
        $this->authorizeAdministrator();
        if ($requireHttps && !$this->pairingOriginConfigured()) {
            throw ValidationException::withMessages(['pairing' => 'Configure the panel public URL as a valid HTTPS origin before generating a pairing code. HTTP pairing is not supported.']);
        }
        $row = NodePolicy::where('node_id', $this->editingNodeId)->first();
        if (!$row || $row->connection_mode !== 'outbound') {
            throw ValidationException::withMessages(['pairing' => 'Save this node in outbound mode before pairing.']);
        }
        return (int) $row->node_id;
    }

    public function generatePairing(): void
    {
        $node = $this->persistedOutboundNode(true);
        $this->issuePairing($node, false);
    }

    public function rotatePairing(): void
    {
        $node = $this->persistedOutboundNode(true);
        $this->issuePairing($node, true);
    }

    private function issuePairing(int $nodeId, bool $rotate): void
    {
        $this->resetValidation('pairing');
        $this->pairing = null;
        try {
            $result = $rotate ? app(ServicePairing::class)->rotate($nodeId) : app(ServicePairing::class)->generate($nodeId);
            $this->pairing = ['pairing_code' => $result['pairing_code'], 'expires_at' => $result['expires_at']];
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['pairing' => $exception->getMessage()]);
        }
    }

    public function revokePairing(): void
    {
        $node = $this->persistedOutboundNode();
        app(ServicePairing::class)->revoke($node);
        $this->pairing = null;
        Notification::make()->success()->title('Service credentials revoked')->send();
    }

    public function testConnection(): void
    {
        $this->authorizeAdministrator();
        abort_unless($this->editingNodeId !== null, 422);
        $this->policy['node_id'] = $this->editingNodeId;
        $this->validate(['policy.node_id' => 'required|integer|exists:nodes,id']);
        if (!NodePolicy::where('node_id', $this->policy['node_id'])->exists()) {
            throw ValidationException::withMessages(['policy.router_url' => 'Save this node configuration before testing.']);
        }
        try {
            $this->routerTest = app(SyncService::class)->testRouterForNode((int) $this->policy['node_id']);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['policy.router_url' => $exception->getMessage()]);
        }
    }

    public function savePolicy(): void
    {
        $this->authorizeAdministrator();
        abort_unless($this->editingNodeId !== null, 422);
        $this->policy['node_id'] = $this->editingNodeId;
        $data = $this->validate([
            'policy.node_id' => 'required|integer|exists:nodes,id',
            'policy.enabled' => 'required|boolean',
            'policy.ipv6_enabled' => 'required|boolean',
            'policy.ipv6_interface' => ['nullable', 'string', 'max:32', 'regex:/\A[a-zA-Z0-9_.:-]+\z/'],
            'policy.connection_mode' => 'required|in:legacy_local,outbound',
            'policy.service_url' => 'nullable|string|max:2048',
            'policy.service_enabled' => 'required|boolean', 'policy.service_dry_run' => 'required|boolean',
            'policy.lease_seconds' => 'required|integer|min:120|max:86400',
            'policy.reconcile_seconds' => 'required|integer|min:15|max:300',
            'policy.forwarding_type' => 'required|in:upnp',
            'policy.router_url' => 'required|string|max:2048',
            'policy.internal_address' => 'required|ipv4',
            'policy.min_port' => 'required|integer|min:1|max:65535',
            'policy.max_port' => 'required|integer|min:1|max:65535|gte:policy.min_port',
        ])['policy'];
        $data['ipv6_interface'] = $data['ipv6_interface'] ?: null;
        if ($data['ipv6_enabled'] && $data['connection_mode'] !== 'outbound') throw ValidationException::withMessages(['policy.ipv6_enabled' => 'IPv6 requires a paired outbound service.']);
        if ($data['reconcile_seconds'] > intdiv($data['lease_seconds'], 3)) {
            throw ValidationException::withMessages(['policy.reconcile_seconds' => 'The reconcile interval must be no more than one third of the mapping lease.']);
        }
        if ($data['connection_mode'] === 'legacy_local') {
            if (!empty($data['service_url']) && !ServiceConfiguration::validateServiceUrl($data['service_url'])) {
                throw ValidationException::withMessages(['policy.service_url' => 'Use an HTTP loopback/private address or a verified HTTPS service URL, without credentials, a query or a fragment.']);
            }
            $data['service_url'] = $data['service_url'] ?: null;
            $this->validate(['newServiceToken' => 'nullable|string|min:32|max:1024|regex:/\A[\x21-\x7E]+\z/', 'clearServiceToken' => 'boolean']);
            if ($this->clearServiceToken && $this->newServiceToken !== '') {
                throw ValidationException::withMessages(['newServiceToken' => 'Choose a replacement token or clear the override, not both.']);
            }
            if ($this->clearServiceToken) $data['service_token'] = null;
            elseif ($this->newServiceToken !== '') $data['service_token'] = $this->newServiceToken;
        } else {
            // Preserve optional legacy overrides, without exposing or replacing their credentials.
            unset($data['service_url']);
        }
        if (!RouterConfiguration::validateUrl($data['router_url'])) {
            throw ValidationException::withMessages(['policy.router_url' => 'Use an HTTP URL with a private LAN IPv4 address, a valid port and a path, without credentials, a query or a fragment.']);
        }
        if ($data['connection_mode'] === 'legacy_local' && ($data['enabled'] || $data['service_enabled']) && (int) $data['node_id'] !== (int) config('port-opener.node_id')) {
            throw ValidationException::withMessages(['policy.enabled' => 'This node has no connected PortOpenerService. Save its settings with public access disabled.']);
        }
        if ($data['connection_mode'] === 'legacy_local' && $data['enabled'] && NodePolicy::where('connection_mode', 'legacy_local')->where('enabled', true)->where('node_id', '!=', $data['node_id'])->exists()) {
            throw ValidationException::withMessages(['policy.enabled' => 'Disable the other node policy first. This version supports one enabled node.']);
        }
        NodePolicy::updateOrCreate(['node_id' => $data['node_id']], $data);
        $this->routerTest = null;
        $this->pairing = null;
        $this->newServiceToken = '';
        $this->clearServiceToken = false;
        $this->saved();
    }

    public function addRule(): void
    {
        $this->authorizeAdministrator();
        if (($this->rule['source'] ?? null) !== 'variable') $this->rule['variable_name'] = null;
        if (($this->rule['source'] ?? null) !== 'allocation_alias') {
            $this->rule['allocation_alias'] = null;
        } elseif (is_string($this->rule['allocation_alias'] ?? null)) {
            $this->rule['allocation_alias'] = trim($this->rule['allocation_alias']);
        }
        $data = $this->validate([
            'rule.egg_id' => 'required|integer|exists:eggs,id', 'rule.name' => 'required|string|max:100',
            'rule.source' => 'required|in:primary_allocation,variable,allocation_alias',
            'rule.variable_name' => 'nullable|required_if:rule.source,variable|string|max:191|regex:/^[A-Za-z_][A-Za-z0-9_]*$/',
            'rule.allocation_alias' => 'nullable|required_if:rule.source,allocation_alias|string|max:191',
            'rule.protocols' => 'required|array|min:1|max:2', 'rule.protocols.*' => 'required|in:TCP,UDP|distinct',
            'rule.public' => 'required|boolean',
        ])['rule'];
        if ($data['source'] === 'variable') {
            if (!Egg::findOrFail($data['egg_id'])->variables()->where('env_variable', $data['variable_name'])->exists()) {
                throw ValidationException::withMessages(['rule.variable_name' => 'Select a variable belonging to this egg.']);
            }
        } else {
            $data['variable_name'] = null;
        }
        EggRule::create($data);
        $this->rule['name'] = '';
        $this->rule['public'] = false;
        $this->saved();
    }

    public function deleteRule(int $id): void
    {
        $this->authorizeAdministrator();
        EggRule::findOrFail($id)->delete();
        $this->saved();
    }

    private function saved(): void
    {
        SyncService::queue();
        Notification::make()->success()->title('Configuration saved')->body('The next synchronization applies these settings.')->send();
    }

    protected function getViewData(): array
    {
        $this->authorizeAdministrator();
        if (!$this->pairingOriginConfigured()) $this->pairing = null;
        return [
            'nodes' => Node::orderBy('name')->get(['id', 'name']), 'eggs' => Egg::orderBy('name')->get(['id', 'name']),
            'nodePolicies' => NodePolicy::all()->keyBy('node_id'), 'connectedNodeId' => (int) config('port-opener.node_id'),
            'selectedNode' => $this->editingNodeId === null ? null : Node::find($this->editingNodeId),
            'selectedPolicy' => $this->editingNodeId === null ? null : NodePolicy::where('node_id', $this->editingNodeId)->first(),
            'panelUrl' => (string) config('app.url'),
            'pairingAvailable' => $this->pairingOriginConfigured(),
            'rules' => EggRule::orderBy('egg_id')->get(), 'status' => app(SyncService::class)->status(),
        ];
    }
}
