"""Read-only discovery contract: router replies, scoped URLs and UDP request/response flow."""
import importlib.util
from pathlib import Path
import socket
import unittest
from unittest.mock import patch

path = Path(__file__).resolve().parents[1]/'resources/helpers/find_portopener_router.py'
spec = importlib.util.spec_from_file_location('router_discovery', path)
discovery = importlib.util.module_from_spec(spec)
spec.loader.exec_module(discovery)
URL = 'http://192.168.178.1:49000/igddesc.xml'


def reply(url=URL, target=discovery.TARGETS[0]):
    return ('HTTP/1.1 200 OK\r\nST: '+target+'\r\nLOCATION: '+url+'\r\n\r\n').encode()


class DiscoveryTests(unittest.TestCase):
    def test_router_targets_and_header_case(self):
        for target in discovery.TARGETS:
            self.assertEqual(discovery.router_url(reply(target=target).replace(b'LOCATION', b'location'), '192.168.178.1'), URL)

    def test_ignores_other_devices_and_unsolicited_messages(self):
        self.assertIsNone(discovery.router_url(reply(target='urn:schemas-upnp-org:device:MediaServer:1'), '192.168.178.1'))
        self.assertIsNone(discovery.router_url(reply().replace(b'HTTP/1.1 200 OK', b'NOTIFY * HTTP/1.1'), '192.168.178.1'))

    def test_rejects_unsupported_or_unsafe_locations(self):
        for url in ('https://192.168.178.1/igd.xml', 'http://example.com/igd.xml', 'http://127.0.0.1/igd.xml',
                    'http://169.254.169.254/igd.xml', 'http://8.8.8.8/igd.xml', 'http://192.168.178.1',
                    'http://user:pass@192.168.178.1/igd.xml', 'http://192.168.178.1/igd.xml?',
                    'http://192.168.178.1/igd.xml#', 'http://192.168.178.1:0/igd.xml',
                    'http://192.168.178.1:99999/igd.xml', 'http://192.168.178.1/igd\x1b.xml',
                    'http://192.168.178.1\\igd.xml'):
            with self.subTest(url=url):
                self.assertIsNone(discovery.router_url(reply(url), '192.168.178.1'))

    def test_location_must_belong_to_reply_sender(self):
        self.assertIsNone(discovery.router_url(reply(), '192.168.178.2'))

    def test_ambiguous_headers_are_rejected(self):
        for extra in ('LOCATION: '+URL, 'ST: '+discovery.TARGETS[1]):
            self.assertIsNone(discovery.router_url(reply()+extra.encode()+b'\r\n', '192.168.178.1'))

    def test_discovery_sends_router_queries_and_deduplicates(self):
        class Connection:
            def __init__(self): self.sent, self.closed = [], False
            def bind(self, address): self.bound = address
            def setsockopt(self, *args): pass
            def sendto(self, packet, address): self.sent.append((packet, address))
            def recvfrom(self, size): return reply(), ('192.168.178.1', 1900)
            def close(self): self.closed = True
        connection = Connection()
        with patch.object(discovery.socket, 'socket', return_value=connection), \
             patch.object(discovery.select, 'select', return_value=([connection], [], [])):
            urls = discovery.discover(timeout=0.001, interface='192.168.178.50')
        self.assertEqual(urls, [URL])
        self.assertEqual(connection.bound, ('192.168.178.50', 0))
        self.assertEqual(len(connection.sent), len(discovery.TARGETS))
        self.assertTrue(all(packet.startswith(b'M-SEARCH * HTTP/1.1\r\n') and address == ('239.255.255.250', 1900)
                            for packet, address in connection.sent))
        self.assertTrue(connection.closed)

    def test_invalid_local_interface_is_rejected(self):
        for address in ('127.0.0.1', '8.8.8.8', 'invalid', '::1'):
            with self.assertRaises(ValueError): discovery.discover(interface=address)

    def test_igd2_url_is_preferred_independently_of_filename(self):
        class Connection:
            def bind(self, address): pass
            def setsockopt(self, *args): pass
            def sendto(self, *args): pass
            def close(self): pass
            def recvfrom(self, size):
                return packets.pop(0), ('192.168.178.1', 1900)
        packets = [reply(target='urn:schemas-upnp-org:device:InternetGatewayDevice:1'),
                   reply('http://192.168.178.1:49000/z.xml', 'urn:schemas-upnp-org:device:InternetGatewayDevice:2')]
        connection = Connection()
        with patch.object(discovery.socket, 'socket', return_value=connection), \
             patch.object(discovery.select, 'select', side_effect=lambda *args: ([connection], [], []) if packets else ([], [], [])):
            urls = discovery.discover(timeout=0.001, interface='192.168.178.50')
        self.assertEqual(urls, ['http://192.168.178.1:49000/z.xml', URL])


if __name__ == '__main__': unittest.main()
