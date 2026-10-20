"""Build releases exclusively from the reviewed public file list."""
import json
from pathlib import Path
import tarfile
import zipfile
from build_ipv4_egg import build as build_ipv4_egg
from public_export import Audit, public_files

ROOT = Path(__file__).resolve().parents[1]


def build():
    build_ipv4_egg()
    names = public_files(ROOT)
    audit = Audit()
    if audit.check(ROOT, names)['findings']:
        raise ValueError('Public audit has findings; run packaging/public_export.py --check')
    manifest = json.loads((ROOT / 'plugin/port-opener/plugin.json').read_text(encoding='utf-8'))
    version = manifest['version']
    output = ROOT / 'dist'
    output.mkdir(exist_ok=True)
    docs = [name for name in names if name.startswith('docs/')]
    prefix = 'plugin/port-opener/'
    with zipfile.ZipFile(output / f'port-opener-{version}.zip', 'w', zipfile.ZIP_DEFLATED) as archive:
        for name in names:
            if not name.startswith(prefix) or name.startswith(prefix + 'tests/'):
                continue
            target = 'port-opener/' + name.removeprefix(prefix)
            if name == prefix + 'plugin.json':
                clean = dict(manifest)
                clean.pop('meta', None)
                archive.writestr(target, json.dumps(clean, indent=2) + '\n')
            elif name == prefix + 'README.md':
                archive.write(ROOT / 'README.md', target)
            else:
                archive.write(ROOT / name, target)
        archive.write(ROOT / 'THIRD_PARTY_NOTICES.md', 'port-opener/THIRD_PARTY_NOTICES.md')
        for name in docs:
            archive.write(ROOT / name, 'port-opener/' + name)
    with tarfile.open(output / f'port-opener-service-{version}.tar.gz', 'w:gz') as archive:
        for name in ('port_opener.py', 'outbound.py', 'ipv6.py', 'diagnostics.py', 'diagnostics-schema.json', 'config.example.json', 'Dockerfile'):
            archive.add(ROOT / 'service' / name, arcname='port-opener-service/' + name)
        for name in ('README.md', 'LICENSE', 'THIRD_PARTY_NOTICES.md'):
            archive.add(ROOT / name, arcname='port-opener-service/' + name)
        archive.add(ROOT / 'service/README.md', arcname='port-opener-service/SERVICE.md')
        for source, target in (
            ('configure_new_install.py', 'configure_new_install.py'),
            ('compose.release.yml', 'compose.yml'),
            ('compose.outbound.yml', 'compose.outbound.yml'),
            ('port-opener.service', 'port-opener.service'),
        ):
            archive.add(ROOT / 'packaging' / source, arcname='port-opener-service/' + target)
        for name in docs:
            archive.add(ROOT / name, arcname='port-opener-service/' + name)
    print(f'Built plugin and service release {version} from the public allowlist in {output}')


if __name__ == '__main__':
    build()
