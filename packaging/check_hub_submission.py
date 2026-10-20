"""Preflight known blocked native PHP writes/process calls in source or a ZIP.

This conservative local check covers the reported Hub rejection and common native
write/process calls. It is not the Hub validator and cannot guarantee acceptance.
It scans development PHP too, because submissions may inspect the full checkout.
"""
import argparse
import json
from pathlib import Path
import re
import zipfile

from public_export import ROOT, public_files

BLOCKED = frozenset({
    'file_put_contents', 'fwrite', 'fputs', 'fopen', 'ftruncate',
    'chmod', 'chown', 'chgrp', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'touch',
    'exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'pcntl_exec',
})
CALL = re.compile(r'(?<![\w:>$])\\?(?P<function>' + '|'.join(sorted(BLOCKED)) + r')\s*\(', re.I)
# Preserve line offsets while ignoring PHP comments and quoted text.
NON_CODE = re.compile(r"//[^\n]*|\#[^\n]*|/\*.*?\*/|'(?:\\.|[^'\\])*'|\"(?:\\.|[^\"\\])*\"", re.S)


def scan_sources(sources):
    findings = []
    for name, data in sources:
        if not name.lower().endswith('.php'):
            continue
        text = data.decode('utf-8')
        code = NON_CODE.sub(lambda match: ''.join('\n' if char == '\n' else ' ' for char in match.group()), text)
        for match in CALL.finditer(code):
            findings.append(dict(path=name, line=code.count('\n', 0, match.start()) + 1,
                                 function=match['function'].lower()))
    return findings


def check_repository(root=ROOT):
    return scan_sources((name, (root / name).read_bytes()) for name in public_files(root))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--zip', type=Path, help='Check a built plugin ZIP instead of source')
    args = parser.parse_args()
    if args.zip:
        with zipfile.ZipFile(args.zip) as archive:
            findings = scan_sources((name, archive.read(name)) for name in archive.namelist())
    else:
        findings = check_repository()
    print(json.dumps(dict(findings=findings), indent=2))
    return bool(findings)


if __name__ == '__main__':
    raise SystemExit(main())
