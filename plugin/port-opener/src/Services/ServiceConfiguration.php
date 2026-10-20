<?php
namespace PortOpener\Services;
class ServiceConfiguration {
public static function validateServiceUrl(string $url):bool {
if(!filter_var($url,FILTER_VALIDATE_URL)||preg_match('/[\x00-\x20\x7f\\\\]/',$url))return false;
$parts=parse_url($url);if(!$parts || isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment']))return false;
if(isset($parts['port'])&&($parts['port']<1||$parts['port']>65535))return false;
if(($parts['scheme'] ?? '')==='https')return true;
if(($parts['scheme'] ?? '')!=='http')return false;
$host=$parts['host'] ?? '';if(in_array($host,['127.0.0.1','localhost'],true))return true;
return RouterConfiguration::validateUrl('http://'.$host.(isset($parts['port'])?':'.$parts['port']:'').'/');
}
}
