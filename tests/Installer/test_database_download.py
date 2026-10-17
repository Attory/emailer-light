"""Native database download integrity, network interruption and resume behavior."""
import hashlib
import importlib.util
import io
import os
from pathlib import Path
import tempfile
import sys
import types
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'installer'))
from provision import SetupError


class Response(io.BytesIO):
    def __init__(self, body, status=200, headers=None):
        super().__init__(body)
        self.status = status
        self.headers = {} if headers is None else headers


@unittest.skipUnless(os.geteuid() == 0, 'Cache ownership is checked by the root CI gate')
class DatabaseArchiveDownload(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.cache = Path(self.temporary.name) / 'cache'
        path = Path(__file__).resolve().parents[2] / 'installer/isolated_database.py'
        spec = importlib.util.spec_from_file_location('emailer_database_download_fixture', path)
        self.module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.module)
        self.body = b'Authentic synthetic vendor bytes, no executable content.\n'
        self.module.SHA256 = hashlib.sha256(self.body).hexdigest()
        self.module.SIZE = len(self.body)
        self.module.Path = lambda path: self.cache if str(path) == '/var/cache/emailer-database' else Path(path)
        self.profile = self.module.DatabaseProfile(types.SimpleNamespace(error=SetupError))

    def tearDown(self):
        self.temporary.cleanup()

    def partial(self, body):
        self.cache.mkdir(mode=0o700)
        path = self.cache / (self.module.ARCHIVE + '.part')
        path.write_bytes(body)
        path.chmod(0o600)
        return path

    def test_complete_https_response_is_verified_before_use(self):
        with patch.object(self.module.urllib.request, 'urlopen', return_value=Response(self.body, headers={'Content-Length': str(len(self.body))})):
            archive = self.profile.archive()
        self.assertEqual(self.body, archive.read_bytes())
        self.assertEqual(0o600, archive.stat().st_mode & 0o777)

    def test_early_eof_retains_partial_bytes_then_resumes_the_exact_range(self):
        prefix = self.body[:12]
        with patch.object(self.module.urllib.request, 'urlopen', return_value=Response(prefix)):
            with self.assertRaisesRegex(SetupError, 'ended early'):
                self.profile.archive()
        partial = self.cache / (self.module.ARCHIVE + '.part')
        self.assertEqual(prefix, partial.read_bytes())
        headers = {'Content-Range': f'bytes {len(prefix)}-{len(self.body)-1}/{len(self.body)}', 'Content-Length': str(len(self.body)-len(prefix))}
        def resume(request, **kwargs):
            self.assertEqual(f'bytes={len(prefix)}-', request.get_header('Range'))
            return Response(self.body[len(prefix):], 206, headers)
        with patch.object(self.module.urllib.request, 'urlopen', side_effect=resume):
            self.assertEqual(self.body, self.profile.archive().read_bytes())
        self.assertFalse(partial.exists())

    def test_crash_after_complete_download_needs_no_range_at_eof(self):
        partial = self.partial(self.body)
        with patch.object(self.module.urllib.request, 'urlopen', side_effect=AssertionError('A complete download must be verified locally.')):
            self.assertEqual(self.body, self.profile.archive().read_bytes())
        self.assertFalse(partial.exists())

    def test_ignored_range_restarts_from_zero_without_appending_duplicate_bytes(self):
        self.partial(self.body[:12])
        with patch.object(self.module.urllib.request, 'urlopen', return_value=Response(self.body, 200)):
            self.assertEqual(self.body, self.profile.archive().read_bytes())

    def test_wrong_range_and_wrong_size_preserve_the_original_partial(self):
        partial = self.partial(self.body[:12])
        for response in (Response(self.body[12:], 206, {'Content-Range': 'bytes 0-9/10'}), Response(self.body, 200, {'Content-Length': '999'})):
            with patch.object(self.module.urllib.request, 'urlopen', return_value=response), self.assertRaises(SetupError):
                self.profile.archive()
            self.assertEqual(self.body[:12], partial.read_bytes())

    def test_full_size_tampered_download_is_quarantined_and_never_returned(self):
        self.partial(b'X' * len(self.body))
        with self.assertRaisesRegex(SetupError, 'pinned SHA-256'):
            self.profile.archive()
        self.assertFalse((self.cache / self.module.ARCHIVE).exists())
        self.assertEqual(1, len(list(self.cache.glob('rejected-*'))))
