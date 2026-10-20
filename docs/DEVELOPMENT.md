# Development

Use a Linux Docker host with Python 3.11+ and Docker Compose. Clone this repository into a new development directory, then run:

```sh
python3 packaging/setup_dev.py
docker compose up -d --build
```

The panel is bound to `127.0.0.1:18080`. For a remote development host, forward that port with SSH, substituting your own username and host:

```sh
ssh -N -L 127.0.0.1:18080:127.0.0.1:18080 developer@development-host.example.com
```

Open http://localhost:18080. Create development accounts with Pelican's built-in interactive command; choose an administrator named `dev-admin` for the legacy admin UI checks:

```sh
docker compose exec panel php artisan p:user:make
```

For the legacy fixtures, also create non-administrator users named `dev-owner` and `dev-other` with synthetic addresses. Enter passwords interactively rather than putting them in command arguments or files. The disposable integration runner creates its own accounts. See [Pelican's user command documentation](https://pelican.dev/docs/panel/advanced/artisan/).

Do not share `.env.dev`. The development panel uses a pinned Pelican image, a separate SQLite database, synthetic servers and a fake router. No live Wings node should be connected. The service has no published host API port. The development Compose file is not a production deployment configuration.

## Source and checks

- The repository root: installable PHP plugin manifest, `src/`, `config/`, `database/`, `resources/` and `routes/`.
- `service/`: Python service, router backends, diagnostics and unit tests.
- `contracts/`: shared API contract.
- `packaging/`: development setup, builders, guarded verification and public export tools.
- `tests/`: PHP plugin/resolver/router tests, isolated Pelican harnesses and manual integration probes.
- `docs/`: installation, operations, validation and publication guidance.

Run from the repository root:

```sh
PYTHONPATH=service python3 -m unittest discover -s service/tests
python3 packaging/egg/test_packaging.py
python3 -m unittest discover -s packaging -p 'test_*.py'
sh packaging/verify_ipv4_pelican.sh
```

The Pelican verification runner builds the plugin ZIP, extracts that deployable artifact and creates a disposable database in a container with networking disabled. Existing panel credentials and databases are not mounted. Other PHP fixtures require the isolated development app and must never run against a production panel. Linux is required for service tests that verify process locks and private POSIX permissions.

Global IPv6 values in synthetic tests are fixtures rather than operator addresses. Some tests need an address that Python classifies as global; they do not contact that address.

## Build and publish

```sh
python3 packaging/build_release.py
python3 packaging/public_export.py --check
python3 packaging/check_hub_submission.py
```

Builders use the explicit [public file list](../packaging/public-files.txt). Unlisted files are not packaged. Review new paths and image pixels before adding them. Bump the plugin version whenever service bundle bytes change; published versioned bundles must remain immutable.

See [PUBLICATION.md](PUBLICATION.md) for the fresh repository export. Generated credentials, ownership state, databases, archives, backups and development account passwords must remain private. New production deployments normally use the managed IPv4 Egg or a deliberately configured host service, not the fake development service.

## Service naming upgrade

The service source, development build context and test suite live under `service/`. Host archives use `port-opener-service-VERSION.tar.gz`, and examples use `service.json`. When updating an existing host unit or Compose configuration, retain the current configuration contents, credentials, installation identity and ownership state; update the launch path to the existing configuration file as needed. No live files are moved by the source rename. Existing production Compose state-volume names are unchanged.

## Plugin root layout

The hub-facing manifest is `plugin.json` at the repository root. Runtime directories are siblings of that file, while Python source stays in `service/` and PHP checks live in `tests/`. The development panel bind-mounts this root at both of its `plugins/port-opener` paths. Root README and license files are the canonical documentation and attribution; there are no nested duplicates.

The plugin ZIP contains only the reviewed runtime directories, manifest, root README/license/notices and documentation. Development files and unlisted local files are excluded. Layout regression tests build in a temporary source copy with unlisted private-file probes, and the Pelican runner exercises the actual built ZIP. The existing versioned service bundle remains byte-identical.

Source clones retain development tools and tests. Git archives use `.gitattributes` export exclusions to omit `packaging/`, `tests/`, `service/`, `contracts/` and development Compose/configuration files, while keeping root `plugin.json` and bundled service resources. Clone the repository for development; an exported deployment archive is not the development checkout.
