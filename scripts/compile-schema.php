<?php

/*
 * What api-core's NodeCompiler::compile() answers for a schema in nodes and
 * its version 1 map, as JSON on stdout: the tuples the engine reads and the
 * compiler's errors. The product's schema marks no color translucent yet,
 * so the script first sets the editor's `alpha` on the nodes of `$alpha`
 * (the fixture says which, for the package's test to do the same). It runs
 * inside api-core's dev container, read from stdin (the container does not
 * see this repository):
 *
 *   docker compose -f docker-compose.dev.yml exec -T api \
 *     php -- resources/themes/marketplace_website/26.0.json \
 *            resources/themes/marketplace_website/v1-map.json < scripts/compile-schema.php
 *
 * The paths are relative to api-core's root.
 */

declare(strict_types=1);

use App\Themes\Schema\NodeCompiler;

require getcwd() . '/vendor/autoload.php';

[ , $schema_path, $map_path ] = $argv + [ null, null, null ];
if (!is_string($schema_path) || !is_file($schema_path) || !is_string($map_path) || !is_file($map_path)) {
    fwrite(STDERR, "Usage: php -- <schema in nodes> <version 1 map> < compile-schema.php\n");
    exit(1);
}
$read = fn (string $path) => json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$nodes = $read($schema_path);

// A token, a component's base, a variant's state, and one marked opaque.
$alpha = [
    'tokens.color.overlay' => true,
    'components.modal.base.background' => true,
    'components.chip.variants.default.states.pressed.background' => true,
    'components.card.base.background' => false,
];
foreach ($alpha as $path => $mark) {
    $node = &$nodes;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($node[$segment] ?? null)) {
            fwrite(STDERR, "The schema has no node at {$path}.\n");
            exit(1);
        }
        $node = &$node[$segment];
    }
    $node['$extensions']['co.ordering.editor']['alpha'] = $mark;
    unset($node);
}

$compiled = NodeCompiler::compile($nodes, $read($map_path));

echo json_encode([
    'description' => "What api-core's NodeCompiler::compile() answers for {$nodes['product_type']}@{$nodes['version']} with its version 1 map, the editor's `alpha` set as `alpha` says.",
    'alpha' => $alpha,
    'schema' => $compiled['schema'],
    'errors' => $compiled['errors'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
