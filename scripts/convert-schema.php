<?php

/*
 * A theme schema written in tuples, converted to the node format
 * (docs/schema-format.md) by NodeConverter, as JSON on stdout: what
 * api-core's `ordering:theme-schema:convert` writes for a schema that keeps
 * its version 1 paths. It refuses to answer unless compiling the result
 * gives back exactly the schema it read, once that declares what its
 * engine would apply by rule (ThemeSchemas::declare()).
 * scripts/sync-fixtures.sh runs it in PHP 8.3:
 *
 *   php scripts/convert-schema.php fixtures/schema.tuples.json
 */

declare(strict_types=1);

use Ordering\ThemeEngine\Schema\NodeCompiler;
use Ordering\ThemeEngine\Schema\NodeConverter;
use Ordering\ThemeEngine\ThemeSchemas;

require dirname(__DIR__) . '/vendor/autoload.php';

$path = $argv[1] ?? null;
$schema = is_string($path) && is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
if (!is_array($schema) || !array_key_exists('v1', $schema)) {
    fwrite(STDERR, "Usage: php scripts/convert-schema.php <schema in tuples, with its version 1 paths>\n");
    exit(1);
}

$converted = NodeConverter::convert($schema);
$compiled = NodeCompiler::compile($converted['schema'], $converted['v1']);
// The order of `inherits` follows the components once compiled; it means nothing.
$normalize = function (array $tuples) {
    if (isset($tuples['inherits'])) {
        ksort($tuples['inherits']);
    }

    return $tuples;
};
if ($compiled['errors'] !== [] || $normalize($compiled['schema']) !== $normalize(ThemeSchemas::declare($schema))) {
    fwrite(STDERR, json_encode($compiled['errors'], JSON_PRETTY_PRINT) . "\nRefused: the conversion does not give back the same schema.\n");
    exit(1);
}

echo NodeConverter::json($converted['schema']);
