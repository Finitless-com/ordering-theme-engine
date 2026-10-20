#!/usr/bin/env php
<?php

/*
|--------------------------------------------------------------------------
| Golden fixtures of ThemeResolver::resolve()
|--------------------------------------------------------------------------
|
| Rewrites the `resolved` of every case under fixtures/cases/ (or of the
| cases named) with what ThemeResolver::resolve() answers for its `values`
| over the frozen schema, fixtures/schema.tuples.json. Run it only when a
| change of behavior is deliberate, and review the diff:
| ThemeResolverGoldenTest checks the files as they are, and the TypeScript
| engine's golden test compares itself with them.
|
|   php scripts/golden-fixtures.php [case …]
|
| scripts/sync-fixtures.sh runs it, in PHP 8.3, for every case. A case
| whose values the schema rejects is refused (resolve() would silently drop
| them), as is an answer that would not come back identical from its JSON.
| A case may declare the migrations of its schema (`migrate`): its values
| are checked once they move.
|
| `description`, `migrate` and `values` are written back as they were read
| (objects, so `{}` stays `{}`); `resolved` is PHP's array, where an empty
| map is `[]`.
|
*/

declare(strict_types=1);

use Ordering\ThemeEngine\Tests\ThemeResolverGolden;
use Ordering\ThemeEngine\ThemeMigrations;
use Ordering\ThemeEngine\ThemeResolver;
use Ordering\ThemeEngine\ThemeValidator;

require dirname(__DIR__) . '/vendor/autoload.php';

$cases = ThemeResolverGolden::cases();
$only = array_slice($argv, 1);
if ($only !== []) {
    $unknown = array_diff($only, array_keys($cases));
    if ($unknown !== []) {
        fwrite(STDERR, 'Unknown case: ' . implode(', ', $unknown) . PHP_EOL);
        exit(1);
    }
    $cases = array_intersect_key($cases, array_flip($only));
}

$failed = false;
foreach ($cases as $name => $path) {
    $case = ThemeResolverGolden::read($path);
    $schema = ThemeResolverGolden::schemaFor($case);
    $values = ThemeResolverGolden::values($case);
    $migrated = ThemeMigrations::apply($values, $schema['migrate'] ?? []);

    $errors = ThemeValidator::validate($migrated, $schema);
    if ($errors !== [] || ThemeResolver::valid($values, $schema) !== $migrated) {
        fwrite(STDERR, "{$name}: the schema rejects " . implode(', ', ThemeValidator::codes($errors) ?: [ 'part of the values' ]) . PHP_EOL);
        $failed = true;
        continue;
    }

    $resolved = ThemeResolverGolden::resolve($case, $schema);
    $json = json_encode([
        'description' => $case->description ?? '',
        ...(property_exists($case, 'migrate') ? [ 'migrate' => $case->migrate ] : []),
        'values' => $case->values,
        'resolved' => $resolved,
    ], ThemeResolverGolden::FLAGS | JSON_THROW_ON_ERROR) . "\n";
    if (json_decode($json, true, 512, JSON_THROW_ON_ERROR)['resolved'] !== $resolved) {
        fwrite(STDERR, "{$name}: the answer does not survive its JSON." . PHP_EOL);
        $failed = true;
        continue;
    }

    file_put_contents($path, $json);
    echo "{$name}\n";
}

exit($failed ? 1 : 0);
