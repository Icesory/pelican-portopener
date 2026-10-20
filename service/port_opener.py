"""PortOpener host PortOpenerService. Python 3.11+, standard library only."""
import argparse
import hashlib
import hmac
import ipaddress
import json
import os
import threading
import time
import uuid
import sys
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urljoin, urlparse
from urllib.request import Request, build_opener, HTTPRedirectHandler
import xml.etree.ElementTree as ET
from diagnostics import EventLog, failure


class NoRedirects(HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, newurl):
        raise ServiceError('unsafe_router_redirect', 'Router redirects are disabled', 503)


def router_open(request, timeout):
    return build_opener(NoRedirects()).open(request, timeout=timeout)


def validate_router_url(value):
    try:
        if not isinstance(value, str) or len(value) > 2048 or any(character.isspace() or ord(character) < 32 for character in value):
            raise ValueError()
        parsed = urlparse(value)
        address = ipaddress.IPv4Address(parsed.hostname or '')
        private = any(address in ipaddress.IPv4Network(network) for network in ('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'))
        if parsed.scheme != 'http' or not private or parsed.username is not None or parsed.password is not None or parsed.query or parsed.fragment or '?' in value or '#' in value or not parsed.path or parsed.params or (parsed.port is not None and not 1 <= parsed.port <= 65535):
            raise ValueError()
        if parsed.netloc != str(address) and parsed.netloc != f'{address}:{parsed.port}':
            raise ValueError()
    except (ValueError, TypeError, AttributeError):
        raise ServiceError('unsafe_router_url', 'Router URL must be HTTP with an RFC1918 IPv4 address, valid port and path; credentials, query and fragment are forbidden')
    return value


class ServiceError(Exception):
    def __init__(self, code, message, status=400):
        super().__init__(message)
        self.code, self.status = code, status


def atomic_save(path, value):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix('.tmp')
    with open(tmp, 'w', encoding='utf-8') as stream:
        os.chmod(tmp, 0o600)
        json.dump(value, stream, sort_keys=True)
        stream.flush()
        os.fsync(stream.fileno())
    os.replace(tmp, path)


class FakeRouter:
    def __init__(self):
        self.rules = {}
    def get(self, port, protocol):
        value = self.rules.get((port, protocol))
        if value and value['expires'] <= time.time():
            del self.rules[(port, protocol)]
            return None
        return value
    def add(self, port, protocol, address, description, lease):
        if self.get(port, protocol):
            raise ServiceError('router_conflict', 'Port is occupied', 409)
        self.rules[(port, protocol)] = dict(address=address, port=port, description=description, enabled=True, expires=time.time()+lease)
    def renew(self, port, protocol, address, description, lease):
        self.rules[(port, protocol)] = dict(address=address, port=port, description=description, enabled=True, expires=time.time()+lease)
    def delete(self, port, protocol):
        self.rules.pop((port, protocol), None)


