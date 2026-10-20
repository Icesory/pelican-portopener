<?php

namespace PortOpener\Services;

class Ipv4Release
{
    public const EGG_UUID = '54ca43ef-8893-4a45-9d36-a68d99073220';

    public function directory(): string { return __DIR__.'/../../resources/releases'; }

    public function manifest(): array
    {
        $manifest = json_decode(file_get_contents($this->directory().'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $path = $this->path($manifest['filename']);
        if (!hash_equals($manifest['sha256'], hash_file('sha256', $path))) throw new \RuntimeException('Bundled IPv4 release checksum failed.');
        return $manifest;
    }

    public function path(string $filename): string
    {
        // A download can only select a bundled service release, never configuration or arbitrary plugin files.
        abort_unless(preg_match('/\Aport-opener-service-ipv4-[0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?\.zip\z/D', $filename), 404);
        $path = $this->directory().'/'.$filename;
        abort_unless(is_file($path), 404);
        return $path;
    }

    public function origin(): string
    {
        $origin = rtrim((string) config('app.url'), '/');
        $parts = parse_url($origin);
        if (!filter_var($origin, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || !empty($parts['path'])) {
            throw new \RuntimeException('Set the panel URL to a trusted HTTPS origin before installing.');
        }
        return $origin;
    }

    public function environment(): array
    {
        $manifest = $this->manifest();
        return ['PORT_OPENER_PANEL_URL' => $this->origin(), 'SERVICE_SHA256' => $manifest['sha256'],
            'SERVICE_DOWNLOAD_URL' => $this->origin().'/api/port-opener/v1/downloads/'.$manifest['filename']];
    }

    public function egg(): array
    {
        $egg = json_decode(file_get_contents($this->directory().'/egg-port-opener-ipv4.json'), true, 512, JSON_THROW_ON_ERROR);
        $egg['uuid'] = self::EGG_UUID;
        $environment = $this->environment();
        foreach ($egg['variables'] as &$variable) $variable['default_value'] = $environment[$variable['env_variable']];
        return $egg;
    }
}
