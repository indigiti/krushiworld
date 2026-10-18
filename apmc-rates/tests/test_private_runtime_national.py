"""Regression for server-private AGMARKNET API-key configuration."""
import importlib.util
import json
import os
from pathlib import Path
from tempfile import TemporaryDirectory
from unittest import TestCase
from unittest.mock import patch
spec = importlib.util.spec_from_file_location('national_private_settings', Path(__file__).resolve().parents[1] / 'collector/national.py')
national = importlib.util.module_from_spec(spec)
spec.loader.exec_module(national)
class NationalPrivateSettingsTests(TestCase):
    def test_private_file_and_process_override(self):
        with TemporaryDirectory() as td:
            root = Path(td)
            p = root / 'storage' / 'config' / 'runtime.json'
            p.parent.mkdir(parents=True)
            p.write_text(json.dumps({'APMC_DATA_GOV_IN_API_KEY':'p'*40}), encoding='utf-8')
            p.chmod(0o600)
            with patch.object(national, 'ROOT', root):
                with patch.dict(os.environ, {}, clear=True):
                    self.assertEqual(national.configured_api_key(), 'p'*40)
                with patch.dict(os.environ, {'APMC_DATA_GOV_IN_API_KEY':'e'*40}):
                    self.assertEqual(national.configured_api_key(), 'e'*40)
                p.chmod(0o640)
                with patch.dict(os.environ, {}, clear=True):
                    with self.assertRaises(RuntimeError):
                        national.configured_api_key()
    def test_absent_config_is_nonfatal(self):
        with TemporaryDirectory() as td, patch.object(national, 'ROOT', Path(td)):
            with patch.dict(os.environ, {}, clear=True):
                self.assertEqual(national.configured_api_key(), '')
