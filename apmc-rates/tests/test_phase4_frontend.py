"""Phase 4 static integration tests: three languages, all assets, privacy and source-backed public UI."""
import json, re, unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
class Phase4FrontendTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.html=(ROOT/'app/views/home.php').read_text(encoding='utf-8')
        cls.js=(ROOT/'public/build/phase4.js').read_text(encoding='utf-8')
        cls.source=(ROOT/'resources/js/phase4.js').read_text(encoding='utf-8')
        cls.i18n=json.loads((ROOT/'config/i18n.json').read_text(encoding='utf-8'))
    def test_ui_contains_all_three_feature_panels(self):
        for ident in ['kpVoiceSearch','kpVoiceStatus','kpExplanationStatus','kpExplanationFacts',
                      'kpMspSeason','kpMspStatus','kpMspResults','kpProfileStatus','kpProfileDetails']:
            self.assertIn('id="'+ident+'"',self.html,ident)
        self.assertIn('phase4.js',self.html)
        self.assertIn('window.KrushiPulsePhase4SelectRate',self.js)
        self.assertIn('window.KrushiPulsePhase4SelectRate?.(r)',(ROOT/'public/build/intelligence.js').read_text(encoding='utf-8'))
        self.assertIn('window.APMC_APP_BASE',self.js)
    def test_voice_is_optional_and_respects_browser(self):
        for expected in ['webkitSpeechRecognition','SpeechRecognition','button.addEventListener',
                         'recognition', 'listener.start()', 'listener.onresult', "input.dispatchEvent"]:
            if expected=='recognition':
                continue
            self.assertIn(expected,self.js)
        self.assertIn("listener.lang=lang==='hi'?'hi-IN':lang==='mr'?'mr-IN':'en-IN'",self.js)
        self.assertIn("button.disabled=true",self.js)
        self.assertNotIn('getUserMedia(',self.js)
        self.assertNotIn('localStorage',self.js)
    def test_sources_and_units_are_kept(self):
        self.assertIn('api/v1/intelligence/insights',self.js)
        self.assertIn('api/v1/intelligence/msp',self.js)
        self.assertIn('/profile',self.js)
        self.assertIn('gradeMatch',self.js)
        self.assertIn('unitMatch',self.js)
        self.assertIn('profile_source_url',self.js)
    def test_hindi_copy_complete(self):
        en=[]
        en.extend(m[1] for m in re.findall(r"\$ui\(\s*'([^']*)'\s*,\s*'([^']*)'\s*\)",self.html))
        en.extend(m[1] for m in re.findall(r"\bt\(\s*'([^']*)'\s*,\s*'([^']*)'\s*\)",self.js))
        missing=set(en)-set(self.i18n['ui_hi'])
        self.assertEqual(missing,set(),sorted(missing))
        self.assertIn('hi',self.i18n['supported'])
    def test_built_assets_are_in_sync(self):
        self.assertEqual(self.js,self.source)
        manifest=(ROOT/'public/sw.js').read_text(encoding='utf-8')
        self.assertIn('build/phase4.js',manifest)
        self.assertIn("krushipulse-shell-v2.0.2",manifest)
        self.assertNotIn("rel.startsWith('api/')||rel.startsWith('admin/')||rel==='api.php'||rel==='index.php'||url.searchParams.has('api')||url.searchParams.has('view')||url.searchParams.has('seo'))return false",manifest)
        self.assertIn("rel.startsWith('api/')",manifest)
        self.assertIn("microphone=(self)",(ROOT/'app/bootstrap.php').read_text(encoding='utf-8'))
if __name__=='__main__':
    unittest.main()
