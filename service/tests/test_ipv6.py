import ipaddress
import io
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from ipv6 import discover_address, listener_present, IPv6Manager, IPv6Router
from port_opener import PortOpenerService, FakeRouter, ServiceError

ADDRESS='2001:4860:ffff:1::1234'

class DiscoveryTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.proc=Path(self.tmp.name)
        (self.proc/'ipv6_route').write_text('')
    def interfaces(self, rows):
        (self.proc/'if_inet6').write_text('\n'.join(f'{ipaddress.IPv6Address(a).packed.hex()} 02 40 00 {flags} {device}' for a,flags,device in rows))
    def test_global_address_excludes_temporary_deprecated_and_ula(self):
        self.interfaces([(ADDRESS,'00','eth0'),('2001:4860:ffff:1::2','01','eth0'),('2001:4860:ffff:1::3','20','eth0'),('fd00::1','00','eth0')])
        self.assertEqual(discover_address(proc=self.proc),dict(interface='eth0',address=ADDRESS))
    def test_multiple_candidates_need_explicit_interface(self):
        self.interfaces([(ADDRESS,'00','eth0'),('2001:4860:ffff:1::2','00','eth1')])
        with self.assertRaises(ServiceError):discover_address(proc=self.proc)
        self.assertEqual(discover_address('eth0',self.proc)['address'],ADDRESS)
    def test_no_eligible_address_is_blocked(self):
        self.interfaces([('fd00::1','00','eth0'),(ADDRESS,'40','eth0')])
        with self.assertRaises(ServiceError):discover_address(proc=self.proc)
    def test_interface_cannot_be_a_path(self):
        with self.assertRaises(ServiceError):discover_address('../../etc',self.proc)
    def test_listener_requires_ipv6_protocol_and_listen_state(self):
        (self.proc/'tcp6').write_text('header\n0: '+'0'*32+':D431 '+'0'*32+':0000 0A\n')
        (self.proc/'udp6').write_text('header\n')
        self.assertTrue(listener_present(ADDRESS,54321,'TCP',self.proc))
        self.assertFalse(listener_present(ADDRESS,54321,'UDP',self.proc))
    def test_default_gateway_is_scoped_to_the_selected_interface(self):
        gateway=ipaddress.IPv6Address('fe80::1').packed.hex()
        (self.proc/'ipv6_route').write_text('0'*32+' 00 '+'0'*32+' 00 '+gateway+' 00000001 00000000 00000000 00000003 eth0\n')
        router=IPv6Router('http://192.168.178.1:49000/igddesc.xml')
        router.bind_target(dict(address=ADDRESS,interface='eth0'),self.proc)
        self.assertEqual(router.gateway,'fe80::1%eth0')
        with self.assertRaises(ServiceError):router.bind_target(dict(address=ADDRESS,interface='eth1'),self.proc)
    def test_verification_denial_stays_unverified(self):
        router=IPv6Router('http://192.168.178.1:49000/igddesc.xml')
        with patch.object(router,'call',side_effect=ServiceError('upnp_403','denied',503)):
            self.assertFalse(router.verify(3))
    def test_mismatched_ipv6_gateway_identity_is_rejected(self):
        router=IPv6Router('http://192.168.178.1:49000/igddesc.xml')
        router.target=dict(address=ADDRESS,interface='eth0')
        xml=lambda name:('<root><UDN>'+name+'</UDN></root>').encode()
        with patch('ipv6.router_open',return_value=io.BytesIO(xml('configured-router'))), patch.object(router,'local_request',return_value=(200,xml('different-router'))):
            with self.assertRaises(ServiceError) as caught:router.discover()
        self.assertEqual(caught.exception.code,'ipv6_router_mismatch')

class Firewall:
    def __init__(self):self.calls=[];self.counter=0
    def capability(self):pass
    def open_tuple(self,address,port,protocol,lease):
        self.calls.append((address,port,protocol,lease));self.counter+=1;return self.counter
    def verify(self,identity):pass

class LifecycleTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.service=PortOpenerService(dict(installation_id='11111111-1111-4111-8111-111111111111',node_id=1,
            internal_address='192.168.178.50',min_port=1024,max_port=65535,state_file=str(Path(self.tmp.name)/'state.json'),
            dry_run=False,backend='upnp',transport='outbound',router_description_url='http://192.168.178.1:49000/igddesc.xml'),FakeRouter())
        self.row=dict(mapping_id='22222222-2222-4222-8222-222222222222:1:udp',server_uuid='22222222-2222-4222-8222-222222222222',allocation_id=1,internal_address='192.168.178.50',port=54321,protocol='UDP')
        self.service.state['desired']=dict(mappings=[self.row])
        self.service.state['ipv6_policy']=dict(enabled=True,interface=None,mapping_ids=[self.row['mapping_id']])
        self.target=dict(address=ADDRESS,interface='eth0')
        self.router=Firewall()
        self.manager=IPv6Manager(self.service,discover=lambda interface:self.target,listener=lambda *args:True,router_factory=lambda url:self.router)
    def test_approved_mapping_uses_exact_tuple_and_short_lease(self):
        self.manager.reconcile()
        self.assertEqual(self.router.calls,[(ADDRESS,54321,'UDP',120)])
        self.assertEqual(self.manager.report['mappings'][0]['state'],'mapped')
    def test_disabled_policy_performs_no_router_write(self):
        self.service.state['ipv6_policy']['enabled']=False
        self.manager.reconcile();self.assertEqual(self.router.calls,[])
    def test_legacy_transport_cannot_reuse_old_ipv6_approval(self):
        self.service.config['transport']='legacy'
        self.manager.reconcile();self.assertEqual(self.router.calls,[])
        self.assertFalse(self.manager.report['enabled'])
    def test_missing_ipv6_listener_does_not_open(self):
        self.manager.listener=lambda *args:False
        self.manager.reconcile();self.assertEqual(self.router.calls,[])
        self.assertEqual(self.manager.report['mappings'][0]['error_code'],'ipv6_listener_missing')
    def test_dry_run_does_not_open(self):
        self.service.dry_run=True
        self.manager.reconcile();self.assertEqual(self.router.calls,[])
    def test_authentication_pause_does_not_renew(self):
        self.service.renewal_paused=True
        self.manager.reconcile();self.assertEqual(self.router.calls,[])
    def test_address_rotation_stops_old_tuple_renewal(self):
        self.manager.reconcile()
        self.target=dict(address='2001:4860:ffff:2::1234',interface='eth0')
        self.manager.reconcile()
        self.assertEqual(self.router.calls[-1][0],self.target['address'])
        self.assertTrue(any(r['state']=='removing' and r['address']==ADDRESS for r in self.manager.report['mappings']))
    def test_removal_expires_without_mutating_opaque_ids(self):
        with patch('ipv6.time.time',return_value=100):self.manager.reconcile()
        self.service.state['ipv6_policy']['mapping_ids']=[]
        with patch('ipv6.time.time',return_value=110):self.manager.reconcile()
        self.assertEqual(self.manager.report['mappings'][0]['state'],'removing')
        with patch('ipv6.time.time',return_value=226):self.manager.reconcile()
        self.assertEqual(self.service.state['ipv6_owned'],{})
        self.assertEqual(len(self.router.calls),1)
    def test_intent_save_failure_prevents_write(self):
        self.service.save=lambda:(_ for _ in ()).throw(OSError('disk full'))
        with self.assertRaises(OSError):self.manager.reconcile()
        self.assertEqual(self.router.calls,[])
    def test_denied_verification_is_not_reported_as_mapped(self):
        self.router.verify=lambda identity:False
        self.manager.reconcile()
        self.assertEqual(self.manager.report['mappings'][0]['state'],'pending')
        self.assertEqual(self.manager.report['mappings'][0]['error_code'],'ipv6_verification_denied')
    def test_unsupported_ipv6_isolated_from_ipv4_state(self):
        self.service.state['owned']={'ipv4-record':{'description':'keep-me'}}
        self.router.capability=lambda:(_ for _ in ()).throw(ServiceError('ipv6_unsupported','unsupported',503))
        self.manager.reconcile()
        self.assertEqual(self.manager.report['error_code'],'ipv6_unsupported')
        self.assertEqual(self.service.state['owned']['ipv4-record']['description'],'keep-me')
