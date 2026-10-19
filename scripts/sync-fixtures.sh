#!/usr/bin/env bash
#
# Refreshes fixtures/ from api-core, the source of truth of the engine:
#
# - fixtures/cases/*.json: api-core's golden cases (tests/fixtures/themes/cases);
# - fixtures/schema.nodes.json: the frozen schema they resolve over
#   (tests/fixtures/themes/schema-marketplace_website-26.0.json), converted to
#   nodes by `ordering:theme-schema:convert`;
# - fixtures/schema-26.0.nodes.json: the product's current schema
#   (resources/themes/marketplace_website/26.0.json), and
#   fixtures/schema-26.0.empty.json, the empty theme api-core resolves with it
#   (scripts/resolve-empty.php);
# - fixtures/php-round.json: PHP's round() over the values the engine rounds
#   (scripts/php-round.php).
#
# It needs api-core's dev container running (docker-compose.dev.yml). It only
# reads api-core: the container writes to its own /tmp, never to the repo.
#
#   API_CORE=../api-core bash scripts/sync-fixtures.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
API_CORE="$(cd "${API_CORE:-$ROOT/../api-core}" && pwd)"
FIXTURES="$ROOT/fixtures"
CONTAINER_TMP="/tmp/ordering-theme-engine"

api() {
  (cd "$API_CORE" && docker compose -f docker-compose.dev.yml exec -T api "$@")
}

# What a command writes, into $1 only when it succeeds and answers JSON.
into() {
  local target="$1" tmp
  shift
  tmp="$(mktemp)"
  if ! "$@" > "$tmp" || ! node -e 'JSON.parse(require("fs").readFileSync(process.argv[1], "utf8"))' "$tmp"; then
    echo "Failed: $target was left as it was." >&2
    cat "$tmp" >&2
    rm -f "$tmp"
    exit 1
  fi
  mv "$tmp" "$target"
}

echo "api-core: $API_CORE ($(git -C "$API_CORE" rev-parse --abbrev-ref HEAD) @ $(git -C "$API_CORE" rev-parse --short HEAD), working tree included)"

# The golden cases, as api-core pins them.
rm -f "$FIXTURES"/cases/*.json
mkdir -p "$FIXTURES/cases"
cp "$API_CORE"/tests/fixtures/themes/cases/*.json "$FIXTURES/cases/"

# Their schema in nodes, converted by api-core itself.
api mkdir -p "$CONTAINER_TMP"
api php artisan ordering:theme-schema:convert \
  tests/fixtures/themes/schema-marketplace_website-26.0.json \
  "$CONTAINER_TMP/golden.nodes.json" "$CONTAINER_TMP/golden.v1.json" >&2
into "$FIXTURES/schema.nodes.json" api cat "$CONTAINER_TMP/golden.nodes.json"

# The current schema and its empty theme.
cp "$API_CORE/resources/themes/marketplace_website/26.0.json" "$FIXTURES/schema-26.0.nodes.json"
into "$FIXTURES/schema-26.0.empty.json" api php -- resources/themes/marketplace_website/26.0.json < "$ROOT/scripts/resolve-empty.php"

# PHP's round(), which the engine imitates.
into "$FIXTURES/php-round.json" api php < "$ROOT/scripts/php-round.php"

api rm -rf "$CONTAINER_TMP"

echo "fixtures/: $(ls "$FIXTURES/cases" | wc -l | tr -d ' ') cases, the golden schema, the 26.0 schema and its empty theme, PHP's round()."
