#!/usr/bin/env python3
from __future__ import annotations

import argparse
import csv
import fcntl
import hashlib
import html
import json
import os
import re
import sys
import time
import traceback
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta
from html.parser import HTMLParser
from pathlib import Path
from zoneinfo import ZoneInfo

ROOT = Path(__file__).resolve().parents[1]
STORAGE = ROOT / "storage"
SYSTEM = STORAGE / "system"
MARKETS_DIR = STORAGE / "markets"
DAILY_DIR = STORAGE / "daily"
COMMODITIES_DIR = STORAGE / "commodities"
EXPORTS_DIR = STORAGE / "exports"
CONFIG = json.loads((Path(__file__).with_name("config.json")).read_text(encoding="utf-8"))
COMMODITY_MAP = json.loads((Path(__file__).with_name("commodity_map.json")).read_text(encoding="utf-8"))
TZ = ZoneInfo("Asia/Kolkata")

for d in [SYSTEM, MARKETS_DIR, DAILY_DIR, COMMODITIES_DIR, EXPORTS_DIR, STORAGE / "current"]:
    d.mkdir(parents=True, exist_ok=True)


def now_iso() -> str:
    return datetime.now(TZ).isoformat(timespec="seconds")


def today_str() -> str:
    return datetime.now(TZ).date().isoformat()


def read_json(path: Path, default):
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return default


def atomic_json(path: Path, data) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
    os.replace(tmp, path)


def append_log(event: str, **context) -> None:
    path = SYSTEM / "refresh.log"
    line = json.dumps({"time": now_iso(), "event": event, **context}, ensure_ascii=False)
    try:
        with path.open("a", encoding="utf-8") as fh:
            fh.write(line + "\n")
        lines = path.read_text(encoding="utf-8").splitlines()
        if len(lines) > 800:
            path.write_text("\n".join(lines[-600:]) + "\n", encoding="utf-8")
    except Exception:
        pass


class TableParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.tables = []
        self.current_table = None
        self.current_row = None
        self.current_cell = None

    def handle_starttag(self, tag, attrs):
        tag = tag.lower()
        if tag == "table":
            self.current_table = []
        elif tag == "tr" and self.current_table is not None:
            self.current_row = []
        elif tag in ("td", "th") and self.current_row is not None:
            self.current_cell = []
        elif tag == "br" and self.current_cell is not None:
            self.current_cell.append(" ")

    def handle_data(self, data):
        if self.current_cell is not None:
            self.current_cell.append(data)

    def handle_endtag(self, tag):
        tag = tag.lower()
        if tag in ("td", "th") and self.current_cell is not None:
            value = clean_text("".join(self.current_cell))
            self.current_row.append(value)
            self.current_cell = None
        elif tag == "tr" and self.current_row is not None:
            if self.current_table is not None and any(self.current_row):
                self.current_table.append(self.current_row)
            self.current_row = None
        elif tag == "table" and self.current_table is not None:
            self.tables.append(self.current_table)
            self.current_table = None


class LinkParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.hrefs = []

    def handle_starttag(self, tag, attrs):
        if tag.lower() != "a":
            return
        for key, value in attrs:
            if key.lower() == "href" and value:
                self.hrefs.append(value)


def clean_text(value: str) -> str:
    return re.sub(r"\s+", " ", html.unescape(value or "")).strip()


def parse_number(value: str):
    text = clean_text(value).replace(",", "")
    found = re.search(r"-?\d+(?:\.\d+)?", text)
    if not found:
        return None
    number = float(found.group(0))
    return int(number) if number.is_integer() else number


def resolve_commodity_name(source_name: str) -> tuple[str, str, str]:
    """Return canonical Marathi, English and emoji names without losing unknown source labels."""
    source_name = clean_text(source_name)
    direct = COMMODITY_MAP.get(source_name)
    if isinstance(direct, dict):
        return direct.get("mr") or source_name, direct.get("en") or source_name, direct.get("emoji", "◦")

    folded = source_name.casefold()
    for mr_name, entry in COMMODITY_MAP.items():
        if not isinstance(entry, dict):
            continue
        en_name = clean_text(str(entry.get("en") or ""))
        aliases = [clean_text(str(a)) for a in entry.get("aliases", []) if a]
        if (en_name and en_name.casefold() == folded) or any(a.casefold() == folded for a in aliases):
            return entry.get("mr") or mr_name, en_name or source_name, entry.get("emoji", "◦")

    return source_name, source_name, "◦"


