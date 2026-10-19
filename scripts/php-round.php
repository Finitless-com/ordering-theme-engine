<?php

/*
 * PHP's round() over a fixed set of values, as JSON on stdout: what
 * src/php.ts phpRound() must answer. It runs with the PHP of api-core's dev
 * container (8.3), read from stdin:
 *
 *   docker compose -f docker-compose.dev.yml exec -T api php < scripts/php-round.php
 *
 * The values are the kind the engine rounds: channels of a mix of two
 * colors, offsets, blurs and opacities scaled by a shadow style, halves of
 * either sign, and a few known to need PHP's pre-rounding.
 */

declare(strict_types=1);

mt_srand(42);
$values = [ 1.005, 0.285, 127.49999999999997, -2.5, 2.675, 5.055, 0.5, -0.5, 1.5, 2.5, 0.49999999999999994, 1e15 + 0.5, 123456789.5 ];
for ($i = 0; $i < 1500; $i++) {
    $values[] = mt_rand(0, 255) * 0.12 + mt_rand(0, 255) * 0.88;
}
for ($i = 0; $i < 1500; $i++) {
    $values[] = (mt_rand(-2000000, 2000000) / 1000) * [ 0.75, 1.3, 1.5, 1.25, 0.6, 1.6 ][$i % 6];
}
for ($i = 0; $i < 1500; $i++) {
    $values[] = mt_rand(0, 1000000) / 1000000 * 255;
}
for ($i = 0; $i < 1000; $i++) {
    $values[] = mt_rand(0, 100) / 100 * [ 0.6, 1.6, 1 ][$i % 3];
}
for ($i = 0; $i < 500; $i++) {
    $values[] = (mt_rand(0, 2000) + 0.5) * (mt_rand(0, 1) === 1 ? 1 : -1);
}

echo json_encode([
    'php' => PHP_VERSION,
    'rounds' => array_map(fn (float $value) => [ $value, round($value), round($value, 2) ], $values),
], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
