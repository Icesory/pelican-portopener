"""Actual local TLS handshakes; no panel/router/network outside loopback."""
import json
import shutil
import ssl
import subprocess
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.error import URLError
from outbound import HTTPSClient
from port_opener import ServiceError


@unittest.skipUnless(shutil.which('openssl'), 'openssl needed to issue temporary test certificate')
class TLSIntegrationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory()
        root = Path(cls.tmp.name)
        cls.cert = root/'cert.pem'
        key = root/'key.pem'
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
            '-keyout', str(key), '-out', str(cls.cert), '-days', '1', '-subj', '/CN=localhost',
            '-addext', 'subjectAltName=IP:127.0.0.1'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass
            def do_GET(self):
                if self.path.endswith('/desired-state'):
                    body = json.dumps({'verified': True}).encode()
                    self.send_response(200)
                    self.send_header('Content-Length', str(len(body)))
                    self.end_headers()
                    self.wfile.write(body)
                else:
                    self.send_response(302)
                    self.send_header('Location', 'http://127.0.0.1/insecure')
                    self.end_headers()
        cls.server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(cls.cert, key)
        cls.server.socket = context.wrap_socket(cls.server.socket, server_side=True)
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()
        cls.origin = 'https://127.0.0.1:'+str(cls.server.server_port)
    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.tmp.cleanup()
    def test_explicit_trusted_ca_real_handshake(self):
        result = HTTPSClient(self.origin, str(self.cert)).request('GET', '/desired-state')
        self.assertTrue(result['verified'])
    def test_default_trust_rejects_self_signed(self):
        with self.assertRaises(URLError) as caught:
            HTTPSClient(self.origin).request('GET', '/desired-state')
        self.assertIsInstance(caught.exception.reason, ssl.SSLCertVerificationError)
    def test_verified_https_cannot_redirect_to_http(self):
        with self.assertRaises(ServiceError):
            HTTPSClient(self.origin, str(self.cert)).request('GET', '/report')
