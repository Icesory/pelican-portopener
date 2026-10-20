# PortOpener host PortOpenerService

## Choose your installation method

For the normal managed IPv4 installation, use **Admin → Server → PortOpener → Overview → Install IPv4 service** after saving the node configuration. The plugin installs the service through Wings and supplies a one-time pairing bootstrap. Pairing happens automatically when the service starts; there is no code to paste into the panel or the managed server's settings.

The manual steps below are for a **host deployment** (for example Docker Compose or systemd, including optional IPv6). Do not run a host service and a managed Egg service for the same node at the same time.

## Manual host pairing

1. Open **Admin → Server → PortOpener → Overview** and click the node's **pencil** button. For a new node, save it first, then reopen its configuration; maintenance actions are only shown for saved nodes.
2. Under **Advanced configuration**, select **Paired outbound HTTPS** as the service connection and save if necessary. The panel must have a valid public HTTPS origin configured.
3. Reopen the node configuration and expand **Service connection and maintenance**. Click **Generate pairing code**. The dialog displays the one-time code and its expiry. The code is valid for ten minutes and can be used once.
4. On the machine running PortOpenerService, open an interactive terminal (or SSH session), change to the extracted service directory and run:

```sh
python3 port_opener.py pair --panel https://panel.example.com \
  --credentials /protected/credentials.json \
  --config /protected/service.json
```

Replace the panel URL and `/protected/` paths with your own HTTPS origin and a private directory writable by the service user. At the terminal's **One-use pairing code:** prompt, paste the code from the panel and press Enter. Characters are deliberately not displayed while you type or paste. **There is no pairing-code input field in the Pelican web interface.**

After successful pairing, start the service with the saved configuration:

```sh
python3 port_opener.py run --config /protected/service.json
```

Use **Check connection** in the node dialog to request a router check. It verifies router communication rather than external game connectivity. Preserve credentials, configuration and ownership state across restarts and upgrades; the generated code is not a replacement for those files.

## Router read-back compatibility

Router read-back compatibility: if `GetSpecificPortMappingEntry` returns UPnP 714,
the service checks `GetGenericPortMappingEntry` before declaring a port absent.
Our FRITZ!Box reports 714 for active wildcard rules that enumeration returns
correctly. The fallback matches external port/protocol, destination, enabled flag
and ownership description. It reads at most 256 entries within a ten-second
enumeration budget. Only end-of-table error 713 establishes absence; timeouts,
denied enumeration and malformed entries remain errors and cannot authorize
creation or deletion. Restricted remote-host entries are not adopted.

## IGD2 selection

The service prefers WANIPConnection:2 over WANIPConnection:1 and WANPPPConnection:1.
For the FRITZ!Box description paths, discovery tries `/igd2desc.xml` before
`/igddesc.xml` on the same configured origin. Other vendor paths stay exact.
A read-only GetExternalIPAddress probe chooses a working interface before writes.
Unavailable/unsupported discovery and reads can fall back; authentication or
permission denial and unsafe URLs do not. Read retries are bounded and mapping
writes are never retried through another interface with an uncertain outcome.
Status reports include the selected service identifier. The configured URL stays
pinned for policy/ownership identity even when discovery selects a fallback.
IPv6 firewall control remains separate; the managed Egg stays IPv4-only.

## Outbound transport details

The low-level service executable retains its legacy local API default when no transport
is configured. The panel's normal managed setup uses paired outbound HTTPS. Outbound
transport opens no inbound listener and verifies Pelican HTTPS. A custom CA is optional;
verification cannot be disabled.

Pair: `python3 port_opener.py pair --panel https://panel.example.com --credentials /protected/credentials.json --config /protected/service.json`.
The code is prompted invisibly. Optional `--code` exposes it in shell history/process
listings. Optional `--config` bootstraps node settings without touching mappings.
The panel's node dry-run setting controls forwarding after pairing, with new nodes
defaulting to dry-run. Generated configuration does not impose an extra local dry-run
ceiling; operators may optionally set local `dry_run:true` for an additional safety lock.
Existing credentials/config files are never overwritten.

Run: `python3 port_opener.py run --config /protected/service.json`.
Outbound polling reports status and executes read-only router tests. Panel outages
retain the previous desired state; the ownership engine continues lease renewal.
Tokens last 30 days and renew within 24 hours of expiry. Credentials are atomically
saved with mode 0600 before rotation switches. The panel provides five-minute old
credential overlap; expired/revoked credentials outside overlap require re-pairing.
No credential is logged. Pairing errors never disable TLS verification.
Verified 401/403 responses or credential expiry pause router renewal without changing
the stored desired generation. Existing finite leases then expire naturally. A
network outage with a still-valid credential retains renewal. After authentication
is repaired, the same desired generation can resume normally. Revoke after cleanup
when immediate closure is required; revocation alone is bounded by lease duration.

