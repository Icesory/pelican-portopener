# PortOpener interface reference

This is a **developer reference** for the API messages, authentication and state shared by the Pelican plugin and PortOpenerService. It is not configuration and does not need to be copied to the panel or Wings host. The release builder excludes `contracts/` from plugin, host-service and Egg deployment packages. Keep it in the source repository for contributors and integrations.

For installation and pairing, follow the main README, `docs/IPV4-EGG.md` for managed installation, or `service/README.md` for a manual host deployment. The managed Egg pairs automatically; manual pairing codes are generated in the node configuration and entered at the service host's terminal prompt.

Versioned sections below record protocol evolution. In the current UI, allocation protocols and forwarding approval are administrator-only; server owners see read-only status. Historical MVP model/service notes at the end are superseded and are not current setup instructions.

## Admin allocation UI and approval authority (0.4.0)

The panel now resolves protocols from port_opener_allocation_protocols, pinned by allocation_id, server_id, node_id, IP and port. Old egg rules are migration/history only. Migration uses the previous complete resolver, so malformed or ambiguous legacy rules do not grant partial new access. Core bulk allocation reassignment invalidates stale approvals before the next query can reuse them.

All forwarding/protocol/IPv6 mutation services require a fresh root-admin actor. Client Network is read-only and masks hidden ports. Native allocation permissions remain Pelican's concern. CreateServer has an opt-in protocol step, default Not managed; existing approved tuples and server exposure settings survive migration.

The admin hub has Overview and Ports info. EditServer's PortOpener tab embeds an extension of Pelican's allocation manager, preserving its actions. No panel core files change. Router URLs stay in native configuration modals.

Node modes use enabled/service_dry_run plus pending_mode. Active to Preview/Paused drains an empty desired snapshot first; matching-generation, non-preview, reconciled reports with no outstanding IPv4/IPv6 rules finish the transition. During unknown Wings state, a retained snapshot is intersected with current approvals, so explicit revocations still remove ports. This supersedes historical owner-editable and egg-rule behavior below.
## Protocol history

## Additive IPv6 opt-in (0.3.0)

Outbound configuration adds `ipv6_enabled` (default false) and nullable `ipv6_interface`. Desired response adds `ipv6_mapping_ids`, a unique subset of the current complete IPv4 snapshot's IDs approved by both the node and server. The v1 snapshot shape stays unchanged. Unknown runtime retains the previous complete desired state while explicit IPv6 approval removal is applied independently.

Service status adds `ipv6: {enabled,state,address,interface,error_code,mappings}`. State is disabled/available/error/simulation. Each row contains the existing mapping_id/port/protocol plus IPv6 address, pending/mapped/blocked/error/removing state and a curated error code. Reports are node-bound, tuple-checked and bounded to 512 address/ID rows; old address leases may temporarily accompany their replacements. Empty reports are valid. DNS is outside scope.

Only outbound transport may open IPv6 pinholes. Address discovery is local to the service; remote configuration cannot supply a destination IPv6 address. Eligible ports must have a local IPv6 listener. Rules use finite 120-second leases and tuple-based renewal; cessation/rotation relies on expiry rather than ID-only deletion. Authentication rejection/expiry pauses both families. A denied verification response is explicitly unverified and cannot become Mapped.

## Outbound HTTPS transport (0.2.0)

The service contacts the panel at `/api/port-opener/v1` over verified HTTPS; it opens no listener. Existing local API mode remains supported. Each node has a separate installation UUID, desired generation, credential and cached report. Existing installations retain their UUID and generation when migrating.

- `POST /pair`: `{pairing_code}` → `{credential, expires_at, node_id, installation_id}`. Random 256-bit codes are hashed, node-bound, single-use and expire after ten minutes.
- `GET /desired-state`: bearer credential → `{schema_version:1, configuration, desired_state, test_request_id}`. Configuration contains `node_id, forwarding_type, router_url, internal_address, min_port, max_port, dry_run, lease_seconds, reconcile_seconds`. Snapshot schema is unchanged.
- `POST /report`: bearer credential, `{status, test_request_id?, test_result?}` → `{accepted:true}`. Mapping identifiers and port/protocol tuples must belong to this node's current or previously reported snapshot. Arbitrary diagnostic messages are not persisted.
- `POST /renew`: bearer credential → replacement credential response, saved atomically before switching. Credentials expire after 30 days; renewal leaves five minutes of overlap. Credentials are only stored hashed by the panel and privately by the service.

