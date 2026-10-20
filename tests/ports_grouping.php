<?php
/** Loaded by the fresh Pelican integration runner; synthetic cached reports only. */
use PortOpener\Filament\Admin\Components\PortsInfo;
use PortOpener\Services\StatusOverviewService;

$real = app(StatusOverviewService::class);
$row = ['node_id' => $node->id, 'node_name' => 'Home node', 'server_id' => $server->id,
    'server_uuid' => $server->uuid, 'server_name' => 'Valheim <script>', 'allocation_ip' => '192.168.178.50',
    'port' => 2456, 'protocol' => 'UDP', 'state' => 'mapped', 'message' => '<unsafe-router-message>',
    'allocation_id' => 1, 'mapping_id' => $server->uuid.':1:udp', 'freshness' => 'fresh'];
$fixture = ['nodes' => [['node_id' => $node->id, 'node_name' => 'Home node']], 'ports' => [
    $row, array_replace($row, ['port' => 2457, 'allocation_id' => 2, 'state' => 'error', 'freshness' => 'stale', 'mapping_id' => $server->uuid.':2:udp']),
    array_replace($row, ['allocation_ip' => '2001:db8::1', 'address_family' => 'IPv6', 'error_code' => 'ipv6_verification_denied', 'state' => 'requested']),
    array_replace($row, ['server_id' => null, 'server_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'server_name' => 'Deleted server', 'allocation_ip' => '192.168.178.60', 'port' => 25565, 'protocol' => 'TCP']),
]];
app()->instance(StatusOverviewService::class, new class($fixture) extends StatusOverviewService {
    public function __construct(private array $fixture) {}
    public function overview(): array { return $this->fixture; }
});
try {
    $ports = \Livewire\Livewire::test(PortsInfo::class);
    $groups = $ports->instance()->serverGroups();
    $check($groups->count() === 2 && count($groups->first()['ports']) === 3, 'one server entry groups IPv4 and IPv6 ports');
    $check(substr_count($ports->html(), 'aria-label="Edit ports for ') === 1, 'one header edit button for existing server, none for deleted server');
    $check(str_contains($ports->html(), 'Valheim &lt;script&gt;') && !str_contains($ports->html(), '<unsafe-router-message>'), 'server names and router details are escaped');
    $check(str_contains($ports->html(), 'Requested (unverified)'), 'IPv6 verification-denied state is preserved');
    $check(str_contains($ports->html(), 'Error (stale)'), 'stale cached state remains explicit');
    $moved = $fixture;
    $moved['ports'][] = array_replace($row, ['node_id' => 999, 'node_name' => 'Former node', 'orphan' => true]);
    $check($ports->instance()->serverGroups($moved)->first()['multiple_nodes'], 'moved-server observations retain source-node context within the server group');
    $filtered = $ports->set('search', ' 2457 ')->instance()->serverGroups();
    $check($filtered->count() === 1 && count($filtered->first()['ports']) === 3, 'nested port search finds parent and retains sibling ports');
    $check(str_contains($ports->html(), '2456') && str_contains($ports->html(), '2457') && !str_contains($ports->html(), '25565'), 'port search renders matching server context only');
    $check($ports->set('search', 'VALHEIM')->instance()->serverGroups()->count() === 1, 'server name search ignores case');
    $check($ports->set('search', '192.168.178.50')->instance()->serverGroups()->count() === 1, 'nested address search');
    $check($ports->set('search', 'home NODE')->instance()->serverGroups()->count() === 2, 'node name search ignores case');
    $groups = $ports->set('search', '')->set('stateFilter', 'error')->instance()->serverGroups();
    $check($groups->count() === 1 && count($groups->first()['ports']) === 1, 'state filter narrows child ports and omits empty servers');
    $check($ports->set('search', '2456')->instance()->serverGroups()->isEmpty(), 'search and port filters apply together');
    $groups = $ports->set('search', '')->set('stateFilter', '')->set('protocolFilter', 'TCP')->instance()->serverGroups();
    $check($groups->count() === 1 && $groups->first()['edit_url'] === null, 'deleted server ports remain visible without edit link');
    $check($ports->set('protocolFilter', '')->set('nodeFilter', '999999')->instance()->serverGroups()->isEmpty(), 'node filter excludes all unmatched servers');
    $check(str_contains($ports->html(), 'No matching servers'), 'empty result uses native section state');
    $ports->set('nodeFilter', '')->set('search', '   ');
    $check($ports->instance()->serverGroups()->count() === 2, 'whitespace search restores all servers');
    $check(str_contains($ports->html(), 'portopener%3A%3Adata%3A%3Atab'), 'single edit link opens server admin PortOpener tab');
} finally { app()->instance(StatusOverviewService::class, $real); }
