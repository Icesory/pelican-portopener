<?php
namespace PortOpener\Jobs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use PortOpener\Services\SyncService;
class SyncPortMappings implements ShouldQueue, ShouldBeUnique {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $uniqueFor = 30;
    public int $tries = 3;
    public function uniqueId(): string { return 'port-opener:sync'; }
    public function backoff(): array { return [10,30,60]; }
    public function handle(SyncService $service): void { $service->sync(); }
}