Environment overrides: `PORT_OPENER_CONFIG`, `PORT_OPENER_TRANSPORT`,
`PORT_OPENER_PANEL_URL`, `PORT_OPENER_CREDENTIALS_FILE`, `PORT_OPENER_CA_FILE`.
Changing the paired origin requires re-pairing. JSON configuration remains supported.
Remote settings cannot change backend, destination, node/installation scope or
exceed local allowed ports. Local dry-run cannot be disabled remotely. Read-only
test UUID/results are persisted against replay, bounded to 256 entries before review.
Pairing pins the initial destination address and allowed port range locally.
Changing these later requires an operator to review the local configuration;
the UI cannot redirect mappings to another LAN destination silently. Router endpoint
changes also require complete desired/owned mapping cleanup. Lease duration and
renewal interval remain fully adjustable from the panel within finite-lease limits
(120..86400 seconds; renewal 5..300 seconds and at most one-third of lease).
Optional local `max_lease_seconds`/`max_reconcile_seconds` impose additional limits.

Python 3.11+ with no dependencies. Linux is required for the process ownership lock.

Run unit tests from this directory: `python3 -m unittest discover -s tests -v`.

Copy `config.example.json` outside the repository, replace installation UUID and
node/address/port policy to match the panel, and provide `PORT_OPENER_TOKEN` through
a restrictive environment file. The token must contain at least 32 characters.
Run `python3 port_opener.py --config /path/to/service.json`. All endpoints require
`Authorization: Bearer TOKEN`. Production binds loopback. Docker development may
bind `0.0.0.0` only inside a private compose network with no published service port.
Mount a writable `/state` volume and set state_file to `/state/state.json` for Docker.
Config is read-only; the image runs as uid 10001. Installation/node/address/allowed
range must be fixed administrative configuration.

`backend: fake` is the default. To select the actual router set `backend: upnp` and
configure its HTTP device-description URL using a private numeric LAN address.
`dry_run: true` validates and observes without any add, renewal or deletion.
Only explicit `dry_run: false` permits mapping mutation. No permanent-lease fallback
is implemented: routers rejecting finite leases report an error for manual review.
Status includes additive `backend: fake|upnp`. A fake backend with dry_run false
reports synthetic `mapped` states; the panel must label these as simulation.

Administrators can configure the node router through authenticated
`PUT /v1/router/configuration` or test it with `POST /v1/router/test`. Both take
`{"node_id":1,"forwarding_type":"upnp","router_url":"http://192.168.178.1:49000/igddesc.xml"}`.
Only the configured node is accepted. HTTP numeric RFC1918 IPv4 URLs with a path
are allowed; credentials, query strings, fragments and redirects are rejected.
Configuration persists atomically in the state file and is restored on restart.
The backend, dry-run setting, address, port range and token cannot change through
these routes. For UPnP, changing endpoints requires empty desired mappings and
completed ownership cleanup; an unchanged endpoint is always idempotent.
The connection test discovers a candidate and reads `GetExternalIPAddress` only;
it never opens a port and does not prove mapping permissions or inbound reachability.
In fake mode the test only validates the URL and sends no network traffic.

Snapshots are complete, monotonically increasing generations. Equal generations
are accepted only for the same canonical snapshot. The last accepted snapshot is
retained through panel outages; PortOpenerService continues renewing its finite leases.
An empty newer snapshot requests cleanup. Before disabling or uninstalling, submit
that snapshot and verify removed status and no retained owned entries. Stop the
process only after cleanup; if offline, resume it to retry cleanup. Stopping the
process without cleanup allows finite leases to expire naturally.

Owned records include exact address, port, protocol and description plus creation
phase. Creation intent is flushed to disk before the router mutation. After a
crash during creation, a matching router rule remains ambiguous and will never be
adopted or deleted automatically. Investigate manually and remove only a verified
service mapping; then restart/reconcile. Unknown or changed router mappings produce
conflicts. Finite lease expiry/router reboot triggers re-creation. State must live
on durable local storage; losing it intentionally forfeits deletion authority.

UPnP has no conditional update/delete primitive. An administrator or another
router client changing a rule between observation and mutation creates an
unavoidable race. Use one service per node, avoid concurrent management of its
assigned ports, and do not treat descriptions as protection against malicious LAN
clients. Router status does not establish Internet reachability.

The service discovers WANIPConnection/WANPPPConnection from the fixed description
URL, without SSDP broadcast. Requests have bounded bodies, mapping count and
timeouts. No token/request body is logged. Source address and router configuration
remain separate from tenant-controlled requests. The fake backend is in-memory;
restarting it intentionally simulates router reboot.


## Sanitized support events (0.6.0)

`diagnostics.py` and its closed JSON schema are required release files. The service writes a private, atomic `state.json.diagnostics.json` beside its ownership state and emits the same sanitized events to stdout. At most 128 events and 64 KiB are retained. Mapping state changes, failures, recovery, router selection/fallback, pairing/setup and accepted generations are recorded without free-form messages, URLs or exception text. Unknown error strings become `internal_error`.

An optional top-level `diagnostics_control` in the authenticated desired-state response carries an absolute debug expiry, separate from configuration for older-client compatibility. The service clamps a request to 900 seconds and uses wall and monotonic deadlines. Repeated delivery of the same request cannot extend it; stop/expiry is persisted. Debug adds repeated mapping checks/report successes. Logs are supplemental: write failures are reported as `storage_ok:false` and never change ownership or stop forwarding. Outbound reports carry sanitized history for the administrator's per-node download. During a panel outage, local history still works while panel downloads remain cached.
