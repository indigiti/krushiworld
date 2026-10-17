#!/usr/bin/env sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PYTHON_BIN=${APMC_PYTHON:-python3}

# National AGMARKNET is optional: its API key stays in the server environment.
# Failures never block Pune market refresh and never erase prior verified rates.
if [ -n "${APMC_DATA_GOV_IN_API_KEY:-}" ]; then
  "$PYTHON_BIN" "$ROOT/collector/national.py" || echo "AGMARKNET refresh unavailable; retaining last-good data" >&2
fi

# Never block the Pune refresh when national history is temporarily unavailable.
"$PYTHON_BIN" "$ROOT/collector/refresh.py" --check --scheduled --trigger server-cron
"$PYTHON_BIN" "$ROOT/collector/history.py" || echo "KrushiPulse history archival unavailable; prior history preserved" >&2
