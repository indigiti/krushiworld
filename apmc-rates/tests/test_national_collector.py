"""Offline contract checks for the opt-in nationwide official mandi importer."""
import importlib.util
import json
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("national_collector",ROOT/"collector/national.py")
national=importlib.util.module_from_spec(spec)
spec.loader.exec_module(national)


def sample(**overrides):
    row={
      "state":"Maharashtra","district":"Nashik","market":"Lasalgaon",
      "commodity":"Onion","variety":"Red","grade":"FAQ",
      "arrival_date":"15/10/2026",
      "min_price":1000,"max_price":1600,"modal_price":1350,
    }
    row.update(overrides)
    return row


class NationalCollectorTests(unittest.TestCase):
    def test_modal_price_and_actual_date_not_invented(self):
        row=national.transform(sample())
        self.assertEqual(row["average"],1350.0)
        self.assertEqual(row["price_type"],"modal")
        self.assertEqual(row["date"],"2026-10-15")
        self.assertEqual(row["unit"],"quintal")
        self.assertEqual(row["state"],"Maharashtra")

    def test_reject_invalid_reports(self):
        self.assertIsNone(national.transform(sample(modal_price="NaN")))
        self.assertIsNone(national.transform(sample(modal_price=2000)))
        self.assertIsNone(national.transform(sample(arrival_date="2099-01-01")))
        self.assertIsNone(national.transform(sample(market="")))

    def test_market_ids_keep_distinct_districts(self):
        a=national.market_id("Maharashtra","Nashik","Main Market")
        b=national.market_id("Maharashtra","Pune","Main Market")
        self.assertNotEqual(a,b)
        self.assertEqual(a,national.market_id("Maharashtra","Nashik","Main Market"))

    def test_build_preserves_directory_not_old_prices(self):
        old=[{"market_id":"agmarknet-old","name_en":"Former mandi","state":"Gujarat","records":91}]
        data,directory=national.build([sample(),sample(commodity="Tomato")],old)
        self.assertEqual(data["meta"]["records"],2)
        self.assertEqual(data["meta"]["markets"],1)
        self.assertEqual(len(directory["markets"]),2)
        former=next(m for m in directory["markets"] if m["market_id"]=="agmarknet-old")
        self.assertEqual(former["records"],0)

    def test_empty_dataset_is_not_published(self):
        with self.assertRaises(RuntimeError):
            national.build([{"state":"Maharashtra"}],[])

    def test_partial_pagination_fails_closed(self):
        class FakeReply:
            status=200
            def __enter__(self):return self
            def __exit__(self,*args):return False
            def read(self,*args):return json.dumps({"total":3,"records":[sample()]}).encode()
        with patch.object(national.urllib.request,"urlopen",return_value=FakeReply()):
            with self.assertRaises(RuntimeError):
                national.retrieve("dummy-test-key")

    def test_frontend_has_directory_filters(self):
        view=(ROOT/"app/views/home.php").read_text(encoding="utf-8")
        js=(ROOT/"public/build/krushipulse.js").read_text(encoding="utf-8")
        for ident in ("kpState","kpDistrict","kpMarket","kpDirectoryStatus"):
            self.assertIn('id="'+ident+'"',view)
        for word in ("loadMarketDirectory","handleNational","api=national","api=markets"):
            self.assertIn(word,js)
        self.assertNotIn("APMC_DATA_GOV_IN_API_KEY",view)


if __name__=="__main__":
    unittest.main()
