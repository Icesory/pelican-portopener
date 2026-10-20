<?php
namespace PortOpener\Services;
use Illuminate\Support\Facades\Http;
use RuntimeException;
class PortOpenerServiceClient {
    public function request(string $method, string $path, ?array $payload = null): array {
        $policy=\PortOpener\Models\NodePolicy::where('node_id',(int)config('port-opener.node_id'))->first();
        $endpoint = rtrim((string)($policy?->service_url ?: config('port-opener.endpoint')), '/');
        $token = (string)($policy?->service_token ?: config('port-opener.token'));
        $validEndpoint=$policy?->service_url ? ServiceConfiguration::validateServiceUrl($endpoint) : (filter_var($endpoint,FILTER_VALIDATE_URL) && in_array(parse_url($endpoint,PHP_URL_SCHEME),['http','https'],true));
        if (!$token || !$validEndpoint) throw new RuntimeException('PortOpener PortOpenerService configuration is incomplete.');
        // Deliberately never include response bodies, request objects or token in exceptions.
        try {
            $response = Http::withToken($token)->acceptJson()->connectTimeout(3)->timeout(15)
                ->withOptions(['allow_redirects'=>false])->send($method, $endpoint.$path, $payload === null ? [] : ['json'=>$payload]);
        } catch (\Throwable $e) { throw new RuntimeException('PortOpener PortOpenerService connection failed.'); }
        if (!$response->successful()) {
            if ($path==='/v1/router/configuration' && $response->status()===409) throw new RuntimeException('Router configuration cannot change while mappings are active. Run port-opener:sync --cleanup first, verify removal, then synchronize the new router settings.');
            if ($path==='/v1/router/test' && $response->status()===503) throw new RuntimeException('Router discovery failed. Check the saved device-description URL and network access from PortOpenerService.');
            throw new RuntimeException('PortOpener PortOpenerService rejected request (HTTP '.$response->status().').');
        }
        $data = $response->json();
        if (!is_array($data)) throw new RuntimeException('PortOpener PortOpenerService returned invalid JSON.');
        return $data;
    }
}
