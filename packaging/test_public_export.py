"""Publication boundary checks; fixtures are synthetic and never contact a router."""
import io
import json
from pathlib import Path
import tempfile
import unittest
import zipfile
from public_export import Audit, export, public_files, safe_name


def archive_bytes(entries):
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, 'w', zipfile.ZIP_DEFLATED) as archive:
        for name, content in entries:
            archive.writestr(name, content)
    return buffer.getvalue()


class PublicExportTests(unittest.TestCase):
    def test_nested_archive_secret_is_detected_without_disclosing_it(self):
        secret = 'gh' + 'p_' + ''.join(chr(65 + number % 26) for number in range(36))
        nested = archive_bytes([('source.py', 'credential = ' + repr(secret))])
        audit = Audit()
        audit.inspect('release.zip', archive_bytes([('service.zip', nested)]))
        self.assertTrue(audit.findings)
        result = json.dumps(audit.findings)
        self.assertNotIn(secret, result)
        self.assertIn('release.zip!service.zip!source.py', result)

    def test_private_comparison_includes_images_without_printing_value(self):
        secret = 'private-' + 'deployment-identity'
        audit = Audit([secret])
        audit.inspect('demo.png', b'\x89PNG\r\n\x1a\n' + secret.encode())
        self.assertEqual(audit.findings, [dict(path='demo.png', rule='private-value')])

    def test_unquoted_environment_credential_is_detected(self):
        secret = ''.join(chr(65 + number % 26) for number in range(40))
        audit = Audit()
        audit.inspect('example.txt', ('PORT_OPENER_TOKEN=' + secret).encode())
        self.assertTrue(any(item['rule'] == 'credential-literal' for item in audit.findings))
        self.assertNotIn(secret, json.dumps(audit.findings))

    def test_unsafe_private_and_traversal_paths_are_rejected(self):
        for name in ['../file', '/absolute', 'a/../file', 'a\\file', 'C:/file',
                     '.git/config', '.env.dev', 'runtime/test.json', 'data/credentials.json',
                     'backend-upload.tar', 'backup.key', 'a//file']:
            with self.subTest(name=name), self.assertRaises(ValueError):
                safe_name(name)

    def test_zip_path_traversal_is_rejected_without_extracting(self):
        with self.assertRaises(ValueError):
            Audit().inspect('bad.zip', archive_bytes([('../outside', 'unsafe')]))

    def test_zip_links_and_duplicate_names_are_rejected(self):
        buffer = io.BytesIO()
        with zipfile.ZipFile(buffer, 'w') as archive:
            link = zipfile.ZipInfo('link')
            link.create_system = 3
            link.external_attr = 0o120777 << 16
            archive.writestr(link, 'target')
        with self.assertRaises(ValueError):
            Audit().inspect('bad.zip', buffer.getvalue())
        import warnings
        with warnings.catch_warnings():
            warnings.simplefilter('ignore', UserWarning)
            duplicate = archive_bytes([('one', 'a'), ('one', 'b')])
        with self.assertRaises(ValueError):
            Audit().inspect('bad.zip', duplicate)

    def test_unknown_binary_and_archive_depth_are_rejected(self):
        audit = Audit()
        audit.inspect('unknown.bin', b'\xff\xfe\x00')
        self.assertEqual(audit.findings[0]['rule'], 'unreviewed-binary')
        payload = b'ordinary text'
        for _ in range(6):
            payload = archive_bytes([('nested.zip', payload)])
        with self.assertRaises(ValueError):
            Audit().inspect('outer.zip', payload)

    def test_export_contains_only_selected_files_and_refuses_existing_target(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / 'source.py').write_text('print(1)', encoding='utf-8')
            (root / '.env').write_text('private', encoding='utf-8')
            target = root / 'export'
            export(root, target, ['source.py'])
            self.assertEqual([p.name for p in target.iterdir()], ['source.py'])
            self.assertEqual((target / 'source.py').read_bytes(), (root / 'source.py').read_bytes())
            with self.assertRaises(ValueError):
                export(root, target, ['source.py'])

    def test_export_rejects_a_link_to_private_source(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / 'private.txt').write_text('private content', encoding='utf-8')
            try:
                (root / 'source.txt').symlink_to(root / 'private.txt')
            except OSError:
                self.skipTest('Creating symbolic links requires platform privileges')
            with self.assertRaises(ValueError):
                export(root, root / 'export', ['source.txt'])

    def test_finding_blocks_export_before_destination_is_created(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / 'source.txt').write_text('private identity data', encoding='utf-8')
            target = root / 'export'
            with self.assertRaises(ValueError):
                export(root, target, ['source.txt'], ['private identity data'])
            self.assertFalse(target.exists())

    def test_missing_or_duplicate_manifest_path_is_rejected(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / 'packaging').mkdir()
            manifest = root / 'packaging/public-files.txt'
            manifest.write_text('missing.py\n', encoding='utf-8')
            with self.assertRaises(ValueError):
                public_files(root)
            manifest.write_text('source.py\nsource.py\n', encoding='utf-8')
            with self.assertRaises(ValueError):
                public_files(root)


if __name__ == '__main__':
    unittest.main()
