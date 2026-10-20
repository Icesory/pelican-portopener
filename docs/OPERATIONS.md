# Operations

Current operator instructions are maintained in the repository [README](../README.md), including installation, per-node pairing, modes, allocation protocols, IPv6, cleanup and upgrades. This replaces the historical single-node/local-API rollout instructions.

## UI and permissions (0.4.0)

Admin → PortOpenerService has Overview and Ports info tabs. Add node configures an existing Wings node; Configure opens a native Filament modal. Router URLs are shown only in configuration. Health badges use fresh reports and distinguish connection failures, router errors and simulation.

Admin → Server → PortOpener embeds Pelican's real allocation relation manager. Its normal add/create, alias, notes, show-port, primary, lock and release operations remain intact. Forwarding protocols and public-access/IPv6 policy are root-admin-only. Owner Network shows read-only, tenant-scoped status and respects show_port.

The server creation wizard has a PortOpener step for selected allocations. It defaults to Not managed and keeps public access Off. Existing valid egg approvals are migrated once; future forwarding uses allocation protocol records pinned to assignment identity.

Bulk allocation updates are observed so release/reassignment deletes stale approvals immediately. External direct database edits are outside the panel event boundary; administrators should not manually reassign allocation rows in SQL.

## Mode transitions

Pausing disables desired exposure while preserving the service connection. Active → Test mode first publishes an empty snapshot with real cleanup still enabled. The pending mode completes only after a matching-generation, reconciled, non-preview service report confirms no remaining rules, including expired IPv6 leases. Authentication loss and router failures leave cleanup pending.

## Development

Compose project portopener-dev uses separate SQLite/panel/service volumes and a fake router. Stop with docker compose stop; retain volumes when restarting. Environment files and runtime credentials are ignored by Git. Native UI tests are in tests/admin_redesign.php and must run only in the isolated development panel.

## Troubleshooting and support

Start with the node's connection/router state and last contact in Overview, then the affected server's Ports info rows. Check connection runs a read-only router test. A mapped result confirms router read-back, not Internet reachability.

Open **Configure > Diagnostics** for the node. Download diagnostics exports a JSON support report assembled from approved fields. Enable debug (15 minutes) requests extra per-port/check events; wait for the next service poll, reproduce the issue, download, and optionally Stop debug. The service independently expires debug mode, even if the panel is unavailable. Controls and download are root-admin-only. Existing older services require an update before debug mode is available.

The service keeps at most 128 events and a 64-KiB private file beside the ownership state, named `state.json.diagnostics.json`. Normal logs record transitions/errors/recovery; debug also records repeated checks. Events are emitted to its console. Downloads contain versions, numeric/boolean policy settings, cached port/protocol states and stable error codes. They omit addresses, URLs, names, installation identity, credentials, pairing codes, raw requests, exception text, environment and ownership files. Both service and panel use the same closed schema, and downloads sanitize cached data again.

For a managed Egg, additional installation output is available in Console and the Wings installation log. Host deployments can use Docker Compose logs or their systemd journal. Panel/queue exceptions remain in their configured application/service logs and are not copied into the diagnostic download. Review other log excerpts separately before sharing; never attach raw environment/configuration/credential/ownership files.

Downloads use cached outbound reports. When authentication or transport prevents reporting, service history can be stale or absent; inspect the local sanitized diagnostic file or console. A mapped result confirms router read-back, not Internet reachability. Nothing is sent to support or a third party automatically.

## Cleanup and rollback

Back up all plugin tables plus service config/credentials/state. Run php artisan port-opener:sync --cleanup before disabling or uninstalling; wait for confirmed removal. Keep installation UUID, snapshot generations and durable ownership state across upgrades. Reverting database generations without service coordination can cause stale-snapshot rejection. Migration rollback deliberately requires operator cleanup/review.

No host firewall or DNS changes are performed. Native allocation mutations continue to use Pelican's own services and authorization; router mappings are managed independently by PortOpenerService.
