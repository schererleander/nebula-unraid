#!/usr/bin/env bash
set -euo pipefail

find src tests -type f \( -name '*.php' -o -name '*.page' -o -path '*/scripts/config' -o -path '*/scripts/watch' -o -path '*/scripts/health' \) -print0 |
  while IFS= read -r -d '' file; do
    if ! result=$(php -l "$file" 2>&1); then
      printf '%s\n' "$result" >&2
      exit 1
    fi
  done

python3 -m compileall -q tests .github/scripts
for file in src/etc/rc.d/rc.nebula src/etc/cron.daily/nebula \
  src/usr/local/emhttp/plugins/nebula/scripts/supervise \
  src/usr/local/emhttp/plugins/nebula/event/* .github/scripts/*.sh; do
  bash -n "$file"
done
