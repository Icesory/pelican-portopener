<x-filament-panels::page>
    @include('port-opener::partials.form-colors')
    @if(($status['backend'] ?? null) === 'fake')
        <x-filament::section heading="Simulation — fake router">
            <p role="status">These mappings are simulated. No real router ports are opened, even when the status says mapped.</p>
        </x-filament::section>
    @endif
    <x-filament::section heading="PortOpenerService">
        <p>Configure PortOpenerService for each Wings node below. Outbound HTTPS keeps the service private and authenticates it with a short-lived pairing code. Existing servers remain private until their owner enables exposure.</p>
        <p class="mt-2">Mode: {{ ($status['dry_run'] ?? true) ? 'Test mode' : 'Router changes enabled' }}. Last reconciliation: {{ $status['last_reconciled_at'] ?? 'Not yet reconciled' }}.</p>
        <p class="mt-2">Router mapping status does not confirm external connectivity.</p>
    </x-filament::section>
    <x-filament::section heading="Wings nodes">
        <p class="mb-4">Configure and pair each node independently. Outbound services connect to this panel over HTTPS. Legacy local mode uses the protected endpoint configured on the panel server and supports node {{ $connectedNodeId ?: 'not configured' }}.</p>
        <div class="overflow-x-auto"><table class="w-full text-left"><caption class="sr-only">Wings nodes and individual port opener settings</caption><thead><tr><th scope="col" class="p-2">Node</th><th scope="col" class="p-2">Forwarding type</th><th scope="col" class="p-2">Router URL</th><th scope="col" class="p-2">Host LAN IP</th><th scope="col" class="p-2">Status</th><th scope="col" class="p-2">Action</th></tr></thead><tbody>
            @forelse($nodes as $node)
                @php($nodePolicy = $nodePolicies->get($node->id))
                <tr><td class="p-2">{{ $node->name }}</td><td class="p-2">{{ ($nodePolicy?->forwarding_type ?? 'upnp') === 'upnp' ? 'UPnP' : $nodePolicy->forwarding_type }}</td><td class="p-2">{{ $nodePolicy?->router_url ?? 'Not configured' }}</td><td class="p-2">{{ $nodePolicy?->internal_address ?? 'Not configured' }}</td><td class="p-2">@if(!$nodePolicy)Not configured @else{{ $nodePolicy->connection_mode === 'outbound' ? 'Outbound HTTPS' : 'Legacy local' }}<br>{{ $nodePolicy->service_enabled ? 'Service enabled' : 'Service disabled' }}<br>Last contact: {{ $nodePolicy->last_contact_at?->toIso8601String() ?? 'Not yet connected' }}@endif</td><td class="p-2"><x-filament::button size="sm" wire:click="editNode({{ $node->id }})" wire:loading.attr="disabled">Configure</x-filament::button></td></tr>
            @empty<tr><td colspan="6" class="p-2">No Wings nodes are configured in Pelican.</td></tr>@endforelse
        </tbody></table></div>
    </x-filament::section>
    <x-filament::modal id="port-opener-node-config" width="3xl" heading="PortOpenerService configuration" :close-button="false" :close-by-clicking-away="false" :close-by-escaping="false">
        <p class="mb-4"><strong>Node: {{ $selectedNode?->name ?? 'Select Configure beside a Wings node' }}</strong>. Settings apply only to this node.</p>
        <form wire:submit="savePolicy" class="space-y-4">
            <label class="block">Service connection <select wire:model.live="policy.connection_mode" class="port-opener-select block w-full rounded border p-2"><option value="outbound">Outbound HTTPS — recommended</option><option value="legacy_local">Local legacy endpoint</option></select></label>
            <label class="block">Panel URL <input type="url" readonly value="{{ $panelUrl }}" class="block w-full rounded border p-2"></label>
            <label class="block">Installation ID <input type="text" readonly value="{{ $selectedPolicy?->installation_id ?? 'Generated when the configuration is saved' }}" class="block w-full rounded border p-2"></label>
            @if($policy['connection_mode'] === 'legacy_local')
                <p>Optional database overrides take precedence over the panel server's environment configuration. Leave the URL blank and clear the token override to use environment settings. Stored tokens are never displayed.</p>
                <label class="block">Legacy service endpoint <input wire:model="policy.service_url" type="url" placeholder="http://127.0.0.1:8787" class="block w-full rounded border p-2"></label>
                <label class="block">Replacement service token <input wire:model="newServiceToken" type="password" autocomplete="new-password" class="block w-full rounded border p-2"></label>
                <p class="text-sm">Leave the token field blank to retain its stored override. A replacement must contain 32–1024 printable ASCII characters without whitespace; it is stored encrypted.</p>
                <label class="block"><input wire:model="clearServiceToken" type="checkbox"> Clear stored token override and use the environment token</label>
                @error('newServiceToken')<p role="alert" class="text-danger-600">{{ $message }}</p>@enderror
                @error('clearServiceToken')<p role="alert" class="text-danger-600">{{ $message }}</p>@enderror
            @else<p>The service connects outbound to the panel. No public listener or long-lived token needs to be pasted here. Save these settings, then generate a pairing code below.</p>@endif
            <label class="block"><input wire:model="policy.service_enabled" type="checkbox"> Enable PortOpenerService for this node</label>
            @if($policy['connection_mode'] === 'outbound')
                <label class="block"><input wire:model="policy.ipv6_enabled" type="checkbox"> Allow opt-in IPv6 firewall rules on this node</label>
                <label class="block">IPv6 network interface (optional)<input wire:model="policy.ipv6_interface" class="block w-full rounded border p-2" placeholder="Automatic, or enp4s0"></label>
                <p class="text-sm">The service detects a non-temporary global IPv6 address locally and follows address changes. Each game server must also approve IPv6, and a host IPv6 listener must exist. DNS stays externally managed.</p>
                <p class="text-sm">Detected IPv6: {{ $selectedPolicy?->last_report['ipv6']['address'] ?? 'Not yet detected' }}. State: {{ $selectedPolicy?->last_report['ipv6']['state'] ?? 'Not yet reported' }}.</p>
            @endif
            <p class="text-sm text-gray-600 dark:text-gray-400">For immediate removal, turn off managed public access and wait for the mappings to be removed before disabling or revoking the service. Authentication shutdown pauses renewal; existing finite leases can remain until expiry.</p>
            @if($policy['connection_mode'] === 'outbound')
                <label class="block"><input wire:model="policy.service_dry_run" type="checkbox"> Test mode — do not change router mappings</label>
            @else
                <p class="text-sm">Legacy test mode is controlled by the local daemon configuration/environment. This form does not change that daemon setting. Use outbound mode to manage test mode from the panel.</p>
            @endif
            <label class="block">Forwarding type <select wire:model="policy.forwarding_type" class="port-opener-select block w-full rounded border p-2"><option value="upnp">UPnP</option></select></label>
            <label class="block">Router description URL <input wire:model="policy.router_url" type="url" placeholder="http://192.168.178.1:49000/igddesc.xml" class="block w-full rounded border p-2"></label>
            <p class="text-sm">Enter the router's UPnP description URL using its private LAN IPv4 address. PortOpenerService validates the destination. Saving settings does not switch a simulated service to a real router.</p>
            <label class="block">Host LAN IPv4 address <input wire:model="policy.internal_address" type="text" placeholder="192.168.178.50" class="block w-full rounded border p-2"></label>
            <div class="flex gap-4"><label>First allowed port <input wire:model="policy.min_port" type="number" min="1" max="65535" class="block rounded border p-2"></label><label>Last allowed port <input wire:model="policy.max_port" type="number" min="1" max="65535" class="block rounded border p-2"></label></div>
            <p class="text-sm">Pairing pins the destination address and initial allowed port range on the service. You can narrow that range here. Changing the destination or expanding the range requires verified mapping cleanup and an operator review of the local service configuration; the service rejects changes outside its local policy until that review is complete.</p>
            <div class="flex gap-4"><label>Mapping lease (seconds) <input wire:model="policy.lease_seconds" type="number" min="120" max="86400" class="block rounded border p-2"></label><label>Reconcile interval (seconds) <input wire:model="policy.reconcile_seconds" type="number" min="15" max="300" class="block rounded border p-2"></label></div>
            <p class="text-sm">The reconcile interval must be no more than one third of the mapping lease. These settings are applied by the service within any operator-defined local safety limits.</p>
            <label class="block"><input wire:model="policy.enabled" type="checkbox"> Allow managed public access on this node</label>
            @foreach($errors->get('policy.*') as $messages)@foreach($messages as $message)<p role="alert" class="text-danger-600">{{ $message }}</p>@endforeach@endforeach
            <x-filament::button type="submit" wire:loading.attr="disabled">Save node policy</x-filament::button>
            <x-filament::button type="button" color="gray" wire:click="testConnection" wire:loading.attr="disabled">Test saved connection</x-filament::button>
            <p class="text-sm">Save changes before testing. The connection test uses the persisted configuration and does not open ports.</p>
            @if($routerTest)
                <p role="status">{{ ($routerTest['backend'] ?? null) === 'outbound' ? 'Connection test queued.' : (($routerTest['backend'] ?? null) === 'fake' ? 'Simulation:' : (($routerTest['router_reachable'] ?? false) ? 'Connection succeeded.' : 'Connection failed.')) }} {{ $routerTest['message'] ?? '' }}</p>
            @endif
        </form>
        @if($policy['connection_mode'] === 'outbound')
            <div class="mt-6 space-y-3">
                <h3 class="font-semibold">Service pairing</h3>
                @if(!$pairingAvailable)<p role="alert" class="text-warning-600">Pairing requires a valid HTTPS panel URL. Configure the panel's public URL as an HTTPS origin before generating or rotating a pairing code. The current URL cannot be used for secure pairing.</p>@endif
                <p>Generate a short-lived, one-time code and enter it in the service's pairing prompt on this Wings host. Keep the code private. Long-lived credentials stay on the service and are never displayed here.</p>
                <p>Save with the service enabled before pairing. From the service directory on this Wings host, run the command below. Replace <code>/protected</code> with a private directory owned by the service account. HTTPS certificate verification is required.</p>
                @if($pairingAvailable)
                    <pre class="overflow-x-auto rounded border p-3"><code>python3 port_opener.py pair --panel {{ escapeshellarg($panelUrl) }} --credentials /protected/credentials.json --config /protected/service.json</code></pre>
                    <p>The prompt hides the pairing code as you enter it. Then start the service with <code>python3 port_opener.py run --config /protected/service.json</code>. Existing credential/configuration files are not overwritten.</p>
                @endif
                @error('pairing')<p role="alert" class="text-danger-600">{{ $message }}</p>@enderror
                <div class="flex flex-wrap gap-3">
                    <x-filament::button type="button" wire:click="generatePairing" wire:loading.attr="disabled" :disabled="!$pairingAvailable">Generate pairing code</x-filament::button>
                    <x-filament::button type="button" color="warning" wire:click="rotatePairing" wire:confirm="Revoke existing service credentials and generate a new pairing code? The service must pair again." wire:loading.attr="disabled" :disabled="!$pairingAvailable">Rotate credentials</x-filament::button>
                    <x-filament::button type="button" color="danger" wire:click="revokePairing" wire:confirm="Revoke this node's service credentials? Its service will lose panel access until paired again." wire:loading.attr="disabled">Revoke credentials</x-filament::button>
                </div>
                @if($pairing)
                    <p>One-time pairing code: <strong>{{ $pairing['pairing_code'] }}</strong></p>
                    <p>Expires: {{ $pairing['expires_at'] }}. The code is hidden when this dialog closes.</p>
                @endif
                <p>Last contact: {{ $selectedPolicy?->last_contact_at?->toIso8601String() ?? 'Not yet connected' }}</p>
                <p>Credential expiry: {{ $selectedPolicy?->credential_expires_at ?? 'No active credential information' }}</p>
                <p>Reported mappings: {{ count($selectedPolicy?->last_report['mappings'] ?? []) }}</p>
            </div>
        @endif
        <div class="mt-6"><x-filament::button type="button" color="gray" wire:click="closeNode" wire:loading.attr="disabled">Close configuration</x-filament::button></div>
    </x-filament::modal>
    <x-filament::section heading="Egg port definitions">
        <p class="mb-4">Specify TCP, UDP, or both explicitly. Variable ports must resolve to an allocation assigned to the server. Approve administrative ports such as RCON only when intended.</p>
        <form wire:submit="addRule" class="space-y-4">
            <label class="block">Egg <select wire:model="rule.egg_id" class="port-opener-select block w-full rounded border p-2"><option value="">Choose an egg</option>@foreach($eggs as $egg)<option value="{{ $egg->id }}">{{ $egg->name }}</option>@endforeach</select></label>
            <label class="block">Service name <input wire:model="rule.name" type="text" maxlength="100" placeholder="Game connection" class="block w-full rounded border p-2"></label>
            <label class="block">Port source <select wire:model.live="rule.source" class="port-opener-select block w-full rounded border p-2"><option value="primary_allocation">Primary allocation</option><option value="variable">Egg variable</option><option value="allocation_alias">Allocation alias</option></select></label>
            @if($rule['source'] === 'variable')<label class="block">Variable name <input wire:model="rule.variable_name" type="text" placeholder="QUERY_PORT" class="block w-full rounded border p-2"></label>@endif
            @if($rule['source'] === 'allocation_alias')
                <label class="block">Allocation alias <input wire:model="rule.allocation_alias" type="text" maxlength="191" placeholder="Valheim" class="block w-full rounded border p-2"></label>
                <p class="text-sm">Matches every allocation assigned to this server with exactly this alias. For example, give ports 2456 and 2457 the alias Valheim to approve both using one UDP definition. Allocations belonging to other servers are excluded.</p>
            @endif
            <fieldset><legend>Protocols</legend><label class="mr-4"><input wire:model="rule.protocols" type="checkbox" value="TCP"> TCP</label><label><input wire:model="rule.protocols" type="checkbox" value="UDP"> UDP</label></fieldset>
            <label class="block"><input wire:model="rule.public" type="checkbox"> Approve this service for public exposure</label>
            @foreach($errors->get('rule.*') as $messages)@foreach($messages as $message)<p role="alert" class="text-danger-600">{{ $message }}</p>@endforeach@endforeach
            <x-filament::button type="submit" wire:loading.attr="disabled">Add definition</x-filament::button>
        </form>
        <div class="mt-6 overflow-x-auto"><table class="w-full text-left"><caption class="sr-only">Configured egg port definitions</caption><thead><tr><th scope="col" class="p-2">Egg</th><th scope="col" class="p-2">Service</th><th scope="col" class="p-2">Source</th><th scope="col" class="p-2">Protocols</th><th scope="col" class="p-2">Approved</th><th scope="col" class="p-2">Action</th></tr></thead><tbody>
            @forelse($rules as $definition)<tr><td class="p-2">{{ $eggs->firstWhere('id', $definition->egg_id)?->name ?? 'Deleted egg' }}</td><td class="p-2">{{ $definition->name }}</td><td class="p-2">{{ $definition->source === 'allocation_alias' ? 'Alias: ' . $definition->allocation_alias : ($definition->source === 'variable' ? $definition->variable_name : 'Primary allocation') }}</td><td class="p-2">{{ implode(', ', $definition->protocols) }}</td><td class="p-2">{{ $definition->public ? 'Yes' : 'No' }}</td><td class="p-2"><x-filament::button size="sm" color="danger" wire:click="deleteRule({{ $definition->id }})" wire:confirm="Delete this definition? Its managed mappings will be removed during synchronization." wire:loading.attr="disabled">Delete</x-filament::button></td></tr>
            @empty<tr><td colspan="6" class="p-2">No egg definitions yet.</td></tr>@endforelse
        </tbody></table></div>
        <p class="mt-4">Owners preview the resolved ports on each server's Network → PortOpenerService section before enabling access.</p>
    </x-filament::section>
</x-filament-panels::page>
