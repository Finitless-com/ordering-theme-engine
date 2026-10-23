<?php

/*
 * What NodeCompiler::compile() answers for a schema in nodes and its
 * version 1 map, as JSON on stdout: the tuples the engine reads and the
 * compiler's errors. scripts/sync-fixtures.sh runs it in PHP 8.3:
 *
 *   php scripts/compile-schema.php meta/reference/reference-1.0.0.json meta/reference/reference-1.0.0.v1-map.json
 */

declare(strict_types=1);

use Ordering\ThemeEngine\Schema\NodeCompiler;

require dirname(__DIR__) . '/vendor/autoload.php';

[ , $schema_path, $map_path ] = $argv + [ null, null, null ];
if (!is_string($schema_path) || !is_file($schema_path) || !is_string($map_path) || !is_file($map_path)) {
    fwrite(STDERR, "Usage: php scripts/compile-schema.php <schema in nodes> <version 1 map>\n");
    exit(1);
}
$read = fn (string $path) => json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$nodes = $read($schema_path);

$compiled = NodeCompiler::compile($nodes, $read($map_path));

echo json_encode([
    'description' => "What api-core's NodeCompiler::compile() answers for {$nodes['product_type']}@{$nodes['version']} with its version 1 map.",
    'schema' => $compiled['schema'],
    'errors' => $compiled['errors'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
