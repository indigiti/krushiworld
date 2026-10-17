#!/usr/bin/env python3
"""Server-only, all-India AGMARKNET ingestion from data.gov.in.

Never overwrites last-good snapshots on partial pagination, API errors or empty responses.
Requires APMC_DATA_GOV_IN_API_KEY; no browser-accessible API key.
"""
from __future__ import annotations

import argparse
import fcntl
import hashlib
import json
import math
import os
import re
import sys
import tempfile
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DEST = ROOT / "storage" / "national"
RESOURCE = "9ef84268-d588-465a-a308-a864a43d0070"
ENDPOINT = "https://api.data.gov.in/resource/" + RESOURCE
PAGE_SIZE = 1000
MAX_ROWS = 100000


def load(path: Path, fallback):
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return fallback


def write_atomic(path: Path, value) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix=".national-", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            json.dump(value, stream, ensure_ascii=False, separators=(",", ":"))
            stream.write("\n")
        os.replace(tmp, path)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)


def slug(label: str) -> str:
    label = re.sub(r"[^a-z0-9]+", "-", label.casefold()).strip("-")
    return label[:55].strip("-") or "market"


def market_id(state: str, district: str, market: str) -> str:
    raw = "|".join([state.casefold().strip(), district.casefold().strip(), market.casefold().strip()])
    return "agmarknet-" + slug(market) + "-" + hashlib.sha1(raw.encode("utf-8")).hexdigest()[:12]


def iso_date(raw: str) -> str | None:
    for pattern in ("%d/%m/%Y", "%Y-%m-%d"):
        try:
            candidate = datetime.strptime(raw.strip(), pattern).date()
            if candidate > datetime.now(timezone.utc).date():
                return None
            return candidate.isoformat()
        except ValueError:
            pass
    return None


def number(raw) -> float | None:
    try:
        result = float(str(raw).replace(",", "").strip())
        return result if math.isfinite(result) and 0 <= result < 1_000_000_000 else None
    except (ValueError, TypeError):
        return None


def transform(row: dict) -> dict | None:
    if not isinstance(row, dict):
        return None
    fields = {str(k).casefold(): v for k, v in row.items()}
    state = str(fields.get("state") or "").strip()
    district = str(fields.get("district") or "").strip()
    market = str(fields.get("market") or "").strip()
    commodity = str(fields.get("commodity") or "").strip()
    date = iso_date(str(fields.get("arrival_date") or ""))
    minimum = number(fields.get("min_price"))
    maximum = number(fields.get("max_price"))
    modal = number(fields.get("modal_price"))
    if not all((state, district, market, commodity, date)) or None in (minimum, maximum, modal):
        return None
    if minimum > maximum or modal < minimum or modal > maximum:
        return None
    commodity_key = hashlib.sha1(commodity.casefold().strip().encode("utf-8")).hexdigest()[:14]
    return {
        "market_id": market_id(state, district, market),
        "market_name": market, "market_name_en": market,
        "name_en": commodity, "name_mr": commodity,
        "commodity_key": commodity_key,
        "category": "Agricultural produce",
        "state": state, "district": district,
        "variety": str(fields.get("variety") or "").strip(),
        "grade": str(fields.get("grade") or "").strip(),
        "date": date, "min": minimum, "max": maximum,
        "average": modal, "price_type": "modal",
        "unit": "quintal", "source": "AGMARKNET / data.gov.in",
        "arrival": None, "change_pct": None,
    }


