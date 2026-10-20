"""Verified HTTPS, node-scoped outbound transport. Never opens a listener."""
import getpass
import json
import os
import ssl
import time
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlparse
from urllib.error import HTTPError
from urllib.request import Request, build_opener, HTTPSHandler
from port_opener import ServiceError, NoRedirects, atomic_save
from diagnostics import failure

PREFIX = '/api/port-opener/v1'


def panel_url(value):
    if not isinstance(value, str) or any(character.isspace() for character in value):
        raise ValueError('Panel URL is invalid')
    parsed = urlparse(value)
    if parsed.scheme != 'https' or not parsed.hostname or parsed.username is not None or parsed.password is not None or parsed.query or parsed.fragment or parsed.path not in ('', '/'):
        raise ValueError('Panel requires an HTTPS origin without credentials, path, query or fragment')
    if parsed.port is not None and not 1 <= parsed.port <= 65535:
        raise ValueError('Panel port is invalid')
    return value.rstrip('/')


def expiry(value):
    if not isinstance(value, str):
        raise ValueError('Token expiry must be an ISO8601 timestamp')
    stamp = datetime.fromisoformat(value.replace('Z', '+00:00'))
    if stamp.tzinfo is None:
        raise ValueError('Token expiry must specify timezone')
    return stamp.timestamp()


class HTTPSClient:
    def __init__(self, origin, ca_file=None):
        self.origin = panel_url(origin)
        context = ssl.create_default_context(cafile=ca_file)
        self.opener = build_opener(NoRedirects(), HTTPSHandler(context=context))

    def request(self, method, path, body=None, token=None):
        if path not in ('/pair', '/desired-state', '/report', '/renew'):
            raise ValueError('Unknown outbound endpoint')
        headers = {'Accept': 'application/json', 'Content-Type': 'application/json'}
        if token:
            headers['Authorization'] = 'Bearer '+token
        data = json.dumps(body).encode() if body is not None else None
        request = Request(self.origin+PREFIX+path, data=data, headers=headers, method=method)
        with self.opener.open(request, timeout=10) as response:
            raw = response.read(262145)
        if len(raw) > 262144:
            raise ServiceError('panel_response_too_large', 'Panel response exceeded limit', 503)
        value = json.loads(raw)
        if not isinstance(value, dict):
            raise ServiceError('invalid_panel_response', 'Panel response must be an object', 503)
        return value


def credentials_from(response, origin, allow_expired=False):
    import uuid
    if type(response.get('node_id')) is not int or response['node_id'] < 1:
        raise ValueError('Pairing node ID is invalid')
    uuid.UUID(response['installation_id'])
    token = response.get('credential')
    if not isinstance(token, str) or len(token) < 32 or '\n' in token or '\r' in token:
        raise ValueError('Pairing token is invalid')
    if not allow_expired and expiry(response['expires_at']) <= time.time():
        raise ValueError('Pairing token is expired')
    return dict(panel_url=panel_url(origin), node_id=response['node_id'], installation_id=response['installation_id'],
        credential=token, expires_at=response['expires_at'])


def pair(origin, code, destination, ca_file=None, expected_scope=None):
    path = Path(destination)
    if path.exists():
        raise ValueError('Credentials already exist; remove them explicitly before pairing again')
    origin = panel_url(origin)
    response = HTTPSClient(origin, ca_file).request('POST', '/pair', {'pairing_code': code or getpass.getpass('One-use pairing code: ')})
    credentials = credentials_from(response, origin)
    if expected_scope is not None and (credentials['node_id'], credentials['installation_id']) != expected_scope:
        raise ValueError('Pairing response does not match the managed installation scope')
    atomic_save(path, credentials)
    return credentials