class UPnPRouter:
    """Discover service from an administrator supplied device-description URL.

    Does not perform SSDP broadcast or follow cross-host control URLs.
    """
    def __init__(self, description_url, timeout=5):
        self.timeout = timeout
        self.description_url = validate_router_url(description_url)
        self.control_url = self.service = None
        self.active_description_url = None
        self.diagnostics = None

    def record_fallback(self, error):
        if self.diagnostics:
            self.diagnostics.log('router_fallback', level='warning', deduplicate=True, **failure(error))

    SERVICES = ('urn:schemas-upnp-org:service:WANIPConnection:2',
                'urn:schemas-upnp-org:service:WANIPConnection:1',
                'urn:schemas-upnp-org:service:WANPPPConnection:1')
    READ_ONLY = {'GetExternalIPAddress', 'GetSpecificPortMappingEntry', 'GetGenericPortMappingEntry'}

    def description_candidates(self):
        # AVM advertises these two descriptions. Other vendors retain their exact URL.
        parsed = urlparse(self.description_url)
        if parsed.path in ('/igddesc.xml', '/igd2desc.xml'):
            return [parsed._replace(path=path).geturl() for path in ('/igd2desc.xml', '/igddesc.xml')]
        return [self.description_url]

    @staticmethod
    def can_fallback(error):
        if isinstance(error, HTTPError) and error.code in (401, 403):
            return False
        if isinstance(error, ServiceError):
            return error.code in ('upnp_401', 'upnp_501', 'router_service_missing', 'invalid_router_response')
        return isinstance(error, (OSError, ET.ParseError))

    def discover(self, skip_control=None):
        self.control_url = self.service = self.active_description_url = None
        last_error = None
        for description in self.description_candidates():
            try:
                with router_open(description, timeout=self.timeout) as response:
                    payload = response.read(262145)
                if len(payload) > 262144:
                    raise ServiceError('invalid_router_response', 'Router description is too large', 503)
                root = ET.fromstring(payload)
            except Exception as error:
                if not self.can_fallback(error):
                    raise
                last_error = error
                self.record_fallback(error)
                continue
            services = []
            for service in root.iter():
                if service.tag.rsplit('}', 1)[-1] != 'service':
                    continue
                fields = {child.tag.rsplit('}', 1)[-1]: child.text for child in service}
                if fields.get('serviceType') in self.SERVICES:
                    services.append(fields)
            for fields in sorted(services, key=lambda item: self.SERVICES.index(item['serviceType']))[:3]:
                kind = fields['serviceType']
                control = urljoin(description, fields.get('controlURL') or '')
                validate_router_url(control)
                if urlparse(control).netloc != urlparse(self.description_url).netloc or not fields.get('controlURL'):
                    raise ServiceError('unsafe_router_url', 'Router control URL changed host or is missing', 503)
                if (control, kind) == skip_control:
                    continue
                self.control_url, self.service = control, kind
                try:
                    # Select a responding interface before any port mapping mutation.
                    result = self._request('GetExternalIPAddress', {})
                    try:
                        ipaddress.IPv4Address(result.get('NewExternalIPAddress', ''))
                    except ValueError:
                        raise ServiceError('invalid_router_response', 'Router address response is invalid', 503)
                    self.active_description_url = description
                    return
                except Exception as error:
                    self.control_url = self.service = None
                    if not self.can_fallback(error):
                        raise
                    last_error = error
                    self.record_fallback(error)
        if last_error:
            raise last_error
        raise ServiceError('router_service_missing', 'No IPv4 port mapping service found', 503)

    def call(self, action, fields):
        if not self.control_url:
            self.discover()
        previous = (self.control_url, self.service)
        try:
            return self._request(action, fields)
        except Exception as error:
            if action not in self.READ_ONLY or self.service not in self.SERVICES or not self.can_fallback(error):
                raise
            # One read-only retry. Never retry a write whose result may be uncertain.
            self.record_fallback(error)
            self.discover(skip_control=previous)
            return self._request(action, fields)

    def _request(self, action, fields):
        envelope = ET.Element('{http://schemas.xmlsoap.org/soap/envelope/}Envelope')
        body = ET.SubElement(envelope, '{http://schemas.xmlsoap.org/soap/envelope/}Body')
        method = ET.SubElement(body, '{'+self.service+'}'+action)
        for name, value in fields.items():
            ET.SubElement(method, name).text = str(value)
        request = Request(self.control_url, data=ET.tostring(envelope), headers={
            'Content-Type': 'text/xml; charset="utf-8"', 'SOAPAction': '"'+self.service+'#'+action+'"'})
        try:
            with router_open(request, timeout=self.timeout) as response:
                payload = response.read(262145)
        except HTTPError as error:
            if error.code in (401, 403):
                raise ServiceError('router_access_denied', 'Router access denied', 503)
            payload = error.read(262145)
        root = ET.fromstring(payload)
        result = {child.tag.rsplit('}', 1)[-1]: child.text for child in root.iter()}
        if 'errorCode' in result:
            raise ServiceError('upnp_'+result['errorCode'], 'Router rejected '+action, 503)
        return result

    def get(self, port, protocol):
        try:
            result = self.call('GetSpecificPortMappingEntry', dict(NewRemoteHost='', NewExternalPort=port, NewProtocol=protocol))
        except ServiceError as error:
            if error.code == 'upnp_714':
                # Some IGDs return NoSuchEntry for an existing wildcard rule.
                # Confirm absence through enumeration before creating/deleting.
                result = self.find_enumerated_mapping(port, protocol)
                if result is None:
                    return None
            else:
                raise
        return self.mapping_from_response(result)

    @staticmethod
    def mapping_from_response(result):
        try:
            address = str(ipaddress.IPv4Address(result['NewInternalClient']))
            port = int(result['NewInternalPort'])
            enabled = result['NewEnabled']
            if not 1 <= port <= 65535 or enabled not in ('0', '1'):
                raise ValueError()
            return dict(address=address, port=port, description=result.get('NewPortMappingDescription') or '', enabled=enabled == '1')
        except (KeyError, ValueError, TypeError):
            raise ServiceError('invalid_router_mapping', 'Router returned an invalid mapping', 503)

    def find_enumerated_mapping(self, port, protocol):
        deadline = time.monotonic() + min(10, self.timeout * 2)
        for index in range(256):
            if time.monotonic() >= deadline:
                raise ServiceError('router_verification_timeout', 'Router enumeration timed out; absence is unconfirmed', 503)
            try:
                result = self.call('GetGenericPortMappingEntry', dict(NewPortMappingIndex=index))
            except ServiceError as error:
                if error.code == 'upnp_713':
                    return None  # SpecifiedArrayIndexInvalid: complete table exhausted.
                raise
            try:
                external = int(result['NewExternalPort'])
                row_protocol = result['NewProtocol']
                if not 1 <= external <= 65535 or row_protocol not in ('TCP', 'UDP'):
                    raise ValueError()
            except (KeyError, ValueError, TypeError):
                raise ServiceError('invalid_router_mapping', 'Router returned an invalid enumeration entry', 503)
            if external == port and row_protocol == protocol:
                if result.get('NewRemoteHost') not in (None, '', '0.0.0.0'):
                    raise ServiceError('router_conflict', 'Port has a restricted remote-host mapping', 409)
                self.mapping_from_response(result)  # Reject malformed verification evidence.
                return result
        raise ServiceError('router_mapping_limit', 'Router enumeration limit reached; absence is unconfirmed', 503)

    def renew(self, port, protocol, address, description, lease):
        self.call('AddPortMapping', dict(NewRemoteHost='', NewExternalPort=port, NewProtocol=protocol,
            NewInternalPort=port, NewInternalClient=address, NewEnabled=1,
            NewPortMappingDescription=description, NewLeaseDuration=lease))
    add = renew

    def delete(self, port, protocol):
        self.call('DeletePortMapping', dict(NewRemoteHost='', NewExternalPort=port, NewProtocol=protocol))


