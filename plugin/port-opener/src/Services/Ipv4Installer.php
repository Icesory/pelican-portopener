<?php

namespace PortOpener\Services;

use App\Enums\{EggFormat, ServerState};
use App\Models\{Egg, Server, User};
use App\Services\Eggs\Sharing\EggImporterService;
use App\Services\Servers\ServerCreationService;
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PortOpener\Jobs\ProvisionIpv4Service;
use PortOpener\Models\{Ipv4Installation, NodeCredential, NodePolicy};

class Ipv4Installer
{
    private ?int $creatingNode = null;

    public function __construct(private Ipv4Release $release, private Ipv4Wings $wings) {}

    public function defersDaemonCreation(int $nodeId): bool { return $this->creatingNode === $nodeId; }

    public function assertCreationAllowed(array $data): void
    {
        if (Egg::find($data['egg_id'] ?? null)?->uuid !== Ipv4Release::EGG_UUID) return;
        if (!$this->creatingNode || (int) ($data['node_id'] ?? 0) !== $this->creatingNode) {
            throw ValidationException::withMessages(['egg_id' => 'Use Install IPv4 service on the PortOpenerService node to prevent duplicate services.']);
        }
    }

    public function request(int $nodeId, bool $reinstall = false): Ipv4Installation
    {
        abort_unless(auth()->user()?->isRootAdmin(), 403);
        if (config('queue.default') === 'sync') throw ValidationException::withMessages(['node_id' => 'Automatic installation requires an asynchronous queue worker.']);
        $this->release->environment();
        return DB::transaction(function () use ($nodeId, $reinstall) {
            // The existing unique node policy row serializes even the first request, before a slot exists.
            $policy = NodePolicy::where('node_id', $nodeId)->lockForUpdate()->firstOrFail();
            $this->validatePolicy($policy);
            $installation = Ipv4Installation::where('node_id', $nodeId)->first();
            if ($installation && $installation->pending()) {
                ProvisionIpv4Service::dispatch($nodeId, $installation->operation_id)->afterCommit();
                return $installation;
            }
            if ($installation && $installation->status === 'ready' && !$reinstall) return $installation;
            if (!$installation && (NodeCredential::where('node_id', $nodeId)->whereNull('revoked_at')->where('expires_at', '>', now())->exists()
                || collect(array_merge($policy->last_report['mappings'] ?? [], $policy->last_report['ipv6']['mappings'] ?? []))->contains(fn ($row) => ($row['state'] ?? '') !== 'removed'))) {
                throw ValidationException::withMessages(['node_id' => 'An existing host service or mapping ownership is present. Complete its handover before creating an Egg service.']);
            }
            $installation ??= new Ipv4Installation(['node_id' => $nodeId]);
            $resumeCreation = $installation->server_id && $installation->resume_phase === 'creating';
            $resumeReinstall = !$reinstall && $installation->server_id && $installation->resume_phase === 'reinstalling';
            $installation->forceFill(['requested_by' => auth()->id(), 'operation_id' => (string) Str::uuid(),
                'status' => $resumeCreation ? 'creating' : ($resumeReinstall ? 'installing' : 'queued'), 'mode' => $reinstall ? 'reinstall' : 'resume',
                'resume_phase' => null, 'started_at' => now(), 'service_started_at' => null, 'last_error' => null])->save();
            ProvisionIpv4Service::dispatch($nodeId, $installation->operation_id)->afterCommit();
            return $installation;
        });
    }

    private function validatePolicy(NodePolicy $policy): void
    {
        if ($policy->connection_mode !== 'outbound' || !$policy->service_enabled || $policy->ipv6_enabled
            || !RouterConfiguration::validateUrl((string) $policy->router_url)) {
            throw ValidationException::withMessages(['node_id' => 'Save an enabled outbound HTTPS node with a valid router URL and IPv6 disabled first.']);
        }
    }

    private function ensureEgg(): Egg
    {
        return Cache::lock('port-opener:ipv4:egg-import', 120)->block(5, function () {
            return app(EggImporterService::class)->fromContent(json_encode($this->release->egg(), JSON_THROW_ON_ERROR), EggFormat::JSON);
        });
    }

    private function verifyServer(Ipv4Installation $installation): Server
    {
        $server = Server::find($installation->server_id);
        if (!$server || (int) $server->node_id !== $installation->node_id || $server->egg->uuid !== Ipv4Release::EGG_UUID
            || $server->external_id !== 'portopener-ipv4-node-'.$installation->node_id
            || !User::find($server->owner_id)?->isRootAdmin() || $server->subusers()->exists()) {
            throw new \RuntimeException('Managed server identity, node or administrator ownership changed.');
        }
        return $server;
    }

