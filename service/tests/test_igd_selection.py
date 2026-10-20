import io
import unittest
from unittest.mock import patch
from urllib.error import HTTPError
from urllib.request import Request
import xml.etree.ElementTree as ET

from port_opener import ServiceError, UPnPRouter
from ipv6 import IPv6Router

ORIGIN = 'http://192.168.178.1:49000'
V2, V1 = UPnPRouter.SERVICES[:2]


def document(*services):
    return ('<root><device><serviceList>'+''.join(
        '<service><serviceType>'+kind+'</serviceType><controlURL>'+url+'</controlURL></service>'
        for kind, url in services)+'</serviceList></device></root>').encode()


def external():
    return io.BytesIO(b'<response><NewExternalIPAddress>203.0.113.1</NewExternalIPAddress></response>')


class IgdSelectionTests(unittest.TestCase):
    def transport(self, descriptions, failure=None):
        calls = []
        def open_request(request, timeout):
            if isinstance(request, Request):
                method = list(list(ET.fromstring(request.data))[0])[0].tag.rsplit('}', 1)[-1]
                calls.append((request.full_url, method))
                if failure:
                    response = failure(request.full_url, method)
                    if response is not None: return response
                return external()
            calls.append((request, 'description'))
            value = descriptions.get(request)
            if value is None: raise HTTPError(request, 404, 'missing', {}, None)
            if isinstance(value, Exception): raise value
            return io.BytesIO(value)
        return calls, patch('port_opener.router_open', side_effect=open_request)

    def test_prefers_v2_service_in_generic_description(self):
        router = UPnPRouter(ORIGIN+'/router.xml')
        calls, transport = self.transport({router.description_url: document((V1, '/v1'), (V2, '/v2'))})
        with transport: router.discover()
        self.assertEqual((router.service, router.control_url), (V2, ORIGIN+'/v2'))
        self.assertEqual(calls[-1], (ORIGIN+'/v2', 'GetExternalIPAddress'))

    def test_fritz_old_url_prefers_advertised_v2_path(self):
        router = UPnPRouter(ORIGIN+'/igddesc.xml')
        calls, transport = self.transport({ORIGIN+'/igd2desc.xml': document((V2, '/v2'))})
        with transport: router.discover()
        self.assertEqual(router.active_description_url, ORIGIN+'/igd2desc.xml')
        self.assertEqual(router.description_url, ORIGIN+'/igddesc.xml')  # Preserve pinned policy/ownership identity.

    def test_missing_v2_description_falls_back_to_v1(self):
        router = UPnPRouter(ORIGIN+'/igd2desc.xml')
        calls, transport = self.transport({ORIGIN+'/igddesc.xml': document((V1, '/v1'))})
        with transport: router.discover()
        self.assertEqual(router.service, V1)
        self.assertEqual(router.active_description_url, ORIGIN+'/igddesc.xml')

    def test_v1_only_generic_router_still_works(self):
        router = UPnPRouter(ORIGIN+'/custom/device.xml')
        calls, transport = self.transport({router.description_url: document((V1, '/v1'))})
        with transport: router.discover()
        self.assertEqual(router.service, V1)
        self.assertEqual([url for url, action in calls if action == 'description'], [router.description_url])

    def test_unsupported_v2_probe_falls_back_before_writes(self):
        router = UPnPRouter(ORIGIN+'/router.xml')
        def failure(url, action):
            if url.endswith('/v2'): return io.BytesIO(b'<fault><errorCode>401</errorCode></fault>')
        calls, transport = self.transport({router.description_url: document((V1, '/v1'), (V2, '/v2'))}, failure)
        with transport: router.discover()
        self.assertEqual(router.service, V1)
        self.assertTrue(all(action in ('description', 'GetExternalIPAddress') for _, action in calls))

    def test_denied_v2_probe_does_not_downgrade(self):
        router = UPnPRouter(ORIGIN+'/router.xml')
        calls, transport = self.transport({router.description_url: document((V2, '/v2'), (V1, '/v1'))},
            lambda url, action: io.BytesIO(b'<fault><errorCode>606</errorCode></fault>'))
        with transport, self.assertRaises(ServiceError) as caught: router.discover()
        self.assertEqual(caught.exception.code, 'upnp_606')
        self.assertFalse(any(url.endswith('/v1') for url, _ in calls))

    def test_denied_description_does_not_downgrade(self):
        router = UPnPRouter(ORIGIN+'/igd2desc.xml')
        calls, transport = self.transport({router.description_url: HTTPError(router.description_url, 403, 'denied', {}, None),
                                           ORIGIN+'/igddesc.xml': document((V1, '/v1'))})
        with transport, self.assertRaises(HTTPError): router.discover()
        self.assertEqual(len(calls), 1)

    def test_unsafe_control_url_is_not_hidden_by_fallback(self):
        for control in ('http://192.168.178.2/control', 'http://user@192.168.178.1:49000/control', '/control?secret=1'):
            router = UPnPRouter(ORIGIN+'/router.xml')
            calls, transport = self.transport({router.description_url: document((V2, control), (V1, '/v1'))})
            with transport, self.assertRaises(ServiceError): router.discover()
            self.assertEqual(len(calls), 1)

    def test_read_failure_can_retry_once_on_v1(self):
        router = UPnPRouter(ORIGIN+'/router.xml')
        def failure(url, action):
            if url.endswith('/v2') and action == 'GetSpecificPortMappingEntry': raise OSError('unreachable')
        calls, transport = self.transport({router.description_url: document((V2, '/v2'), (V1, '/v1'))}, failure)
        with transport: router.call('GetSpecificPortMappingEntry', {})
        self.assertEqual(router.service, V1)
        self.assertEqual([url for url, action in calls if action == 'GetSpecificPortMappingEntry'], [ORIGIN+'/v2', ORIGIN+'/v1'])

    def test_write_timeout_never_retries_on_another_interface(self):
        router = UPnPRouter(ORIGIN+'/router.xml')
        def failure(url, action):
            if action == 'AddPortMapping': raise OSError('write outcome unknown')
        calls, transport = self.transport({router.description_url: document((V2, '/v2'), (V1, '/v1'))}, failure)
        with transport, self.assertRaises(OSError): router.call('AddPortMapping', {})
        self.assertEqual([call for call in calls if call[1] == 'AddPortMapping'], [(ORIGIN+'/v2', 'AddPortMapping')])
        self.assertEqual(router.service, V2)

    def test_unsupported_write_does_not_retry(self):
        router = UPnPRouter(ORIGIN+'/router.xml')
        def failure(url, action):
            if action == 'AddPortMapping': return io.BytesIO(b'<fault><errorCode>401</errorCode></fault>')
        calls, transport = self.transport({router.description_url: document((V2, '/v2'), (V1, '/v1'))}, failure)
        with transport, self.assertRaises(ServiceError): router.call('AddPortMapping', {})
        self.assertFalse(any(url.endswith('/v1') for url, _ in calls))

    def test_mapping_absence_does_not_select_another_interface(self):
        router = UPnPRouter(ORIGIN+'/router.xml')
        def failure(url, action):
            if action == 'GetSpecificPortMappingEntry': return io.BytesIO(b'<fault><errorCode>714</errorCode></fault>')
        calls, transport = self.transport({router.description_url: document((V2, '/v2'), (V1, '/v1'))}, failure)
        with transport, self.assertRaises(ServiceError) as caught: router.call('GetSpecificPortMappingEntry', {})
        self.assertEqual(caught.exception.code, 'upnp_714')
        self.assertEqual(router.service, V2)

    def test_read_retries_are_bounded(self):
        router = UPnPRouter(ORIGIN+'/router.xml')
        def failure(url, action):
            if action == 'GetSpecificPortMappingEntry': raise OSError('all interfaces unavailable')
        calls, transport = self.transport({router.description_url: document((V2, '/v2'), (V1, '/v1'))}, failure)
        with transport, self.assertRaises(OSError): router.call('GetSpecificPortMappingEntry', {})
        self.assertEqual(len([call for call in calls if call[1] == 'GetSpecificPortMappingEntry']), 2)

    def test_ipv6_firewall_is_not_replaced_with_ipv4_service(self):
        router = IPv6Router(ORIGIN+'/igd2desc.xml')
        router.control_url, router.service = ORIGIN+'/ipv6', 'urn:schemas-upnp-org:service:WANIPv6FirewallControl:1'
        with patch.object(router, '_request', side_effect=OSError('unavailable')) as request, patch.object(router, 'discover') as discover:
            with self.assertRaises(OSError): router.call('GetFirewallStatus', {})
        request.assert_called_once()
        discover.assert_not_called()


if __name__ == '__main__': unittest.main()
