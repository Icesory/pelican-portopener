import hashlib
import io
import json
import os
from pathlib import Path
import sys
import tempfile
import time
from datetime import datetime, timezone
import unittest
from unittest.mock import patch
import zipfile

ROOT = Path(__file__).resolve().parents[2]
sys.path[:0] = [str(ROOT / 'service'), str(ROOT / 'packaging'), str(ROOT / 'packaging/egg')]
import install
import start_ipv4
import build_ipv4_egg


class PackagingTests(unittest.TestCase):
    def test_automatic_pairing_recovers_after_config_request_failure(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            data = root / 'data'
            data.mkdir()
            identity = '11111111-1111-4111-8111-111111111111'
            expires = datetime.fromtimestamp(time.time() + 3600, timezone.utc).isoformat()
            (data / 'bootstrap.json').write_text(json.dumps(dict(panel_url='https://panel.example.com', node_id=1,
                installation_id=identity, pairing_code='a'*64, expires_at=expires)))
            reply = dict(node_id=1, installation_id=identity, credential='b'*64, expires_at=expires)
            with patch.dict(os.environ, PORT_OPENER_PANEL_URL='https://panel.example.com'), \
                 patch('outbound.HTTPSClient.request', return_value=reply) as exchange, \
                 patch.object(start_ipv4, 'bootstrap_config', side_effect=RuntimeError('temporary outage')):
                with self.assertRaises(RuntimeError):
                    start_ipv4.prepare(root)
                self.assertEqual(exchange.call_count, 1)
            saved = (data / 'credentials.json').read_bytes()
            self.assertFalse((data / 'bootstrap.json').exists())
            def configure(credentials, credentials_path, destination):
                Path(destination).write_text('{"transport":"outbound","backend":"upnp"}')
            with patch.dict(os.environ, PORT_OPENER_PANEL_URL='https://panel.example.com'), \
                 patch.object(start_ipv4, 'pair') as pair, patch.object(start_ipv4, 'bootstrap_config', side_effect=configure):
                start_ipv4.prepare(root)
            pair.assert_not_called()
            self.assertEqual((data / 'credentials.json').read_bytes(), saved)

    def test_wrong_node_pairing_never_saves_credentials(self):
        from outbound import pair
        with tempfile.TemporaryDirectory() as tmp:
            destination = Path(tmp) / 'credentials.json'
            identity = '11111111-1111-4111-8111-111111111111'
            reply = dict(node_id=2, installation_id=identity, credential='b'*64,
                expires_at=datetime.fromtimestamp(time.time() + 3600, timezone.utc).isoformat())
            with patch('outbound.HTTPSClient.request', return_value=reply):
                with self.assertRaises(ValueError):
                    pair('https://panel.example.com', 'a'*64, destination, expected_scope=(1, identity))
            self.assertFalse(destination.exists())

    def test_reinstallation_preserves_data(self):
        archive, _ = build_ipv4_egg.build()
        payload = archive.read_bytes()
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / 'data').mkdir()
            saved = root / 'data/credentials.json'
            saved.write_text('keep credentials')
            (root / 'data/service.json').write_text('keep configuration')
            (root / 'data/state.json').write_text('keep ownership')
            before = {path.name: path.read_bytes() for path in (root / 'data').iterdir()}
            (root / 'outbound.py').write_text('old release')
            response = io.BytesIO(payload)
            response.url = 'https://example.com/release.zip'
            with patch.dict(os.environ, SERVICE_DOWNLOAD_URL=response.url,
                            SERVICE_SHA256=hashlib.sha256(payload).hexdigest()), patch.object(install, 'urlopen', return_value=response):
                install.main(root)
            self.assertEqual(saved.read_text(), 'keep credentials')
            self.assertEqual({path.name: path.read_bytes() for path in (root / 'data').iterdir()}, before)
            self.assertNotEqual((root / 'outbound.py').read_text(), 'old release')

    def test_release_and_embedded_installer(self):
        archive, egg_path = build_ipv4_egg.build()
        payload = archive.read_bytes()
        egg = json.loads(egg_path.read_text())
        checksum = next(row['default_value'] for row in egg['variables'] if row['env_variable'] == 'SERVICE_SHA256')
        self.assertEqual(set(install.verified_files(payload, checksum)), install.FILES)
        with zipfile.ZipFile(archive) as release:
            self.assertEqual(release.read('LICENSE'), (ROOT / 'LICENSE').read_text(encoding='utf-8').encode())
        self.assertIn((ROOT / 'packaging/egg/install.py').read_text(), egg['scripts']['installation']['script'])
        self.assertTrue(all(not row['user_editable'] for row in egg['variables']))
        build_ipv4_egg.build('https://example.com/release.zip')
        self.assertEqual(hashlib.sha256(archive.read_bytes()).hexdigest(), checksum)
        build_ipv4_egg.build()
        with self.assertRaises(ValueError):
            install.verified_files(payload + b'changed', checksum)

    def test_unknown_archive_paths_rejected(self):
        buffer = io.BytesIO()
        with zipfile.ZipFile(buffer, 'w') as archive:
            archive.writestr('../data/credentials.json', 'overwrite')
        payload = buffer.getvalue()
        with self.assertRaises(ValueError):
            install.verified_files(payload, hashlib.sha256(payload).hexdigest())

    def test_restart_preserves_credentials_state_and_enforces_ipv4(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            data = root / 'data'
            data.mkdir()
            credentials = '{"panel_url":"https://panel.example.com","credential":"secret"}'
            (data / 'credentials.json').write_text(credentials)
            (data / 'state.json').write_text('{"owned":{"rule":"keep"}}')
            (data / 'service.json').write_text('{"transport":"outbound","backend":"upnp","ipv4_only":false}')
            with patch.dict(os.environ, PORT_OPENER_PANEL_URL='https://panel.example.com'), patch.object(start_ipv4, 'pair') as pair:
                start_ipv4.prepare(root)
            pair.assert_not_called()
            self.assertEqual((data / 'credentials.json').read_text(), credentials)
            self.assertEqual(json.loads((data / 'state.json').read_text())['owned'], {'rule': 'keep'})
            self.assertTrue(json.loads((data / 'service.json').read_text())['ipv4_only'])

    def test_host_ipv6_state_refused(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            data = root / 'data'
            data.mkdir()
            (data / 'credentials.json').write_text('{"panel_url":"https://panel.example.com"}')
            (data / 'service.json').write_text('{"transport":"outbound","backend":"upnp"}')
            (data / 'state.json').write_text('{"ipv6_owned":{"rule":{}}}')
            with patch.dict(os.environ, PORT_OPENER_PANEL_URL='https://panel.example.com'):
                with self.assertRaises(ValueError):
                    start_ipv4.prepare(root)


if __name__ == '__main__':
    unittest.main()