def fetch(url: str) -> str:
    headers = {
        "User-Agent": CONFIG.get("user_agent", "APMC-Pune-Data-Engine/1.0"),
        "Accept": "text/html,application/xhtml+xml",
        "Accept-Language": "mr,en;q=0.9",
        "Cache-Control": "no-cache",
    }
    request = urllib.request.Request(url, headers=headers)
    retries = int(CONFIG.get("retries", 2))
    timeout = int(CONFIG.get("timeout_seconds", 15))
    last_error = None
    for attempt in range(retries + 1):
        try:
            with urllib.request.urlopen(request, timeout=timeout) as response:
                raw = response.read()
                encoding = response.headers.get_content_charset() or "utf-8"
                try:
                    return raw.decode(encoding, errors="replace")
                except LookupError:
                    return raw.decode("utf-8", errors="replace")
        except Exception as exc:
            last_error = exc
            if attempt < retries:
                time.sleep(1.25 * (attempt + 1))
    raise RuntimeError(f"Fetch failed: {url}: {last_error}")


def discover_candidate_dates(market: dict, previous_date: str | None = None) -> list[str]:
    """Return recent candidate dates, newest first.

    The listing is only a discovery aid: APMC can expose a date before its table
    has rate rows. Direct dated pages are therefore verified one by one.
    """
    slug = market["slug"]
    limit = max(3, min(31, int(CONFIG.get("date_probe_limit", 14))))
    dates = [today_str()]
    url = f"{CONFIG['source_base'].rstrip('/')}/bajarbhav/daily-bajarbhav-dates/{slug}"

    try:
        body = fetch(url)
        parser = LinkParser()
        parser.feed(body)
        pattern = re.compile(rf"/view-daily-bajarbhav/{re.escape(slug)}/(\d{{4}}-\d{{2}}-\d{{2}})")
        for href in parser.hrefs:
            m = pattern.search(urllib.parse.unquote(href))
            if m:
                dates.append(m.group(1))
        dates.extend(re.findall(rf"view-daily-bajarbhav/{re.escape(slug)}/(\d{{4}}-\d{{2}}-\d{{2}})", body))
    except Exception as exc:
        # Listing failure must not disable direct date-addressable source pages.
        append_log("date_listing_error", market=slug, error=str(exc)[:300])

    current = datetime.now(TZ).date()
    for offset in range(1, min(limit, 8)):
        dates.append((current - timedelta(days=offset)).isoformat())
    if previous_date:
        dates.append(previous_date)

    dates = sorted({d for d in dates if re.fullmatch(r"\d{4}-\d{2}-\d{2}", d or "")}, reverse=True)
    if previous_date:
        newer_or_same = [d for d in dates if d >= previous_date]
        if newer_or_same:
            dates = newer_or_same
    return dates[:limit]


def discover_latest_date(market: dict) -> str | None:
    dates = discover_candidate_dates(market)
    return dates[0] if dates else None


def parse_rate_table(body: str, market: dict, date: str) -> list[dict]:
    parser = TableParser()
    parser.feed(body)
    best = []
    for table in parser.tables:
        valid = []
        for row in table:
            if len(row) < 6:
                continue
            arrival, min_p, max_p, avg_p = [parse_number(row[i]) for i in range(2, 6)]
            if avg_p is None or min_p is None or max_p is None:
                continue
            source_name = clean_text(row[0])
            unit = clean_text(row[1])
            if not source_name or any(k in source_name for k in ["शेतमालाचे नाव", "Commodity"]):
                continue
            name_mr, name_en, emoji = resolve_commodity_name(source_name)
            key_basis = f"{name_en}|{name_mr}".lower().encode("utf-8")
            commodity_key = hashlib.sha1(key_basis).hexdigest()[:14]
            valid.append({
                "commodity_key": commodity_key,
                "source_name": source_name,
                "name_mr": name_mr,
                "name_en": name_en,
                "emoji": emoji,
                "unit": unit,
                "arrival": arrival or 0,
                "min": min_p,
                "max": max_p,
                "average": avg_p,
                "date": date,
                "market_slug": market["slug"],
                "feed_slug": market["slug"],
                "market_id": market.get("market_id") or market["slug"],
                "market_name": market["market"],
                "market_name_en": market["market"],
                "market_name_mr": market.get("market_mr") or market["market"],
                "category": market["category"],
                "category_en": market["category"],
                "category_mr": market.get("category_mr") or market["category"],
            })
        if len(valid) > len(best):
            best = valid
    return best


