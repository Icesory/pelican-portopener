<?php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('app.name') !== 'PortOpener Development' || config('port-opener.endpoint') !== 'http://port-opener-service:8787') {
    throw new RuntimeException('Refusing integration test outside isolated fake environment.');
}
use PortOpener\Services\{PortOpenerServiceClient,ExposureService,SyncService};
use App\Models\{Server,User};
Illuminate\Support\Facades\Queue::fake();
$service = app(PortOpenerServiceClient::class);
if (($service->request('GET','/v1/status')['backend'] ?? null) !== 'fake') {
    throw new RuntimeException('Integration test requires fake router.');
}
$server = Server::where('name','PortOpener Synthetic Server')->firstOrFail();
$owner = User::findOrFail($server->owner_id);
$sync = app(SyncService::class);
$exposure = app(ExposureService::class);
$check = fn ($condition,$message) => $condition ?: throw new RuntimeException($message);
try {
    $exposure->setEnabled($server,$owner,true);
    $first = $sync->sync();
    $check(count($first['mappings'])===3, 'Expected three TCP/UDP simulated mappings.');
    foreach ($first['mappings'] as $row) $check($row['state']==='mapped','Creation must be verified.');
    $second = $sync->sync();
    $check($second['generation']===$first['generation'],'Identical snapshot must keep generation.');
    $exposure->setEnabled($server,$owner,false);
    $empty = $sync->sync();
    $check($empty['generation']>$first['generation'],'Cleanup must increment generation.');
    foreach ($empty['mappings'] as $row) $check($row['state']==='removed','Owned simulated mapping must be removed.');
    $check(!$service->request('GET','/v1/status')['dry_run'],'Exercise fake mutations, not preview only.');
    echo "End-to-end fake flow passed: owner enable, three mappings, idempotent sync, disable and verified cleanup.\n";
} finally {
    $exposure->setEnabled($server,$owner,false);
    $sync->sync();
}
