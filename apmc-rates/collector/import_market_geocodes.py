#!/usr/bin/env python3
"""Import source-documented national mandi coordinates from an operator-reviewed CSV.

CSV fields: market_id,latitude,longitude,precision,source_url,pincode (optional).
The market ID must exist in the official, already imported directory. No geocoding,
PIN centroid guessing, or geospatial scraping takes place in this command.
"""
from __future__ import annotations

import argparse
import csv
import json
import math
import re
import sys
from pathlib import Path
from urllib.parse import urlsplit

from national import DEST, load, write_atomic

OUTPUT = DEST / "geocodes.json"
ALLOWED_PRECISION = {"surveyed", "locality", "approximate"}
HEADER = {"market_id", "latitude", "longitude", "precision", "source_url"}


def validate_row(row: dict, known: set[str]) -> tuple[str, dict]:
    market_id = str(row.get("market_id") or "").strip()
    if market_id not in known or not market_id.startswith("agmarknet-"):
        raise ValueError("unknown_or_non_national_mandi")
    try:
        latitude = float(row["latitude"])
        longitude = float(row["longitude"])
    except (ValueError, TypeError, KeyError) as err:
        raise ValueError("invalid_coordinates") from err
    # Mainland India, island territories and nearby Indian markets.
    if not math.isfinite(latitude) or not math.isfinite(longitude) or not 6 <= latitude <= 38 or not 68 <= longitude <= 98:
        raise ValueError("out_of_india_reference_bounds")
    precision = str(row.get("precision") or "").strip()
    if precision not in ALLOWED_PRECISION:
        raise ValueError("invalid_location_precision")
    origin = str(row.get("source_url") or "").strip()
    url = urlsplit(origin)
    if url.scheme != "https" or not url.netloc or url.username or url.password or len(origin) > 350:
        raise ValueError("missing_or_invalid_geographic_provenance")
    pincode = str(row.get("pincode") or "").strip()
    if pincode and not re.fullmatch(r"[1-9][0-9]{5}", pincode):
        raise ValueError("invalid_pincode")
    result = {
        "latitude": latitude, "longitude": longitude, "precision": precision,
        "source": origin,
    }
    if pincode:
        result["pincode"] = pincode
    return market_id, result


def ingest_csv(path: Path, known: set[str]) -> dict:
    with path.open(newline="", encoding="utf-8-sig") as source:
        reader = csv.DictReader(source)
        if not reader.fieldnames or not HEADER.issubset(reader.fieldnames):
            raise ValueError("missing_required_csv_headers")
        result = {}
        for number, row in enumerate(reader, start=2):
            if number > 50002:
                raise ValueError("geocode_file_too_large")
            key, item = validate_row(row, known)
            if key in result:
                raise ValueError("duplicate_market_id")
            result[key] = item
    if not result:
        raise ValueError("no_verified_geo_rows")
    return result


def run(path: Path, dry_run: bool = False) -> int:
    directory = load(DEST / "directory.json", {})
    known = {str(m.get("market_id")) for m in directory.get("markets", []) if isinstance(m, dict)}
    if not known:
        raise RuntimeError("import_agmarknet_directory_first")
    selected = ingest_csv(path, known)
    existing = load(OUTPUT, {}).get("markets", {})
    merged = dict(existing) if isinstance(existing, dict) else {}
    merged.update(selected)
    if not dry_run:
        write_atomic(OUTPUT, {"markets": merged})
    print(f"Validated {len(selected)} national mandi geocodes; total curated {len(merged)}"
          + (" [DRY RUN]" if dry_run else ""))
    return 0


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--csv", type=Path, required=True)
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()
    try:
        sys.exit(run(args.csv, args.dry_run))
    except (OSError, ValueError, RuntimeError) as exc:
        print("Geocode import rejected: " + str(exc)[:110], file=sys.stderr)
        sys.exit(1)
