"""CI checks for the packaged release and PHP behavior using pinned upstream tools."""

import base64
import hashlib
import io
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest
from urllib.request import urlopen
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'src'
MANIFEST = ET.parse(ROOT / 'dist/nebula.plg').getroot()


class ReleaseTests(unittest.TestCase):
    def test_package_matches_source(self):
        package = next(file for file in MANIFEST.findall('FILE') if file.get('Type') == 'base64')
        payload = base64.b64decode(package.findtext('INLINE'))
        self.assertEqual(hashlib.sha256(payload).hexdigest(), package.findtext('SHA256'))
        expected = {str(source.relative_to(SOURCE)): source
                    for source in SOURCE.rglob('*') if source.is_file()}
        with tarfile.open(fileobj=io.BytesIO(payload), mode='r:xz') as archive:
            self.assertFalse(any(item.name.startswith('./') for item in archive.getmembers()))
            files = {item.name.removeprefix('./'): item for item in archive if item.isfile()}
            self.assertEqual(set(files), set(expected))
            for name, source in expected.items():
                self.assertEqual(archive.extractfile(files[name]).read(), source.read_bytes(), name)
                self.assertEqual((files[name].uid, files[name].gid), (0, 0), name)
                if name.startswith(('etc/rc.d/', 'etc/cron.daily/')) or '/scripts/' in name or '/event/' in name:
                    self.assertEqual(files[name].mode, 0o755, name)
        for file in MANIFEST.findall('FILE'):
            if file.get('Run') == '/bin/bash':
                subprocess.run(['bash', '-n'], input=file.findtext('INLINE'), text=True, check=True)

    def test_php_behavior(self):
        for name in ['integration', 'platform', 'watcher']:
            subprocess.run(['php', str(ROOT / f'tests/{name}_test.php')], check=True)

    def test_profiles_with_upstream_tools(self):
        with tempfile.TemporaryDirectory(prefix='nebula-profiles-') as directory:
            root = Path(directory)
            (root / 'bin').mkdir()
            fixtures = root / 'fixtures'
            fixtures.mkdir()
            for file in MANIFEST.findall('FILE'):
                url = file.findtext('URL')
                if not url:
                    continue
                with urlopen(url, timeout=60) as response:
                    payload = response.read()
                self.assertEqual(hashlib.sha256(payload).hexdigest(), file.findtext('SHA256'))
                if file.get('Name').endswith('.tar.gz'):
                    with tarfile.open(fileobj=io.BytesIO(payload), mode='r:gz') as archive:
                        for name in ['nebula', 'nebula-cert']:
                            (root / 'bin' / name).write_bytes(archive.extractfile(name).read())
                else:
                    (root / 'bin/yq').write_bytes(payload)
            for binary in (root / 'bin').iterdir():
                binary.chmod(0o755)
            cert = str(root / 'bin/nebula-cert')
            for name in ['ca', 'other-ca']:
                subprocess.run([cert, 'ca', '-name', name, '-duration', '1h',
                                '-out-crt', f'{fixtures}/{name}.crt', '-out-key', f'{fixtures}/{name}.key'], check=True)
            for name in ['client', 'other']:
                subprocess.run([cert, 'sign', '-name', 'unraid-test', '-networks', '10.253.254.2/24',
                                '-duration', '30m', '-ca-crt', str(fixtures / 'ca.crt'),
                                '-ca-key', str(fixtures / 'ca.key'), '-out-crt', f'{fixtures}/{name}.crt',
                                '-out-key', f'{fixtures}/{name}.key'], check=True)
            subprocess.run(['php', str(ROOT / 'tests/profile_test.php'), str(root), str(fixtures)], check=True)
