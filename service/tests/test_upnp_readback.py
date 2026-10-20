import unittest
import tempfile
from pathlib import Path
from unittest.mock import patch
from port_opener import UPnPRouter, ServiceError, PortOpenerService


class ReadbackTests(unittest.TestCase):
    def setUp(self):
        self.router = UPnPRouter('http://192.168.178.1:49000/igddesc.xml')
        self.entry = dict(NewRemoteHost=None, NewExternalPort='2456', NewProtocol='UDP',
            NewInternalClient='192.168.178.50', NewInternalPort='2456',
            NewEnabled='1', NewPortMappingDescription='owned', NewLeaseDuration='3500')

    def lookup(self, entries, end='upnp_713'):
        def call(action, fields):
            if action == 'GetSpecificPortMappingEntry':
                raise ServiceError('upnp_714', 'NoSuchEntry', 503)
            self.assertEqual(action, 'GetGenericPortMappingEntry')
            index = fields['NewPortMappingIndex']
            if index >= len(entries):
                raise ServiceError(end, 'Enumeration ended', 503)
            return entries[index]
        return patch.object(self.router, 'call', side_effect=call)

    def test_existing_rule_survives_false_specific_absence(self):
        unrelated = dict(self.entry, NewExternalPort='2455', NewProtocol='TCP')
        with self.lookup([unrelated, self.entry]):
            result = self.router.get(2456, 'UDP')
        self.assertEqual(result, dict(address='192.168.178.50', port=2456, description='owned', enabled=True))

    def test_true_absence_requires_complete_enumeration(self):
        with self.lookup([dict(self.entry, NewProtocol='TCP')]):
            self.assertIsNone(self.router.get(2456, 'UDP'))

    def test_failed_enumeration_is_not_absence(self):
        with self.lookup([], 'upnp_606'), self.assertRaises(ServiceError) as caught:
            self.router.get(2456, 'UDP')
        self.assertEqual(caught.exception.code, 'upnp_606')

    def test_bounded_enumeration_is_not_absence(self):
        with self.lookup([dict(self.entry, NewExternalPort='2455')] * 256), self.assertRaises(ServiceError) as caught:
            self.router.get(2456, 'UDP')
        self.assertEqual(caught.exception.code, 'router_mapping_limit')

    def test_restricted_host_cannot_be_adopted(self):
        with self.lookup([dict(self.entry, NewRemoteHost='192.168.178.20')]), self.assertRaises(ServiceError) as caught:
            self.router.get(2456, 'UDP')
        self.assertEqual(caught.exception.code, 'router_conflict')

    def test_invalid_evidence_cannot_verify_mapping(self):
        with self.lookup([dict(self.entry, NewInternalClient='invalid')]), self.assertRaises(ServiceError) as caught:
            self.router.get(2456, 'UDP')
        self.assertEqual(caught.exception.code, 'invalid_router_mapping')

    def test_working_specific_query_does_not_enumerate(self):
        with patch.object(self.router, 'call', return_value=self.entry) as call:
            self.assertTrue(self.router.get(2456, 'UDP')['enabled'])
        call.assert_called_once()

    def test_enumeration_deadline_is_not_absence(self):
        with self.lookup([]), patch('port_opener.time.monotonic', side_effect=[0, 11]), self.assertRaises(ServiceError) as caught:
            self.router.get(2456, 'UDP')
        self.assertEqual(caught.exception.code, 'router_verification_timeout')

    def test_existing_applied_owned_rule_is_verified_without_another_write(self):
        with tempfile.TemporaryDirectory() as directory:
            installation = '11111111-1111-4111-8111-111111111111'
            server = '22222222-2222-4222-8222-222222222222'
            service = PortOpenerService(dict(installation_id=installation, node_id=1,
                internal_address='192.168.178.50', min_port=1024, max_port=65535,
                state_file=str(Path(directory)/'state.json'), dry_run=False), self.router)
            row = dict(mapping_id=server+':1:udp', server_uuid=server, allocation_id=1,
                internal_address='192.168.178.50', port=2456, protocol='UDP')
            service.state['desired'] = service.validate(dict(schema_version=1, installation_id=installation,
                node_id=1, generation=1, mappings=[row]))
            service.state['owned'][row['mapping_id']] = dict(row, address=row['internal_address'], description='owned', phase='applied')
            with self.lookup([self.entry]):
                service.reconcile()
            self.assertEqual(service.status()['mappings'][0]['state'], 'mapped')
            self.assertEqual(service.state['owned'][row['mapping_id']]['phase'], 'confirmed')
