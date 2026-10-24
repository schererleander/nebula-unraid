"""Update upstream downloads, installer paths, and review notes together."""

import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import xml.etree.ElementTree as ET

path = Path('plugin/nebula.plg')
manifest = path.read_text(encoding='utf-8')
document = ET.fromstring(manifest)
updates = []
notes = []

def replace_once(text, old, new, description):
    if not old or text.count(old) != 1:
        raise ValueError(f'Expected one {description} occurrence, got {text.count(old)}')
    return text.replace(old, new, 1)

for name, repository, asset in [('Nebula', 'slackhq/nebula', 'nebula-linux-amd64.tar.gz'),
                                ('yq', 'mikefarah/yq', 'yq_linux_amd64')]:
    release = json.loads(subprocess.check_output(
        ['gh', 'api', f'repos/{repository}/releases/latest'], text=True))
    tag = release['tag_name']
    if not re.fullmatch(r'v?\d+\.\d+\.\d+', tag):
        raise ValueError(f'Unexpected release tag for {repository}: {tag}')
    matching = [file for file in document.findall('FILE')
                if (file.findtext('URL') or '').startswith(
                    f'https://github.com/{repository}/releases/download/')]
    if len(matching) != 1:
        raise ValueError(f'Expected exactly one manifest FILE for {repository}')
    file = matching[0]
    old_url = file.findtext('URL')
    old_digest = file.findtext('SHA256')
    cache_name = file.get('Name', '')
    assets = [item for item in release['assets'] if item['name'] == asset]
    if len(assets) != 1:
        raise ValueError(f'Expected exactly one {asset} download for {repository}')
    new_url = assets[0]['browser_download_url']
    if not new_url.startswith(f'https://github.com/{repository}/releases/download/{tag}/'):
        raise ValueError(f'Unexpected asset URL for {repository}: {new_url}')
    old_tag = old_url.split('/')[-2]
    old_version = old_tag.removeprefix('v')
    new_version = tag.removeprefix('v')
    if new_url == old_url:
        continue
    if old_version not in cache_name:
        raise ValueError(f'Cannot find the previous version in {repository} cache filename')
    new_cache = cache_name.replace(old_version, new_version, 1)

    with tempfile.TemporaryDirectory() as directory:
        subprocess.run(['gh', 'release', 'download', tag, '--repo', repository,
                        '--pattern', asset, '--dir', directory], check=True)
        digest = hashlib.sha256((Path(directory) / asset).read_bytes()).hexdigest()

    manifest = replace_once(manifest, cache_name, new_cache, 'cached filename')
    manifest = replace_once(manifest, old_url, new_url, 'download URL')
    manifest = replace_once(manifest, old_digest, digest, 'checksum')
    # The installer must consume the same versioned file that Unraid caches.
    manifest = replace_once(manifest, f'$state/{Path(cache_name).name}',
                            f'$state/{Path(new_cache).name}', 'installer cached filename')
    updates.append(f'{name} {old_version} → {new_version}')
    compare_url = f'https://github.com/{repository}/compare/{old_tag}...{tag}'
    notes.append(f'- [{name}]({release["html_url"]}): {old_version} → {new_version} '
                 f'([compare]({compare_url}))')

ET.fromstring(manifest)
if updates:
    path.write_text(manifest, encoding='utf-8')
title = 'chore: update ' + ', '.join(updates) if updates else 'chore: update upstream tools'
(Path(os.environ['RUNNER_TEMP']) / 'nebula-updates.md').write_text(
    'Update pinned upstream tools and SHA256 checksums.\n\n' + '\n'.join(notes) + '\n',
    encoding='utf-8')
with open(os.environ['GITHUB_OUTPUT'], 'a', encoding='utf-8') as output:
    output.write(f'title={title}\n')
