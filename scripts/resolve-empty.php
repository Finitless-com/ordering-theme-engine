<?php

/*
 * What ThemeResolver::resolve() answers for the empty theme over a schema
 * in nodes, compiled with its version 1 map, as JSON on stdout.
 * scripts/sync-fixtures.sh runs it in PHP 8.3:
 *
 *   php scripts/resolve-empty.php meta/reference/reference-1.0.0.json meta/reference/reference-1.0.0.v1-map.json
 */

declare(strict_types=1);

use Ordering\ThemeEngine\ThemeResolver;
use Ordering\ThemeEngine\ThemeSchemas;

require dirname(__DIR__) . '/vendor/autoload.php';

[ , $path, $map_path ] = $argv + [ null, null, null ];
if (!is_string($path) || !is_file($path) || !is_string($map_path) || !is_file($map_path)) {
    fwrite(STDERR, "Usage: php scripts/resolve-empty.php <schema in nodes> <version 1 map>\n");
    exit(1);
}

$read = fn (string $path) => json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$compiled = ThemeSchemas::tuples($read($path), $read($map_path));
if ($compiled['errors'] !== []) {
    fwrite(STDERR, json_encode($compiled['errors'], JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$schema = $compiled['schema'];

$resolved = ThemeResolver::resolve([], $schema);

echo json_encode([
    'description' => "The empty theme over {$schema['product_type']}@{$schema['version']}, resolved by api-core.",
    'values' => new \stdClass(),
    'resolved' => $resolved,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
