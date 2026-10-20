<# Read-only SSDP discovery. Run on a Windows PC connected to the Wings node's LAN. #>
param(
    [ValidateRange(2, 15)][int]$TimeoutSeconds = 4,
    [string]$InterfaceAddress
)

function Test-PortOpenerPrivateIPv4([string]$Address) {
    $parsed = $null
    if (-not [Net.IPAddress]::TryParse($Address, [ref]$parsed) -or $parsed.AddressFamily -ne [Net.Sockets.AddressFamily]::InterNetwork) { return $false }
    $bytes = $parsed.GetAddressBytes()
    return $bytes[0] -eq 10 -or ($bytes[0] -eq 172 -and $bytes[1] -ge 16 -and $bytes[1] -le 31) -or ($bytes[0] -eq 192 -and $bytes[1] -eq 168)
}

function Get-PortOpenerRouterUrl([string]$Packet, [string]$SenderAddress) {
    $lines = $Packet -split "`r`n"
    if ($lines[0] -notmatch '^HTTP/1\.1 200(?: |$)') { return }
    $headers = @{}
    foreach ($line in $lines | Select-Object -Skip 1) {
        $separator = $line.IndexOf(':')
        if ($separator -lt 1) { continue }
        $name = $line.Substring(0, $separator).Trim().ToLowerInvariant()
        if ($headers.ContainsKey($name) -and $name -in @('location', 'st')) { return }
        $headers[$name] = $line.Substring($separator + 1).Trim()
    }
    if ($headers['st'] -notmatch '^urn:schemas-upnp-org:(device:InternetGatewayDevice:[12]|service:WANIPConnection:[12]|service:WANPPPConnection:1)$') { return }
    $location = $headers['location']
    if (-not $location -or $location -match '[\x00-\x20\x7f\\]' -or $location -match '[?#]') { return }
    $url = $null
    if (-not [Uri]::TryCreate($location, [UriKind]::Absolute, [ref]$url)) { return }
    if ($url.Scheme -ne 'http' -or -not (Test-PortOpenerPrivateIPv4 $url.Host) -or $url.Host -ne $SenderAddress -or $url.UserInfo -or $url.Port -lt 1 -or $url.Port -gt 65535) { return }
    if ($location -notmatch '^http://(?<address>(?:[0-9]{1,3}\.){3}[0-9]{1,3})(?::[0-9]{1,5})?/' -or $Matches['address'] -ne $url.Host) { return }
    return $location
}

function Find-PortOpenerRouter([int]$Seconds = 4, [string]$LocalAddress) {
    $targets = @('device:InternetGatewayDevice:2', 'service:WANIPConnection:2', 'device:InternetGatewayDevice:1', 'service:WANIPConnection:1', 'service:WANPPPConnection:1')
    if ($LocalAddress) {
        if (-not (Test-PortOpenerPrivateIPv4 $LocalAddress)) { throw 'Choose this computer''s private LAN IPv4 for -InterfaceAddress.' }
        $addresses = @($LocalAddress)
    } else {
        $addresses = @([Net.NetworkInformation.NetworkInterface]::GetAllNetworkInterfaces() |
            Where-Object OperationalStatus -eq Up | ForEach-Object { $_.GetIPProperties().UnicastAddresses } |
            ForEach-Object { $_.Address.ToString() } | Where-Object { Test-PortOpenerPrivateIPv4 $_ } | Sort-Object -Unique)
    }
    if (-not $addresses.Count) { $addresses = @('0.0.0.0') }
    $clients = [Collections.Generic.List[Net.Sockets.UdpClient]]::new()
    $found = @{}
    try {
        foreach ($address in $addresses) {
            $client = [Net.Sockets.UdpClient]::new([Net.Sockets.AddressFamily]::InterNetwork)
            try {
                $client.Client.Bind([Net.IPEndPoint]::new([Net.IPAddress]::Parse($address), 0))
                $client.Client.ReceiveTimeout = 100
                $client.Client.SetSocketOption([Net.Sockets.SocketOptionLevel]::IP, [Net.Sockets.SocketOptionName]::MulticastTimeToLive, 2)
                if ($address -ne '0.0.0.0') {
                    $client.Client.SetSocketOption([Net.Sockets.SocketOptionLevel]::IP, [Net.Sockets.SocketOptionName]::MulticastInterface, [Net.IPAddress]::Parse($address).GetAddressBytes())
                }
                foreach ($target in $targets) {
                    $message = "M-SEARCH * HTTP/1.1`r`nHOST: 239.255.255.250:1900`r`nMAN: `"ssdp:discover`"`r`nMX: 2`r`nST: urn:schemas-upnp-org:$target`r`n`r`n"
                    $packet = [Text.Encoding]::ASCII.GetBytes($message)
                    [void]$client.Send($packet, $packet.Length, [Net.IPEndPoint]::new([Net.IPAddress]::Parse('239.255.255.250'), 1900))
                }
                $clients.Add($client)
            } catch { $client.Close() }
        }
        $timer = [Diagnostics.Stopwatch]::StartNew()
        $packets = 0
        while ($clients.Count -and $timer.Elapsed.TotalSeconds -lt $Seconds -and $packets -lt 1024) {
            foreach ($client in $clients) {
                if ($client.Available -le 0) { continue }
                $sender = [Net.IPEndPoint]::new([Net.IPAddress]::Any, 0)
                try {
                    $packet = $client.Receive([ref]$sender)
                    $packets++
                    $message = [Text.Encoding]::ASCII.GetString($packet)
                    $location = Get-PortOpenerRouterUrl $message $sender.Address.ToString()
                    if ($location) {
                        $version = if ($message -match '(?im)^ST:\s*urn:schemas-upnp-org:(device:InternetGatewayDevice|service:WANIPConnection):2\s*$') { 2 } else { 1 }
                        if (-not $found.ContainsKey($location) -or $version -gt $found[$location]) { $found[$location] = $version }
                    }
                } catch [Net.Sockets.SocketException] { }
            }
            Start-Sleep -Milliseconds 20
        }
    } finally { foreach ($client in $clients) { $client.Close() } }
    return @(Sort-PortOpenerRouterCandidates $found)
}

function Sort-PortOpenerRouterCandidates([hashtable]$Candidates) {
    $Candidates.GetEnumerator() | Sort-Object @{Expression = { $_.Value }; Descending = $true}, Name | ForEach-Object { $_.Key }
}

if ($MyInvocation.InvocationName -ne '.') {
    Write-Host 'Searching this LAN for UPnP routers (read-only)...'
    $urls = @(Find-PortOpenerRouter -Seconds $TimeoutSeconds -LocalAddress $InterfaceAddress)
    if (-not $urls.Count) {
        Write-Host 'No supported router URL found. Use the Wings node''s LAN; check UPnP, firewall, guest Wi-Fi and VPN settings.'
        exit 1
    }
    Write-Host "`nRouter URL candidates (IGD2 preferred; IGD1 fallback):"
    $urls | Write-Output
    Write-Host "`nPaste the matching URL into PortOpener > node configuration > Router UPnP URL, save and run Check connection."
}
