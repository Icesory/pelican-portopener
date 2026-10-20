<?php
namespace PortOpener\Services;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PortOpener\Models\{NodePolicy, Ipv4Installation};
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Support exports are rebuilt from closed field/value allowlists, never raw models or logs. */
class NodeDiagnostics
{
    public static function schema(): array {
        static $schema;
        return $schema ??= json_decode(file_get_contents(__DIR__.'/../../resources/diagnostics-schema.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function date(mixed $value): ?string {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) return null;
        try { return Carbon::parse($value)->utc()->format('Y-m-d\TH:i:sP'); } catch (\Throwable) { return null; }
    }

    private static function version(mixed $value): ?string {
        return is_string($value) && preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}(?:-(?:alpha|beta|rc)\.\d{1,3})?$/D', $value) ? $value : null;
    }

    public static function error(mixed $value): ?string {
        if ($value === null) return null;
        return is_string($value) && (in_array($value, self::schema()['errors'], true) || preg_match('/^upnp_[0-9]{3}$/D', $value)) ? $value : 'internal_error';
    }

    private static function number(mixed $value, int $min, int $max): ?int {
        return is_int($value) && $value >= $min && $value <= $max ? $value : null;
    }

    public static function sanitize(mixed $input): ?array {
        if (!is_array($input) || ($input['schema_version'] ?? null) !== 1 || strlen(json_encode($input)) > 98304) return null;
        $schema = self::schema();
        $output = ['schema_version' => 1, 'service_version' => self::version($input['service_version'] ?? null),
            'python_version' => self::version($input['python_version'] ?? null), 'debug_active' => ($input['debug_active'] ?? false) === true,
            'debug_until' => self::date($input['debug_until'] ?? null), 'storage_ok' => ($input['storage_ok'] ?? false) === true, 'events' => []];
        foreach (array_slice(is_array($input['events'] ?? null) ? $input['events'] : [], -128) as $row) {
            if (!is_array($row) || !in_array($row['event'] ?? null, $schema['events'], true)
                || !in_array($row['level'] ?? null, $schema['levels'], true) || !($time = self::date($row['timestamp'] ?? null))) continue;
            $event = ['timestamp' => $time, 'event' => $row['event'], 'level' => $row['level']];
            foreach (['outcome'=>'outcomes','state'=>'states','protocol'=>'protocols','family'=>'families','phase'=>'phases','router_service'=>'router_services'] as $key=>$list) {
                if (in_array($row[$key] ?? null, $schema[$list], true)) $event[$key] = $row[$key];
            }
            foreach (['port'=>[1,65535],'generation'=>[0,2147483647],'http_status'=>[100,599],'mapping_count'=>[0,256],'duration_ms'=>[0,600000]] as $key=>$bounds) {
                if (($value = self::number($row[$key] ?? null, ...$bounds)) !== null) $event[$key] = $value;
            }
            if (($error = self::error($row['error_code'] ?? null)) !== null) $event['error_code'] = $error;
            $output['events'][] = $event;
        }
        return $output;
    }

    private function policy(int $nodeId): NodePolicy {
        abort_unless(auth()->user()?->isRootAdmin(), 403);
        return NodePolicy::where('node_id', $nodeId)->firstOrFail();
    }

    public function enable(int $nodeId): void {
        $policy = $this->policy($nodeId);
        if ($policy->connection_mode !== 'outbound' || ($policy->service_diagnostics['schema_version'] ?? null) !== 1) {
            throw ValidationException::withMessages(['diagnostics' => 'Pair an updated outbound PortOpenerService and wait for its first diagnostics report.']);
        }
        $policy->forceFill(['debug_until' => now()->addMinutes(15)])->saveQuietly();
    }

    public function disable(int $nodeId): void { $this->policy($nodeId)->forceFill(['debug_until' => null])->saveQuietly(); }

    public function summary(int $nodeId): string {
        $policy = $this->policy($nodeId);
        $received = self::sanitize($policy->service_diagnostics);
        if (!$received) return 'Service events unavailable. Update PortOpenerService to 0.6.0 or later and wait for its first report. A panel status download is still available.';
        $serviceActive = $received['debug_active'] && $received['debug_until'] && Carbon::parse($received['debug_until'])->isFuture();
        return $policy->debug_until?->isFuture()
            ? 'Debug requested until '.$policy->debug_until->utc()->format('H:i:s').' UTC. '.($serviceActive ? 'Service last reported debug active.' : 'Waiting for the service to apply the request.')
            : ($serviceActive ? 'Debug stop requested; the service will apply it on its next poll. Its local deadline still applies.' : 'Normal logging. Temporary debug mode is off.');
    }

    public function snapshot(int $nodeId): array {
        $policy = $this->policy($nodeId);
        $manifest = json_decode(file_get_contents(__DIR__.'/../../plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        $report = $policy->last_report ?? [];
        $service = self::sanitize($policy->service_diagnostics);
        if ($service) {
            $service['debug_active'] = $service['debug_active'] && $service['debug_until'] && Carbon::parse($service['debug_until'])->isFuture();
            $service['received_at'] = self::date($policy->service_diagnostics['received_at'] ?? null);
        }
        $ports = [];
        foreach (['IPv4' => $report['mappings'] ?? [], 'IPv6' => $report['ipv6']['mappings'] ?? []] as $family=>$rows) {
            foreach (array_slice(is_array($rows) ? $rows : [], 0, 256) as $row) {
                if (!is_array($row) || ($port = self::number($row['port'] ?? null, 1, 65535)) === null
                    || !in_array($row['protocol'] ?? null, self::schema()['protocols'], true)) continue;
                $ports[] = ['port'=>$port, 'protocol'=>$row['protocol'], 'family'=>$family,
                    'state'=>in_array($row['state'] ?? null, self::schema()['states'], true) ? $row['state'] : 'unknown',
                    'error_code'=>self::error($row['error_code'] ?? null)];
            }
        }
        $settings = [];
        foreach (['min_port'=>[1,65535], 'max_port'=>[1,65535], 'lease_seconds'=>[120,86400], 'reconcile_seconds'=>[5,300]] as $name=>$bounds) $settings[$name] = self::number($policy->$name, ...$bounds);
        foreach (['enabled','service_enabled','service_dry_run','ipv6_enabled'] as $name) $settings[$name] = (bool)$policy->$name;
        $settings['connection_mode'] = in_array($policy->connection_mode, ['outbound','legacy_local'], true) ? $policy->connection_mode : 'unknown';
        $settings['router_configured'] = RouterConfiguration::validateUrl((string)$policy->router_url);
        $slot = Ipv4Installation::where('node_id', $nodeId)->first();
        return ['schema_version'=>1, 'generated_at'=>now()->utc()->format('Y-m-d\TH:i:sP'), 'node_id'=>$nodeId,
            'versions'=>['plugin'=>self::version($manifest['version'] ?? null), 'php'=>self::version(PHP_VERSION)],
            'settings'=>$settings, 'debug_requested_until'=>$policy->debug_until?->toIso8601String(),
            'managed_service'=> $slot ? ['status'=>in_array($slot->status, ['queued','creating','stopping','reinstalling','installing','pairing','starting','ready','failed'], true) ? $slot->status : 'unknown'] : null,
            'status'=>['last_contact_at'=>$policy->last_contact_at?->toIso8601String(), 'desired_generation'=>self::number($policy->desired_generation,0,2147483647),
                'reported_generation'=>self::number($report['generation'] ?? null,0,2147483647), 'router_reachable'=>($report['router_reachable'] ?? false) === true,
                'router_service'=>in_array($report['router_service'] ?? null, self::schema()['router_services'], true) ? $report['router_service'] : null,
                'renewal_paused'=>($report['renewal_paused'] ?? false) === true, 'ports'=>$ports],
            'service'=>$service,
            'privacy'=>['configuration'=>'Allowlisted flags and numeric limits only; addresses, names, URLs and identities omitted.',
                'events'=>'Closed event/error codes only; no exception text, raw requests, environment, credentials or ownership journal.'],
            'limitations'=>['Cached reports may be stale or absent if the service cannot reach the panel.',
                'Mapped confirms router read-back, not external Internet connectivity.']];
    }

    public function download(int $nodeId): StreamedResponse {
        $payload = json_encode($this->snapshot($nodeId), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        return response()->streamDownload(fn () => print($payload), 'portopener-node-'.$nodeId.'-'.now()->utc()->format('Ymd-His').'.json',
            ['Content-Type'=>'application/json', 'Cache-Control'=>'no-store, private', 'X-Content-Type-Options'=>'nosniff']);
    }
}
