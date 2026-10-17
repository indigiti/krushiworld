#!/usr/bin/env python3
"""Incremental market-price history for KrushiPulse (private SQLite, stdlib only).

Called after the existing daily source collectors, not from user HTTP requests.
Deduplication preserves source, date, variety, grade, market and unit.
Records are published only when backed by a real snapshot and a valid source date.
"""
from __future__ import annotations
import argparse
import json
import math
import os
import sqlite3
import sys
from datetime import date
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
STORAGE = ROOT / "storage"
SCHEMA = """
CREATE TABLE IF NOT EXISTS prices (
 source TEXT NOT NULL, market_id TEXT NOT NULL,
 commodity_key TEXT NOT NULL, commodity_name TEXT NOT NULL,
 variety TEXT NOT NULL, grade TEXT NOT NULL, unit TEXT NOT NULL,
 report_date TEXT NOT NULL,
 min_price REAL NOT NULL, max_price REAL NOT NULL, modal_price REAL NOT NULL,
 PRIMARY KEY(source,market_id,commodity_key,variety,grade,unit,report_date)
);
CREATE INDEX IF NOT EXISTS prices_lookup ON prices(commodity_key,report_date,market_id);
CREATE INDEX IF NOT EXISTS prices_market_date ON prices(market_id,report_date);
CREATE TABLE IF NOT EXISTS imports (source TEXT PRIMARY KEY, collected_at TEXT, record_count INTEGER NOT NULL);
"""

def read_json(path: Path) -> dict:
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        return data if isinstance(data, dict) else {}
    except (OSError, ValueError):
        return {}

def valid_date(value: object) -> bool:
    if not isinstance(value, str):
        return False
    try:
        parsed = date.fromisoformat(value)
        return parsed.isoformat() == value and parsed <= date.today()
    except ValueError:
        return False

def normalized(row: dict, source: str):
    if not isinstance(row, dict):
        return None
    market = str(row.get("market_id") or "").strip()
    name = str(row.get("name_en") or "").strip()
    key = str(row.get("commodity_key") or name.casefold()).strip()
    unit = str(row.get("unit") or "").strip().casefold()
    day = str(row.get("date") or "")
    if not market or not name or not key or not unit or not valid_date(day):
        return None
    if len(market) > 112 or len(key) > 160 or len(name) > 200 or len(unit) > 32:
        return None
    try:
        low, high, modal = (float(row[field]) for field in ("min", "max", "average"))
    except (ValueError, TypeError, KeyError):
        return None
    if not all(math.isfinite(x) and 0 <= x <= 100_000_000 for x in (low, high, modal)):
        return None
    if not low <= modal <= high:
        return None
    variety = str(row.get("variety") or "").strip()[:100]
    grade = str(row.get("grade") or "").strip()[:100]
    return source, market, key, name, variety, grade, unit, day, low, high, modal

def archive(pune: Path, national: Path, db_path: Path) -> dict:
    db_path.parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(db_path, timeout=15)
    try:
        conn.execute("PRAGMA busy_timeout=15000")
        conn.executescript(SCHEMA)
        counted: dict[str, int] = {}
        with conn:
            for source, path in (("Pune APMC", pune), ("AGMARKNET", national)):
                snapshot = read_json(path)
                rows = snapshot.get("records", [])
                if not isinstance(rows, list) or not rows:
                    continue  # No update means no invented empty history day.
                clean = [item for row in rows if (item := normalized(row, source)) is not None]
                if not clean:
                    continue
                conn.executemany("""
                INSERT INTO prices (
                    source,market_id,commodity_key,commodity_name,variety,grade,unit,
                    report_date,min_price,max_price,modal_price
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?)
                ON CONFLICT(source,market_id,commodity_key,variety,grade,unit,report_date)
                DO UPDATE SET min_price=excluded.min_price,max_price=excluded.max_price,
                              modal_price=excluded.modal_price,commodity_name=excluded.commodity_name
                """, clean)
                counted[source] = len(clean)
                updated = str(snapshot.get("meta", {}).get("updated_at") or snapshot.get("meta", {}).get("generated_at") or "")
                conn.execute("INSERT INTO imports(source,collected_at,record_count) VALUES (?,?,?) "
                             "ON CONFLICT(source) DO UPDATE SET collected_at=excluded.collected_at,record_count=excluded.record_count",
                             (source, updated[:35], len(clean)))
        total = conn.execute("SELECT count(*) FROM prices").fetchone()[0]
        return {"stored": total, "snapshot_records": counted}
    finally:
        conn.close()

def main() -> int:
    cli = argparse.ArgumentParser()
    cli.add_argument("--pune", type=Path, default=STORAGE / "current/all.json")
    cli.add_argument("--national", type=Path, default=STORAGE / "national/current.json")
    cli.add_argument("--db", type=Path, default=STORAGE / "history/rates.sqlite")
    args = cli.parse_args()
    try:
        result = archive(args.pune, args.national, args.db)
        print(f"KrushiPulse history: {result['stored']} verified price observations")
        return 0
    except (OSError, sqlite3.Error, ValueError) as exc:
        print(f"History archival unavailable [{type(exc).__name__}]; existing rates kept", file=sys.stderr)
        return 1

if __name__ == "__main__":
    sys.exit(main())
