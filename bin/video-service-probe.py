"""Probe the local video API without exposing authentication values."""
import json
import os
from pathlib import Path
import sys
import tomllib
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


def authentication_keys():
    keys = []
    # A rotated runtime binding may differ from the idle service's previous key.
    # Read that key only from a process proven to be our exact owned launcher.
    try:
        pid = Path('/workspace/.setup/video-service.pid').read_text().strip()
        process = Path('/proc') / pid
        args = (process / 'cmdline').read_bytes().split(b'\0')
        if pid.isdigit() and process.stat().st_uid == os.geteuid() and len(args) > 1 and args[1] == b'/workspace/oldora/bin/start-video-service.py':
            for field in (process / 'environ').read_bytes().split(b'\0'):
                name, separator, value = field.partition(b'=')
                if separator and name == b'MONEYPRINTER_API_KEY':
                    keys.append(value.decode(errors='surrogateescape'))
                    break
    except (OSError, ValueError):
        pass
    if 'MONEYPRINTER_API_KEY' in os.environ:
        keys.append(os.environ['MONEYPRINTER_API_KEY'])
    try:
        root = Path(os.environ.get('MONEYPRINTER_ROOT', '/workspace/.setup/MoneyPrinterTurbo'))
        with (root / 'config.toml').open('rb') as stream:
            key = tomllib.load(stream).get('app', {}).get('api_key', '')
        if isinstance(key, str):
            keys.append(key)
    except (OSError, ValueError):
        pass
    keys.append('')
    return list(dict.fromkeys(keys))


def request_page(page, key):
    request = Request('http://127.0.0.1:8080/api/v1/tasks?page=%d&page_size=1000' % page)
    if key:
        request.add_header('x-api-key', key)
    with urlopen(request, timeout=5) as response:
        payload = json.load(response)
    if payload.get('status') != 200 or not isinstance(payload.get('data'), dict):
        raise ValueError('Invalid API response')
    return payload['data']


def main(mode):
    if mode == 'listening':
        try:
            request_page(1, '')
            return 0
        except HTTPError:
            # A protected or unrelated HTTP service still occupies this port.
            return 0
        except (OSError, URLError, ValueError):
            return 1
    unauthorized = False
    data = None
    selected_key = ''
    for key in authentication_keys():
        try:
            data = request_page(1, key)
            selected_key = key
            break
        except HTTPError as error:
            unauthorized = unauthorized or error.code in (401, 403)
        except (OSError, URLError, ValueError):
            pass
    if data is None:
        return 3 if unauthorized else 1
    if mode == 'ready':
        return 0
    checked = 0
    try:
        for page in range(1, 1001):
            if page > 1:
                data = request_page(page, selected_key)
            tasks = data['tasks']
            if not isinstance(tasks, list):
                return 2
            if any(task.get('state') not in (-1, 1) or task.get('cross_post_state') in ('pending', 'processing') for task in tasks):
                return 2
            checked += len(tasks)
            if checked >= int(data['total']):
                return 0
            if not tasks:
                return 2
    except (OSError, URLError, ValueError, KeyError, TypeError):
        pass
    return 2


if __name__ == '__main__':
    mode = sys.argv[1] if len(sys.argv) == 2 else ''
    sys.exit(main(mode) if mode in ('ready', 'idle', 'listening') else 1)
