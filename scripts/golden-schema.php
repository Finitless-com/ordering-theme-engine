<?php

/*
 * The golden schema of the golden cases (fixtures/schema.tuples.json), as
 * JSON on stdout: the reference schema compiled with its version 1 map,
 * in the tuples of the format before nodes, and without the rules it
 * declares (what follows Identity, the colors' and components' derivations,
 * the matrix), so the engine applies its own: what a schema that declares
 * none gets (docs/schema-format.md §11.5). It keeps only what that format
 * had: no site's settings, no editor's limits, translucent colors nor
 * narrowed tokens. scripts/sync-fixtures.sh runs it in PHP 8.3:
 *
 *   php scripts/golden-schema.php meta/reference/reference-1.0.0.json meta/reference/reference-1.0.0.v1-map.json
 */

declare(strict_types=1);

use Ordering\ThemeEngine\Schema\NodeCompiler;

require dirname(__DIR__) . '/vendor/autoload.php';

[ , $schema_path, $map_path ] = $argv + [ null, null, null ];
if (!is_string($schema_path) || !is_file($schema_path) || !is_string($map_path) || !is_file($map_path)) {
    fwrite(STDERR, "Usage: php scripts/golden-schema.php <schema in nodes> <version 1 map>\n");
    exit(1);
}
$read = fn (string $path) => json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$compiled = NodeCompiler::compile($read($schema_path), $read($map_path));
if ($compiled['errors'] !== []) {
    fwrite(STDERR, 'The schema does not compile: ' . json_encode(array_column($compiled['errors'], 'code')) . "\n");
    exit(1);
}

$keys = [ 'product_type', 'version', 'format', 'platforms', 'tokens', 'components', 'inherits', 'visibility', 'images', 'pages', 'v1' ];
$schema = array_intersect_key($compiled['schema'], array_flip($keys));
$schema['v1'] = array_filter($schema['v1'], fn (string $path) => !str_starts_with($path, 'site.'), ARRAY_FILTER_USE_KEY);

echo json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
