# Managed IPv4 Egg verification

Verified on 20 October 2026 against Pelican's 1.0.0-rc.1 development image and the installed Wings 1.0.0-rc.1 API contract.

## Results

| Check | Result | Boundary |
| --- | --- | --- |
| Python service tests | 67 passed | Includes outbound credentials, TLS, scope, router ownership and IPv4 behavior |
| Egg packaging/bootstrap tests | 7 passed | Archive integrity, automatic pairing, interrupted bootstrap recovery and preservation of saved files |
| Isolated Laravel provisioning | 37 passed | Simulated creation/import/Wings; duplicate prevention, retries and reinstall safeguards |
| Real Pelican integration | 22 passed | Fresh SQLite schema, actual importer/model validation/creation/provider binding/Filament/actions and HTTPS routes; Wings HTTP is simulated |
| Actual Wings bridge network | Passed | Device description and SOAP reachable; existing UDP 2456 read-back matches the LAN host |
| Temporary bridge mapping | Passed | UDP 54329 created, verified and removed through PortOpenerService; no test listener opened |
| Real Wings installation | Passed | Separate Wings 1.0.0-rc.1 instance on a dedicated Linux host; actual queue, ZIP download, installation callback, file bootstrap, pairing and fresh report |
| Real Wings reinstallation | Passed | Same server UUID, unchanged credentials/configuration and installation identity, retained empty ownership state, fresh authenticated contact and reachable router |
| Runtime isolation and permissions | Passed | Bridge networking, no published allocation/API port; Wings UID 999 owns the 0700 data directory and 0600 credential/configuration/state files; consumed bootstrap removed |
| Explicit connection check through the Egg | Passed | Normal panel test request consumed by the actual service; FRITZ!Box discovery and read-only `GetExternalIPAddress` SOAP succeeded |

The temporary port was checked against host listeners and Pelican allocations before testing. The probe used a separate installation identity, local ownership state and a 120-second lease. Cleanup submitted an empty snapshot and confirmed that the rule was absent. The four existing game mappings remained mapped.

## Defect reproduced and fixed

Reinstallation previously accepted any heartbeat after the operation was requested. The previous service process could have sent that heartbeat before it was stopped, causing a newly started process to appear ready prematurely.

The installer now records `service_started_at` immediately before starting the process and waits for authenticated contact strictly after that timestamp. The operation timeout still uses the original `started_at`. A regression test reproduced the failure before the fix and now confirms that an old heartbeat is rejected and new contact completes setup. Timestamp resolution can require waiting until the next poll before showing Ready.

## Reproduce

From the project directory on a Linux Docker host:

```sh
sh packaging/verify_ipv4_pelican.sh
```

This runner copies only plugin source into an ephemeral container, enables its private plugin copy and builds an empty SQLite database. Its Docker network is disabled. It does not read an existing panel database, production credentials or Wings configuration. `APP_ENV=local` is deliberate: Pelican disables plugin loading under `testing`.

The smaller harness can run with an isolated Laravel vendor directory:

```sh
php plugin/tests/ipv4_installation.php /path/to/laravel/vendor/autoload.php
python packaging/egg/test_packaging.py
PYTHONPATH=service python -m unittest discover -s service/tests
```

`tests/integration/bridge_probe.py` is a manual router mutation test, outside the automated suites. It requires explicit authorization, prior listener/allocation checks and the configured router/LAN address. It must not be scheduled.

## Real Wings commissioning

An additional end-to-end run used a fresh, disposable panel database and a separate instance of the server's installed Wings binary. The normal database queue worker completed installation and reinstallation without mocked Wings responses or manually advancing provisioning phases. Repeated install requests reused the same installation and only one managed server existed.

