"""Offline Phase 2/3 regression tests: exact-rate history, daily backfill, no invented trends."""
import json, sqlite3, tempfile, unittest
from datetime import date, timedelta
from pathlib import Path
import sys
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'collector'))
import history

def rate(day,market="main-market-yard",unit="kg",variety="Red",grade="FAQ",modal=80):
 return {"market_id":market,"commodity_key":"onion","name_en":"Onion","unit":unit,
         "date":day,"min":modal-10,"max":modal+10,"average":modal,"variety":variety,"grade":grade}

class HistoryTests(unittest.TestCase):
 def test_normalizer_preserves_report_date_and_exact_quality(self):
  day=(date.today()-timedelta(days=1)).isoformat()
  item=history.normalized(rate(day), "Pune APMC")
  self.assertEqual(item[2],"onion")
  self.assertEqual(item[4:7],("Red","FAQ","kg"))
  self.assertEqual(item[7],day)
  self.assertIsNone(history.normalized(rate("2100-01-01"),"Pune APMC"))
  self.assertIsNone(history.normalized(rate(day,modal=float('nan')),"Pune APMC"))
  self.assertIsNone(history.normalized({**rate(day),"min":100,"max":120},"Pune APMC"))
 def test_idempotent_source_quality_and_market_isolation(self):
  day=(date.today()-timedelta(days=3)).isoformat()
  with tempfile.TemporaryDirectory() as tmp:
   root=Path(tmp);p=root/'pune.json';n=root/'national.json';db=root/'rates.sqlite'
   rows=[rate(day),rate(day,market='manjari',modal=90),rate(day,unit='quintal',modal=95),
         rate(day,variety='White',modal=110),rate(day,grade='A',modal=120)]
   p.write_text(json.dumps({"meta":{"generated_at":"2026-01-01T12:00:00Z"},"records":rows}))
   n.write_text(json.dumps({"records":[rate(day,market="agmarknet-test",modal=150)]}))
   first=history.archive(p,n,db);second=history.archive(p,n,db)
   self.assertEqual(first['stored'],6);self.assertEqual(second['stored'],6)
   with sqlite3.connect(db) as connection:
    self.assertEqual(connection.execute("SELECT count(*) FROM prices").fetchone()[0],6)
    self.assertEqual(connection.execute("SELECT count(*) FROM prices WHERE unit='kg' AND variety='Red' AND grade='FAQ'").fetchone()[0],3)
 def test_existing_daily_archive_backfill(self):
  day=date.today()-timedelta(days=7)
  with tempfile.TemporaryDirectory() as tmp:
   root=Path(tmp);folder=root/'daily'/day.strftime('%Y')/day.strftime('%m')/day.strftime('%d')
   folder.mkdir(parents=True)
   (folder/'main-onion.json').write_text(json.dumps({'records':[rate(day.isoformat())]}))
   db=root/'history.sqlite'
   self.assertEqual(history.backfill_pune(30,db,root/'daily'),1)
   self.assertEqual(history.backfill_pune(30,db,root/'daily'),1)
   with sqlite3.connect(db) as connection:
    self.assertEqual(connection.execute("SELECT count(*) FROM prices").fetchone()[0],1)
 def test_history_never_stored_in_repository(self):
  readme=(ROOT/'README.md').read_text(encoding='utf-8')
  self.assertIn('KrushiPulse',readme)
  script=(ROOT/'bin/server-refresh.sh').read_text(encoding='utf-8')
  self.assertIn('collector/history.py',script)

if __name__=='__main__':unittest.main()
