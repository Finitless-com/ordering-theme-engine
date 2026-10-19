<?php

/*
 * PHP's version_compare() and (string) cast over a fixed set of values, as
 * JSON on stdout: what src/php.ts versionCompare() and phpString() must
 * answer (validate() compares a schema's version with the previous one's,
 * and SchemaDiff compares options as strings). It runs with the PHP of
 * api-core's dev container, read from stdin:
 *
 *   docker compose -f docker-compose.dev.yml exec -T api php < scripts/php-strings.php
 */

declare(strict_types=1);

$versions = [
    '26', '26.0', '26.0.0', '26.0.1', '26.1', '26.1.0', '26.10.0', '26.9.9', '27.0.0', '0', '',
    '26.1.0-beta', '26.1.0beta', '26.1.0-alpha', '26.1.0-RC1', '26.1.0rc2', '26.1.0-dev', '26.1.0-pl1', '26.1.0-p',
    '26.1.0-patch', '26.1.0.x', '26.1.0-', '26.1.0.', '.26', '26..1', '#26', '26_1_0', '26+1', 'abc', '1.0.0-b1', '1a', 'a1',
];
$pairs = [];
foreach ($versions as $a) {
    foreach ($versions as $b) {
        $pairs[] = [ $a, $b, version_compare($a, $b) ];
    }
}

$floats = [ 0.1, 0.1 + 0.2, 1.5, -1.5, 26.5, 1 / 3, 2 / 3, 1e14, 1e15, 1.5e15, 1e20, 1e25, 1.25e-5, 0.0001, 0.00012345678901234, 123456789012.345, 99999999999999.9, -0.0, 3.0, 1e-10 ];
$strings = array_map(fn ($value) => [ $value, (string) $value ], $floats);

echo json_encode([
    'php' => PHP_VERSION,
    'version_compare' => $pairs,
    'strings' => $strings,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