The test panel served the unmodified bundled ZIP over HTTPS on its private Docker network. A temporary CA signed its certificate; verification remained enabled for the installer, runtime and Wings callbacks. Test images extended the shipped runtime (`ghcr.io/parkervcp/yolks:python_3.12`) and installer (`python:3.12-slim-bookworm`) solely to trust this CA. The test Egg changed those two image references; its script, startup command, three environment variables and service payload were unchanged. This validates the shipped base images with an additional trust anchor, rather than an unmodified image against a publicly trusted certificate.

The actual runtime paired automatically, deleted its bootstrap, reported the FRITZ!Box reachable and reached Ready. Reinstallation downloaded and installed code again, then reused the same credentials and configuration; hashes of those private files matched. The installation identity and empty ownership state also matched, and a fresh post-start heartbeat completed readiness.

Router connectivity was established separately through the normal Check connection request and its authenticated result. An empty reconciliation currently sets `router_reachable=true` without a router request, so that field alone is insufficient evidence when no mappings exist. Improving that health signal remains a follow-up; it did not replace the explicit connection test in this verification.

This commissioning used preview mode with no approved game allocations. Preservation of a nonempty mapping ownership journal during an actual Egg reinstall and external game connectivity remain outside this run. The separate active bridge probe above already verified real router create/read/delete operations. All 133 automated checks were rerun successfully on the server. Public certificate trust was checked separately after deployment, as described below.

At the end of isolated commissioning, the live panel was still on 0.4.1 with its host service active. Fresh managed installation intentionally rejects a node with existing live credentials or mapping ownership. The Install button does not migrate an existing host service. IPv6 continues to require the host deployment.

## Live deployment — 20 October 2026

At the operator's request, the tested 0.5.0 plugin was installed on the live panel, including the latest navigation, node overview and ports-filter UI. The uploaded plugin ZIP checksum matched the local package. Plugin source, plugin tables and host-service data were backed up privately on the server.

The existing host service first processed an empty snapshot and confirmed all four managed IPv4 rules removed. Its durable journal then contained no IPv4 or IPv6 ownership and no desired mappings. Only after that acknowledgement was the host container stopped and its pairing revoked. The node was prepared in Preview for a fresh managed Egg installation; allocation protocols and server forwarding settings were preserved. Verification excluded the observation timestamps updated by normal runtime polling.

The new migration and bundled release validation passed, the queue worker restarted and the panel returned HTTP 200. Both unmodified Egg base images successfully downloaded the live panel's service ZIP using their normal certificate stores, with TLS verification enabled and a matching bundled checksum. The service files also matched the fixtures used for commissioning. No new Egg was started during this deployment; live setup and active forwarding were left for the operator to test. Running game-server containers were unchanged.

## API contract review

Wings 1.0.0-rc.1 supplies the directory, write, chmod and rename endpoints used by provisioning. Its filesystem implementation assigns files/directories to the configured server UID. Missing directories produce 404 responses, which the transport handles. These endpoints and resulting file ownership were also exercised by the real commissioning run.

- [Wings file routes](https://github.com/pelican-dev/wings/blob/v1.0.0-rc.1/router/router.go)
- [File API handlers](https://github.com/pelican-dev/wings/blob/v1.0.0-rc.1/router/router_server_files.go)
- [Filesystem ownership and directory handling](https://github.com/pelican-dev/wings/blob/v1.0.0-rc.1/server/filesystem/filesystem.go)
- [Docker container configuration, including allocation-free servers](https://github.com/pelican-dev/wings/blob/v1.0.0-rc.1/environment/docker/container.go)


## IGD2 managed reinstall - 0.5.2 (2026-10-20)

The live managed service was upgraded in place to 0.5.2 after its empty snapshot and durable journal confirmed cleanup. The same Wings server UUID and pairing were retained. The service's installed source checksum matches the reviewed source. A fresh read-only check of the IGD2 URL passed before forwarding resumed; all four approved UDP mappings were verified through WANIPConnection:2 at matching generation 14. The two running game containers were not restarted. This validates controlled cleanup/reinstall/resume, not uninterrupted forwarding or external game connectivity. No IPv6 exposure was enabled.