def bootstrap_config(credentials, credentials_file, destination, ca_file=None):
    from port_opener import PortOpenerService, UPnPRouter
    response = HTTPSClient(credentials['panel_url'], ca_file).request('GET', '/desired-state', token=credentials['credential'])
    remote = response['configuration']
    config = dict(transport='outbound', backend='upnp', dry_run=False,
        credentials_file=str(Path(credentials_file).resolve()), panel_url=credentials['panel_url'],
        installation_id=credentials['installation_id'], node_id=credentials['node_id'],
        internal_address=remote['internal_address'], min_port=remote['min_port'], max_port=remote['max_port'],
        router_description_url=remote['router_url'], lease_seconds=remote['lease_seconds'],
        reconcile_seconds=remote['reconcile_seconds'], state_file=str(Path(destination).resolve().parent/'state.json'))
    if ca_file:
        config['ca_file'] = str(Path(ca_file).resolve())
    service = PortOpenerService(config, UPnPRouter(remote['router_url']))
    if service.state['owned'] or service.state['desired']:
        raise ValueError('Existing state requires explicit configuration review before bootstrap')
    transport = OutboundTransport(service, config)
    # Validate policy without persisting or contacting the router.
    with service.lock:
        original = service.configure_router
        service.configure_router = lambda body: None
        try:
            transport.apply_config(remote)
            service.validate(response['desired_state'])
        finally:
            service.configure_router = original
    atomic_save(destination, config)