class PortOpenerService:
    def __init__(self, config, router):
        self.config, self.router = config, router
        self.lock = threading.RLock()
        self.path = Path(config['state_file'])
        self.state = json.loads(self.path.read_text()) if self.path.exists() else dict(desired=None, owned={})
        self.results = []
        self.last_reconciled = None
        self.reachable = False
        self.dry_run = config.get('dry_run', True)
        uuid.UUID(config['installation_id'])
        address = ipaddress.IPv4Address(config['internal_address'])
        if not any(address in ipaddress.IPv4Network(network) for network in ('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16')):
            raise ValueError('Internal address must be a private LAN IPv4 address')
        if config.get('backend', 'fake') not in ('fake', 'upnp'):
            raise ValueError('backend must be fake or upnp')
        if type(config['node_id']) is not int or config['node_id'] < 1:
            raise ValueError('Node must be a positive integer')
        if type(self.dry_run) is not bool:
            raise ValueError('dry_run must be a boolean')
        if type(config['min_port']) is not int or type(config['max_port']) is not int or not 1 <= config['min_port'] <= config['max_port'] <= 65535:
            raise ValueError('Allowed port range is invalid')
        if type(config.get('listen_port', 8787)) is not int or not 1 <= config.get('listen_port', 8787) <= 65535:
            raise ValueError('Listen port is invalid')
        if self.state.get('installation_id', config['installation_id']) != config['installation_id']:
            raise ValueError('State belongs to a different installation')
        if self.state.get('node_id', (self.state.get('desired') or {}).get('node_id', config['node_id'])) != config['node_id']:
            raise ValueError('State belongs to a different node')
        self.state['installation_id'] = config['installation_id']
        self.state['node_id'] = config['node_id']
        persisted_url = self.state.get('router_url')
        if persisted_url:
            validate_router_url(persisted_url)
            if config.get('backend', 'fake') == 'upnp':
                self.router = UPnPRouter(persisted_url)
        from ipv6 import IPv6Manager
        self.ipv6_manager = IPv6Manager(self)
        self.diagnostics = EventLog(self.path)
        self.router.diagnostics = self.diagnostics
        self.diagnostics.log('service_started')

    def save(self):
        atomic_save(self.path, self.state)

    def validate_router_request(self, body):
        if not isinstance(body, dict) or set(body) != {'node_id', 'forwarding_type', 'router_url'}:
            raise ServiceError('invalid_router_configuration', 'Node, forwarding type and router URL are required')
        if type(body['node_id']) is not int or body['node_id'] != self.config['node_id']:
            raise ServiceError('untrusted_scope', 'Node does not match')
        if body['forwarding_type'] != 'upnp':
            raise ServiceError('unsupported_forwarding_type', 'Only UPnP forwarding is supported')
        return validate_router_url(body['router_url'])

    def configure_router(self, body):
        with self.lock:
            url = self.validate_router_request(body)
            current = self.state.get('router_url') or (self.config.get('router_description_url') if self.config.get('backend', 'fake') == 'upnp' else None)
            if current == url:
                return self.status()
            if self.config.get('backend', 'fake') == 'upnp' and (self.state['owned'] or self.state.get('ipv6_owned') or (self.state['desired'] or {}).get('mappings')):
                raise ServiceError('router_cleanup_required', 'Remove desired and owned mappings before changing routers', 409)
            candidate = UPnPRouter(url) if self.config.get('backend', 'fake') == 'upnp' else self.router
            previous = self.state.get('router_url')
            self.state['router_url'] = url
            try:
                self.save()
            except Exception:
                if previous is None:
                    self.state.pop('router_url', None)
                else:
                    self.state['router_url'] = previous
                raise
            self.router = candidate
            self.router.diagnostics = self.diagnostics
            self.reachable = False
            return self.status()

    def test_router(self, body):
        with self.lock:
            url = self.validate_router_request(body)
            backend = self.config.get('backend', 'fake')
            if backend == 'fake':
                return dict(backend=backend, success=True, router_reachable=False, service_type=None,
                    message='Simulation: router URL validated; no network request was sent', router_url=url)
            try:
                candidate = UPnPRouter(url)
                candidate.discover()
                candidate.call('GetExternalIPAddress', {})
            except ServiceError:
                raise
            except Exception:
                raise ServiceError('router_unavailable', 'Router discovery or read-only connection test failed', 503)
            return dict(backend=backend, success=True, router_reachable=True,
                service_type=candidate.service.split(':service:', 1)[-1],
                message='UPnP discovery and read-only connection test succeeded; port sharing permissions remain unverified', router_url=url)

    def validate(self, body):
        if not isinstance(body, dict) or set(body) != {'schema_version','installation_id','generation','node_id','mappings'}:
            raise ServiceError('invalid_snapshot', 'A complete v1 snapshot is required')
        if type(body['schema_version']) is not int or type(body['node_id']) is not int or body['schema_version'] != 1 or body['installation_id'] != self.config['installation_id'] or body['node_id'] != self.config['node_id']:
            raise ServiceError('untrusted_scope', 'Installation or node does not match')
        if type(body['generation']) is not int or body['generation'] < 1:
            raise ServiceError('invalid_generation', 'Generation must be a positive integer')
        if not isinstance(body['mappings'], list) or len(body['mappings']) > self.config.get('max_mappings', 256):
            raise ServiceError('invalid_mappings', 'Mapping limit exceeded')
        seen, ids = set(), set()
        for row in body['mappings']:
            if not isinstance(row, dict) or set(row) != {'mapping_id','server_uuid','allocation_id','internal_address','port','protocol'}:
                raise ServiceError('invalid_mapping', 'Mapping fields are invalid')
            try:
                server = str(uuid.UUID(row['server_uuid']))
            except (ValueError, TypeError, AttributeError):
                raise ServiceError('invalid_server', 'Server UUID is invalid')
            if type(row['allocation_id']) is not int or row['allocation_id'] < 1 or row['protocol'] not in ('TCP','UDP'):
                raise ServiceError('invalid_mapping', 'Allocation or protocol is invalid')
            if row['mapping_id'] != f"{server}:{row['allocation_id']}:{row['protocol'].lower()}":
                raise ServiceError('invalid_mapping_id', 'Mapping ID does not match its allocation')
            if row['internal_address'] != self.config['internal_address']:
                raise ServiceError('untrusted_address', 'Destination address is not configured')
            if type(row['port']) is not int or not 1 <= self.config['min_port'] <= row['port'] <= self.config['max_port'] <= 65535:
                raise ServiceError('blocked_port', 'Port is outside allowed range')
            key = (row['port'], row['protocol'])
            if key in seen or row['mapping_id'] in ids:
                raise ServiceError('duplicate_mapping', 'Duplicate port/protocol or mapping ID')
            seen.add(key)
            ids.add(row['mapping_id'])
        return dict(body, mappings=sorted(body['mappings'], key=lambda row: row['mapping_id']))

    def accept(self, body):
        with self.lock:
            self.last_accept_persisted = False
            body = self.validate(body)
            old = self.state['desired']
            if old and (body['generation'] < old['generation'] or (body['generation'] == old['generation'] and body != old)):
                raise ServiceError('generation_conflict', 'Stale or reused generation', 409)
            self.state['desired'] = body
            try:
                self.save()
            except Exception:
                self.state['desired'] = old
                raise
            self.last_accept_persisted = True
            self.reconcile()
            return self.status()

    def matches(self, observed, record):
        return observed and all(observed.get(key) == record[key] for key in ('address','port','description'))

    def result(self, row, state, code=None, message=''):
        self.results.append(dict(mapping_id=row['mapping_id'], port=row['port'], protocol=row['protocol'], state=state, error_code=code, message=message))
        fields = dict(port=row['port'], protocol=row['protocol'], family='IPv4', state=state)
        if code: fields['error_code'] = code
        self.diagnostics.log('mapping_state', level='error' if state in ('error', 'conflict', 'blocked') else 'info',
                             deduplicate=True, **fields)
        self.diagnostics.log('mapping_check', level='debug', **fields)

    def reconcile(self):
        with self.lock:
            if getattr(self, 'credential_expires_at', float('inf')) <= time.time():
                self.renewal_paused = True
            if getattr(self, 'renewal_paused', False):
                return
            self.results = []
            desired = self.state['desired']
            if not desired:
                return
            rows = {row['mapping_id']: row for row in desired['mappings']}
            self.reachable = True
            for identity, record in list(self.state['owned'].items()):
                row = rows.get(identity)
                if row and row['port'] == record['port'] and row['protocol'] == record['protocol']:
                    continue
                try:
                    observed = self.router.get(record['port'], record['protocol'])
                    if observed and (record['phase'] != 'confirmed' or not self.matches(observed, record)):
                        self.result(record, 'conflict', 'ownership_lost', 'Old mapping cannot be safely removed')
                        continue
                    if self.dry_run:
                        self.result(record, 'removing', message='Dry run: removal planned')
                        continue
                    if observed:
                        self.router.delete(record['port'], record['protocol'])
                        if self.router.get(record['port'], record['protocol']):
                            raise ServiceError('delete_unverified', 'Router mapping still exists', 503)
                    del self.state['owned'][identity]
                    self.save()
                    self.result(record, 'removed')
                except Exception as error:
                    self.reachable = False
                    self.result(record, 'error', getattr(error, 'code', 'router_unavailable'), 'Cleanup failed; retry retained')
            for identity, row in rows.items():
                try:
                    record = self.state['owned'].get(identity)
                    if record and (record['port'] != row['port'] or record['protocol'] != row['protocol']):
                        self.result(row, 'blocked', 'cleanup_pending', 'Previous mapping must be removed first')
                        continue
                    observed = self.router.get(row['port'], row['protocol'])
                    if observed and (not record or record['phase'] not in ('confirmed', 'applied') or not self.matches(observed, record)):
                        self.result(row, 'conflict', 'occupied_or_ambiguous', 'Mapping is occupied or lacks durable ownership evidence')
                        continue
                    if self.dry_run:
                        self.result(row, 'pending', message='Dry run: mapping validated')
                        continue
                    lease = self.config.get('lease_seconds', 3600)
                    if not record:
                        description = 'PO:'+hashlib.sha256((self.config['installation_id']+identity).encode()).hexdigest()[:24]
                        record = dict(row, address=row['internal_address'], description=description, phase='creating')
                        self.state['owned'][identity] = record
                        self.save()  # Intent durable before mutation. Ambiguous crash never adopted.
                    if observed and record['phase'] == 'applied':
                        # A successful write was persisted; retry its verification without another write.
                        pass
                    elif observed:
                        self.router.renew(row['port'], row['protocol'], record['address'], record['description'], lease)
                    else:
                        record['phase'] = 'creating'
                        self.save()
                        self.router.add(row['port'], row['protocol'], record['address'], record['description'], lease)
                        record['phase'] = 'applied'
                        try:
                            self.save()
                        except Exception:
                            record['phase'] = 'creating'
                            raise
                    verified = self.router.get(row['port'], row['protocol'])
                    if not self.matches(verified, record):
                        raise ServiceError('create_unverified', 'Mapping creation could not be verified', 503)
                    if not verified.get('enabled'):
                        raise ServiceError('mapping_disabled', 'Router mapping is disabled', 503)
                    previous_phase = record['phase']
                    record['phase'] = 'confirmed'
                    try:
                        self.save()
                    except Exception:
                        record['phase'] = previous_phase
                        raise
                    self.result(row, 'mapped')
                except Exception as error:
                    self.reachable = False
                    self.result(row, 'error', getattr(error, 'code', 'router_unavailable'), 'Router operation failed; retry retained')
            self.last_reconciled = datetime.now(timezone.utc).isoformat()
            self.diagnostics.log('router_selected', router_service=getattr(self.router, 'service', None), deduplicate=True)
            self.ipv6_manager.reconcile()
            self.diagnostics.log('ipv6_state', state=self.ipv6_manager.report.get('state'),
                                 error_code=self.ipv6_manager.report.get('error_code'), family='IPv6', deduplicate=True)

    def status(self):
        with self.lock:
            return dict(schema_version=1, generation=(self.state['desired'] or {}).get('generation', 0), dry_run=self.dry_run,
                backend=self.config.get('backend', 'fake'),
                renewal_paused=getattr(self, 'renewal_paused', False),
                ipv6=self.ipv6_manager.report,
                router_url=self.state.get('router_url') or (self.config.get('router_description_url') if self.config.get('backend', 'fake') == 'upnp' else None),
                router_reachable=self.reachable, router_service=getattr(self.router, 'service', None),
                last_reconciled_at=self.last_reconciled, mappings=self.results.copy())


