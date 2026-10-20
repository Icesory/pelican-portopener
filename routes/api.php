<?php
use Illuminate\Support\Facades\Route;use PortOpener\Http\Controllers\NodeServiceController;use PortOpener\Http\Middleware\{RequireServiceHttps,AuthenticateNodeService};
Route::prefix('api/port-opener/v1')->middleware([RequireServiceHttps::class,'throttle:port-opener-api'])->group(function(){
Route::get('downloads/{filename}',[\PortOpener\Http\Controllers\Ipv4DownloadController::class,'download'])->name('port-opener.ipv4.download');
Route::get('router-discovery/{filename}',[\PortOpener\Http\Controllers\RouterDiscoveryController::class,'download'])->name('port-opener.router-helper');
Route::post('pair',[NodeServiceController::class,'pair'])->middleware('throttle:port-opener-pair');
Route::middleware(AuthenticateNodeService::class)->group(function(){Route::get('desired-state',[NodeServiceController::class,'desired']);Route::post('report',[NodeServiceController::class,'report']);Route::post('renew',[NodeServiceController::class,'renew'])->middleware('throttle:port-opener-renew');});
});