    /** One bounded step per queue attempt; the operation ID makes older queued jobs harmless. */
    public function advance(int $nodeId, string $operationId): bool
    {
        return DB::transaction(function () use ($nodeId, $operationId) {
            $policy = NodePolicy::where('node_id', $nodeId)->lockForUpdate()->firstOrFail();
            $installation = Ipv4Installation::where('node_id', $nodeId)->lockForUpdate()->firstOrFail();
            if ($installation->operation_id !== $operationId || !$installation->pending()) return true;
            if ($installation->started_at->lt(now()->subMinutes(25))) throw new \RuntimeException('Provisioning timed out.');
            $this->validatePolicy($policy);
            $environment = $this->release->environment();
            if ($installation->status === 'queued') {
                if (!$installation->server_id) {
                    $egg = $this->ensureEgg();
                    $owner = User::findOrFail($installation->requested_by);
                    if (!$owner->isRootAdmin()) throw new \RuntimeException('Installing administrator no longer has access.');
                    $existing = Server::where('external_id', 'portopener-ipv4-node-'.$nodeId)->get();
                    if ($existing->count() > 1) throw new \RuntimeException('Duplicate managed server markers require administrator review.');
                    $server = $existing->first();
                    if (!$server) {
                        if (Server::where('node_id', $nodeId)->where('egg_id', $egg->id)->exists()) {
                            throw new \RuntimeException('An unmanaged server already uses the service Egg on this node.');
                        }
                        $this->creatingNode = $nodeId;
                        try {
                            $server = app(ServerCreationService::class)->handle([
                                'name' => 'PortOpenerService IPv4 · '.$policy->node_id, 'node_id' => $nodeId,
                                'external_id' => 'portopener-ipv4-node-'.$nodeId, 'owner_id' => $owner->id,
                                'egg_id' => $egg->id, 'allocation_id' => null, 'environment' => $environment,
                                'memory' => 128, 'swap' => 0, 'disk' => 256, 'cpu' => 25, 'io' => 500,
                                'oom_killer' => true, 'database_limit' => 0, 'allocation_limit' => 0,
                                'backup_limit' => 0, 'skip_scripts' => false, 'start_on_completion' => false,
                            ]);
                        } finally { $this->creatingNode = null; }
                    }
                    $installation->server_id = $server->id;
                    $installation->status = 'creating';
                    $installation->save();
                    $this->verifyServer($installation);
                } else {
                    $this->verifyServer($installation);
                    $installation->update(['status' => 'stopping']);
                }
                return false;
            }

            $server = $this->verifyServer($installation);
            if ($installation->status === 'creating') {
                $this->wings->ensureCreated($server);
                $installation->update(['status' => 'installing']);
                return false;
            }
            if ($installation->status === 'stopping') {
                $state = $this->wings->state($server);
                if ($state !== 'offline') {
                    if (in_array($state, ['running', 'starting'], true)) $this->wings->power($server, 'stop');
                    return false;
                }
                if ($installation->mode === 'reinstall') {
                    $egg = $this->ensureEgg();
                    foreach ($egg->variables as $variable) {
                        $server->serverVariables()->updateOrCreate(['variable_id' => $variable->id], ['variable_value' => $environment[$variable->env_variable]]);
                    }
                    $this->wings->sync($server);
                    $server->forceFill(['status' => ServerState::Installing])->save();
                    // Commit the installation marker before sending the reinstall request/callback.
                    $installation->update(['status' => 'reinstalling']);
                    return false;
                }
                $installation->update(['status' => 'installing']);
                return false;
            }

            if ($installation->status === 'reinstalling') {
                $this->wings->reinstall($server);
                $installation->update(['status' => 'installing']);
                return false;
            }

            if ($installation->status === 'installing') {
                $status = $server->getRawOriginal('status');
                if ($status === 'installing') return false;
                if ($status !== null) throw new \RuntimeException('Egg installation did not finish successfully.');
                $installation->update(['status' => 'pairing']);
                return false;
            }

            if ($installation->status === 'pairing') {
                $state = $this->wings->state($server);
                if ($state !== 'offline') {
                    if (in_array($state, ['running', 'starting'], true)) $this->wings->power($server, 'stop');
                    return false;
                }
                $files = $this->wings->files($server, '/data');
                if (!in_array('credentials.json', $files, true)) {
                    if (array_intersect(['service.json', 'state.json'], $files)) throw new \RuntimeException('Existing data without credentials requires explicit recovery.');
                    $pairing = app(ServicePairing::class)->generateForInstallation($installation);
                    $this->wings->bootstrap($server, [
                        'panel_url' => $this->release->origin(), 'node_id' => $nodeId,
                        'installation_id' => $policy->installation_id, 'pairing_code' => $pairing['pairing_code'],
                        'expires_at' => $pairing['expires_at'],
                    ]);
                }
                // Existing credentials/config/state are never replaced or read through the panel.
                $installation->update(['status' => 'starting', 'service_started_at' => now()]);
                $this->wings->power($server, 'start');
                return false;
            }

            if ($installation->status === 'starting') {
                // The previous process can report after a reinstall request but before it is stopped.
                // Require a heartbeat after this start, not merely after the operation was requested.
                if ($installation->service_started_at && $policy->last_contact_at && $policy->last_contact_at->gt($installation->service_started_at)
                    && $this->wings->state($server) === 'running') {
                    $installation->update(['status' => 'ready', 'last_error' => null]);
                    return true;
                }
                return false;
            }
            throw new \RuntimeException('Unknown installation phase.');
        });
    }

    public function failed(int $nodeId, string $operationId): void
    {
        DB::transaction(function () use ($nodeId, $operationId) {
            $installation = Ipv4Installation::where('node_id', $nodeId)->where('operation_id', $operationId)->lockForUpdate()->first();
            if (!$installation || !$installation->pending()) return;
            $phase = $installation->status;
            $installation->update(['status' => 'failed', 'resume_phase' => $phase, 'last_error' => 'Setup failed during '.$phase.'. Check the service console, Wings installation log, saved node policy and queue worker. Resume setup, or reinstall after an installation failure. Existing data is retained.']);
        });
    }
}
