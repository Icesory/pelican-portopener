"""Keep the reported Hub rejection out of the full source checkout."""
import unittest

from check_hub_submission import check_repository, scan_sources


class HubSubmissionTests(unittest.TestCase):
    def test_reported_native_calls_are_rejected_with_paths_and_lines(self):
        findings = scan_sources([('packaging/dev.php', b"<?php\nfile_put_contents('a', 'b');\nchmod('a', 0600);\nfwrite(STDERR, 'failed');")])
        self.assertEqual(findings, [
            dict(path='packaging/dev.php', line=2, function='file_put_contents'),
            dict(path='packaging/dev.php', line=3, function='chmod'),
            dict(path='packaging/dev.php', line=4, function='fwrite'),
        ])

    def test_namespaced_and_mixed_case_native_calls_are_rejected(self):
        findings = scan_sources([('test.php', b"<?php \\FiLe_PuT_CoNtEnTs('a', 'b');")])
        self.assertEqual([row['function'] for row in findings], ['file_put_contents'])

    def test_native_process_calls_are_rejected(self):
        findings = scan_sources([('test.php', b"<?php shell_exec('example'); proc_open('example', [], $pipes);")])
        self.assertEqual([row['function'] for row in findings], ['shell_exec', 'proc_open'])

    def test_comments_strings_and_non_php_files_are_not_calls(self):
        self.assertEqual(scan_sources([
            ('test.php', b"<?php // chmod('a', 0600);\n/* fwrite($x, $y); */ echo 'file_put_contents(a, b)';"),
            ('guide.md', b"file_put_contents('a', 'b');"),
        ]), [])

    def test_readonly_access_and_console_output_are_allowed(self):
        self.assertEqual(scan_sources([('test.php', b"<?php $data = file_get_contents('manifest.json'); echo $data; exit(1);")]), [])

    def test_full_reviewed_source_including_development_php_is_clean(self):
        self.assertEqual(check_repository(), [])


if __name__ == '__main__':
    unittest.main()
