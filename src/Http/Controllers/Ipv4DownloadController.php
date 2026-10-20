<?php

namespace PortOpener\Http\Controllers;

use PortOpener\Services\Ipv4Release;

class Ipv4DownloadController
{
    public function download(string $filename, Ipv4Release $release)
    {
        return response()->download($release->path($filename), $filename, [
            'Content-Type' => 'application/zip', 'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
