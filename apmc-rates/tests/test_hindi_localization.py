"""Ensure Hindi covers every authored storefront/runtime message without broken fallbacks."""
import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

class HindiLocalizationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.data=json.loads((ROOT/'config/i18n.json').read_text(encoding='utf-8'))
        cls.home=(ROOT/'app/views/home.php').read_text(encoding='utf-8')
        cls.js=(ROOT/'public/build/krushipulse.js').read_text(encoding='utf-8')
        cls.near=(ROOT/'public/build/nearest-mandi.js').read_text(encoding='utf-8')

    def test_translation_completeness(self):
        self.assertEqual(self.data['supported'],['en','mr','hi'])
        self.assertIn('hi',self.data['labels'])
        self.assertIn('hi',self.data['markets']['Main Market Yard'])
        self.assertIn('hi',self.data['categories']['Vegetables'])
        pairs=re.findall(r"\\$ui\\(\\s*'((?:\\\\'|[^'])*)'\\s*,\\s*'((?:\\\\'|[^'])*)'\\s*\\)",self.home)
        runtime=[]
        for js in (self.js,self.near):
            runtime += re.findall(r"\\bt\\(\\s*'((?:\\\\'|[^'])*)'\\s*,\\s*'((?:\\\\'|[^'])*)'\\s*\\)",js)
        all_english={en for _,en in pairs+runtime}
        missing=all_english-set(self.data['ui_hi'])
        self.assertFalse(missing, 'Missing Hindi copy: '+str(sorted(missing)))
        self.assertGreaterEqual(len(all_english),145)

    def test_commodity_and_unit_translations(self):
        commodities=self.data['commodities']
        self.assertGreaterEqual(len(commodities),270)
        for key,info in commodities.items():
            self.assertTrue(info.get('hi'),key)
        for info in self.data['units'].values():
            self.assertTrue(info.get('hi'))
        for name,expected in [('Tomato','टमाटर'),('Onion','प्याज'),('Wheat','गेहूँ')]:
            self.assertEqual(commodities[name]['hi'],expected)

    def test_route_and_switcher(self):
        router=(ROOT/'app/Http/RouteResolver.php').read_text(encoding='utf-8')
        self.assertIn('(en|mr|hi)',router)
        self.assertIn('hreflang="hi-IN"',self.home)
        self.assertIn("canonical('hi')",self.home)
        self.assertIn('id="kpLanguage"',self.home)
        self.assertIn('lang="hi"',self.home)
        self.assertIn('window.APMC_ROUTE',self.home)
        self.assertIn('lang===\'hi\'',self.js)
        self.assertIn('states_hi',self.js)
        self.assertIn('districts_hi',self.js)
        self.assertIn('ui_hi',self.near)

    def test_build_sync(self):
        self.assertEqual(self.js,(ROOT/'resources/js/krushipulse.js').read_text(encoding='utf-8'))
        self.assertEqual(self.near,(ROOT/'resources/js/nearest-mandi.js').read_text(encoding='utf-8'))
        self.assertEqual(
            (ROOT/'public/build/admin.js').read_text(encoding='utf-8'),
            (ROOT/'resources/js/admin.js').read_text(encoding='utf-8')
        )
if __name__=='__main__':
    unittest.main()
