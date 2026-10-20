<?php

namespace PortOpener\Repositories;

use App\Repositories\Daemon\DaemonServerRepository;
use PortOpener\Services\Ipv4Installer;

/** Used only by ServerCreationService. Persist managed identity before any remote side effect. */
class ManagedIpv4DaemonServerRepository extends DaemonServerRepository
{
    public function create(bool $startOnCompletion = true): void
    {
        if (app(Ipv4Installer::class)->defersDaemonCreation((int) $this->server->node_id)) return;
        parent::create($startOnCompletion);
    }
}
