#!/usr/bin/env bash
#
# Regenerates what fixtures/ derives from the PHP engine of this repository
# (php/src), the reference the TypeScript engine is compared with:
#
# - fixtures/cases/*.json: the `resolved` of each golden case, what
#   ThemeResolver::resolve() answers for its `values` over the frozen schema
#   (fixtures/schema.tuples.json; scripts/golden-fixtures.php);
# - fixtures/schema.nodes.json: that frozen schema converted to nodes
#   (scripts/convert-schema.php);
# - fixtures/schema-26.0.empty.json: the empty theme over the product's
#   current schema (fixtures/schema-26.0.nodes.json; scripts/resolve-empty.php);
# - fixtures/schema-26.0.compiled.json: the tuples NodeCompiler::compile()
#   answers for that schema with its version 1 map (fixtures/v1-map.json;
#   scripts/compile-schema.php);
# - fixtures/validate.json: what ThemeSchemaPublisher::validate() answers
#   for each mutation of that schema (scripts/validate-cases.php);
# - fixtures/valid.json: what ThemeValidator::validate() and
#   ThemeResolver::valid() answer for each theme over those tuples, and
#   ThemeValidator::settings() for each site's settings
#   (scripts/valid-cases.php);
# - fixtures/php-round.json and fixtures/php-strings.json: PHP's round(),
#   version_compare() and (string) cast (scripts/php-round.php,
#   scripts/php-strings.php).
#
# What it reads and never writes: the cases' `description`, `migrate` and
# `values`, the two schemas, the version 1 map and meta/theme-schema-1.json.
#
# It runs PHP in docker, pinned to the PHP 8.3 the fixtures record, so it
# needs nothing but docker on the host; it installs vendor/ with composer:2
# when it is missing. CI runs it and fails if it changes anything.
#
#   bash scripts/sync-fixtures.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FIXTURES="$ROOT/fixtures"
PHP_IMAGE="${PHP_IMAGE:-php:8.3.35-cli}"
COMPOSER_IMAGE="${COMPOSER_IMAGE:-composer:2}"

in_docker() {
  docker run --rm -i -u "$(id -u):$(id -g)" -v "$ROOT:/app" -w /app "$@"
}

php() {
  in_docker "$PHP_IMAGE" php "$@"
}

# What a command writes, into $1 only when it succeeds and answers JSON.
into() {
  local target="$1" tmp
  shift
  tmp="$(mktemp)"
  if ! "$@" > "$tmp" || ! php -r 'json_decode(stream_get_contents(STDIN), flags: JSON_THROW_ON_ERROR);' < "$tmp"; then
    echo "Failed: $target was left as it was." >&2
    cat "$tmp" >&2
    rm -f "$tmp"
    exit 1
  fi
  mv "$tmp" "$target"
}

if [[ ! -f "$ROOT/vendor/autoload.php" ]]; then
  in_docker -e COMPOSER_HOME=/tmp/composer "$COMPOSER_IMAGE" install --no-interaction --no-progress >&2
fi

echo "PHP: $(php -r 'echo PHP_VERSION;') ($PHP_IMAGE), engine at $(git -C "$ROOT" rev-parse --short HEAD) with the working tree"

# The golden cases' answers, written in place.
php scripts/golden-fixtures.php > /dev/null

# Their schema in nodes.
into "$FIXTURES/schema.nodes.json" php scripts/convert-schema.php fixtures/schema.tuples.json

# The current schema: its empty theme, its tuples and what validate() answers for its mutations.
into "$FIXTURES/schema-26.0.empty.json" php scripts/resolve-empty.php fixtures/schema-26.0.nodes.json fixtures/v1-map.json
into "$FIXTURES/schema-26.0.compiled.json" php scripts/compile-schema.php fixtures/schema-26.0.nodes.json fixtures/v1-map.json
into "$FIXTURES/validate.json" php scripts/validate-cases.php fixtures/schema-26.0.nodes.json fixtures/v1-map.json
into "$FIXTURES/valid.json" php scripts/valid-cases.php fixtures/schema-26.0.compiled.json

# PHP's round(), version_compare() and (string), which the TypeScript engine imitates.
into "$FIXTURES/php-round.json" php scripts/php-round.php
into "$FIXTURES/php-strings.json" php scripts/php-strings.php

echo "fixtures/: $(ls "$FIXTURES/cases" | wc -l | tr -d ' ') golden cases, the golden schema in nodes, the 26.0 schema's empty theme and tuples, $(php -r 'echo count(json_decode(file_get_contents("fixtures/validate.json"), true)["cases"]);') validate() cases, $(php -r 'echo count(json_decode(file_get_contents("fixtures/valid.json"), true)["cases"]);') valid() cases, PHP's round() and strings."