def handler_for(service, token):
    class Handler(BaseHTTPRequestHandler):
        def log_message(self, *args):
            pass
        def reply(self, status, value):
            payload = json.dumps(value).encode()
            self.send_response(status)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(payload)))
            self.end_headers()
            self.wfile.write(payload)
        def handle_request(self):
            self.connection.settimeout(10)
            try:
                if not hmac.compare_digest(self.headers.get('Authorization', ''), 'Bearer '+token):
                    raise ServiceError('unauthorized', 'Authentication required', 401)
                if self.command == 'GET' and self.path == '/v1/health':
                    return self.reply(200, dict(schema_version=1, status='ok'))
                if self.command == 'GET' and self.path == '/v1/status':
                    return self.reply(200, service.status())
                routes = {('PUT', '/v1/desired-state'): service.accept,
                    ('PUT', '/v1/router/configuration'): service.configure_router,
                    ('POST', '/v1/router/test'): service.test_router}
                action = routes.get((self.command, self.path))
                if action is None:
                    raise ServiceError('not_found', 'Endpoint not found', 404)
                if self.headers.get('Transfer-Encoding'):
                    raise ServiceError('invalid_request', 'Chunked bodies are unsupported')
                length = int(self.headers.get('Content-Length', '0'))
                if not 0 < length <= 262144:
                    raise ServiceError('invalid_request', 'Body size is invalid', 413)
                body = json.loads(self.rfile.read(length))
                self.reply(200, action(body))
            except ServiceError as error:
                self.reply(error.status, dict(error_code=error.code, message=str(error)))
            except (ValueError, TypeError, TimeoutError):
                self.reply(400, dict(error_code='invalid_request', message='Invalid JSON request'))
            except Exception:
                self.reply(503, dict(error_code='service_unavailable', message='PortOpenerService operation failed'))
        do_GET = handle_request
        do_PUT = handle_request
        do_POST = handle_request
    return Handler


