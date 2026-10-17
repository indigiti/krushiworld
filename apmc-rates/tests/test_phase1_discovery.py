"""Phase 1 contracts: geo provenance, PIN matching, freshness, and AGMARKNET ingestion."""
import csv
import importlib
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/"collector"))
import import_market_geocodes as geo
import national


class PhaseOneTests(unittest.TestCase):
    def test_curated_pune_postal_codes_are_source_documented(self):
        data=json.loads((ROOT/'config/postal_market_references.json').read_text(encoding='utf-8'))
        self.assertEqual(data['markets']['main-market-yard']['pincode'],'411037')
        self.assertEqual(data['markets']['pimpri']['pincode'],'411017')
        for item in data['markets'].values():
            self.assertTrue(item['source'].startswith('https://apmcpune.in'))
        national_codes=json.loads((ROOT/'config/national_market_coordinates.json').read_text(encoding='utf-8'))
        self.assertEqual(national_codes['markets'],{})

    def test_coordinates_require_source_and_real_mandi_id(self):
        known={'agmarknet-laslgaon-123456789abc'}
        valid=dict(market_id='agmarknet-laslgaon-123456789abc',latitude='20.11',
                   longitude='74.23',precision='locality',
                   source_url='https://example.gov.in/maps',pincode='422306')
        key,value=geo.validate_row(valid,known)
        self.assertEqual(key,valid['market_id'])
        self.assertEqual(value['pincode'],'422306')
        for mutation in (
            {'market_id':'agmarknet-invented-id'},
            {'market_id':'main-market-yard'},
            {'latitude':'nan'},
            {'longitude':'220'},
            {'source_url':'http://example.gov.in/maps'},
            {'source_url':'https://user:secret@example.gov.in/maps'},
            {'precision':'pin_centroid'},
            {'pincode':'1234'},
        ):
            with self.subTest(mutation=mutation),self.assertRaises(ValueError):
                geo.validate_row({**valid,**mutation},known)

    def test_dry_run_keeps_old_geodata_untouched(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp)
            inputfile=root/'input.csv'
            with inputfile.open('w',newline='',encoding='utf-8') as stream:
                writer=csv.DictWriter(stream,fieldnames=sorted(geo.HEADER|{'pincode'}))
                writer.writeheader()
                writer.writerow({
                    'market_id':'agmarknet-laslgaon-123456789abc',
                    'latitude':'20.11','longitude':'74.23',
                    'precision':'locality','source_url':'https://example.gov.in/maps',
                    'pincode':'422306',
                })
            national.write_atomic(root/'directory.json',{'markets':[{'market_id':'agmarknet-laslgaon-123456789abc'}]})
            with patch.object(geo,'DEST',root),patch.object(geo,'OUTPUT',root/'geocodes.json'):
                self.assertEqual(geo.run(inputfile,dry_run=True),0)
                self.assertFalse((root/'geocodes.json').exists())
                self.assertEqual(geo.run(inputfile,dry_run=False),0)
                self.assertEqual(len(json.loads((root/'geocodes.json').read_text())['markets']),1)

    def test_directory_reports_freshness_without_claiming_live(self):
        php=(ROOT/'app/NationalMarketRepository.php').read_text(encoding='utf-8')
        service=(ROOT/'app/Application/MarketDirectoryService.php').read_text(encoding='utf-8')
        for marker in ('latest_report_date','import_age_hours','stale','cached_unconfigured','has_snapshot','last_attempt_at'):
            self.assertIn(marker,php)
        for marker in ('with_coordinates','without_coordinates','with_postal_reference','without_current_report','national_market_coordinates.json'):
            self.assertIn(marker,service)
        js=(ROOT/'public/build/krushipulse.js').read_text(encoding='utf-8')
        self.assertIn('kpGeoCoverage',js)
        self.assertIn('latest_report_date',js)
        self.assertIn('stale',js)
        for token in ('kpPincode','kpPincodeSearch','kpMandiQuickSearch','kpMandiQuickButton','kpGeoCoverage'):
            self.assertIn('id="'+token+'"',(ROOT/'app/views/home.php').read_text(encoding='utf-8'))

    def test_browser_geolocation_is_optional_and_not_uploaded(self):
        javascript=(ROOT/'public/build/nearest-mandi.js').read_text(encoding='utf-8')
        self.assertIn('navigator.geolocation.getCurrentPosition',javascript)
        self.assertNotIn('watchPosition',javascript)
        self.assertNotIn('localStorage',javascript)
        self.assertIn('market_id', (ROOT/'app/Application/MarketDirectoryService.php').read_text(encoding='utf-8'))
        self.assertIn('No source-verified mandi postal reference',javascript)
        self.assertIn('PIN centroid',javascript)

    def test_agmarknet_retains_last_good_and_redacts_exception_message(self):
        source=(ROOT/'collector/national.py').read_text(encoding='utf-8')
        self.assertIn('unexpected_record_drop',source)
        self.assertIn('health.json',source)
        self.assertIn('last_success_at',source)
        self.assertNotIn('str(exc)[:180]',source)
        self.assertIn('type(exc).__name__',source)


if __name__=='__main__':
    unittest.main()
