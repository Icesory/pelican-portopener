<?php

namespace PortOpener\Http\Controllers;

class RouterDiscoveryController
{
    public function download(string $filename)
    {
        abort_unless(in_array($filename, ['Find-PortOpenerRouter.ps1', 'find_portopener_router.py'], true), 404);
        return response()->download(__DIR__.'/../../../resources/helpers/'.$filename, $filename, [
            'Content-Type' => 'text/plain; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
