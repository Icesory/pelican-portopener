$ErrorActionPreference = 'Stop'
. "$PSScriptRoot/../plugin/port-opener/resources/helpers/Find-PortOpenerRouter.ps1"
$url = 'http://192.168.178.1:49000/igddesc.xml'
$target = 'urn:schemas-upnp-org:device:InternetGatewayDevice:1'
$reply = "HTTP/1.1 200 OK`r`nST: $target`r`nLOCATION: $url`r`n`r`n"
$checks = 0
if ((Get-PortOpenerRouterUrl $reply '192.168.178.1') -ne $url) { throw 'Router URL was not accepted.' }; $checks++
if ((Get-PortOpenerRouterUrl $reply.ToLowerInvariant().Replace('http/1.1', 'HTTP/1.1') '192.168.178.1') -ne $url) { throw 'Header case was not accepted.' }; $checks++
foreach ($bad in @('http://example.com/igd.xml', 'http://127.0.0.1/igd.xml', 'http://169.254.169.254/igd.xml',
    'https://192.168.178.1/igd.xml', 'http://user:pass@192.168.178.1/igd.xml', 'http://192.168.178.1',
    'http://192.168.178.1/igd.xml?', 'http://192.168.178.1/igd.xml#', 'http://192.168.178.1:0/igd.xml', 'http://192.168.178.1:99999/igd.xml')) {
    if (Get-PortOpenerRouterUrl $reply.Replace($url, $bad) '192.168.178.1') { throw "Unsupported URL accepted: $bad" }; $checks++
}
if (Get-PortOpenerRouterUrl $reply '192.168.178.2') { throw 'Different sender was accepted.' }; $checks++
if (Get-PortOpenerRouterUrl ($reply + "LOCATION: $url`r`n") '192.168.178.1') { throw 'Ambiguous headers were accepted.' }; $checks++
if (Get-PortOpenerRouterUrl $reply.Replace($target, 'urn:schemas-upnp-org:device:MediaServer:1') '192.168.178.1') { throw 'Non-router device was accepted.' }; $checks++
if (Get-PortOpenerRouterUrl $reply.Replace('HTTP/1.1 200 OK', 'NOTIFY * HTTP/1.1') '192.168.178.1') { throw 'Unsolicited notification was accepted.' }; $checks++
$ranked = @(Sort-PortOpenerRouterCandidates @{'http://192.168.178.1/a.xml' = 1; 'http://192.168.178.1/z.xml' = 2})
if ($ranked[0] -ne 'http://192.168.178.1/z.xml') { throw 'IGD2 was not preferred over the alphabetically first IGD1 URL.' }; $checks++
Write-Output "$checks PowerShell discovery checks passed."