def retrieve(api_key: str) -> list[dict]:
    rows: list[dict] = []
    expected: int | None = None
    offset = 0
    while True:
        url = ENDPOINT + "?" + urllib.parse.urlencode({
            "api-key": api_key, "format": "json",
            "limit": PAGE_SIZE, "offset": offset
        })
        request = urllib.request.Request(url, headers={"User-Agent": "KrushiPulse-AGMARKNET/1.0", "Accept": "application/json"})
        # Never log URLs, as they contain the API key.
        with urllib.request.urlopen(request, timeout=25) as response:
            if response.status != 200:
                raise RuntimeError("AGMARKNET HTTP request failed")
            data = json.load(response)
        if not isinstance(data, dict) or not isinstance(data.get("records"), list):
            raise ValueError("AGMARKNET returned an invalid data structure")
        batch = data["records"]
        if expected is None:
            expected = int(data.get("total", len(batch)))
            if expected > MAX_ROWS:
                raise RuntimeError("AGMARKNET returned more records than the configured safety limit")
        rows.extend(batch)
        offset += len(batch)
        if offset >= expected:
            break
        if not batch or len(batch) < PAGE_SIZE:
            raise RuntimeError("AGMARKNET pagination incomplete; retaining previous snapshot")
    if not rows:
        raise RuntimeError("AGMARKNET returned no records; retaining last-good snapshot")
    return rows


def build(rows: list[dict], previous: list[dict]) -> tuple[dict, dict]:
    items: dict[tuple, dict] = {}
    for row in rows:
        record = transform(row)
        if record:
            key = tuple(record.get(k) for k in ("market_id", "commodity_key", "variety", "grade", "date"))
            items[key] = record
    if not items:
        raise RuntimeError("AGMARKNET had no valid price rows")
    records = list(items.values())
    market_map: dict[str, dict] = {}
    for r in records:
        key = r["market_id"]
        m = market_map.setdefault(key, {
            "market_id": key, "slug": key, "name": r["market_name_en"],
            "name_en": r["market_name_en"], "name_mr": r["market_name_en"],
            "state": r["state"], "district": r["district"],
            "source": "AGMARKNET", "records": 0, "date": None,
        })
        m["records"] += 1
        if m["date"] is None or r["date"] > m["date"]:
            m["date"] = r["date"]

    # Keep old discovered market names in the directory, but do not claim
    # they have current price rows if absent from the latest reported snapshot.
    directory = {m["market_id"]: {**m, "records": 0} for m in previous if isinstance(m, dict) and m.get("market_id")}
    directory.update(market_map)
    return ({
        "meta": {"source": "AGMARKNET / data.gov.in", "resource": RESOURCE,
                 "updated_at": datetime.now(timezone.utc).isoformat(),
                 "markets": len(market_map), "records": len(records)},
        "records": records,
    }, {
        "meta": {"source": "AGMARKNET / data.gov.in", "updated_at": datetime.now(timezone.utc).isoformat()},
        "markets": sorted(directory.values(), key=lambda m: (m.get("state", ""), m.get("district", ""), m.get("name_en", ""))),
    })


def run(force: bool = False) -> int:
    key = os.getenv("APMC_DATA_GOV_IN_API_KEY", "").strip()
    if not key:
        print("National AGMARKNET feed not configured (APMC_DATA_GOV_IN_API_KEY missing).")
        return 0
    DEST.mkdir(parents=True, exist_ok=True)
    with (DEST / ".national-refresh.lock").open("a+") as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return 0
        snapshot_file = DEST / "current.json"
        interval_hours = max(1, min(24, int(os.getenv("APMC_NATIONAL_INTERVAL_HOURS", "6"))))
        if not force and snapshot_file.exists():
            from time import time
            if time() - snapshot_file.stat().st_mtime < interval_hours * 3600:
                return 0
        try:
            raw_rows = retrieve(key)
            older = load(DEST / "directory.json", {}).get("markets", [])
            snapshot, directory = build(raw_rows, older)
            write_atomic(snapshot_file, snapshot)
            write_atomic(DEST / "directory.json", directory)
            print(f"AGMARKNET: {snapshot['meta']['records']} records, {len(directory['markets'])} known markets")
            return 0
        except Exception as exc:
            # Do not include API key or request URL in errors.
            print("AGMARKNET refresh failed (last verified rates kept): " + str(exc)[:180], file=sys.stderr)
            return 1


if __name__ == "__main__":
    cli = argparse.ArgumentParser()
    cli.add_argument("--force", action="store_true")
    args = cli.parse_args()
    sys.exit(run(force=args.force))
