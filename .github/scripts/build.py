"""Build the installable plugin and reproducible release archives."""

import base64
from datetime import date, datetime, timezone
import hashlib
import os
from pathlib import Path
import re
import subprocess
import xml.etree.ElementTree as ET

manifest_path = Path('plugin/nebula.plg')
manifest = manifest_path.read_text(encoding='utf-8')
document = ET.fromstring(manifest)
version = document.get('version', '')
match = re.fullmatch(r'(\d{4})\.(\d{2})\.(\d{2})[a-z]*', version)
if not match:
    raise ValueError('Plugin version must use YYYY.MM.DD with an optional letter suffix')
date(*(int(part) for part in match.groups()))

packages = [item for item in document.findall('FILE') if item.get('Type') == 'base64']
if len(packages) != 1:
    raise ValueError('Expected exactly one base64 FILE in the plugin manifest')
package_name = packages[0].get('Name', '')
expected_name = f'nebula-unraid-{version}-noarch-1.txz'
if Path(package_name).name != expected_name:
    raise ValueError(f'Unexpected package name: {package_name!r}')

if os.environ.get('RELEASE_BUILD') == 'true':
    release_version = datetime.now(timezone.utc).strftime('%Y.%m.%d.%H%M')
    release_name = f'nebula-unraid-{release_version}-noarch-1.txz'
    manifest = manifest.replace(f'version="{version}"', f'version="{release_version}"', 1)
    manifest = manifest.replace(f'### {version}\n', f'### {release_version}\n', 1)
    manifest = manifest.replace(expected_name, release_name)
    package_name = package_name.replace(expected_name, release_name)
    version, expected_name = release_version, release_name

source = Path('src')
members = sorted(path.name for path in source.iterdir())
output = Path('dist')
output.mkdir(exist_ok=True)
archive = output / expected_name
metadata = ['--sort=name', '--mtime=@0', '--owner=0', '--group=0', '--numeric-owner']
subprocess.run(['tar', *metadata, '-cJf', str(archive), '-C', 'src', *members], check=True)
payload = archive.read_bytes()
digest = hashlib.sha256(payload).hexdigest()

# Replace the embedded package without reformatting the rest of the manifest.
start = manifest.index(f'<FILE Name="{package_name}"')
end = manifest.index('</FILE>', start) + len('</FILE>')
encoded = base64.encodebytes(payload).decode('ascii').strip()
block = f'<FILE Name="{package_name}" Type="base64">\n<INLINE>\n{encoded}\n</INLINE>\n'
block += f'<SHA256>{digest}</SHA256>\n</FILE>'
updated = manifest[:start] + block + manifest[end:]
ET.fromstring(updated)  # Reject invalid XML rather than publishing it.
(output / 'nebula.plg').write_text(updated, encoding='utf-8')
subprocess.run(['tar', *metadata, '-czf', str(output / f'nebula-{version}.tar.gz'),
                '-C', 'dist', 'nebula.plg', archive.name], check=True)
if os.environ.get('GITHUB_OUTPUT'):
    with open(os.environ['GITHUB_OUTPUT'], 'a', encoding='utf-8') as result:
        result.write(f'version={version}\n')