def normalized_hash(records: list[dict]) -> str:
    rows = []
    for r in records:
        rows.append([r["name_mr"], r["unit"], r["arrival"], r["min"], r["max"], r["average"]])
    raw = json.dumps(rows, ensure_ascii=False, separators=(",", ":"), sort_keys=False)
    return hashlib.sha256(raw.encode("utf-8")).hexdigest()


def fetch_market_for_date_allow_empty(market: dict, date: str) -> tuple[list[dict], str]:
    url = f"{CONFIG['source_base'].rstrip('/')}/bajarbhav/view-daily-bajarbhav/{market['slug']}/{date}"
    try:
        body = fetch(url)
    except RuntimeError as exc:
        message = str(exc)
        if "HTTP Error 404" in message or "HTTP Error 410" in message:
            return [], url
        raise
    return parse_rate_table(body, market, date), url


def fetch_market_for_date(market: dict, date: str) -> tuple[list[dict], str]:
    records, url = fetch_market_for_date_allow_empty(market, date)
    if not records:
        raise RuntimeError("No valid 6-column rate rows found")
    return records, url


def find_latest_populated(market: dict, previous_date: str | None = None) -> tuple[str, list[dict], str] | None:
    for date in discover_candidate_dates(market, previous_date):
        records, url = fetch_market_for_date_allow_empty(market, date)
        if records:
            return date, records, url
    return None


def market_snapshot_path(slug: str) -> Path:
    return MARKETS_DIR / f"{slug}.json"


def daily_snapshot_path(date: str, slug: str) -> Path:
    y, m, d = date.split("-")
    return DAILY_DIR / y / m / d / f"{slug}.json"


def update_commodity_index(record: dict) -> None:
    path = COMMODITIES_DIR / f"{record['commodity_key']}.json"
    doc = read_json(path, {
        "commodity_key": record["commodity_key"],
        "name_mr": record["name_mr"],
        "name_en": record["name_en"],
        "emoji": record.get("emoji", "◦"),
        "history": [],
    })
    history = [h for h in doc.get("history", []) if not (h.get("date") == record["date"] and h.get("market_slug") == record["market_slug"])]
    history.append({
        "date": record["date"], "market_slug": record["market_slug"], "market_name": record["market_name"],
        "market_name_en": record.get("market_name_en", record["market_name"]),
        "market_name_mr": record.get("market_name_mr", record["market_name"]),
        "category": record["category"], "category_en": record.get("category_en", record["category"]),
        "category_mr": record.get("category_mr", record["category"]),
        "unit": record["unit"], "arrival": record["arrival"],
        "min": record["min"], "max": record["max"], "average": record["average"],
    })
    history.sort(key=lambda x: (x.get("date", ""), x.get("market_slug", "")))
    doc["history"] = history[-730:]
    atomic_json(path, doc)


def enrich_record(record: dict, cache: dict) -> dict:
    key = record["commodity_key"]
    if key not in cache:
        cache[key] = read_json(COMMODITIES_DIR / f"{key}.json", {"history": []})
    history = [h for h in cache[key].get("history", []) if h.get("market_slug") == record["market_slug"]]
    history.sort(key=lambda x: x.get("date", ""))
    prior = [h for h in history if h.get("date", "") < record["date"]]
    prev = prior[-1] if prior else None
    previous_average = prev.get("average") if prev else None
    change_pct = None
    change_amount = None
    if previous_average not in (None, 0):
        change_amount = record["average"] - previous_average
        change_pct = round(change_amount / previous_average * 100, 2)
    trend = [h.get("average", 0) for h in history if h.get("date", "") <= record["date"]][-7:]
    out = dict(record)
    out.update({"previous_average": previous_average, "change_amount": change_amount, "change_pct": change_pct, "trend": trend})
    return out


def save_market_snapshot(market: dict, date: str, records: list[dict], source_url: str, source_hash: str) -> None:
    snapshot = {
        "market": market,
        "date": date,
        "source_url": source_url,
        "source_hash": source_hash,
        "captured_at": now_iso(),
        "records": records,
    }
    atomic_json(market_snapshot_path(market["slug"]), snapshot)
    atomic_json(daily_snapshot_path(date, market["slug"]), snapshot)
    for record in records:
        update_commodity_index(record)