class BoundedHTTPServer(ThreadingHTTPServer):
    daemon_threads = True
    request_queue_size = 16
    def __init__(self, *args, **kwargs):
        self.slots = threading.BoundedSemaphore(16)
        super().__init__(*args, **kwargs)
    def process_request(self, request, client_address):
        if not self.slots.acquire(blocking=False):
            self.shutdown_request(request)
            return
        try:
            super().process_request(request, client_address)
        except Exception:
            self.slots.release()
            raise
    def process_request_thread(self, request, client_address):
        request.settimeout(10)
        try:
            super().process_request_thread(request, client_address)
        finally:
            self.slots.release()


def main():
    if len(sys.argv) > 1 and sys.argv[1] == 'pair':
        from outbound import pair, bootstrap_config
        parser = argparse.ArgumentParser(description='Pair POS with Pelican using verified HTTPS')
        parser.add_argument('--panel', default=os.environ.get('PORT_OPENER_PANEL_URL'), required=not bool(os.environ.get('PORT_OPENER_PANEL_URL')))
        parser.add_argument('--code')
        parser.add_argument('--credentials', required=True)
        parser.add_argument('--config', help='Optional: write an outbound service configuration automatically')
        parser.add_argument('--ca-file', default=os.environ.get('PORT_OPENER_CA_FILE'))
        args = parser.parse_args(sys.argv[2:])
        if args.config and Path(args.config).exists():
            raise SystemExit('Configuration already exists; refusing overwrite')
        credentials = pair(args.panel, args.code, args.credentials, args.ca_file)
        if args.config:
            bootstrap_config(credentials, args.credentials, args.config, args.ca_file)
        print('Pairing credentials saved. No router mappings were changed.')
        return
    arguments = sys.argv[1:]
    if arguments[:1] == ['run']:
        arguments = arguments[1:]
    parser = argparse.ArgumentParser()
    parser.add_argument('--config', default=os.environ.get('PORT_OPENER_CONFIG'), required=not bool(os.environ.get('PORT_OPENER_CONFIG')))
    args = parser.parse_args(arguments)
    config = json.loads(Path(args.config).read_text())
    for variable, key in [('PORT_OPENER_TRANSPORT', 'transport'), ('PORT_OPENER_PANEL_URL', 'panel_url'), ('PORT_OPENER_CREDENTIALS_FILE', 'credentials_file'), ('PORT_OPENER_CA_FILE', 'ca_file')]:
        if os.environ.get(variable):
            config[key] = os.environ[variable]
    transport = config.get('transport', 'legacy')
    if transport not in ('legacy', 'outbound'):
        raise SystemExit('transport must be legacy or outbound')
    token = os.environ.get('PORT_OPENER_TOKEN', '')
    if transport == 'legacy' and len(token) < 32:
        raise SystemExit('PORT_OPENER_TOKEN must contain at least 32 characters')
    lease = config.get('lease_seconds', 3600)
    interval = config.get('reconcile_seconds', 60)
    if not 120 <= lease <= 86400 or not 5 <= interval <= lease // 3:
        raise SystemExit('Finite lease and renewal interval are invalid')
    backend = config.get('backend', 'fake')
    if backend not in ('fake', 'upnp'):
        raise SystemExit('backend must be fake or upnp')
    router = FakeRouter() if backend == 'fake' else UPnPRouter(config['router_description_url'])
    # Fail on concurrent processes, including a second dry-run reconciler.
    import fcntl
    state_path = Path(config['state_file'])
    state_path.parent.mkdir(parents=True, exist_ok=True)
    lock_file = open(str(state_path)+'.lock', 'a')
    fcntl.flock(lock_file, fcntl.LOCK_EX | fcntl.LOCK_NB)
    service = PortOpenerService(config, router)
    outbound = None
    if transport == 'outbound':
        from outbound import OutboundTransport
        outbound = OutboundTransport(service, config)
    def loop():
        while True:
            try:
                service.reconcile()
            except Exception as error:
                # Durable desired/ownership remain available for next retry.
                service.reachable = False
                service.diagnostics.log('reconcile_failed', level='error', deduplicate=True, **failure(error))
            seconds = service.config.get('reconcile_seconds', interval)
            if service.state.get('ipv6_policy', {}).get('enabled') or service.state.get('ipv6_owned'):
                seconds = min(seconds, 30)
            time.sleep(seconds)
    threading.Thread(target=loop, daemon=True).start()
    if outbound is not None:
        outbound.run()
        return
    BoundedHTTPServer((config.get('bind', '127.0.0.1'), config.get('listen_port', 8787)), handler_for(service, token)).serve_forever()


if __name__ == '__main__':
    main()
