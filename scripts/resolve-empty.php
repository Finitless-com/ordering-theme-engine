<?php

/*
 * What api-core's ThemeResolver::resolve() answers for the empty theme over
 * a schema in nodes, as JSON on stdout. It runs inside api-core's dev
 * container, read from stdin (the container does not see this repository):
 *
 *   docker compose -f docker-compose.dev.yml exec -T api \
 *     php -- resources/themes/marketplace_website/26.0.json < scripts/resolve-empty.php
 *
 * The path is relative to api-core's root. Like api-core's golden test
 * (tests/Unit/Themes/ThemeResolverGolden.php), it needs only the autoloader:
 * resolve() with a schema in hand reads neither the database nor Redis.
 */

declare(strict_types=1);

use App\Models\SiteTheme;
use App\Themes\ThemeFormat;
use App\Themes\ThemeResolver;
use App\Themes\ThemeSchemas;

require getcwd() . '/vendor/autoload.php';

$path = $argv[1] ?? null;
if (!is_string($path) || !is_file($path)) {
    fwrite(STDERR, "Usage: php -- <schema in nodes, relative to api-core> < resolve-empty.php\n");
    exit(1);
}

$nodes = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$compiled = ThemeSchemas::tuples($nodes);
if ($compiled['errors'] !== []) {
    fwrite(STDERR, json_encode($compiled['errors'], JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$schema = $compiled['schema'];

$site_theme = new SiteTheme();
$site_theme->setRawAttributes([
    'version' => ThemeFormat::VERSION,
    'product_type' => $schema['product_type'],
    'schema_version' => $schema['version'],
    'values' => '{}',
]);

$resolved = (new ThemeResolver(new ThemeSchemas()))->resolve($site_theme, $schema);

echo json_encode([
    'description' => "The empty theme over {$schema['product_type']}@{$schema['version']}, resolved by api-core.",
    'values' => new \stdClass(),
    'resolved' => $resolved,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
