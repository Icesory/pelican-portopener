import copy
import json
import ssl
import tempfile
import time
import unittest
from datetime import datetime, timezone
from pathlib import Path
from unittest.mock import patch
from urllib.error import HTTPError
from outbound import HTTPSClient, OutboundTransport, panel_url, credentials_from
from port_opener import PortOpenerService, FakeRouter, atomic_save, ServiceError

INSTALLATION = '11111111-1111-4111-8111-111111111111'


def timestamp(seconds):
    return datetime.fromtimestamp(time.time()+seconds, timezone.utc).isoformat()


class OutboundTests(unittest.TestCase):
    def test_debug_control_and_report_never_include_credential(self):
        self.response['diagnostics_control'] = dict(debug_until=timestamp(900))
        with patch.object(self.transport.client, 'request', side_effect=[self.response, {}]) as request:
            self.transport.cycle()
        diagnostics = request.call_args_list[1].args[2]['diagnostics']
        self.assertTrue(diagnostics['debug_active'])
        self.assertNotIn(self.credentials['credential'], json.dumps(diagnostics))
        self.assertNotIn('diagnostics_control', self.service.state)

    def test_transport_failure_and_recovery_are_safe_and_visible(self):
        secret = self.credentials['credential']
        with patch.object(self.transport.client, 'request', side_effect=HTTPError('https://'+secret+'.invalid',403,secret,{},None)):
            with self.assertRaises(HTTPError): self.transport.cycle()
        with patch.object(self.transport.client, 'request', side_effect=[self.response, {}]): self.transport.cycle()
        payload = self.service.diagnostics.report()
        self.assertNotIn(secret,json.dumps(payload))
        self.assertTrue(any(row.get('error_code')=='authentication_failed' for row in payload['events']))
        self.assertTrue(any(row.get('outcome')=='recovered' for row in payload['events']))

    def test_ipv4_only_rejects_remote_ipv6_before_router_changes(self):
        self.transport.config['ipv4_only'] = True
        self.remote['ipv6_enabled'] = True
        with patch.object(self.service, 'configure_router') as configure:
            with self.assertRaises(ServiceError) as error:
                self.transport.apply_config(self.remote)
            self.assertEqual(error.exception.code, 'ipv6_not_supported')
            configure.assert_not_called()

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        root = Path(self.tmp.name)
        self.credentials = dict(panel_url='https://panel.example.com', node_id=1, installation_id=INSTALLATION,
            credential='a'*64, expires_at=timestamp(30*86400))
        self.path = root/'credentials.json'
        atomic_save(self.path, self.credentials)
        self.config = dict(node_id=1, installation_id=INSTALLATION, internal_address='192.168.178.50',
            min_port=50000, max_port=55000, state_file=str(root/'state.json'), credentials_file=str(self.path),
            backend='fake', dry_run=False)
        self.service = PortOpenerService(self.config, FakeRouter())
        self.transport = OutboundTransport(self.service, self.config)
        self.remote = dict(node_id=1, internal_address=self.config['internal_address'], min_port=50000,
            max_port=55000, dry_run=False, forwarding_type='upnp', router_url='http://192.168.178.1:49000/igddesc.xml',
            lease_seconds=3600, reconcile_seconds=60)
        self.response = dict(schema_version=1, configuration=self.remote,
            desired_state=dict(schema_version=1, installation_id=INSTALLATION, node_id=1, generation=1, mappings=[]),
            test_request_id=None)
    def test_https_only_and_origin_validation(self):
        for url in ['http://panel.example.com', 'https://user:pass@panel.example.com', 'https://panel.example.com/x',
                    'https://panel.example.com/?q=x', 'https://panel.example.com/#fragment']:
            with self.assertRaises(ValueError):
                panel_url(url)
    def test_default_ca_verification_and_explicit_ca(self):
        context = ssl.create_default_context()
        with patch('outbound.ssl.create_default_context', return_value=context) as factory:
            HTTPSClient('https://panel.example.com')
            factory.assert_called_once_with(cafile=None)
        with patch('outbound.ssl.create_default_context', return_value=context) as factory:
            HTTPSClient('https://panel.example.com', '/trusted/custom-ca.pem')
            factory.assert_called_once_with(cafile='/trusted/custom-ca.pem')
        self.assertTrue(context.check_hostname)
        self.assertEqual(context.verify_mode, ssl.CERT_REQUIRED)
    def test_poll_report_and_replay_test_id(self):
        self.response['test_request_id'] = '33333333-3333-4333-8333-333333333333'
        with (patch.object(self.transport.client, 'request', side_effect=[self.response, {}, self.response, {}]) as request,
             patch.object(self.service, 'test_router', wraps=self.service.test_router) as test):
            self.transport.cycle()
            self.transport.cycle()
        self.assertEqual(test.call_count, 1)
        self.assertEqual(request.call_args_list[0].args[:2], ('GET', '/desired-state'))
        report = request.call_args_list[1].args[2]
        self.assertEqual(report['test_request_id'], self.response['test_request_id'])
        self.assertEqual(report['status']['generation'], 1)
    def test_invalid_scope_preserves_state(self):
        self.response['desired_state']['node_id'] = 2
        with patch.object(self.transport.client, 'request', return_value=self.response), self.assertRaises(ServiceError):
            self.transport.cycle()
        self.assertIsNone(self.service.state['desired'])
        self.assertNotIn('router_url', self.service.state)
    def test_reconcile_failure_cannot_revert_durably_accepted_preview_policy(self):
        self.service.dry_run = False
        self.service.state['outbound_effective_dry_run'] = False
        self.remote['dry_run'] = True
        with patch.object(self.transport.client, 'request', return_value=self.response), patch.object(self.service, 'reconcile', side_effect=OSError('disk failure after acceptance')):
            with self.assertRaises(OSError): self.transport.cycle()
        self.assertTrue(self.service.last_accept_persisted)
        self.assertTrue(self.service.dry_run)
        self.assertTrue(json.loads(self.service.path.read_text())['outbound_effective_dry_run'])
    def test_remote_policy_cannot_expand_local_constraints(self):
        for changes in ({'max_port': 65535}, {'internal_address': '192.168.178.99'}, {'dry_run': 'false'}, {'backend': 'upnp'}, {'lease_seconds': 0}):
            with self.assertRaises(ServiceError):
                self.transport.apply_config(dict(self.remote, **changes))
        self.config['dry_run'] = True
        self.transport.config['dry_run'] = True
        self.transport.apply_config(self.remote)
        self.assertTrue(self.service.dry_run)
    def test_rotation_persistence_failure_keeps_old_credential(self):
        self.transport.credentials['expires_at'] = timestamp(60)
        rotated = dict(credential='b'*64, expires_at=timestamp(30*86400), node_id=1, installation_id=INSTALLATION)
        with patch.object(self.transport.client, 'request', return_value=rotated), patch('outbound.atomic_save', side_effect=OSError('disk full')):
            with self.assertRaises(OSError):
                self.transport.renew_if_needed()
        self.assertEqual(self.transport.credentials['credential'], 'a'*64)
        self.assertEqual(json.loads(self.path.read_text())['credential'], 'a'*64)
    def test_expired_credential_renews_before_poll(self):
        self.credentials['expires_at'] = timestamp(-30)
        atomic_save(self.path, self.credentials)
        transport = OutboundTransport(self.service, self.config)
        rotated = dict(credential='b'*64, expires_at=timestamp(30*86400))
        with patch.object(transport.client, 'request', side_effect=[rotated, self.response, {}]) as request:
            transport.cycle()
        self.assertEqual(request.call_args_list[0].args[:2], ('POST', '/renew'))
        self.assertEqual(request.call_args_list[1].kwargs['token'], 'b'*64)
        self.assertEqual(json.loads(self.path.read_text())['credential'], 'b'*64)
    def test_no_redirect_handler(self):
        from port_opener import NoRedirects
        with self.assertRaises(ServiceError):
            NoRedirects().redirect_request(None, None, 302, '', {}, 'http://elsewhere/')
    def test_unauthorized_pauses_renewal_without_changing_snapshot(self):
        self.service.accept(self.response['desired_state'])
        original = copy.deepcopy(self.service.state['desired'])
        with patch.object(self.transport.client, 'request', side_effect=HTTPError('https://panel.example.com', 401, '', {}, None)):
            with self.assertRaises(HTTPError):
                self.transport.cycle()
        self.assertTrue(self.service.renewal_paused)
        with patch.object(self.service.router, 'get') as get:
            self.service.reconcile()
            get.assert_not_called()
        self.assertEqual(self.service.state['desired'], original)
        with patch.object(self.transport.client, 'request', side_effect=[self.response, {}]):
            self.transport.cycle()
        self.assertFalse(self.service.renewal_paused)
        self.assertEqual(self.service.state['desired'], original)
    def test_expiry_pauses_reconciliation_during_network_outage(self):
        self.service.credential_expires_at = time.time()-1
        self.service.reconcile()
        self.assertTrue(self.service.renewal_paused)
        self.assertIsNone(self.service.state['desired'])
    def test_panel_dryrun_controls_outbound_without_local_edit(self):
        self.assertTrue(self.service.dry_run)  # Initial, unpolled configuration is safe.
        with patch.object(self.transport.client, 'request', side_effect=[self.response, {}]):
            self.transport.cycle()
        self.assertFalse(self.service.dry_run)
        self.remote['dry_run'] = True
        with patch.object(self.transport.client, 'request', side_effect=[self.response, {}]):
            self.transport.cycle()
        self.assertTrue(self.service.dry_run)
    def test_unauthorized_pause_survives_service_restart(self):
        with patch.object(self.transport.client, 'request', side_effect=HTTPError('https://panel.example.com', 403, '', {}, None)):
            with self.assertRaises(HTTPError):
                self.transport.cycle()
        restarted = PortOpenerService(self.config, FakeRouter())
        OutboundTransport(restarted, self.config)
        self.assertTrue(restarted.renewal_paused)
    def test_panel_can_increase_lease_and_interval(self):
        self.transport.apply_config(dict(self.remote, lease_seconds=7200, reconcile_seconds=120))
        self.assertEqual(self.service.config['lease_seconds'], 7200)
        self.assertEqual(self.service.config['reconcile_seconds'], 120)
        self.transport.config['max_lease_seconds'] = 3600
        with self.assertRaises(ServiceError):
            self.transport.apply_config(dict(self.remote, lease_seconds=7200))


if __name__ == '__main__':
    unittest.main()
