<?php
namespace PortOpener\Providers;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use PortOpener\Services\SyncService;
class PortOpenerPluginProvider extends ServiceProvider {
    public function register(): void
    {
        $this->app->singleton(\PortOpener\Services\Ipv4Installer::class);
        $this->app->when(\App\Services\Servers\ServerCreationService::class)
            ->needs(\App\Repositories\Daemon\DaemonServerRepository::class)
            ->give(\PortOpener\Repositories\ManagedIpv4DaemonServerRepository::class);
        $this->app->extend(\App\Services\Servers\ServerCreationService::class, fn ($service) => new \PortOpener\Services\PortOpenerServerCreation($service));
    }
    public function boot(): void {
        \Livewire\Livewire::component('port-opener.admin-allocations', \PortOpener\Filament\Admin\Components\PortOpenerAllocations::class);
        \Livewire\Livewire::component('port-opener.server-settings', \PortOpener\Filament\Admin\Components\ServerForwardingSettings::class);
        \Livewire\Livewire::component('port-opener.ports-info', \PortOpener\Filament\Admin\Components\PortsInfo::class);
        // Core assignment/release operations use bulk updates. Invalidate pinned approvals
        // immediately after each allocation update, including release/reassign round trips.
        DB::listen(function (\Illuminate\Database\Events\QueryExecuted $event) {
            static $purging = false;
            if ($purging || !preg_match('/^\s*update\s+[`"]?allocations[`"]?\s+/i', $event->sql)) return;
            if (!\Illuminate\Support\Facades\Schema::hasTable('port_opener_allocation_protocols')) return;
            $purging = true;
            try { app(\PortOpener\Services\AllocationProtocols::class)->purgeReassigned(); }
            finally { $purging = false; }
            DB::afterCommit(fn () => SyncService::queue());
        });
        Event::listen('eloquent.deleted: '.\App\Models\Server::class, fn ($server) => \PortOpener\Models\AllocationProtocol::where('server_id', $server->id)->delete());
        \Illuminate\Support\Facades\RateLimiter::for('port-opener-api',fn($request)=>\Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by('port-opener-api:'.$request->ip()));
        \Illuminate\Support\Facades\RateLimiter::for('port-opener-pair',fn($request)=>\Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by('port-opener-pair:'.$request->ip()));
        \Illuminate\Support\Facades\RateLimiter::for('port-opener-renew',fn($request)=>\Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by('port-opener-renew:'.hash('sha256',(string)$request->bearerToken())));
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('port-opener:sync')->everyMinute()->withoutOverlapping();
            $schedule->call(function () {
                \PortOpener\Models\Ipv4Installation::whereNotIn('status', ['ready', 'failed'])->each(function ($installation) {
                    \PortOpener\Jobs\ProvisionIpv4Service::dispatch($installation->node_id, $installation->operation_id);
                });
            })->name('port-opener:resume-ipv4-installs')->everyMinute()->withoutOverlapping();
        });
        foreach ([\App\Models\Server::class,\App\Models\Allocation::class,\App\Models\ServerVariable::class,\PortOpener\Models\NodePolicy::class,\PortOpener\Models\EggRule::class] as $model) {
            foreach (['saved','deleted'] as $event) Event::listen("eloquent.$event: $model", fn () => DB::afterCommit(fn () => SyncService::queue()));
        }
        foreach ([\PortOpener\Models\NodePolicy::class,\PortOpener\Models\EggRule::class] as $model) {
            foreach (['saved','deleted'] as $event) Event::listen("eloquent.$event: $model", function ($record) use ($event) {
                Log::info('PortOpener policy changed',['type'=>class_basename($record),'id'=>$record->id,'action'=>$event,'user_id'=>auth()->id()]);
            });
        }
    }
}
