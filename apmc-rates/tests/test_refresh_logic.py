#!/usr/bin/env python3
from __future__ import annotations

import argparse
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("apmc_refresh", ROOT / "collector" / "refresh.py")
refresh = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(refresh)


class RefreshLogicTests(unittest.TestCase):
    def setUp(self):
        self.market = refresh.CONFIG["markets"][0]

    def test_empty_latest_date_falls_back_to_populated_previous_date(self):
        listing = (
            '<a href="/bajarbhav/view-daily-bajarbhav/main-fruit/2026-10-01">1</a>'
            '<a href="/bajarbhav/view-daily-bajarbhav/main-fruit/2026-09-30">2</a>'
        )
        empty = (
            "<table><tr><th>Commodity</th><th>Unit</th><th>Arrival</th>"
            "<th>Min</th><th>Max</th><th>Average</th></tr></table>"
        )
        populated = (
            "<table><tr><th>Commodity</th><th>Unit</th><th>Arrival</th>"
            "<th>Min</th><th>Max</th><th>Average</th></tr>"
            "<tr><td>Apple</td><td>10 kg</td><td>100</td><td>800</td>"
            "<td>1200</td><td>1000</td></tr></table>"
        )
        original_fetch = refresh.fetch

        def fake_fetch(url: str) -> str:
            if "daily-bajarbhav-dates" in url:
                return listing
            if url.endswith("/2026-10-01"):
                return empty
            if url.endswith("/2026-09-30"):
                return populated
            return empty

        try:
            refresh.fetch = fake_fetch
            result = refresh.find_latest_populated(self.market, "2026-09-17")
            self.assertIsNotNone(result)
            self.assertEqual(result[0], "2026-09-30")
            self.assertEqual(len(result[1]), 1)
        finally:
            refresh.fetch = original_fetch

    def test_no_new_data_preserves_last_good_snapshot_metadata(self):
        original_dirs = self._capture_dirs()
        original_find = refresh.find_latest_populated
        try:
            with tempfile.TemporaryDirectory() as td:
                self._use_temp_storage(Path(td))
                snapshot = {"date": "2026-09-17", "source_hash": "abc", "records": [{"dummy": 1}]}
                refresh.atomic_json(refresh.market_snapshot_path(self.market["slug"]), snapshot)
                refresh.find_latest_populated = lambda market, previous: None
                manifest = {
                    "markets": {
                        self.market["slug"]: {
                            "status": "error",
                            "last_checked_at": "2026-10-01T08:00:00+05:30",
                        }
                    }
                }
                self.assertFalse(refresh.process_market(self.market, manifest))
                state = manifest["markets"][self.market["slug"]]
                self.assertEqual(state["status"], "no_new_data")
                self.assertEqual(state["date"], "2026-09-17")
                self.assertEqual(state["hash"], "abc")
                self.assertEqual(state["records"], 1)
        finally:
            refresh.find_latest_populated = original_find
            self._restore_dirs(original_dirs)

    def test_scheduled_invocation_writes_heartbeat_without_source_poll_when_not_due(self):
        original_dirs = self._capture_dirs()
        try:
            with tempfile.TemporaryDirectory() as td:
                self._use_temp_storage(Path(td))
                refresh.atomic_json(refresh.SYSTEM / "manifest.json", {"markets": {}, "last_checked_at": refresh.now_iso()})
                args = argparse.Namespace(
                    check=True,
                    force=False,
                    retry_errors=False,
                    rebuild=False,
                    backfill_days=0,
                    trigger="server-cron",
                    scheduled=True,
                )
                self.assertEqual(refresh.run(args), 0)
                manifest = json.loads((refresh.SYSTEM / "manifest.json").read_text(encoding="utf-8"))
                self.assertTrue(manifest.get("last_server_heartbeat_at"))
                self.assertEqual(manifest.get("last_server_status"), "healthy")
        finally:
            self._restore_dirs(original_dirs)

    @staticmethod
    def _capture_dirs():
        return (
            refresh.STORAGE,
            refresh.SYSTEM,
            refresh.MARKETS_DIR,
            refresh.DAILY_DIR,
            refresh.COMMODITIES_DIR,
            refresh.EXPORTS_DIR,
        )

    @staticmethod
    def _restore_dirs(values):
        (
            refresh.STORAGE,
            refresh.SYSTEM,
            refresh.MARKETS_DIR,
            refresh.DAILY_DIR,
            refresh.COMMODITIES_DIR,
            refresh.EXPORTS_DIR,
        ) = values

    @staticmethod
    def _use_temp_storage(root: Path):
        refresh.STORAGE = root / "storage"
        refresh.SYSTEM = refresh.STORAGE / "system"
        refresh.MARKETS_DIR = refresh.STORAGE / "markets"
        refresh.DAILY_DIR = refresh.STORAGE / "daily"
        refresh.COMMODITIES_DIR = refresh.STORAGE / "commodities"
        refresh.EXPORTS_DIR = refresh.STORAGE / "exports"
        for directory in [
            refresh.SYSTEM,
            refresh.MARKETS_DIR,
            refresh.DAILY_DIR,
            refresh.COMMODITIES_DIR,
            refresh.EXPORTS_DIR,
            refresh.STORAGE / "current",
        ]:
            directory.mkdir(parents=True, exist_ok=True)


if __name__ == "__main__":
    unittest.main()
