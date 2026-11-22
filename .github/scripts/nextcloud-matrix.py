#!/usr/bin/env python3
"""Builds the test matrix of .github/workflows/nextcloud.yml from appinfo/info.xml.

Nextcloud versions: every major from <nextcloud min-version> to max-version.
Each is tested on the stable<N> branch of nextcloud/server, or on master
while that branch does not exist yet. PHP versions: the lowest and the
highest version that both the app (<php min-version max-version>) and that
Nextcloud release (lib/versioncheck.php of the branch) support.

Prints GitHub Actions outputs (key=json) to stdout:
  sqlite    every Nextcloud version x {lowest PHP, highest PHP}
  databases oldest and newest Nextcloud x {mysql, pgsql}
  docker    oldest and newest Nextcloud as official image tags
With --nightly the sqlite matrix also gets nextcloud/server master
(marked experimental: allowed to fail).
"""

import json
import re
import subprocess
import sys
import urllib.request
import xml.etree.ElementTree as ET

INFO_XML = 'nextcloud/appinfo/info.xml'
SERVER = 'https://github.com/nextcloud/server'
RAW = 'https://raw.githubusercontent.com/nextcloud/server/{ref}/lib/versioncheck.php'


def php_id(version: str) -> int:
    major, minor = (int(p) for p in version.split('.')[:2])
    return major * 10000 + minor * 100


def php_version(version_id: int) -> str:
    return f'{version_id // 10000}.{version_id // 100 % 100}'


def server_branches() -> set[str]:
    out = subprocess.run(['git', 'ls-remote', '--heads', SERVER, 'stable*'],
                         check=True, capture_output=True, text=True).stdout
    return {line.split('refs/heads/')[1] for line in out.splitlines() if 'refs/heads/' in line}


def server_php_range(ref: str) -> tuple[int, int]:
    """[min, max] PHP version ids that lib/versioncheck.php of ref accepts."""
    with urllib.request.urlopen(RAW.format(ref=ref), timeout=30) as response:
        source = response.read().decode()
    low = re.search(r'PHP_VERSION_ID\s*<\s*(\d+)', source)
    high = re.search(r'PHP_VERSION_ID\s*>=\s*(\d+)', source)
    if not low or not high:
        sys.exit(f'cannot read the PHP range of nextcloud/server {ref}')
    upper = int(high.group(1))
    # ">= 80600" means 8.5 is the newest supported minor.
    return int(low.group(1)), upper - 100


def php_pair(app: tuple[int, int], ref: str) -> list[str]:
    server = server_php_range(ref)
    low, high = max(app[0], server[0]), min(app[1], server[1])
    if low > high:
        sys.exit(f'app PHP {php_version(app[0])}-{php_version(app[1])} and nextcloud/server {ref} '
                 f'PHP {php_version(server[0])}-{php_version(server[1])} have no version in common')
    return sorted({php_version(low), php_version(high)}, key=php_id)


def main() -> None:
    nightly = '--nightly' in sys.argv
    deps = ET.parse(INFO_XML).getroot().find('dependencies')
    nc = deps.find('nextcloud')
    php = deps.find('php')
    nc_min, nc_max = int(nc.get('min-version')), int(nc.get('max-version'))
    app_php = (php_id(php.get('min-version')), php_id(php.get('max-version')))

    branches = server_branches()
    versions = []
    for major in range(nc_min, nc_max + 1):
        ref = f'stable{major}' if f'stable{major}' in branches else 'master'
        versions.append({'nextcloud': str(major), 'ref': ref, 'php': php_pair(app_php, ref)})

    sqlite = [
        {'nextcloud': v['nextcloud'], 'server-ref': v['ref'], 'php': p, 'experimental': False}
        for v in versions for p in v['php']
    ]
    if nightly:
        for p in php_pair(app_php, 'master'):
            sqlite.append({'nextcloud': 'master', 'server-ref': 'master', 'php': p, 'experimental': True})

    edges = [versions[0], versions[-1]]
    databases = [
        {'nextcloud': v['nextcloud'], 'server-ref': v['ref'], 'php': p, 'database': db}
        for v, p in ((edges[0], edges[0]['php'][0]), (edges[1], edges[1]['php'][-1]))
        for db in ('mysql', 'pgsql')
    ]
    docker = [{'nextcloud': v['nextcloud']} for v in edges]

    for key, value in (('sqlite', sqlite), ('databases', databases), ('docker', docker)):
        print(f'{key}={json.dumps({"include": value}, separators=(",", ":"))}')


if __name__ == '__main__':
    main()
