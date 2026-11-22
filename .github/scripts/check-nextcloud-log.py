#!/usr/bin/env python3
"""Fails when nextcloud.log has errors (level >= 3) from the Merlin app.

An entry counts as Merlin's when its app is "merlin" or its message or
exception mentions OCA\\Merlin or Merlin\\Core. Merlin warnings are printed
but do not fail. Usage: check-nextcloud-log.py path/to/nextcloud.log
"""

import json
import sys

LEVELS = {0: 'DEBUG', 1: 'INFO', 2: 'WARN', 3: 'ERROR', 4: 'FATAL'}


def is_merlin(entry: dict) -> bool:
    if entry.get('app') == 'merlin':
        return True
    text = json.dumps([entry.get('message'), entry.get('exception')])
    return 'OCA\\\\Merlin' in text or 'Merlin\\\\Core' in text or 'apps/merlin/' in text


def main(path: str) -> int:
    try:
        lines = open(path, encoding='utf-8', errors='replace').readlines()
    except FileNotFoundError:
        print(f'{path} does not exist, nothing logged')
        return 0
    errors = 0
    for line in lines:
        try:
            entry = json.loads(line)
        except ValueError:
            continue
        level = int(entry.get('level', 0))
        if level < 2 or not is_merlin(entry):
            continue
        message = str(entry.get('message', ''))[:1000]
        label = LEVELS.get(level, str(level))
        if level >= 3:
            errors += 1
            print(f'::error title=nextcloud.log {label}::{message}')
            if entry.get('exception'):
                print(json.dumps(entry['exception'], indent=2)[:6000])
        else:
            print(f'::warning title=nextcloud.log {label}::{message}')
    print(f'{errors} Merlin error(s) in {path}')
    return 1 if errors else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1] if len(sys.argv) > 1 else 'nextcloud.log'))
