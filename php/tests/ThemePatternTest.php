<?php

declare(strict_types=1);

namespace Ordering\ThemeEngine\Tests;

use Ordering\ThemeEngine\ThemePattern;
use Ordering\ThemeEngine\ThemeValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A string's `pattern` rule is a regular expression in the subset PCRE and
 * ECMAScript read alike, so the API and the editor agree on every value.
 * The same cases as test/pattern.test.ts.
 */
class ThemePatternTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function accepted(): array
    {
        return array_combine($list = [
            '^\\d{6,12}$',
            '^[a-zA-Z][\\w]*(\\.[a-zA-Z][\\w]*)+$',
            '^(?:https?)://\\w+',
            'a|b|',
            '()',
            '^(?=.*\\d)(?!.*x).{8,}$',
            '[-a-z]',
            '[a-z-]',
            '[^-]',
            '[\\-\\]\\\\]',
            '[\\--/]',
            'a{2,}?',
            'x*?y+?z??',
            'a{0,65535}',
            '\\/path',
            '[\\n\\r\\t]',
            '[\\t-\\r]',
            '[!-~]',
            '[{}()|.*+?$^]',
            '\\[\\]\\{\\}\\(\\)\\|\\^\\$\\.\\?\\*\\+\\\\',
                        '\\D\\W[\\d\\w]',
            'ñ+ü',
            '^a$b^',
        ], array_map(fn ($pattern) => [ $pattern ], $list));
    }

    /** @return array<string, array{string}> */
    public static function rejected(): array
    {
        return array_combine($list = [
            // \s is whitespace by a different list in each (U+0085 only in PHP, U+FEFF only in ECMAScript).
            '\\s', '\\S', '[\\s]',
            // Word boundaries, by a \w that is Unicode in PHP and ASCII in ECMAScript.
            '\\b', '\\B', 'a\\bb',
            // PCRE's own escapes.
            '\\A', '\\z', '\\Z', '\\G', '\\K', '\\Qa\\E', '\\h', '\\v', '\\R', '\\X', '\\e', '\\0',
            // Properties and character codes.
            '\\p{L}', '\\P{L}', '[\\p{L}]', '\\x41', '\\x{41}', '\\u0041', '\\u{41}', '\\cA',
            // Backreferences.
            '(a)\\1', '(?<n>a)\\k<n>',
            // `\-` outside a class, which ECMAScript rejects.
            'a\\-b',
            // A lone `]`, `{` or `}`, which PCRE takes as a literal.
            'a]', 'a{', 'a}', '{2}', 'a{,3}', 'a{x}', 'a{1,2', 'a{1, 2}', 'a{3,2}', 'a{65536}', 'a{99999999999999999999}',
            // Groups other than capturing, non-capturing and lookahead.
            '(?<=a)b', '(?<!a)b', '(?<n>a)', '(?P<n>a)', '(?i)a', '(?i:a)', '(?>a)', '(?(1)a|b)', '(?#c)', '(?|a)', '(?',
            // Unbalanced parentheses.
            '(a', 'a)', '((a)', '(a))',
            // Quantifiers: possessive, repeated, with nothing to repeat or over an assertion.
            'a++', 'a*+', 'a?+', 'a{2}+', 'a**', 'a{2}{3}', '*a', 'a|*b', '(+a)', '^*', '$?', '(?=a)*', '(?!a){2}',
            // Classes.
            '[]', '[^]', '[]a]', '[a', '[[:alpha:]]', '[a[]', '[\\b]', '[\\B]', '[\\D]', '[^\\W]', '[a-\\d]', '[\\d-z]', '[\\w-a]', '[z-a]',
            '[a-z-0]', '[a-c-e]', '[a--]', '[\\x41]', '[\\q]',
            // A trailing backslash and control characters.
            'a\\', "a\x01b", "a\0b", "a\nb", "[\t]", "a\x7f",
        ], array_map(fn ($pattern) => [ $pattern ], $list));
    }

    #[DataProvider('accepted')]
    public function testAPatternOfTheSubsetIsAValidRule(string $pattern): void
    {
        self::assertNotNull(ThemePattern::source($pattern));
        self::assertTrue(ThemeValidator::validRules('string', [ 'pattern' => $pattern ]));
    }

    #[DataProvider('rejected')]
    public function testAPatternOutsideTheSubsetIsNoValidRuleAndNoValueMatchesIt(string $pattern): void
    {
        self::assertNull(ThemePattern::source($pattern));
        self::assertFalse(ThemeValidator::validRules('string', [ 'pattern' => $pattern ]));
        self::assertFalse(ThemePattern::matches($pattern, 'a'));
    }

    public function testAPatternThatIsNotUtf8IsOutsideTheSubset(): void
    {
        self::assertNull(ThemePattern::source("a\xffb"));
    }

    public function testDollarMatchesOnlyAtTheVeryEnd(): void
    {
        self::assertTrue(ThemePattern::matches('^\\d{6,12}$', '123456'));
        self::assertFalse(ThemePattern::matches('^\\d{6,12}$', "123456\n"));
        self::assertFalse(ThemePattern::matches('^\\d{6,12}$', "123456\n7"));
        self::assertSame('invalid', ThemeValidator::value('string', "123456\n", null, [ 'pattern' => '^\\d{6,12}$' ]));
        self::assertNull(ThemeValidator::value('string', '123456', null, [ 'pattern' => '^\\d{6,12}$' ]));
    }

    public function testDotMatchesWhatItMatchesInEcmaScript(): void
    {
        self::assertSame("a[^\\n\\r\u{2028}\u{2029}]b", ThemePattern::source('a.b'));
        self::assertSame('a[.]b\\.', ThemePattern::source('a[.]b\\.'));
        self::assertTrue(ThemePattern::matches('^.$', 'a'));
        self::assertTrue(ThemePattern::matches('^.$', 'ñ'));
        self::assertTrue(ThemePattern::matches('^.$', "\t"));
        foreach ([ "\n", "\r", "\u{2028}", "\u{2029}" ] as $line_break) {
            self::assertFalse(ThemePattern::matches('^.$', $line_break));
        }
    }

    public function testClassesAndQuantifiersMatchAsWritten(): void
    {
        self::assertTrue(ThemePattern::matches('^[^-]+$', 'abc'));
        self::assertFalse(ThemePattern::matches('^[^-]+$', 'a-c'));
        self::assertTrue(ThemePattern::matches('^[\\--/]$', '.'));
        self::assertTrue(ThemePattern::matches('^a{2,}?$', 'aaa'));
        self::assertFalse(ThemePattern::matches('^a{2}$', 'aaa'));
        self::assertTrue(ThemePattern::matches('^[a-zA-Z][\\w]*(\\.[a-zA-Z][\\w]*)+$', 'com.ordering.app'));
        self::assertFalse(ThemePattern::matches('^[a-zA-Z][\\w]*(\\.[a-zA-Z][\\w]*)+$', 'ordering'));
    }

    public function testDigitsAndWordCharactersAreAsciiAsInEcmaScript(): void
    {
        // PHP's u modifier alone makes them Unicode.
        self::assertSame(1, preg_match('/^\\d$/u', '٣'));
        self::assertSame('[0-9][^0-9][A-Za-z0-9_][^A-Za-z0-9_]', ThemePattern::source('\\d\\D\\w\\W'));
        self::assertSame('[0-9][^A-Za-z0-9_-]', ThemePattern::source('[\\d][^\\w-]'));
        self::assertFalse(ThemePattern::matches('^\\w$', 'ñ'));
        self::assertTrue(ThemePattern::matches('^\\W$', 'ñ'));
        self::assertFalse(ThemePattern::matches('^\\d$', '٣'));
        self::assertFalse(ThemePattern::matches('^[\\d]$', '٣'));
        self::assertFalse(ThemePattern::matches('^\\d{6,12}$', '٣٣٣٣٣٣'));
    }
}
