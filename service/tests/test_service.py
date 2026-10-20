import copy
import http.client
import json
import tempfile
import threading
import unittest
from http.server import ThreadingHTTPServer
from pathlib import Path
from unittest.mock import patch
from port_opener import PortOpenerService, ServiceError, FakeRouter, handler_for, UPnPRouter

INSTALLATION = '11111111-1111-4111-8111-111111111111'
SERVER = '22222222-2222-4222-8222-222222222222'


class ServiceTests(unittest.TestCase):
    def router_payload(self, url='http://192.168.178.1:49000/igddesc.xml'):
        return dict(node_id=1, forwarding_type='upnp', router_url=url)
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.config = dict(installation_id=INSTALLATION, node_id=1, internal_address='192.168.178.50',
            min_port=50000, max_port=55000, state_file=str(Path(self.tmp.name)/'state.json'), dry_run=False)
        self.router = FakeRouter()
        self.service = PortOpenerService(self.config, self.router)
        self.row = dict(mapping_id=SERVER+':1:tcp', server_uuid=SERVER, allocation_id=1,
            internal_address='192.168.178.50', port=54321, protocol='TCP')
    def snapshot(self, generation=1, rows=None):
        return dict(schema_version=1, installation_id=INSTALLATION, node_id=1,
            generation=generation, mappings=[self.row.copy()] if rows is None else rows)
    def test_create_idempotency_restart_cleanup(self):
        self.assertEqual(self.service.accept(self.snapshot())['mappings'][0]['state'], 'mapped')
        self.service.accept(self.snapshot())
        self.service = PortOpenerService(self.config, self.router)
        self.service.reconcile()
        self.assertEqual(self.service.status()['mappings'][0]['state'], 'mapped')
        self.service.accept(self.snapshot(2, []))
        self.assertIsNone(self.router.get(54321, 'TCP'))
    def test_unknown_mapping_never_changed(self):
        self.router.add(54321, 'TCP', '192.168.178.20', 'manual', 3600)
        self.assertEqual(self.service.accept(self.snapshot())['mappings'][0]['state'], 'conflict')
        self.service.accept(self.snapshot(2, []))
        self.assertEqual(self.router.get(54321, 'TCP')['description'], 'manual')
    def test_ownership_replaced_never_deleted(self):
        self.service.accept(self.snapshot())
        self.router.renew(54321, 'TCP', '192.168.178.50', 'manual', 3600)
        self.assertEqual(self.service.accept(self.snapshot(2, []))['mappings'][0]['state'], 'conflict')
        self.assertEqual(self.router.get(54321, 'TCP')['description'], 'manual')
    def test_crash_after_creation_is_ambiguous(self):
        original = self.service.save
        def fail_confirm():
            if any(row['phase'] == 'applied' for row in self.service.state['owned'].values()):
                raise OSError('disk failure')
            original()
        self.service.save = fail_confirm
        self.service.accept(self.snapshot())
        restarted = PortOpenerService(self.config, self.router)
        restarted.reconcile()
        self.assertEqual(restarted.status()['mappings'][0]['state'], 'conflict')
        restarted.accept(self.snapshot(2, []))
        self.assertIsNotNone(self.router.get(54321, 'TCP'))
    def test_successful_write_survives_failed_verification_and_restart(self):
        original_get = self.router.get
        original_add = self.router.add
        def add_then_fail_read(*args):
            original_add(*args)
            self.router.get = lambda *args: (_ for _ in ()).throw(OSError('read unavailable'))
        self.router.add = add_then_fail_read
        self.assertEqual(self.service.accept(self.snapshot())['mappings'][0]['state'], 'error')
        self.assertEqual(self.service.state['owned'][self.row['mapping_id']]['phase'], 'applied')
        self.router.get = original_get
        restarted = PortOpenerService(self.config, self.router)
        with patch.object(self.router, 'renew', side_effect=AssertionError('Verification must precede renewal')):
            restarted.reconcile()
        self.assertEqual(restarted.status()['mappings'][0]['state'], 'mapped')
        restarted.accept(self.snapshot(2, []))
        self.assertIsNone(self.router.get(54321, 'TCP'))
    def test_applied_mapping_replaced_is_not_adopted(self):
        self.service.accept(self.snapshot())
        self.service.state['owned'][self.row['mapping_id']]['phase'] = 'applied'
        self.service.save()
        self.router.rules[(54321, 'TCP')]['description'] = 'manual'
        restarted = PortOpenerService(self.config, self.router)
        restarted.reconcile()
        self.assertEqual(restarted.status()['mappings'][0]['state'], 'conflict')
        restarted.accept(self.snapshot(2, []))
        self.assertEqual(self.router.get(54321, 'TCP')['description'], 'manual')
    def test_expired_lease_and_router_reboot(self):
        self.service.accept(self.snapshot())
        self.router.rules[(54321, 'TCP')]['expires'] = 0
        self.service.reconcile()
        self.assertEqual(self.service.status()['mappings'][0]['state'], 'mapped')
        self.router.rules.clear()
        self.service.reconcile()
        self.assertIsNotNone(self.router.get(54321, 'TCP'))
    def test_invalid_snapshot_and_generations_preserve_desired(self):
        self.service.accept(self.snapshot(2))
        invalid = self.snapshot(3)
        invalid['mappings'][0]['internal_address'] = '192.168.178.99'
        for body in (invalid, self.snapshot(1), self.snapshot(2, [])):
            with self.assertRaises(ServiceError):
                self.service.accept(body)
        self.assertEqual(self.service.state['desired'], self.snapshot(2))
        self.assertIsNotNone(self.router.get(54321, 'TCP'))
    def test_tcp_udp_are_independent_duplicate_rejected(self):
        udp = dict(self.row, mapping_id=SERVER+':1:udp', protocol='UDP')
        result = self.service.accept(self.snapshot(rows=[self.row, udp]))
        self.assertEqual([row['state'] for row in result['mappings']], ['mapped', 'mapped'])
        with self.assertRaises(ServiceError):
            self.service.accept(self.snapshot(2, [self.row, self.row]))
    def test_dry_run_never_mutates(self):
        self.service.dry_run = True
        self.assertEqual(self.service.accept(self.snapshot())['mappings'][0]['state'], 'pending')
        self.assertEqual(self.router.rules, {})
        self.assertEqual(self.service.state['owned'], {})
    def test_cleanup_failure_retries(self):
        self.service.accept(self.snapshot())
        original = self.router.delete
        self.router.delete = lambda *args: (_ for _ in ()).throw(OSError('offline'))
        self.assertEqual(self.service.accept(self.snapshot(2, []))['mappings'][0]['state'], 'error')
        self.assertTrue(self.service.state['owned'])
        self.router.delete = original
        self.service.reconcile()
        self.assertFalse(self.service.state['owned'])
    def test_port_change_waits_for_cleanup(self):
        self.service.accept(self.snapshot())
        self.router.renew(54321, 'TCP', '192.168.178.50', 'manual', 3600)
        changed = dict(self.row, port=54322)
        result = self.service.accept(self.snapshot(2, [changed]))
        self.assertEqual(result['mappings'][-1]['state'], 'blocked')
        self.assertIsNone(self.router.get(54322, 'TCP'))
    def test_config_and_boolean_scope_rejected(self):
        for changes in ({'backend': 'upnp-typo'}, {'dry_run': 'false'}, {'node_id': True}, {'internal_address': '8.8.8.8'}):
            with self.assertRaises(ValueError):
                PortOpenerService(dict(self.config, **changes), self.router)
        for key in ('schema_version', 'node_id'):
            body = self.snapshot()
            body[key] = True
            with self.assertRaises(ServiceError):
                self.service.accept(body)
    def test_snapshot_persistence_failure_keeps_previous_desired(self):
        self.service.accept(self.snapshot())
        self.service.save = lambda: (_ for _ in ()).throw(OSError('disk unavailable'))
        with self.assertRaises(OSError):
            self.service.accept(self.snapshot(2, []))
        self.assertEqual(self.service.state['desired'], self.snapshot())
        self.assertIsNotNone(self.router.get(54321, 'TCP'))
    def test_http_auth_and_bounds(self):
        token = 'x'*32
        server = ThreadingHTTPServer(('127.0.0.1', 0), handler_for(self.service, token))
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        connection = http.client.HTTPConnection(*server.server_address)
        self.addCleanup(connection.close)
        connection.request('GET', '/v1/status')
        response = connection.getresponse()
        self.assertEqual(response.status, 401)
        response.read()
        connection.request('PUT', '/v1/desired-state', json.dumps(self.snapshot()), {'Authorization': 'Bearer '+token})
        response = connection.getresponse()
        self.assertEqual(response.status, 200)
        response.read()
        connection.request('PUT', '/v1/desired-state', '', {'Authorization': 'Bearer '+token, 'Content-Length': '262145'})
        response = connection.getresponse()
        self.assertEqual(response.status, 413)
        response.read()
    def test_http_invalid_json_and_token_do_not_mutate(self):
        token = 'x'*32
        server = ThreadingHTTPServer(('127.0.0.1', 0), handler_for(self.service, token))
        threading.Thread(target=server.serve_forever, daemon=True).start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        connection = http.client.HTTPConnection(*server.server_address)
        self.addCleanup(connection.close)
        connection.request('PUT', '/v1/desired-state', json.dumps(self.snapshot()), {'Authorization': 'Bearer wrong'})
        response = connection.getresponse()
        self.assertEqual(response.status, 401)
        response.read()
        self.assertIsNone(self.service.state['desired'])
        connection.request('PUT', '/v1/desired-state', '{invalid', {'Authorization': 'Bearer '+token})
        response = connection.getresponse()
        self.assertEqual(response.status, 400)
        response.read()
        self.assertIsNone(self.service.state['desired'])
        self.assertEqual(self.router.rules, {})
    def test_fake_router_configuration_and_restart(self):
        self.service.accept(self.snapshot())
        payload = self.router_payload()
        with patch('port_opener.router_open', side_effect=AssertionError('No network allowed')):
            result = self.service.test_router(payload)
            self.assertTrue(result['success'])
            self.assertEqual(result['backend'], 'fake')
            self.assertFalse(result['router_reachable'])
            self.assertIsNone(result['service_type'])
            self.service.configure_router(payload)
            self.service.configure_router(payload)
        restarted = PortOpenerService(self.config, self.router)
        self.assertIs(restarted.router, self.router)
        self.assertEqual(restarted.status()['router_url'], payload['router_url'])
        self.assertFalse(restarted.dry_run)
        self.assertIsNotNone(self.router.get(54321, 'TCP'))
    def test_router_configuration_scope_and_url_validation(self):
        unsafe = ['https://192.168.178.1/x', 'http://127.0.0.1/x', 'http://169.254.1.1/x',
            'http://8.8.8.8/x', 'http://router.local/x', 'http://[::1]/x',
            'http://user:pass@192.168.178.1/x', 'http://192.168.178.1/x?q=1',
            'http://192.168.178.1/x#f', 'http://192.168.178.1',
            'http://192.168.178.1:0/x', 'http://192.168.178.1:65536/x',
            'http://192.168.178.1:/x', 'http://192.168.178.1/x?', 'http://192.168.178.1/x#',
            'http://192.168.178.1/x\n', 'http://192.168.178.1/x;params']
        for url in unsafe:
            with self.subTest(url=url), self.assertRaises(ServiceError):
                self.service.configure_router(self.router_payload(url))
        for changes in ({'node_id': 2}, {'node_id': True}, {'forwarding_type': 'pcp'}, {'dry_run': False}):
            with self.assertRaises(ServiceError):
                self.service.configure_router(dict(self.router_payload(), **changes))
        self.assertNotIn('router_url', self.service.state)
    def test_upnp_endpoint_switch_blocked_and_restore(self):
        config = dict(self.config, backend='upnp', router_description_url='http://192.168.178.1:49000/igddesc.xml')
        service = PortOpenerService(config, UPnPRouter(config['router_description_url']))
        service.state['desired'] = self.snapshot()
        # Same endpoint is idempotent, including while mappings are desired.
        service.configure_router(self.router_payload())
        changed = self.router_payload('http://192.168.178.2:49000/igddesc.xml')
        with self.assertRaises(ServiceError) as caught:
            service.configure_router(changed)
        self.assertEqual(caught.exception.status, 409)
        service.state['desired'] = self.snapshot(2, [])
        service.state['owned'] = {'retained': self.row}
        with self.assertRaises(ServiceError):
            service.configure_router(changed)
        service.state['owned'] = {}
        service.configure_router(changed)
        restarted = PortOpenerService(config, UPnPRouter(config['router_description_url']))
        self.assertEqual(restarted.router.description_url, changed['router_url'])
        self.assertEqual(restarted.status()['router_url'], changed['router_url'])
    def test_upnp_router_test_only_discovers_and_reads(self):
        config = dict(self.config, backend='upnp', router_description_url=self.router_payload()['router_url'])
        service = PortOpenerService(config, UPnPRouter(config['router_description_url']))
        def discovered(candidate):
            candidate.service = 'urn:schemas-upnp-org:service:WANIPConnection:1'
        with patch.object(UPnPRouter, 'discover', autospec=True, side_effect=discovered) as discover, patch.object(UPnPRouter, 'call', return_value={'NewExternalIPAddress': '203.0.113.1'}) as call:
            result = service.test_router(self.router_payload())
        self.assertEqual(discover.call_count, 1)
        call.assert_called_once_with('GetExternalIPAddress', {})
        self.assertTrue(result['success'])
        self.assertTrue(result['router_reachable'])
        self.assertEqual(result['service_type'], 'WANIPConnection:1')
        self.assertNotIn('router_url', service.state)
    def test_http_router_routes_require_auth(self):
        token = 'x'*32
        server = ThreadingHTTPServer(('127.0.0.1', 0), handler_for(self.service, token))
        threading.Thread(target=server.serve_forever, daemon=True).start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        connection = http.client.HTTPConnection(*server.server_address)
        self.addCleanup(connection.close)
        payload = json.dumps(self.router_payload())
        for method, path in [('POST', '/v1/router/test'), ('PUT', '/v1/router/configuration')]:
            connection.request(method, path, payload)
            response = connection.getresponse()
            self.assertEqual(response.status, 401)
            response.read()
            self.assertNotIn('router_url', self.service.state)
            connection.request(method, path, payload, {'Authorization': 'Bearer '+token})
            response = connection.getresponse()
            self.assertEqual(response.status, 200)
            response.read()
        self.assertEqual(self.service.status()['router_url'], self.router_payload()['router_url'])
        self.assertEqual(self.router.rules, {})


if __name__ == '__main__':
    unittest.main()
