# Managed IPv4 service Egg

Version 0.5.0 adds **Install IPv4 service**, **Resume IPv4 setup** and **Reinstall IPv4 service** to Admin → Server → PortOpener → Overview. The panel hosts the service ZIP and supplies a private pairing bootstrap through its authenticated Wings connection. No separate release host, manual Egg import or pasted console code is needed.

## Install

1. Import and enable `dist/port-opener-0.6.4.zip`. Let plugin migrations finish, and restart the panel queue worker so it loads the new job and service classes.
2. Ensure the panel has a trusted HTTPS origin in `APP_URL`, a working asynchronous queue worker and the normal every-minute Laravel scheduler. The installer refuses the synchronous queue driver. The worker must permit the job's 60-second timeout and queue `retry_after` must exceed it. Wings must be able to reach the panel's HTTPS download/API routes; the panel must be able to reach the configured Wings API.
3. Add the Wings node in the PortOpener overview, or open Configure. Use **Find router URL** beside the URL field if needed: run the downloaded helper on a computer on the Wings node's LAN and paste its result. Save the outbound HTTPS connection, router device-description URL, host LAN IPv4, allowed port range and lease settings. Disable IPv6 and begin in Test mode.
4. Click **Install IPv4 service**. The service runs under the installing root administrator, without subusers, with 128 MiB memory, 256 MiB disk, CPU limit 25%, and no incoming allocation. It uses the normal bridge network. Node capacity must permit those resources.
5. Watch the IPv4 service column. The plugin saves the server identity, requests installation, waits for Pelican's installation callback, supplies the private bootstrap and starts the service. `ready` requires a fresh service report and a running server, not just a successful start request.
6. Use Check connection and inspect Ports info. Test mode can read router mappings but does not open or renew ports. After reviewing configuration, switch to Active, repeat the read-only connection check, test one approved game allocation and verify external connectivity.

The service contacts a configured router URL over HTTP; it does not need SSDP multicast discovery. Bridge-to-LAN traffic must be permitted and the router must accept requests from this path. The mapping destination is the **host LAN IPv4**, not the service container's bridge IP. IPv6 is rejected locally even if enabled in panel policy later. The host deployment retains IPv6 support.

## Downloads and pairing

The plugin ZIP bundles a release ZIP, an Egg template and its SHA-256 manifest. This public, HTTPS-only route serves only bundled versioned ZIPs:

```text
https://YOUR-PANEL/api/port-opener/v1/downloads/port-opener-service-ipv4-0.5.0.zip
```

The archive contains four Python files and no credentials, configuration or pairing codes. Installation validates the full ZIP checksum and accepts only the expected filenames. The plugin supplies its configured HTTPS origin and bundled checksum automatically. A stable Egg UUID means importing updates the same Egg.

After code installation, the plugin generates a node-scoped, ten-minute, single-use pairing code. Through the authenticated Wings file API it creates a private `data/` directory, writes `bootstrap.json.pending`, sets permissions to 0700/0600, then renames the bootstrap into place. The code is not placed in an Egg variable, startup command, download URL or archive. Only its hash is stored in the pairing database.

The service validates the panel origin and bootstrap expiry, exchanges the code, checks the returned node and installation identity **before saving credentials**, and deletes the bootstrap after credentials are durable. If fetching initial configuration fails after pairing, restarting retries configuration bootstrap using saved credentials. Normal 30-day credential renewal continues unchanged.

## Duplicate prevention and retries

A database table has a unique node ID and unique server reference. Locking the existing node policy serializes first-time requests; later steps also lock the installation record. Repeated clicks reuse that record. A per-operation UUID makes old queued jobs harmless.

The server's database identity and UUID are committed **before contacting Wings**. Only managed creation is deferred from Pelican's normal creation service; other servers retain normal repository behavior. A lost Wings response is retried by checking the saved UUID and never creates a second UUID. Every step checks the server's node, Egg UUID, external marker, administrator ownership and absence of subusers.

Manual creation with the reserved service Egg is rejected; use the node action. An untracked server using that Egg also blocks a duplicate. Direct database changes, manually copied Eggs with different UUIDs and externally launched containers remain outside this workflow.

