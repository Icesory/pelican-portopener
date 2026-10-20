<?php

namespace PortOpener\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use PortOpener\Services\Ipv4Installer;

class ProvisionIpv4Service implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 180;
    public int $timeout = 60;
    public function __construct(public int $nodeId, public string $operationId) {}

    public function handle(Ipv4Installer $installer): void
    {
        try {
            if (!$installer->advance($this->nodeId, $this->operationId)) $this->release(10);
        } catch (\Throwable $e) {
            // Do not serialize/log Wings request bodies, tokens, or pairing responses in failed_jobs.
            $installer->failed($this->nodeId, $this->operationId);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        app(Ipv4Installer::class)->failed($this->nodeId, $this->operationId);
    }
}
