<?php
namespace PortOpener\Services;
use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
class RuntimeStateProvider {
    public function state(Server $server):string {
        try {
            // Use confirmed Wings details API without getDetails() swallowing/reporting credential-bearing exceptions.
            $details=app(DaemonServerRepository::class)->setServer($server)->getHttpClient()->connectTimeout(3)->timeout(5)
                ->withOptions(['allow_redirects'=>false])->get('/api/servers/'.$server->uuid)->throw()->json();
            $state=$details['state'] ?? $details['current_state'] ?? null;
            return in_array($state,['running','starting','stopping','offline'],true) ? $state : 'unknown';
        } catch (\Throwable $e) {return 'unknown';}
    }
}
