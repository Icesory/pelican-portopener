<?php
namespace PortOpener\Services;
use PortOpener\Models\NodePolicy;
use RuntimeException;
class RouterConfiguration {
    public static function validateUrl(string $url): bool {
        if (!filter_var($url,FILTER_VALIDATE_URL) || preg_match('/[\x00-\x20\x7f\\\\]/',$url)) return false;
        $parts=parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'http' || isset($parts['user'],$parts['pass'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || empty($parts['path']) || $parts['path'][0] !== '/') return false;
        $host=$parts['host'] ?? '';
        if (!filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) return false;
        if (isset($parts['port']) && ($parts['port']<1 || $parts['port']>65535)) return false;
        $octets=array_map('intval',explode('.',$host));
        return $octets[0]===10 || ($octets[0]===172 && $octets[1]>=16 && $octets[1]<=31)
            || ($octets[0]===192 && $octets[1]===168);
    }
    public static function payload(int $nodeId, bool $requireUrl = false): ?array {
        if ($nodeId<1 || $nodeId!==(int)config('port-opener.node_id')) throw new RuntimeException('This node has no configured local PortOpener service.');
        $policy=NodePolicy::where('node_id',$nodeId)->first();
        $url=$policy?->router_url;
        if (!$url) {
            if ($requireUrl) throw new RuntimeException('Save a router URL for this node before testing.');
            return null; // Preserve the service boot configuration for upgraded existing installations.
        }
        if ($policy->forwarding_type!=='upnp' || !self::validateUrl($url)) throw new RuntimeException('Node router settings require UPnP and a valid private IPv4 HTTP URL with a path.');
        return ['node_id'=>$nodeId,'forwarding_type'=>'upnp','router_url'=>$url];
    }
}
