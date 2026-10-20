<div class="space-y-4">
    <p>Run a helper on a computer connected to the <strong>same home network as this Wings node</strong>. The helper uses SSDP to discover router URLs. A normal browser page cannot send SSDP packets.</p>
    <p>Download a helper, open a terminal in the download folder and run the command below. Results list <strong>IGD2 first, with IGD1 as fallback</strong>. Copy the first matching router URL into <strong>Router UPnP URL</strong>, save the node and use <strong>Check connection</strong>.</p>
    <x-filament::section heading="Windows PowerShell" compact>
        <div class="space-y-3">
            <x-filament::button tag="a" :href="route('port-opener.router-helper', ['filename' => 'Find-PortOpenerRouter.ps1'])" icon="tabler-download" color="gray">Download PowerShell helper</x-filament::button>
            <pre class="overflow-x-auto text-sm">powershell -NoProfile -ExecutionPolicy Bypass -File .\Find-PortOpenerRouter.ps1</pre>
        </div>
    </x-filament::section>
    <x-filament::section heading="Linux / macOS / Python 3" compact>
        <div class="space-y-3">
            <x-filament::button tag="a" :href="route('port-opener.router-helper', ['filename' => 'find_portopener_router.py'])" icon="tabler-download" color="gray">Download Python helper</x-filament::button>
            <pre class="overflow-x-auto text-sm">python3 find_portopener_router.py</pre>
        </div>
    </x-filament::section>
    <p class="text-sm text-gray-500 dark:text-gray-400">The helpers only discover URLs. They do not open ports, change router settings or send results to the panel. If several URLs appear, select the Wings node's router. If nothing appears, check UPnP and local firewall settings; guest Wi-Fi or a VPN may isolate the router.</p>
</div>
