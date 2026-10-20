"""Copy an explicit public allowlist; inspect source and nested ZIPs before writing.

This is a publication guard, not a complete secret detector. Review new images
and use --private-values with a private JSON list of deployment-specific values.
Findings contain relative paths and rule names, never the matched text.
"""
import argparse
import collections
import io
import json
import math
from pathlib import Path, PurePosixPath
import re
import stat
import zipfile

ROOT = Path(__file__).resolve().parents[1]
LIST = 'packaging/public-files.txt'
MAX_BYTES = 64 * 1024 * 1024
PATTERNS = {
    'private-key': re.compile(r'-----BEGIN (?:RSA |OPENSSH |EC |DSA |ENCRYPTED )?PRIVATE KEY-----'),
    'provider-token': re.compile(r'gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{40,}|AKIA[0-9A-Z]{16}|xox[baprs]-[A-Za-z0-9-]{20,}'),
    'personal-home-path': re.compile(r'/home/(?!container(?:/|\b))[a-z_][a-z0-9_-]*/|[A-Z]:[\\/]Users[\\/](?!Public\b)[^\\/\s]+', re.I),
}
LITERAL = re.compile(r'''(?i)\b(?:\w*_)?(?:credential|token|secret|password|app_key)["']?\s*[:=]\s*["']?([A-Za-z0-9+/=_:-]{24,})(?:["']|\s|$)''')
PRIVATE_NAMES = {'token.env', 'panel-settings.env', 'service.json', 'credentials.json', 'state.json', 'bootstrap.json', 'dev-login.txt', 'backend-upload.tar'}


def safe_name(name):
    path = PurePosixPath(name)
    if not name or '\\' in name or ':' in name or path.is_absolute() or '..' in path.parts or str(path) != name:
        raise ValueError('Unsafe relative path')
    if any(part.lower() in {'.git', '.ssh', '.aws', 'runtime', 'dev-data', 'backups', 'vendor', 'node_modules', '__pycache__'}
           or part.lower().startswith('.env') or part.lower() in PRIVATE_NAMES for part in path.parts):
        raise ValueError('Private path is forbidden')
    if path.suffix.lower() in {'.pem', '.key', '.sqlite', '.log', '.tar', '.gz', '.env'}:
        raise ValueError('Private or unreviewed archive type')
    return path


def public_files(root=ROOT):
    names = [line.strip() for line in (root / LIST).read_text(encoding='utf-8').splitlines()
             if line.strip() and not line.lstrip().startswith('#')]
    if len(names) != len(set(names)) or len(names) != len({name.lower() for name in names}):
        raise ValueError('Duplicate public path')
    for name in names:
        source_path(root, name)
    return names


def source_path(root, name):
    path = root / safe_name(name)
    # Reject links in every component, including linked directories.
    current = path
    while current != root.parent:
        if current.is_symlink() or (hasattr(current, 'is_junction') and current.is_junction()):
            raise ValueError('Linked public path is forbidden')
        current = current.parent
    if not path.is_file() or not path.resolve().is_relative_to(root.resolve()):
        raise ValueError('Public path is missing or outside the project: ' + name)
    return path


class Audit:
    def __init__(self, private_values=()):
        self.private_values = [str(value).encode('utf-8') for value in private_values if len(str(value)) >= 8]
        self.findings = []
        self.entries = 0
        self.bytes = 0

    def finding(self, name, rule, line=None):
        self.findings.append(dict(path=name, rule=rule, **({'line': line} if line else {})))

    def inspect(self, name, data, depth=0):
        self.entries += 1
        self.bytes += len(data)
        if self.bytes > MAX_BYTES or depth > 4:
            raise ValueError('Audit size/depth limit exceeded')
        for value in self.private_values:
            if value in data:
                self.finding(name, 'private-value')
                break
        if name.endswith('.zip'):
            with zipfile.ZipFile(io.BytesIO(data)) as archive:
                members = archive.infolist()
                if len(members) > 1000 or len({entry.filename for entry in members}) != len(members):
                    raise ValueError('Invalid archive member count')
                for entry in members:
                    safe_name(entry.filename.rstrip('/'))
                    if stat.S_ISLNK(entry.external_attr >> 16) or entry.flag_bits & 1:
                        raise ValueError('Linked/encrypted archive entry')
                    if entry.file_size > MAX_BYTES - self.bytes:
                        raise ValueError('Archive size limit exceeded')
                    if not entry.is_dir():
                        self.inspect(name + '!' + entry.filename, archive.read(entry), depth + 1)
            return
        if name.endswith('.png'):
            # Historical browser captures have a .png name but JPEG encoding.
            if not data.startswith((b'\x89PNG\r\n\x1a\n', b'\xff\xd8\xff')):
                raise ValueError('Invalid reviewed image')
            return  # Pixel content requires separate visual review.
        try:
            text = data.decode('utf-8')
        except UnicodeDecodeError:
            self.finding(name, 'unreviewed-binary')
            return
        for number, line in enumerate(text.splitlines(), 1):
            for rule, pattern in PATTERNS.items():
                if pattern.search(line):
                    self.finding(name, rule, number)
            for match in LITERAL.finditer(line):
                value = match.group(1)
                frequencies = collections.Counter(value)
                entropy = -sum((n / len(value)) * math.log2(n / len(value)) for n in frequencies.values())
                if entropy >= 3.5:
                    self.finding(name, 'credential-literal', number)

    def check(self, root, names):
        for name in names:
            self.inspect(name, (root / name).read_bytes())
        return dict(files=len(names), entries_including_archives=self.entries,
                    bytes_inspected=self.bytes, findings=self.findings)


def export(root, destination, names, private_values=()):
    # Read and inspect the exact immutable byte buffers subsequently written.
    payloads = {name: source_path(root, name).read_bytes() for name in names}
    audit = Audit(private_values)
    for name, data in payloads.items():
        audit.inspect(name, data)
    if audit.findings:
        raise ValueError('Public audit has findings; run --check for redacted details')
    if destination.exists():
        raise ValueError('Export destination must be a new directory')
    destination.mkdir(parents=True)
    for name, data in payloads.items():
        path = destination / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
    return audit


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true', help='Audit only; no export')
    parser.add_argument('--output', type=Path, help='New directory to receive repository contents (without .git)')
    parser.add_argument('--private-values', type=Path, help='Private JSON string list; never copied or printed')
    args = parser.parse_args()
    if bool(args.check) == bool(args.output):
        parser.error('Choose exactly one of --check or --output')
    values = json.loads(args.private_values.read_text(encoding='utf-8')) if args.private_values else []
    if not isinstance(values, list) or any(not isinstance(value, str) for value in values):
        parser.error('Private values must be a JSON list of strings')
    names = public_files()
    if args.check:
        audit = Audit(values)
        print(json.dumps(audit.check(ROOT, names), indent=2))
        return bool(audit.findings)
    audit = export(ROOT, args.output.resolve(), names, values)
    print(f'Exported {len(names)} reviewed files; inspected {audit.entries} entries including ZIP contents. No Git history copied.')
    return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (ValueError, OSError, zipfile.BadZipFile) as error:
        # Error text from external inputs can contain secrets; only show type.
        print('Public export refused (' + type(error).__name__ + '). Check paths, manifest and audit findings.')
        raise SystemExit(1)
