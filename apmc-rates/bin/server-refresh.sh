#!/usr/bin/env sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PYTHON_BIN=${APMC_PYTHON:-python3}

# National AGMARKNET is optional: its API key stays in the server environment.
# Failures never block Pune market refresh and never erase prior verified rates.
if [ -n "${APMC_DATA_GOV_IN_API_KEY:-}" ]; then
  "$PYTHON_BIN" "$ROOT/collector/national.py" || echo "AGMARKNET refresh unavailable; retaining last-good data" >&2
fi

exec "$PYTHON_BIN" "$ROOT/collector/refresh.py" --check --scheduled --trigger server-cron