def rebuild_aggregate(manifest: dict) -> tuple[dict, str]:
    all_records = []
    market_summaries = []
    category_set = set()
    cache = {}
    for market in CONFIG["markets"]:
        snapshot = read_json(market_snapshot_path(market["slug"]), {})
        records = snapshot.get("records", [])
        if records:
            enriched = [enrich_record(r, cache) for r in records]
            all_records.extend(enriched)
            market_summaries.append({
                "slug": market["slug"], "name": market["market"],
                "name_en": market["market"], "name_mr": market.get("market_mr") or market["market"],
                "category": market["category"], "category_en": market["category"],
                "category_mr": market.get("category_mr") or market["category"],
                "date": snapshot.get("date"), "records": len(records), "captured_at": snapshot.get("captured_at")
            })
            category_set.add(market["category"])

    up = down = unchanged = 0
    total_arrival = 0
    for r in all_records:
        total_arrival += float(r.get("arrival") or 0)
        c = r.get("change_pct")
        if c is None or abs(c) < 0.005: unchanged += 1
        elif c > 0: up += 1
        else: down += 1

    dates = [m.get("date") for m in market_summaries if m.get("date")]
    serving_date = max(dates) if dates else None
    priority = ["Tomato", "Onion", "Potato", "Garlic", "Ginger", "Okra", "Chilli", "Peas"]
    featured = []
    used = set()
    for name in priority:
        matches = [r for r in all_records if r.get("name_en") == name]
        matches.sort(key=lambda r: (r.get("date", ""), float(r.get("arrival") or 0)), reverse=True)
        if matches:
            key = (matches[0]["market_slug"], matches[0]["commodity_key"])
            if key not in used:
                featured.append(matches[0]); used.add(key)
    if len(featured) < 4:
        candidates = sorted(all_records, key=lambda r: float(r.get("arrival") or 0), reverse=True)
        for r in candidates:
            key = (r["market_slug"], r["commodity_key"])
            if key not in used:
                featured.append(r); used.add(key)
            if len(featured) >= 8: break

    statuses = manifest.get("markets", {})
    errors = sum(1 for s in statuses.values() if s.get("status") == "error")
    stale = sum(1 for s in statuses.values() if s.get("date") and s.get("date") != today_str())
    status = "healthy" if all_records and errors == 0 else ("degraded" if all_records else "warming")

    aggregate = {
        "meta": {
            "generated_at": now_iso(), "serving_date": serving_date, "status": status,
            "records": len(all_records), "markets_available": len(market_summaries), "markets_expected": len(CONFIG["markets"]),
            "markets_error": errors, "markets_stale": stale,
        },
        "pulse": {"up": up, "down": down, "unchanged": unchanged, "total_arrival": round(total_arrival, 2)},
        "markets": market_summaries,
        "categories": sorted(category_set),
        "featured": featured[:8],
        "records": all_records,
    }
    aggregate_hash = hashlib.sha256(json.dumps(aggregate["records"], ensure_ascii=False, sort_keys=True).encode("utf-8")).hexdigest()
    atomic_json(STORAGE / "current" / "all.json", aggregate)
    write_csv(all_records)
    return aggregate, aggregate_hash


def write_csv(records: list[dict]) -> None:
    path = EXPORTS_DIR / "latest.csv"
    tmp = path.with_suffix(".csv.tmp")
    fields = ["date", "market_id", "market_name", "market_name_en", "market_name_mr", "feed_slug", "market_slug", "category", "category_en", "category_mr", "source_name", "name_mr", "name_en", "unit", "arrival", "min", "max", "average", "change_pct"]
    with tmp.open("w", encoding="utf-8-sig", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=fields, extrasaction="ignore")
        writer.writeheader()
        writer.writerows(records)
    os.replace(tmp, path)


