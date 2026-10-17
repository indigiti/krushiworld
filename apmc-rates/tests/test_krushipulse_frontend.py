"""Static regression checks for the new KrushiPulse storefront and browser preferences."""
import re
import unittest
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
class KrushiPulseFrontendTests(unittest.TestCase):
    def setUp(self):
        self.view=(ROOT/'app/views/home.php').read_text(encoding='utf-8')
        self.js=(ROOT/'public/build/krushipulse.js').read_text(encoding='utf-8')
        self.css=(ROOT/'public/build/krushipulse.css').read_text(encoding='utf-8')
    def test_source_date_and_no_fictional_prices(self):
        self.assertIn('Report date',self.view)
        self.assertIn("r.date||'—'",self.js)
        self.assertIn('No verified rates match',self.view)
        self.assertNotIn('₹2,425',self.view)
        self.assertNotIn('Play Store',self.view)
    def test_krushipulse_brand_and_assets(self):
        self.assertIn('KrushiPulse',self.view)
        self.assertNotIn('BhavLive',self.view)
        self.assertIn("apmc_asset('krushipulse.js')",self.view)
        self.assertIn("apmc_asset('krushipulse.css')",self.view)
        self.assertNotIn('cdn.jsdelivr.net',self.view)
    def test_unique_required_mounts(self):
        ids=re.findall(r'\bid="([^"]+)"',self.view)
        self.assertEqual(len(ids),len(set(ids)))
        for element in ('kpWatchlist','kpTheme','kpLanguage','kpPreferencesOpen','kpDrawer','kpPrefMarket','kpCropChoices','kpSearch','kpMarket','kpCategory','kpOnlySaved','kpTableRows','kpSavePrefs'):
            self.assertIn(element,ids)
    def test_nearest_mandi_contract(self):
        import json
        geo=json.loads((ROOT/'config/market_coordinates.json').read_text(encoding='utf-8'))
        self.assertEqual(len(geo),5)
        js=(ROOT/'public/build/nearest-mandi.js').read_text(encoding='utf-8')
        self.assertIn('getCurrentPosition',js)
        self.assertIn('distance(',js)
        self.assertIn('api=markets',js)
        self.assertNotIn('localStorage',js)
        self.assertNotIn('watchPosition',js)
        self.assertIn('id="kpLocate"',self.view)
        self.assertIn("apmc_asset('nearest-mandi.js')",self.view)

    def test_three_persistent_themes_and_saved_dashboard(self):
        for theme in ('harvest','midnight','saffron'):
            self.assertIn('data-theme="'+theme+'"',self.css)
            self.assertIn("'"+theme+"'",self.js)
        self.assertIn('localStorage.setItem',self.js)
        self.assertIn('krushipulse.preferences.v1',self.js)
        self.assertIn('prefs.crops',self.js)
        self.assertIn('prefs.market',self.js)
if __name__=='__main__':unittest.main()
