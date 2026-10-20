<?php
namespace PortOpener\Console\Commands;
use Illuminate\Console\Command;
use PortOpener\Services\SyncService;
class SyncPorts extends Command {
    protected $signature = 'port-opener:sync {--dry-run : Preview without submitting} {--cleanup : Submit empty state to remove owned mappings}';
    protected $description = 'Reconcile approved server allocations with the local PortOpenerService';
    public function handle(SyncService $service): int {
        try {
            $result = $service->sync((bool)$this->option('dry-run'),(bool)$this->option('cleanup'));
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)); return self::SUCCESS;
        } catch (\Throwable $e) { $this->error($e->getMessage()); return self::FAILURE; }
    }
}
