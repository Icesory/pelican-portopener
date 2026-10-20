"""Bounded support events. No free-form strings, requests, URLs or exception text."""
import json
import os
import re
import ssl
import threading
import time
from datetime import datetime, timezone
from pathlib import Path
from urllib.error import HTTPError, URLError

VERSION = '0.6.4'
SCHEMA = json.loads(Path(__file__).with_name('diagnostics-schema.json').read_text(encoding='utf-8'))
ENUMS = {'outcome': 'outcomes', 'state': 'states', 'protocol': 'protocols', 'family': 'families',
         'phase': 'phases', 'router_service': 'router_services'}
BOUNDS = {'port': (1, 65535), 'generation': (0, 2147483647), 'http_status': (100, 599),
          'mapping_count': (0, 256), 'duration_ms': (0, 600000)}


def stamp(value):
    if not isinstance(value, str) or not re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})', value):
        return None
    try:
        return datetime.fromisoformat(value.replace('Z', '+00:00')).astimezone(timezone.utc).isoformat(timespec='seconds')
    except ValueError:
        return None


def code(value):
    return value if isinstance(value, str) and (value in SCHEMA['errors'] or re.fullmatch(r'upnp_[0-9]{3}', value)) else 'internal_error'


def failure(error):
    if isinstance(error, HTTPError):
        return dict(error_code='authentication_failed' if error.code in (401, 403) else 'http_error',
                    **({'http_status': error.code} if type(error.code) is int and 100 <= error.code <= 599 else {}))
    if isinstance(error, ssl.SSLError) or isinstance(error, URLError) and isinstance(error.reason, ssl.SSLError):
        return dict(error_code='tls_error')
    if isinstance(error, TimeoutError): return dict(error_code='network_timeout')
    if hasattr(error, 'code'): return dict(error_code=code(error.code))
    if isinstance(error, OSError): return dict(error_code='network_error')
    if isinstance(error, ValueError): return dict(error_code='invalid_response')
    return dict(error_code='internal_error')


def clean_event(row):
    if not isinstance(row, dict) or row.get('event') not in SCHEMA['events'] or row.get('level') not in SCHEMA['levels']:
        return None
    timestamp = stamp(row.get('timestamp'))
    if timestamp is None: return None
    result = dict(timestamp=timestamp, event=row['event'], level=row['level'])
    for key, name in ENUMS.items():
        if row.get(key) in SCHEMA[name]: result[key] = row[key]
    for key, (minimum, maximum) in BOUNDS.items():
        if type(row.get(key)) is int and minimum <= row[key] <= maximum: result[key] = row[key]
    if row.get('error_code') is not None: result['error_code'] = code(row['error_code'])
    return result


class EventLog:
    MAX_EVENTS = 128
    MAX_BYTES = 65536

    def __init__(self, state_path, clock=None, monotonic=None, stdout=True):
        self.path = Path(str(state_path)+'.diagnostics.json')
        self.clock, self.monotonic = clock or time.time, monotonic or time.monotonic
        self.stdout, self.storage_ok = stdout, True
        self.lock = threading.RLock()
        self.events, self.last = [], {}
        self.debug_until = self.debug_request = None
        self.deadline = 0
        try:
            if self.path.exists():
                with self.path.open('rb') as stream: payload = stream.read(self.MAX_BYTES+1)
                if len(payload) <= self.MAX_BYTES:
                    saved = json.loads(payload)
                    self.events = [event for row in saved.get('events', [])[-self.MAX_EVENTS:]
                                   if (event := clean_event(row)) is not None]
                    # Never restore an expired or more-than-15-minute debug request.
                    self.set_debug(saved.get('debug_until'), persist=False)
        except (OSError, ValueError, TypeError, AttributeError):
            self.storage_ok = False

    def now_stamp(self):
        return datetime.fromtimestamp(self.clock(), timezone.utc).isoformat(timespec='seconds')

    def set_debug(self, until, persist=True):
        with self.lock:
            self.expire()
            requested = stamp(until)
            if requested == self.debug_request: return
            active = requested is not None and datetime.fromisoformat(requested).timestamp() > self.clock()
            old = self.debug_until is not None
            self.debug_request = requested
            if active:
                remaining = min(900, datetime.fromisoformat(requested).timestamp()-self.clock())
                self.debug_until = datetime.fromtimestamp(self.clock()+remaining, timezone.utc).isoformat(timespec='seconds')
                self.deadline = self.monotonic()+remaining
                if persist: self.log('debug_enabled')
            else:
                self.debug_until, self.deadline = None, 0
                if old and persist: self.log('debug_disabled')
            if persist: self.save()

    def expire(self):
        if self.debug_until and (self.clock() >= datetime.fromisoformat(self.debug_until).timestamp() or self.monotonic() >= self.deadline):
            self.debug_until, self.deadline = None, 0
            self.log('debug_expired')

    def log(self, event, level='info', deduplicate=False, **fields):
        with self.lock:
            self.expire()
            if level == 'debug' and not self.debug_until: return
            row = clean_event(dict(fields, timestamp=self.now_stamp(), event=event, level=level))
            if row is None: return
            key = (event, row.get('port'), row.get('protocol'), row.get('family'))
            signature = {name: value for name, value in row.items() if name != 'timestamp'}
            if deduplicate and self.last.get(key) == signature: return
            self.last[key] = signature
            # Bound deduplication memory even if allocations churn indefinitely.
            if len(self.last) > 512: self.last = {key: signature}
            self.events = (self.events+[row])[-self.MAX_EVENTS:]
            self.save()
            if self.stdout:
                try: print(json.dumps(row, separators=(',', ':')), flush=True)
                except (OSError, ValueError): pass

    def save(self):
        try:
            self.path.parent.mkdir(parents=True, exist_ok=True)
            data = json.dumps(dict(schema_version=1, service_version=VERSION, debug_until=self.debug_until,
                                   events=self.events), separators=(',', ':'))
            while len(data.encode()) > self.MAX_BYTES and self.events:
                self.events.pop(0)
                data = json.dumps(dict(schema_version=1, service_version=VERSION, debug_until=self.debug_until,
                                       events=self.events), separators=(',', ':'))
            temporary = Path(str(self.path)+'.tmp')
            with temporary.open('w', encoding='utf-8') as stream:
                os.chmod(temporary, 0o600)
                stream.write(data)
            os.replace(temporary, self.path)
            self.storage_ok = True
        except OSError:
            # Observability must never prevent forwarding or ownership persistence.
            self.storage_ok = False

    def report(self):
        with self.lock:
            self.expire()
            import platform
            return dict(schema_version=1, service_version=VERSION, python_version=platform.python_version(),
                        debug_active=self.debug_until is not None, debug_until=self.debug_until,
                        storage_ok=self.storage_ok, events=[dict(row) for row in self.events])