def process_market(market: dict, manifest: dict, force: bool = False, retry_errors: bool = False) -> bool:
    slug = market["slug"]
    state = manifest.setdefault("markets", {}).get(slug, {})
    snapshot = read_json(market_snapshot_path(slug), {})
    previous_date = state.get("date") or snapshot.get("date")
    previous_hash = state.get("hash") or snapshot.get("source_hash")
    if previous_date and not state.get("date"):
        state["date"] = previous_date
    if previous_hash and not state.get("hash"):
        state["hash"] = previous_hash
    if snapshot.get("records") and not state.get("records"):
        state["records"] = len(snapshot.get("records", []))
    today = today_str()
    changed = False

    try:
        result = find_latest_populated(market, previous_date)
        if result is None:
            # A dated page with no rows is not an error. Preserve the last-good
            # snapshot and report that APMC has not published newer usable rows.
            state.update({"status": "no_new_data", "last_checked_at": now_iso(), "error": None})
            manifest.setdefault("markets", {})[slug] = state
            append_log("market_no_new_data", market=slug, previous_date=previous_date)
            return False

        latest_date, records, source_url = result
        source_hash = normalized_hash(records)
        changed = force or source_hash != previous_hash or latest_date != previous_date
        if changed or not market_snapshot_path(slug).exists():
            save_market_snapshot(market, latest_date, records, source_url, source_hash)

        status = "no_new_data" if latest_date == previous_date and latest_date != today else "ok"
        manifest["markets"][slug] = {
            "status": status, "date": latest_date, "hash": source_hash, "records": len(records),
            "last_checked_at": now_iso(), "last_full_fetch_at": now_iso(), "error": None,
            "runtime": "python",
        }
        append_log("market_checked", market=slug, date=latest_date, changed=changed, status=status)
        return changed
    except Exception as exc:
        # Do not delete or replace the last good local snapshot.
        state.update({"status": "error", "last_checked_at": now_iso(), "error": str(exc)[:600], "runtime": "python"})
        manifest.setdefault("markets", {})[slug] = state
        append_log("market_error", market=slug, error=str(exc)[:600])
        return False


def backfill(days: int, manifest: dict) -> bool:
    changed = False
    start = datetime.now(TZ).date()
    for offset in range(max(1, days)):
        date = (start - timedelta(days=offset)).isoformat()
        for market in CONFIG["markets"]:
            path = daily_snapshot_path(date, market["slug"])
            if path.exists():
                continue
            try:
                records, source_url = fetch_market_for_date(market, date)
                source_hash = normalized_hash(records)
                # Historical fetch updates history but should not replace a newer current snapshot.
                snapshot = {"market": market, "date": date, "source_url": source_url, "source_hash": source_hash, "captured_at": now_iso(), "records": records}
                atomic_json(path, snapshot)
                for record in records:
                    update_commodity_index(record)
                changed = True
                append_log("backfill_saved", market=market["slug"], date=date, records=len(records))
            except Exception as exc:
                append_log("backfill_skip", market=market["slug"], date=date, error=str(exc)[:250])
    return changed


def rebuild_indexes_from_daily() -> None:
    # Recreate commodity history from archived daily files while preserving current snapshots.
    for file in COMMODITIES_DIR.glob("*.json"):
        try: file.unlink()
        except Exception: pass
    for path in sorted(DAILY_DIR.glob("*/*/*/*.json")):
        snap = read_json(path, {})
        for record in snap.get("records", []):
            update_commodity_index(record)
    for path in sorted(MARKETS_DIR.glob("*.json")):
        snap = read_json(path, {})
        for record in snap.get("records", []):
            update_commodity_index(record)


def write_health(aggregate: dict, manifest: dict) -> None:
    errors = []
    for slug, state in manifest.get("markets", {}).items():
        if state.get("status") == "error":
            errors.append({"market": slug, "error": state.get("error")})
    health = {
        "status": aggregate.get("meta", {}).get("status", "warming"),
        "checked_at": now_iso(),
        "python": sys.version.split()[0],
        "storage_writable": os.access(STORAGE, os.W_OK),
        "records": aggregate.get("meta", {}).get("records", 0),
        "markets_available": aggregate.get("meta", {}).get("markets_available", 0),
        "markets_expected": len(CONFIG["markets"]),
        "errors": errors,
    }
    atomic_json(SYSTEM / "health.json", health)


def scheduled_interval_seconds() -> int:
    schedule = CONFIG.get("schedule", {})
    hour = datetime.now(TZ).hour
    if 9 <= hour < 15:
        return int(schedule.get("active_seconds", 300))
    if 6 <= hour < 9:
        return int(schedule.get("morning_seconds", 900))
    if 15 <= hour < 18:
        return int(schedule.get("evening_seconds", 600))
    return int(schedule.get("overnight_seconds", 3600))


def scheduled_refresh_due(manifest: dict) -> bool:
    raw = manifest.get("last_checked_at")
    if not raw:
        return True
    try:
        last = datetime.fromisoformat(str(raw))
        if last.tzinfo is None:
            last = last.replace(tzinfo=TZ)
        age = (datetime.now(TZ) - last.astimezone(TZ)).total_seconds()
        return age >= scheduled_interval_seconds()
    except Exception:
        return True


