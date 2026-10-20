<?php
require __DIR__.'/../src/Services/RouterConfiguration.php';
use PortOpener\Services\RouterConfiguration;
$cases=[
    'http://192.168.178.1:49000/igddesc.xml'=>true,
    'http://10.0.0.1/root.xml'=>true,
    'http://172.16.0.1/device.xml'=>true,
    'http://172.31.255.254/'=>true,
    'http://192.168.178.1'=>false,
    'https://192.168.178.1/device.xml'=>false,
    'http://127.0.0.1/device.xml'=>false,
    'http://169.254.0.1/device.xml'=>false,
    'http://8.8.8.8/device.xml'=>false,
    'http://172.15.0.1/device.xml'=>false,
    'http://172.32.0.1/device.xml'=>false,
    'http://router.local/device.xml'=>false,
    'http://[::1]/device.xml'=>false,
    'http://user:pass@192.168.178.1/device.xml'=>false,
    'http://user@192.168.178.1/device.xml'=>false,
    'http://192.168.178.1/device.xml?x=1'=>false,
    'http://192.168.178.1/device.xml#x'=>false,
    'http://192.168.178.1:0/device.xml'=>false,
    'http://192.168.178.1:65536/device.xml'=>false,
    'http://3232235777/device.xml'=>false,
    'http://192.168.178.1/device name.xml'=>false,
];
foreach ($cases as $url=>$expected) if (RouterConfiguration::validateUrl($url)!==$expected) throw new RuntimeException('URL validation failed: '.$url);
echo count($cases)." router URL checks passed\n";
