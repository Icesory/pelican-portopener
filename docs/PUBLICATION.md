# Prepare the public repository

Copy **the contents of the reviewed export** into the new repository root. Do not copy this development checkout, its parent directory or any old `.git` directory. Existing local history is private and has not been rewritten or certified for publication.

The authoritative file-by-file list is [packaging/public-files.txt](../packaging/public-files.txt). It is an explicit allowlist, not a wildcard. It includes original source, tests, clean documentation, examples, builders, public export tools and required versioned service bundles. Files retain their paths relative to the PortOpener project root.

## Included and excluded

| Area | Public export |
| --- | --- |
| `LICENSE`, `THIRD_PARTY_NOTICES.md`, `README.md`, Git ignore/attributes | Included |
| `service/`, `contracts/`, `plugin/` | Reviewed source/tests and required bundled release assets |
| `packaging/` | Only the explicitly listed builders, examples and guarded development/test tools |
| `tests/integration/` | Fake flow and parameterized manual bridge probe; the old personal live probe is excluded |
| `docs/` | Listed generic guides and two visually reviewed synthetic screenshots |
| `.git`, parent work plans, private configuration, credentials, state, databases, logs, backups | Excluded |
| Old upload TAR, local deployment/debug scripts, historical live-service/recovery notes, other screenshots | Excluded |
| `runtime/`, `dev-data/`, `dist/`, dependencies | Excluded; release assets are built separately |

Older bundled service ZIPs remain for existing versioned download URLs. They were inspected as archives as well as files. Their bytes remain unchanged. New service ZIPs include `LICENSE`; the top-level repository/plugin license also applies to original code in historical bundles.

## Build, audit and export

From the project root, using Python 3.11+:

```sh
python3 packaging/build_release.py
python3 packaging/public_export.py --check
python3 packaging/public_export.py --output runtime/public-repository
```

The destination must not exist; the exporter never merges with or overwrites another checkout. It validates every path, rejects links/private filenames, scans text and nested ZIP members, then copies only the checked bytes. The release builder uses this same allowlist. A new unlisted file cannot silently enter the plugin or host archive.

For an additional private comparison, create an **untracked** JSON list containing known deployment-specific values (for example actual hostnames, globally routed addresses, usernames/paths and credential values). Keep it under `runtime/`, and supply it for both audit and export:

```sh
python3 packaging/public_export.py --check --private-values runtime/private-values.json
python3 packaging/public_export.py --output runtime/public-repository-reviewed --private-values runtime/private-values.json
```

Do not put that private JSON file in the export or share it. Results show only relative paths, rule names and line numbers. Matched values are never printed. An audit finding blocks export. Invalid/unreadable input also blocks export. A successful scan is a useful check, not a guarantee that arbitrary secrets or personal information cannot exist.

Use the file list again when adding new files. Visually review screenshots; source-text scanning cannot inspect pixels. Only two reviewed development screenshots are currently approved. Avoid photographing credentials, personal domains, globally routed addresses or account details.

## Publication audit — 20 October 2026

Tracked source and nested release assets were inspected for key/token signatures, credential assignments and personal deployment references. Personal domain/path references were removed from public guides; an operator IPv6 prefix reused by synthetic tests was replaced. Local deployment helpers and historical notes remain private. The old upload TAR was inspected and excluded. No real private key or credential literal was identified in the selected source or bundled service ZIPs.

The final export was additionally compared against known private deployment identities. No production credentials were retrieved for this audit. The final machine-readable result is kept privately with the export. No claim is made about old Git history, unselected local files or secret formats outside the checks. Keep the existing checkout and its history private.

The 156-file public set includes 204 inspected entries when bundled ZIP contents are counted. Two screenshots received visual review. The 0.6.4 verification on a separate Linux server directory passed 11 export-boundary tests, seven Egg packaging tests, 96 service tests and 105 fresh Pelican integration checks. Documentation assets, fresh helper setup/overwrite refusal and both Compose files also passed. Windows and Linux reproduced the same versioned service ZIP. A network-isolated container also verified the renamed Docker default startup, service user, service.json mount and authenticated fake-backend health/status. No live installation was changed.

## License and first push

Original plugin/service code, tools and documentation use MIT, copyright 2026 Icesory. This permits redistribution and modification while requiring retention of its notice. Upstream projects and trademarks retain their own terms; see [THIRD_PARTY_NOTICES.md](../THIRD_PARTY_NOTICES.md).

Create the GitHub repository empty (without an autogenerated README/license), or retain this license when combining with initialized files. Initialize Git only in the clean export/new clone and inspect `git status` before committing. Never add the old development repository as a remote to push its existing history. Installation ZIPs belong in GitHub Releases; the source repository is populated from the allowlist export.

Before the first release, enable GitHub private vulnerability reporting, review the remaining checks in [VALIDATION.md](VALIDATION.md), choose the public tag and configure update/download links for the actual repository. No repository URL or automatic update endpoint is guessed in advance.