HTTP and redirects are refused. Standard CA/hostname verification is mandatory. API requests are throttled. The bearer credential selects its node, so payloads cannot select a different node. A verified authentication rejection or expiry pauses router lease renewal while preserving the accepted desired generation; valid network outages retain the previous desired mappings. Revocation is bounded by existing finite lease duration. Router tests are read-only and correlated with a queued request UUID and saved router settings.

Administrative node modal configuration is separate from server exposure approval. Root administrators can pair, revoke or rotate. Rotation revokes existing credentials and generates a new pairing code. Long-term credentials are never displayed in the web UI. Optional legacy URL/token settings use encrypted write-only token storage and fall back to existing panel environment settings for the configured legacy node.

## Legacy local transport

Plugin ID: port-opener. Namespace: PortOpener. Development panel plugin API 1.

PortOpenerService endpoints: authenticated GET /v1/health, GET /v1/status, PUT /v1/desired-state. Bearer token from environment/config file. Bind loopback by default; Docker development service binds only private compose network, never a published host port. Production host PortOpenerService uses loopback. Fake router is default in development.

Additive status field `backend` is `fake` or `upnp`; UI labels fake results as simulation independently of dry_run.

## Per-node router settings

NodePolicy adds forwarding_type (upnp) and router_url (nullable for pre-existing configurations). All node rows are visible to administrators, with saved settings and explicit service connection state. Only the configured local service node is synchronized in this version; saving another node's settings does not imply a service connection.

Authenticated PUT /v1/router/configuration and POST /v1/router/test accept exactly node_id, forwarding_type, router_url. URL must be HTTP with a private RFC1918 numeric IPv4 host, a nonempty description path, no credentials/query/fragment and a valid port. Both require the configured node. Test discovers candidate without changing mappings; fake backend returns an explicit simulated result. Configuration cannot change backend, dry_run, token, destination address or allowlist. Switching a real router requires owned/desired mappings empty first. Persist URL and restore on restart. Same URL is idempotent. Expose configured router_url in service status.

PUT body: {"schema_version":1,"installation_id":"UUID","generation":1,"node_id":1,"mappings":[{"mapping_id":"serverUUID:allocationID:tcp","server_uuid":"UUID","allocation_id":1,"internal_address":"192.168.178.50","port":54321,"protocol":"TCP"}]}
One complete snapshot for configured installation/node. Single node in MVP. port 1..65535 constrained by service allowlist. TCP/UDP only. IDs validated, duplicate protocol+port rejected. Configure trusted installation and internal address on service, never trust request address alone. Lower generation rejected; equal generation accepted only if identical. Empty valid mappings cleans owned rules. Invalid input never changes state.

PUT returns accepted generation and status (202 for queued or 200 for synchronous). GET status {"schema_version":1,"generation":1,"dry_run":true,"router_reachable":true,"last_reconciled_at":"ISO8601 or null","mappings":[{"mapping_id":"...","port":54321,"protocol":"TCP","state":"pending|mapped|conflict|blocked|error|removing|removed","error_code":null,"message":"..."}]}. Failures JSON {"error_code":"stable_code","message":"readable"} with appropriate 400/401/409/503. No token in responses/logs. Router status is not proof of external reachability.

## Historical MVP model and UI notes (superseded)

Panel database tables managed by the plugin: port_opener_node_policies (node_id unique, enabled false, internal_address, min_port, max_port); port_opener_egg_rules (egg_id, name, source primary_allocation|variable, variable_name nullable, protocols JSON list TCP/UDP, public bool); port_opener_exposures (server_id unique, enabled false); port_opener_sync_states (installation_id, generation, desired_hash, last_status JSON, last_error, synced_at). Keep UI integration aligned when database fields or types change.

Panel services expected by UI: ExposureService::preview(Server): array of mapping rows; ExposureService::setEnabled(Server, User, bool): void checks permissions; SyncService::sync(bool $dryRun = false): array; SyncService::status(): array. Exposure permission: owner or root admin for MVP (subuser changes deferred); tenant visibility still follows panel access, status/exposure controls cannot cross server boundaries. Keep the UI aligned with the backend service namespaces and signatures.

Node policies and egg rules admin-only. UI uses backend methods for user mutations. Service endpoint/token config from plugin config/env; administrators only, no user-controlled URLs. Backend queues/debounces reconciliation, collects full snapshot successfully before submitting; excludes suspended/deleted/disabled servers and unapproved ports. Unknown metadata blocks exposure. No core/Wings edits, live DB reads, or real router writes in service development tests.
