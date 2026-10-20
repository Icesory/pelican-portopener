# Validation and known limits

The development baseline is Pelican Panel 1.0.0-rc.1, plugin API 1, Wings 1.0.0-rc.1 and Linux with Python 3.12. Compatibility with other panel versions, routers or operating systems is not established by these results.

## Existing functional verification

The 0.6.0 baseline passed 96 Linux service tests, 105 fresh Pelican integration checks, seven Egg packaging tests and 58 native admin checks. Later wording/save-placement changes retained those paths. These are historical results, not a claim that every suite was rerun for each documentation change.

Coverage includes:

- Node-scoped authenticated pairing, TLS validation, credential rotation/replay, generation ordering and guarded API routes.
- Exact allocation ownership, protocol approval, cleanup retries, conflict/read-back handling, IGD2 preference and bounded IGD1 fallback.
- Managed Egg installation/reinstallation, preserved private data and readiness requiring a fresh post-start service report.
- Native admin tables, grouped nested port search, filters, fixed child columns, server allocation actions and administrator-only mutations.
- IPv6 address/interface eligibility, listener checks, explicit approval, address changes and finite unverified pinhole expiry.
- Closed-schema diagnostics, hostile cached strings, secret exclusion, report limits, debug expiry and private local history.

Manual router testing verified IPv4 TCP/UDP create/read/delete behavior on a FRITZ!Box 6591 Cable. A temporary unused UDP mapping also passed through IGD2 from a Wings bridge network and was removed. A disposable Wings instance completed queued HTTPS download, automatic pairing and reinstall. Controlled cleanup/reinstall/resume preserved managed service identity and verified approved IPv4 mappings afterward.

Those checks do not establish uninterrupted forwarding during reinstall or external game/browser reachability for every game. Router read-back and fresh service contact have narrower meanings than an outside client successfully joining a game.

## Public packaging verification

Version 0.6.3 added MIT notices, a reviewed public file manifest, export checks and package licensing. It does not change router reconciliation or panel permissions. The Egg installer additionally accepts the explicit `LICENSE` payload member. See [PUBLICATION.md](PUBLICATION.md) for the publication audit scope and repeatable checks.

## Before a public launch

- Repeat a clean plugin and managed Egg install on the intended release artifacts, including recovery from failed provisioning.
- Verify TCP and UDP game connectivity from another Internet connection.
- Exercise a second router model, multi-node operation, router reboot/lease renewal and loss of panel connectivity.
- Rehearse plugin upgrade, service reinstall, cleanup, disable/uninstall and coordinated recovery with nonempty ownership state.
- Confirm supported panel/Wings versions and establish a private vulnerability-reporting channel on the public repository.

IPv6 remains opt-in and experimental. The tested router accepts pinhole requests but denies verification, so those rules remain Requested (unverified). No independent external IPv6 traffic test has established reachability. The managed Egg remains IPv4-only. DNS, host firewall changes and ISP CGNAT/DS-Lite workarounds remain outside scope.

## Service naming — 0.6.4

Current source, exception names, internal variables, development configuration, container user, tests and host archives consistently use service naming. The default development configuration is `runtime/service.json`, the source/build directory is `service/`, and the host archive is `port-opener-service-0.6.4.tar.gz`. The current service bundle is rebuilt under a new version; older versioned ZIPs remain immutable for existing installation URLs. The HTTP `User-Agent` header retains its standard protocol name. Router reconciliation, pairing and ownership policy are unchanged.

The renamed 0.6.4 tree passed 96 Linux service tests, seven Egg packaging tests, 11 export tests and 105 fresh Pelican integration checks. The host archive setup, overwrite refusal, README assets and Compose files also passed. A fresh Docker image started with its default command, the service user and service.json; authenticated health/status checks used a fake router with networking disabled and no published host port. Live installations and router mappings were unchanged.

## Repository root plugin layout

Verified `plugin.json` at the source root with sibling runtime directories and relocated PHP tests. The build selects only reviewed runtime files for the installable `port-opener/` folder. Six layout regression tests verify root class resolution, complete ZIP structure, exclusion of development and unlisted local files, stripped installed metadata, canonical README/license, unchanged service ZIP bytes/checksum and the independent host archive.

The isolated Linux run passed 25 packaging/discovery/export/layout tests, seven Egg tests, 96 service tests, 105 real Pelican checks against the built plugin ZIP, 22 resolver checks, 21 router URL checks and 37 relocated installation checks. Windows also passed 17 PowerShell discovery checks. Actual Docker bind mounts resolved the root manifest, plugin class and bundled release at both development plugin paths. Host setup, overwrite refusal, documentation assets and Compose checks passed. No live installation was modified.

## Live plugin upgrade — 20 October 2026

The plugin ZIP built from commit `a847136` was installed over version 0.6.2 on the live panel as version 0.6.4. The uploaded ZIP checksum and all 89 deployed payload files matched the local release; installed enablement metadata was preserved. Existing migrations were byte-identical, with nothing to migrate. Route/view caches were cleared and the panel queue worker restarted.

Live rendering checks passed for the node overview, grouped Ports info, router discovery dialog and server PortOpener allocation tab. Pre-update allocation protocols, forwarding approvals, node settings and managed server identity matched after installation. The node remained Connected through IGD2 with five mapped IPv4 ports and matching desired/reported generation 17. The managed service and game containers were not reinstalled or restarted.

A Docker bridge client verified the current service download over trusted HTTPS, including its bundled SHA-256, the panel login returned HTTP 200 and the unauthenticated desired-state API returned HTTP 401. The prior plugin source and pre-update configuration snapshot were retained privately; the full eight-table plugin dump was completed after source replacement without schema changes. These checks cover a live plugin upgrade while retaining the existing service runtime; they do not establish fresh service installation or external game connectivity for this artifact.
