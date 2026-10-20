<?php

namespace PortOpener\Services;

use App\Models\Objects\DeploymentObject;
use App\Models\Server;
use App\Services\Servers\ServerCreationService;
use Illuminate\Validation\ValidationException;

/** Decorates the existing creation service; core server creation and Wings calls stay intact. */
class PortOpenerServerCreation extends ServerCreationService
{
    public function __construct(private ServerCreationService $inner) {}

    public function handle(array $data, ?DeploymentObject $deployment = null): Server
    {
        app(Ipv4Installer::class)->assertCreationAllowed($data);
        $choices = $data['port_opener_protocols'] ?? [];
        if ($choices) {
            abort_unless(auth()->user()?->isRootAdmin(), 403);
            $assigned = collect([$data['allocation_id'] ?? null, $data['allocation_additional'] ?? []])->flatten()->filter()->map(fn ($id) => (int) $id)->all();
            foreach ($choices as $id => $choice) {
                if (!in_array((int) $id, $assigned, true) || !is_string($choice) || !array_key_exists($choice, AllocationProtocols::OPTIONS)) {
                    throw ValidationException::withMessages(['port_opener_protocols' => 'Protocols must refer to the selected allocations.']);
                }
            }
        }
        $server = $this->inner->handle($data, $deployment);
        foreach ($choices as $id => $choice) app(AllocationProtocols::class)->set($server, auth()->user(), (int) $id, $choice);
        return $server;
    }
}