def run(args) -> int:
    lock_path = SYSTEM / "refresh.lock"
    lock_fh = lock_path.open("a+")
    try:
        fcntl.flock(lock_fh.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        append_log("refresh_skipped", reason="already_running", trigger=args.trigger)
        return 0

    manifest_path = SYSTEM / "manifest.json"
    manifest = read_json(manifest_path, {"markets": {}})
    is_server = bool(args.scheduled or args.trigger == "server-cron")

    if is_server:
        manifest["last_server_heartbeat_at"] = now_iso()
        manifest["last_server_status"] = "running"
        atomic_json(manifest_path, manifest)
        if args.scheduled and not args.force and not args.backfill_days and not args.rebuild and not scheduled_refresh_due(manifest):
            manifest["last_server_status"] = "healthy"
            atomic_json(manifest_path, manifest)
            append_log("scheduled_refresh_skipped", reason="not_due", interval=scheduled_interval_seconds())
            fcntl.flock(lock_fh.fileno(), fcntl.LOCK_UN)
            lock_fh.close()
            return 0

    manifest["refresh_started_at"] = now_iso()
    manifest["refresh_trigger"] = args.trigger
    atomic_json(manifest_path, manifest)
    append_log("refresh_started", trigger=args.trigger, force=args.force, backfill_days=args.backfill_days)

    any_changed = False
    try:
        if args.rebuild:
            rebuild_indexes_from_daily()
            any_changed = True
        elif args.backfill_days:
            any_changed = backfill(args.backfill_days, manifest) or any_changed
        else:
            for market in CONFIG["markets"]:
                any_changed = process_market(market, manifest, force=args.force, retry_errors=args.retry_errors) or any_changed

        aggregate, aggregate_hash = rebuild_aggregate(manifest)
        previous_aggregate_hash = manifest.get("aggregate_hash")
        manifest["last_checked_at"] = now_iso()
        if any_changed or aggregate_hash != previous_aggregate_hash:
            manifest["last_updated_at"] = now_iso()
        manifest["aggregate_hash"] = aggregate_hash
        manifest["refresh_finished_at"] = now_iso()
        manifest["status"] = aggregate.get("meta", {}).get("status", "warming")
        if is_server:
            manifest["last_server_checked_at"] = manifest["last_checked_at"]
            manifest["last_server_heartbeat_at"] = now_iso()
            manifest["last_server_status"] = "healthy"
            manifest.pop("last_server_error", None)
        atomic_json(manifest_path, manifest)
        write_health(aggregate, manifest)
        append_log("refresh_finished", changed=any_changed, records=aggregate["meta"]["records"], status=manifest["status"], trigger=args.trigger)
        return 0
    except Exception as exc:
        manifest["last_checked_at"] = now_iso()
        manifest["refresh_finished_at"] = now_iso()
        manifest["status"] = "error"
        manifest["fatal_error"] = str(exc)[:1000]
        if is_server:
            manifest["last_server_heartbeat_at"] = now_iso()
            manifest["last_server_status"] = "error"
            manifest["last_server_error"] = str(exc)[:1000]
            manifest["last_server_failed_at"] = now_iso()
        atomic_json(manifest_path, manifest)
        atomic_json(SYSTEM / "health.json", {"status": "error", "checked_at": now_iso(), "message": str(exc), "trace": traceback.format_exc()[-4000:]})
        append_log("refresh_fatal", error=str(exc)[:1000], trigger=args.trigger)
        return 1
    finally:
        try:
            fcntl.flock(lock_fh.fileno(), fcntl.LOCK_UN)
            lock_fh.close()
        except Exception:
            pass


def parse_args():
    p = argparse.ArgumentParser(description="Pune APMC server-scheduled flat-file collector")
    p.add_argument("--check", action="store_true", help="Check all configured markets")
    p.add_argument("--force", action="store_true", help="Force full source fetch")
    p.add_argument("--retry-errors", action="store_true", help="Retry feeds currently marked error")
    p.add_argument("--rebuild", action="store_true", help="Rebuild commodity indexes from stored snapshots")
    p.add_argument("--backfill-days", type=int, default=0, help="Fetch direct historical URLs for N days")
    p.add_argument("--trigger", default="manual", help="Trigger label for diagnostics")
    p.add_argument("--scheduled", action="store_true", help="Cron mode: write server heartbeat and skip source polling until due")
    return p.parse_args()


if __name__ == "__main__":
    raise SystemExit(run(parse_args()))
