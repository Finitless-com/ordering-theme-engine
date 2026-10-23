<?php

declare(strict_types=1);

namespace Ordering\ThemeEngine\Tests;

use Ordering\ThemeEngine\ThemeSchemaPublisher;
use PHPUnit\Framework\TestCase;

/**
 * The reference schema (meta/reference/) as api-core publishes it: 1.0.0
 * with its version 1 map, 1.1.0 against 1.0.0 and 2.0.0 against 1.1.0, the
 * last version of the previous base. test/reference.test.ts checks the same
 * in TypeScript, the meta-schema and that it uses every shape of it.
 */
class ReferenceSchemaTest extends TestCase
{
    public function testEachVersionIsValidAgainstTheOneBefore(): void
    {
        $v1_map = self::json('reference-1.0.0.v1-map.json');
        $answers = [
            ThemeSchemaPublisher::validate(self::version('1.0.0'), $v1_map),
            ThemeSchemaPublisher::validate(self::version('1.1.0'), $v1_map, self::version('1.0.0')),
            ThemeSchemaPublisher::validate(self::version('2.0.0'), $v1_map, null, false, self::version('1.1.0')),
        ];

        self::assertSame([ [ null, [], [] ], [ null, [], [] ], [ null, [], [] ] ], array_map(fn (array $answer) => [ $answer['code'], $answer['errors'], $answer['warnings'] ], $answers));
        self::assertSame([ 'tokens.color.favorite', 'components.button.base.icon_gap', 'visibility.product.navigation', 'pages.home.sections.recent', 'site.contact_email' ], $answers[1]['changes']['migrated']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function version(string $version): array
    {
        return self::json("reference-{$version}.json");
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $file): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/meta/reference/' . $file), true, 512, JSON_THROW_ON_ERROR);
    }
}