The scheduler requeues incomplete operations every minute. **Resume IPv4 setup** retries a failed operation using the same server. Setup times out after 25 minutes and shows a sanitized failure message including its phase. Raw credential-bearing HTTP exceptions are not stored in installation errors or job payloads. If code installation failed, fix its cause then use **Reinstall IPv4 service**. A lost reinstall response is uncertain: Resume waits for its callback; an explicit Reinstall can retry if the original request never reached Wings.

Deleting or moving the managed server does not silently create a replacement. Its saved reference remains and provisioning stops for administrator review. Keep the server on its assigned node and under root-administrator ownership.

## Reinstall and upgrade

Click **Reinstall IPv4 service** and confirm. The plugin stops the existing server, updates the release URL/checksum, commits its installing marker, reinstalls code and starts the same server. The installer replaces only:

- `port_opener.py`
- `outbound.py`
- `ipv6.py` (shared dependency; IPv6 stays disabled)
- `diagnostics.py` and `diagnostics-schema.json` (bounded, allowlisted event history)
- `start_ipv4.py`
- `LICENSE` (included from 0.6.3)

It leaves `data/credentials.json`, `data/service.json`, `data/state.json` and the process lock intact. The plugin checks filenames rather than reading credential contents through Wings. Existing credentials prevent generation of a new pairing code or replacement of configuration/state. The service removes obsolete bootstraps on startup without replacing credentials.

Back up `data/` privately before upgrades. Never delete ownership state to repair authentication. Missing credentials alongside existing configuration/state stop setup for explicit recovery. Revoked or expired credentials require the existing controlled recovery procedure; reinstall deliberately does not replace them. Changing the panel origin or locally pinned host address/allowed range requires explicit review.

Do not run the host service and Egg service together for one node. Fresh managed installation refuses nodes with active service credentials or outstanding reported mappings. Existing host deployments need a handover preserving installation identity and ownership state. Stop/clean up the host service before transferring data; clean up active IPv6 ownership on the host first. The button does not perform this migration automatically.

Stopping the service stops renewals; finite leases can remain until expiry. Configure your usual server restart policy for host reboots. The Egg denylist blocks credential, configuration and state file access through Wings. Bootstrap paths remain writable for provisioning; administrator-only ownership is required.

## Build and test

From the PortOpener directory:

```text
python packaging/build_release.py
```

This builds the plugin ZIP, host-service archive, IPv4 ZIP/checksum and Egg JSON. `build_ipv4_egg.py` alone refreshes IPv4 artifacts and bundled release resources. Retain older bundled ZIPs while any server still points to their versioned URLs. Never replace a published version with different source.

The standalone Egg export still supports manual console pairing on panels without managed provisioning after supplying HTTPS download and panel URLs. With this plugin enabled, the stable service Egg is reserved for managed installation.

The isolated harness uses Laravel/SQLite with simulated Pelican creation/import and Wings responses. A second harness uses the actual Pelican image, models, Egg importer, server creation service, Filament rendering and routed pairing/download endpoints, with only Wings HTTP responses simulated. It covers creation without allocations, duplicate prevention, one-use automatic pairing, reinstall persistence and readiness after a new service heartbeat. Python tests cover archive integrity, allowlisting, reinstalls, automatic pairing, partial-bootstrap recovery and IPv4 policy.

Run the PHP harness with an isolated Laravel 12 vendor autoloader (never against production):

```text
php plugin/tests/ipv4_installation.php /path/to/laravel/vendor/autoload.php
python packaging/egg/test_packaging.py
sh packaging/verify_ipv4_pelican.sh
```

Verification on 20 October 2026 passed 74 Python tests, 37 isolated provisioning checks and 22 real Pelican integration checks. A container on the actual Wings bridge reached the FRITZ!Box, created UDP 54329 to the host LAN address, verified it and removed it. No listener was opened. The existing four game mappings remain active.

A separate Wings instance on the server also completed actual queued installation, automatic HTTPS pairing and reinstallation. The same server UUID, credentials, configuration and installation identity survived; private files had the correct Wings ownership and permissions, and an explicit read-only connection test confirmed the router reachable. This used the shipped base images with a temporary test CA added, preview mode and an empty ownership journal. External game connectivity and preservation of nonempty mapping ownership during an Egg reinstall were not tested. Version 0.5.0 was subsequently deployed to the live panel on 20 October 2026, with the host service stopped after verified cleanup and the node prepared in Preview for operator testing. See [verification details](IPV4-VERIFICATION.md).
