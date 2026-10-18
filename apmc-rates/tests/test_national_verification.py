"""Verify AGMARKNET data before a completed refresh flag is published."""
import importlib.util
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("national_verify", ROOT/"collector/national.py")
national=importlib.util.module_from_spec(spec)
spec.loader.exec_module(national)

def row(date="15/10/2026", market="Lasalgaon", commodity="Onion"):
    return dict(state="Maharashtra", district="Nashik", market=market,
        commodity=commodity, variety="Red", grade="FAQ",
        arrival_date=date, min_price=1000, max_price=1500, modal_price=1200)

class NationalVerificationTests(unittest.TestCase):
    def test_valid_candidate_passes(self):
        snapshot,directory=national.build([row(),row(commodity="Tomato")],[])
        result=national.verify_candidate(snapshot,directory,{})
        self.assertEqual(result["records"],2)
        self.assertEqual(result["markets"],1)
        self.assertEqual(result["latest_report_date"],"2026-10-15")
    def test_rejects_wrong_meta_counts(self):
        snapshot,directory=national.build([row()],[])
        snapshot["meta"]["records"]=99
        with self.assertRaises(RuntimeError):
            national.verify_candidate(snapshot,directory,{})
    def test_rejects_invalid_modal_price(self):
        snapshot,directory=national.build([row()],[])
        snapshot["records"][0]["average"]=3000
        with self.assertRaises(RuntimeError):
            national.verify_candidate(snapshot,directory,{})
    def test_rejects_regressed_source_date(self):
        snapshot,directory=national.build([row()],[])
        before={"records":[{"date":"2026-10-17"}]}
        with self.assertRaises(RuntimeError):
            national.verify_candidate(snapshot,directory,before)
    def test_rejects_missing_market_reference(self):
        snapshot,directory=national.build([row()],[])
        snapshot["records"][0]["market_id"]="unknown"
        with self.assertRaises(RuntimeError):
            national.verify_candidate(snapshot,directory,{})
    def test_rejects_duplicate_candidate(self):
        snapshot,directory=national.build([row()],[])
        snapshot["records"].append(dict(snapshot["records"][0]))
        snapshot["meta"]["records"]=2
        with self.assertRaises(RuntimeError):
            national.verify_candidate(snapshot,directory,{})

if __name__=="__main__":
    unittest.main()
