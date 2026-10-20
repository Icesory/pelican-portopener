import io
import json
import os
import ssl
import tempfile
import unittest
from contextlib import redirect_stdout
from datetime import datetime, timezone
from pathlib import Path
from unittest.mock import patch
from urllib.error import HTTPError

from diagnostics import EventLog, clean_event, failure
from port_opener import ServiceError


class DiagnosticsTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.state = Path(self.tmp.name)/'state.json'
        self.wall, self.elapsed = 1800000000.0, 100.0
        self.log = EventLog(self.state, clock=lambda:self.wall, monotonic=lambda:self.elapsed, stdout=False)
        self.secret = 'TEST_SECRET_'+os.urandom(24).hex()

    def until(self, seconds):
        return datetime.fromtimestamp(self.wall+seconds, timezone.utc).isoformat(timespec='seconds')

    def test_never_serializes_arbitrary_fields_or_values(self):
        self.log.log('mapping_state', port=2456, protocol='UDP', state='error', error_code='upnp_606',
                     token=self.secret, request={'Authorization':self.secret}, message=self.secret, url=self.secret)
        base = dict(event='mapping_state', level='info', timestamp=self.until(0), port=2456, protocol='UDP')
        for key in ('event','level','timestamp','port','protocol','error_code','state','phase','outcome','family','router_service','duration_ms','generation'):
            row = clean_event(dict(base, **{key:self.secret}))
            self.assertNotIn(self.secret, json.dumps(row))
        self.assertNotIn(self.secret, self.log.path.read_text())
        self.assertNotIn(self.secret, json.dumps(self.log.report()))
        self.assertEqual(self.log.events[0]['error_code'], 'upnp_606')

    def test_exception_text_is_never_read(self):
        class UnsafeError(Exception):
            def __str__(self): raise AssertionError('Never render exception text')
        for error in (UnsafeError(), ServiceError(self.secret,self.secret), HTTPError(self.secret,403,self.secret,{},io.BytesIO(self.secret.encode())), ssl.SSLError(self.secret)):
            self.assertNotIn(self.secret,json.dumps(failure(error)))
        self.assertEqual(failure(HTTPError('x',403,'x',{},None)), {'error_code':'authentication_failed','http_status':403})

    def test_debug_expiry_without_panel_contact(self):
        self.log.set_debug(self.until(20))
        self.log.log('mapping_check', level='debug', port=2456)
        self.elapsed += 21
        self.log.log('mapping_check', level='debug', port=2457)
        report = self.log.report()
        self.assertFalse(report['debug_active'])
        self.assertEqual([row.get('port') for row in report['events'] if row['event']=='mapping_check'], [2456])
        self.assertEqual(report['events'][-1]['event'], 'debug_expired')

    def test_same_request_does_not_extend_deadline_after_clock_reversal(self):
        until = self.until(30)
        self.log.set_debug(until)
        self.wall -= 3600
        self.elapsed += 31
        self.log.set_debug(until)
        self.assertFalse(self.log.report()['debug_active'])

    def test_debug_is_capped_at_fifteen_minutes(self):
        self.log.set_debug(self.until(36000))
        self.assertLessEqual(datetime.fromisoformat(self.log.debug_until).timestamp()-self.wall,900)
        self.elapsed += 901
        self.assertFalse(self.log.report()['debug_active'])

    def test_stop_debug_and_restart_cannot_restore_it(self):
        self.log.set_debug(self.until(120))
        self.log.set_debug(None)
        loaded=EventLog(self.state, clock=lambda:self.wall, monotonic=lambda:self.elapsed, stdout=False)
        self.assertFalse(loaded.report()['debug_active'])

    def test_restart_preserves_events_but_not_expired_debug(self):
        self.log.set_debug(self.until(120))
        self.wall += 121
        loaded=EventLog(self.state, clock=lambda:self.wall, monotonic=lambda:self.elapsed, stdout=False)
        self.assertFalse(loaded.report()['debug_active'])
        self.assertTrue(loaded.events)

    def test_ring_and_disk_are_bounded(self):
        self.log.set_debug(self.until(900))
        for port in range(1,401): self.log.log('mapping_check', level='debug', port=port)
        self.assertEqual(len(self.log.report()['events']),128)
        self.assertLessEqual(self.log.path.stat().st_size,65536)
        self.assertEqual(self.log.events[-1]['port'],400)
        self.assertEqual(self.log.path.stat().st_mode & 0o777,0o600)

    def test_normal_events_deduplicate_and_recovery_is_recorded(self):
        for _ in range(5): self.log.log('mapping_state', port=2456, state='error', deduplicate=True)
        self.log.log('mapping_state', port=2456, state='mapped', deduplicate=True)
        self.assertEqual(len(self.log.events),2)

    def test_log_io_failure_does_not_affect_ownership(self):
        self.state.write_text('{"owned":"unchanged"}')
        with patch('diagnostics.os.replace',side_effect=OSError(self.secret)):
            self.log.log('service_started')
        self.assertFalse(self.log.report()['storage_ok'])
        self.assertEqual(self.state.read_text(),'{"owned":"unchanged"}')

    def test_existing_file_is_sanitized_before_export(self):
        row=dict(event='service_started',level='info',timestamp=self.until(0),token=self.secret)
        self.log.path.write_text(json.dumps(dict(events=[row],credential=self.secret)))
        loaded=EventLog(self.state,clock=lambda:self.wall,monotonic=lambda:self.elapsed,stdout=False)
        self.assertNotIn(self.secret,json.dumps(loaded.report()))

    def test_stdout_is_also_sanitized(self):
        self.log.stdout=True
        with redirect_stdout(io.StringIO()) as output:
            self.log.log('panel_poll', level='error', error_code=self.secret, message=self.secret)
        self.assertNotIn(self.secret,output.getvalue())
        self.assertEqual(json.loads(output.getvalue())['error_code'],'internal_error')

    def test_closed_console_does_not_break_logging_or_forwarding(self):
        self.log.stdout=True
        with patch('builtins.print',side_effect=ValueError('closed output')):
            self.log.log('service_started')
        self.assertEqual(self.log.report()['events'][-1]['event'],'service_started')


if __name__=='__main__': unittest.main()
