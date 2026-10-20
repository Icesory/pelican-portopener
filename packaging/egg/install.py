"""Runs in the Egg installer. Download, verify, then install only known files."""
import hashlib
import io
import os
from pathlib import Path
import re
from urllib.parse import urlparse
from urllib.request import urlopen
import zipfile

FILES = {'port_opener.py', 'outbound.py', 'ipv6.py', 'diagnostics.py', 'diagnostics-schema.json', 'start_ipv4.py', 'LICENSE'}


def verified_files(payload, expected):
    if hashlib.sha256(payload).hexdigest() != expected.lower():
        raise ValueError('Release SHA-256 does not match')
    with zipfile.ZipFile(io.BytesIO(payload)) as archive:
        if len(archive.infolist()) != len(FILES) or set(archive.namelist()) != FILES:
            raise ValueError('Release has unexpected files')
        if any(item.file_size > 1024 * 1024 for item in archive.infolist()):
            raise ValueError('Release file too large')
        return {name: archive.read(name) for name in FILES}


def main(destination='/mnt/server'):
    url = os.environ.get('SERVICE_DOWNLOAD_URL', '')
    checksum = os.environ.get('SERVICE_SHA256', '')
    if urlparse(url).scheme != 'https' or not urlparse(url).hostname:
        raise ValueError('Set an HTTPS SERVICE_DOWNLOAD_URL to your hosted release ZIP')
    if not re.fullmatch(r'[0-9a-fA-F]{64}', checksum):
        raise ValueError('Set SERVICE_SHA256 from the trusted release checksum')
    with urlopen(url, timeout=60) as response:
        if urlparse(response.url).scheme != 'https':
            raise ValueError('Release download redirected away from HTTPS')
        payload = response.read(4 * 1024 * 1024 + 1)
    if len(payload) > 4 * 1024 * 1024:
        raise ValueError('Release download too large')
    files = verified_files(payload, checksum)
    root = Path(destination)
    root.mkdir(parents=True, exist_ok=True)
    for name, content in files.items():
        temporary = root / (name + '.install')
        temporary.write_bytes(content)
        temporary.replace(root / name)
    print('PortOpenerService IPv4 installed. Saved data was preserved. Start to pair.')


if __name__ == '__main__':
    main()
