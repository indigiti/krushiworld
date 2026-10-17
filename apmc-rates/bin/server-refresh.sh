#!/usr/bin/env sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PYTHON_BIN=${APMC_PYTHON:-python3}

exec "$PYTHON_BIN" "$ROOT/collector/refresh.py" --check --scheduled --trigger server-cron