class OutboundTransport:
    def __init__(self, service, config):
        self.service, self.config = service, dict(config)
        self.path = Path(config['credentials_file'])
        loaded = json.loads(self.path.read_text())
        self.credentials = credentials_from(loaded, loaded['panel_url'], allow_expired=True)
        origin = config.get('panel_url') or loaded['panel_url']
        if panel_url(origin) != panel_url(loaded['panel_url']):
            raise ValueError('Configured panel differs from paired origin; pair explicitly')
        if loaded['node_id'] != config['node_id'] or loaded['installation_id'] != config['installation_id']:
            raise ValueError('Credentials do not match configured node/installation')
        self.client = HTTPSClient(origin, config.get('ca_file'))
        self.service.renewal_paused = self.service.state.get('outbound_unauthorized', False) or expiry(self.credentials['expires_at']) <= time.time()
        self.service.credential_expires_at = expiry(self.credentials['expires_at'])
        # Unpaired/unpolled state remains safe; the panel controls normal dry-run.
        self.service.dry_run = config.get('dry_run', False) or self.service.state.get('outbound_effective_dry_run', True)

    def renew_if_needed(self):
        if expiry(self.credentials['expires_at']) - time.time() > 86400:
            return
        self.phase = 'renew'
        response = self.client.request('POST', '/renew', {}, self.credentials['credential'])
        if type(response.get('node_id', self.config['node_id'])) is not int or response.get('node_id', self.config['node_id']) != self.config['node_id'] or response.get('installation_id', self.config['installation_id']) != self.config['installation_id']:
            raise ServiceError('untrusted_scope', 'Renewal changed credential scope')
        updated = dict(self.credentials, credential=response['credential'], expires_at=response['expires_at'])
        updated = credentials_from(updated, self.credentials['panel_url'])
        atomic_save(self.path, updated)  # Rotation switches only after local durability.
        self.credentials = updated
        self.service.credential_expires_at = expiry(updated['expires_at'])
        self.service.diagnostics.log('credential_renewal', outcome='success')

    def apply_config(self, remote):
        allowed = {'node_id', 'internal_address', 'min_port', 'max_port', 'dry_run', 'forwarding_type', 'router_url', 'lease_seconds', 'reconcile_seconds', 'ipv6_enabled', 'ipv6_interface'}
        if not isinstance(remote, dict) or set(remote) - allowed:
            raise ServiceError('invalid_node_configuration', 'Unknown remote node policy fields')
        if remote.get('node_id') != self.config['node_id'] or type(remote.get('node_id')) is not int:
            raise ServiceError('untrusted_scope', 'Remote configuration scope does not match')
        for key in ('dry_run',):
            if type(remote.get(key)) is not bool:
                raise ServiceError('invalid_node_configuration', 'Enabled and dry-run must be booleans')
        if type(remote.get('ipv6_enabled', False)) is not bool:
            raise ServiceError('invalid_node_configuration', 'IPv6 enablement must be a boolean')
        if self.config.get('ipv4_only') and remote.get('ipv6_enabled', False):
            raise ServiceError('ipv6_not_supported', 'This installation supports IPv4 only; disable IPv6 for this node')
        interface = remote.get('ipv6_interface')
        if interface is not None:
            import re
            if not isinstance(interface, str) or not re.fullmatch(r'[a-zA-Z0-9_.:-]{1,32}', interface):
                raise ServiceError('invalid_node_configuration', 'Invalid local IPv6 interface')
        if remote.get('internal_address') != self.config['internal_address']:
            raise ServiceError('untrusted_address', 'Remote destination differs from local policy')
        for key in ('min_port', 'max_port'):
            if type(remote.get(key)) is not int:
                raise ServiceError('invalid_node_configuration', 'Remote port policy must be integers')
        if not self.config['min_port'] <= remote['min_port'] <= remote['max_port'] <= self.config['max_port']:
            raise ServiceError('blocked_port', 'Remote range exceeds local policy')
        lease, interval = remote.get('lease_seconds'), remote.get('reconcile_seconds')
        if type(lease) is not int or type(interval) is not int or not 120 <= lease <= 86400 or not 5 <= interval <= lease//3:
            raise ServiceError('invalid_node_configuration', 'Finite lease or reconciliation interval is invalid')
        lease_cap = self.config.get('max_lease_seconds', 86400)
        interval_cap = self.config.get('max_reconcile_seconds', 300)
        if type(lease_cap) is not int or type(interval_cap) is not int or not 120 <= lease_cap <= 86400 or not 5 <= interval_cap <= 300:
            raise ServiceError('invalid_local_policy', 'Local lease/interval ceiling is invalid')
        if lease > lease_cap or interval > interval_cap:
            raise ServiceError('blocked_node_policy', 'Requested lease/interval exceeds an explicit local ceiling')
        self.service.configure_router(dict(node_id=remote['node_id'], forwarding_type=remote['forwarding_type'], router_url=remote['router_url']))
        # A locally enforced dry run cannot be disabled remotely.
        self.service.dry_run = self.config.get('dry_run', False) or remote['dry_run']
        self.service.state['outbound_effective_dry_run'] = self.service.dry_run
        self.service.config['lease_seconds'] = lease
        self.service.config['reconcile_seconds'] = interval
        return remote

    def cycle(self):
        self.phase = 'desired_state'
        try:
            result = self._cycle()
            self.service.diagnostics.log('panel_poll', outcome='recovered' if getattr(self, 'had_failure', False) else 'success',
                                         phase='report', deduplicate=True)
            self.service.diagnostics.log('panel_report', level='debug', outcome='success')
            self.had_failure = False
            return result
        except HTTPError as error:
            self.had_failure = True
            self.service.diagnostics.log('panel_poll', level='error', phase=self.phase, outcome='failed', deduplicate=True, **failure(error))
            if error.code in (401, 403):
                self.service.renewal_paused = True
                self.service.state['outbound_unauthorized'] = True
                self.service.save()
            raise
        except Exception as error:
            self.had_failure = True
            self.service.diagnostics.log('panel_poll', level='error', phase=self.phase, outcome='failed', deduplicate=True, **failure(error))
            raise
        finally:
            if expiry(self.credentials['expires_at']) <= time.time():
                self.service.renewal_paused = True

    def _cycle(self):
        self.renew_if_needed()
        self.phase = 'desired_state'
        response = self.client.request('GET', '/desired-state', token=self.credentials['credential'])
        desired = response['desired_state']
        current = self.service.state['desired']
        canonical = self.service.validate(desired)
        if current and (canonical['generation'] < current['generation'] or (canonical['generation'] == current['generation'] and canonical != current)):
            raise ServiceError('generation_conflict', 'Panel snapshot generation is stale or reused', 409)
        test_id = response.get('test_request_id')
        if test_id is not None:
            import uuid
            try:
                if not isinstance(test_id, str):
                    raise ValueError()
                uuid.UUID(test_id)
            except (ValueError, AttributeError):
                raise ServiceError('invalid_test_request', 'Test request ID must be a UUID')
        remote = response['configuration']
        if not isinstance(remote, dict) or type(remote.get('min_port')) is not int or type(remote.get('max_port')) is not int:
            raise ServiceError('invalid_node_configuration', 'Remote port policy must be integers')
        if any(not remote['min_port'] <= row['port'] <= remote['max_port'] for row in desired['mappings']):
            raise ServiceError('blocked_port', 'Snapshot exceeds remote port policy')
        previous_dry_run = self.service.dry_run
        previous_effective = self.service.state.get('outbound_effective_dry_run', True)
        ipv6_ids = response.get('ipv6_mapping_ids', [])
        approved_ids = {row['mapping_id'] for row in canonical['mappings']}
        if not isinstance(ipv6_ids, list) or len(ipv6_ids) > 256 or any(not isinstance(value, str) or value not in approved_ids for value in ipv6_ids) or len(set(ipv6_ids)) != len(ipv6_ids):
            raise ServiceError('invalid_ipv6_scope', 'IPv6 mappings must be a subset of this node snapshot')
        previous_ipv6 = self.service.state.get('ipv6_policy')
        with self.service.lock:
            remote = self.apply_config(remote)
            control = response.get('diagnostics_control', {})
            if not isinstance(control, dict):
                raise ServiceError('invalid_node_configuration', 'Diagnostics control must be an object')
            self.service.diagnostics.set_debug(control.get('debug_until'))
            self.service.diagnostics.log('configuration_accepted', outcome='success', deduplicate=True,
                                         mapping_count=len(canonical['mappings']))
            self.service.renewal_paused = False
            self.service.state['outbound_unauthorized'] = False
            self.service.last_accept_persisted = False
            try:
                self.service.state['ipv6_policy'] = dict(enabled=remote.get('ipv6_enabled', False), interface=remote.get('ipv6_interface'), mapping_ids=sorted(ipv6_ids))
                self.service.accept(desired)
                self.service.diagnostics.log('snapshot_accepted', generation=canonical['generation'],
                                             mapping_count=len(canonical['mappings']), deduplicate=True)
            except Exception:
                # Keep policy that was durably accepted if subsequent reconciliation failed.
                if not self.service.last_accept_persisted:
                    self.service.dry_run = previous_dry_run
                    self.service.state['outbound_effective_dry_run'] = previous_effective
                    if previous_ipv6 is None: self.service.state.pop('ipv6_policy', None)
                    else: self.service.state['ipv6_policy'] = previous_ipv6
                raise
        report = {'status': self.service.status()}
        if test_id is not None:
            cache = self.service.state.setdefault('outbound_tests', {})
            saved = cache.get(test_id)
            if not saved:
                self.phase = 'router_test'
                try:
                    result = self.service.test_router(dict(node_id=remote['node_id'], forwarding_type=remote['forwarding_type'], router_url=remote['router_url']))
                    self.service.diagnostics.log('router_check', outcome='success')
                except Exception as error:
                    self.service.diagnostics.log('router_check', level='error', outcome='failed', **failure(error))
                    result = dict(success=False, router_reachable=False, message='Read-only router test failed')
                saved = {'id': test_id, 'result': result}
                cache[test_id] = saved
                if len(cache) > 256:
                    # Refuse unbounded growth rather than forget replay protection.
                    del cache[test_id]
                    raise ServiceError('test_history_limit', 'Router test history limit reached; administrator review required')
                try:
                    self.service.save()
                except Exception:
                    cache.pop(test_id, None)
                    raise
            report.update(test_request_id=test_id, test_result=saved['result'])
        report['diagnostics'] = self.service.diagnostics.report()
        self.phase = 'report'
        self.client.request('POST', '/report', report, self.credentials['credential'])

    def run(self):
        interval = self.config.get('poll_seconds', 30)
        if type(interval) is not int or not 5 <= interval <= 300:
            raise ValueError('poll_seconds must be 5..300')
        failures = 0
        while True:
            try:
                self.cycle()
                failures = 0
            except HTTPError as error:
                if error.code in (401, 403):
                    self.service.renewal_paused = True
                failures = min(failures+1, 4)
            except Exception:
                # No exception text/token logging; last desired state is retained.
                failures = min(failures+1, 4)
            if expiry(self.credentials['expires_at']) <= time.time():
                self.service.renewal_paused = True
            time.sleep(min(300, interval*(2**failures)))
