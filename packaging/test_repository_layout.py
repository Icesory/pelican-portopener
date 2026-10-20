"""Verify deployment artifacts from an isolated copy of the root plugin layout."""
import hashlib
import io
import json
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile
import unittest
from unittest.mock import patch
import zipfile

import build_ipv4_egg
import build_release
from public_export import public_files

ROOT = Path(__file__).resolve().parents[1]
PRIVATE_MARKER = 'DO-NOT-PUBLISH-local-fixture'


class RepositoryLayoutTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='portopener-layout-')
        cls.addClassCleanup(cls.temp.cleanup)
        cls.fixture = Path(cls.temp.name)
        for name in public_files(ROOT):
            target = cls.fixture / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / name, target)
        # Simulate local files beside the plugin. They must never enter packages.
        for name in ('.env.private', 'src/unlisted-local.php', 'service/unlisted-local.json', 'docs/unreviewed.png'):
            (cls.fixture / name).write_text(PRIVATE_MARKER, encoding='utf-8')
        metadata = cls.fixture / 'plugin.json'
        manifest = json.loads(metadata.read_text(encoding='utf-8'))
        manifest['meta'] = {'status': 'enabled', 'status_message': 'development-only'}
        metadata.write_text(json.dumps(manifest), encoding='utf-8')
        cls.manifest = manifest
        cls.version = manifest['version']
        with patch.object(build_release, 'ROOT', cls.fixture), patch.object(build_ipv4_egg, 'root', cls.fixture):
            build_release.build()
        cls.zip_path = cls.fixture / f'dist/port-opener-{cls.version}.zip'
        cls.host_path = cls.fixture / f'dist/port-opener-service-{cls.version}.tar.gz'

    def test_root_manifest_resolves_its_plugin_class(self):
        self.assertTrue((ROOT / 'plugin.json').is_file())
        self.assertFalse((ROOT / 'plugin').exists())
        main = ROOT / 'src' / (self.manifest['class'] + '.php')
        self.assertTrue(main.is_file())
        self.assertIn('namespace ' + self.manifest['namespace'] + ';', main.read_text(encoding='utf-8'))

    def test_plugin_zip_has_one_installable_folder_with_manifest_and_runtime(self):
        prefix = self.manifest['id'] + '/'
        with zipfile.ZipFile(self.zip_path) as archive:
            names = set(archive.namelist())
            for name in ('plugin.json', 'LICENSE', 'README.md', 'THIRD_PARTY_NOTICES.md',
                         'src/PortOpenerPlugin.php', 'config/port-opener.php', 'routes/api.php',
                         'resources/releases/manifest.json', 'resources/diagnostics-schema.json'):
                self.assertIn(prefix + name, names)
            self.assertTrue(all(name.startswith(prefix) for name in names))
            self.assertFalse(any('/plugin/' in name for name in names))

    def test_plugin_zip_excludes_development_and_unlisted_files(self):
        prefix = self.manifest['id'] + '/'
        forbidden = ('service/', 'packaging/', 'tests/', 'contracts/', '.git/', 'runtime/')
        with zipfile.ZipFile(self.zip_path) as archive:
            for name in archive.namelist():
                relative = name.removeprefix(prefix)
                self.assertFalse(relative.startswith(forbidden), name)
                self.assertNotIn(PRIVATE_MARKER.encode(), archive.read(name), name)
            self.assertNotIn(prefix + 'compose.yml', archive.namelist())
            self.assertNotIn(prefix + 'src/unlisted-local.php', archive.namelist())
            self.assertNotIn(prefix + 'docs/unreviewed.png', archive.namelist())

    def test_plugin_zip_strips_installed_metadata_and_preserves_root_readme(self):
        with zipfile.ZipFile(self.zip_path) as archive:
            metadata = json.loads(archive.read('port-opener/plugin.json'))
            self.assertNotIn('meta', metadata)
            self.assertEqual(metadata['version'], self.version)
            self.assertEqual(archive.read('port-opener/README.md'), (ROOT / 'README.md').read_bytes())
            self.assertEqual(archive.read('port-opener/LICENSE'), (ROOT / 'LICENSE').read_bytes())

    def test_bundled_service_checksum_and_published_bytes_are_preserved(self):
        with zipfile.ZipFile(self.zip_path) as archive:
            release = json.loads(archive.read('port-opener/resources/releases/manifest.json'))
            data = archive.read('port-opener/resources/releases/' + release['filename'])
            self.assertEqual(hashlib.sha256(data).hexdigest(), release['sha256'])
            self.assertEqual(data, (ROOT / 'resources/releases' / release['filename']).read_bytes())

    def test_release_build_refuses_a_blocked_call_in_development_php(self):
        fixture = self.fixture / 'tests/ipv4_pelican.php'
        original = fixture.read_bytes()
        try:
            fixture.write_bytes(b"<?php fwrite(STDERR, 'fixture failure');")
            with patch.object(build_release, 'ROOT', self.fixture):
                with self.assertRaisesRegex(ValueError, 'Blocked native PHP calls'):
                    build_release.build()
        finally:
            fixture.write_bytes(original)

    def test_git_archive_excludes_development_and_keeps_root_manifest(self):
        with tempfile.TemporaryDirectory(prefix='portopener-git-archive-') as temp:
            source = Path(temp)
            names = public_files(ROOT)
            for name in names:
                target = source / name
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(ROOT / name, target)
            def git(*args):
                return subprocess.run(['git', '-C', str(source), *args], check=True,
                                      capture_output=True).stdout
            git('init', '--quiet')
            git('add', '--', *names)
            tree = git('write-tree').decode().strip()
            with zipfile.ZipFile(io.BytesIO(git('archive', '--format=zip', tree))) as archive:
                exported = archive.namelist()
                self.assertIn('plugin.json', exported)
                self.assertIn('src/PortOpenerPlugin.php', exported)
                self.assertIn('resources/releases/manifest.json', exported)
                self.assertIn('resources/releases/port-opener-service-ipv4-' + self.version + '.zip', exported)
                self.assertIn('LICENSE', exported)
                self.assertIn('docs/admin-overview.png', exported)
                self.assertFalse(any(name.startswith(('packaging/', 'tests/', 'service/', 'contracts/')) for name in exported))
                self.assertNotIn('compose.yml', exported)
                self.assertNotIn('meta', json.loads(archive.read('plugin.json')))

    def test_host_archive_remains_independent_of_the_plugin_layout(self):
        with tarfile.open(self.host_path) as archive:
            names = archive.getnames()
            self.assertTrue(all(name.startswith('port-opener-service/') for name in names))
            self.assertEqual(archive.extractfile('port-opener-service/port_opener.py').read(),
                             (ROOT / 'service/port_opener.py').read_bytes())
            self.assertIn('port-opener-service/SERVICE.md', names)
            self.assertIn('port-opener-service/compose.outbound.yml', names)
            self.assertNotIn('port-opener-service/plugin.json', names)
            for member in archive.getmembers():
                if member.isfile():
                    self.assertNotIn(PRIVATE_MARKER.encode(), archive.extractfile(member).read(), member.name)


if __name__ == '__main__':
    unittest.main()
