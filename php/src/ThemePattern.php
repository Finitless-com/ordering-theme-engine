<?php

namespace Ordering\ThemeEngine;

/**
 * A string property's `pattern` rule: a regular expression in the subset
 * that PCRE (here and in the Ordering.co API) and ECMAScript with the `u`
 * flag (the editor) read the same way, so a value passes in both or in
 * neither. src/pattern.ts scans it with the same logic.
 *
 * The subset (anything else and self::source() answers null):
 *
 * - literal characters, except the metacharacters `\ ^ $ . | ? * + ( ) [ ] { }`
 *   (a lone `]`, `{` or `}` included) and the control characters
 *   (U+0000 to U+001F and U+007F: `\n`, `\r` and `\t` write those);
 * - the escapes `\d \D \w \W \n \r \t` and an escaped metacharacter or `/`
 *   (`\-` only inside a class, where ECMAScript accepts it);
 * - `.`;
 * - the anchors `^` and `$`, which never repeat;
 * - `( … )`, `(?: … )` and the lookaheads `(?= … )` and `(?! … )`, which
 *   never repeat; parentheses balance;
 * - `|`;
 * - the quantifiers `? * +`, `{n}`, `{n,}` and `{n,m}` (digits, n ≤ m ≤
 *   65535, PCRE's largest), optionally lazy (`?`), each after something
 *   that repeats and never after another (no possessive `++`);
 * - the classes `[ … ]` and `[^ … ]`, not empty, of literals other than
 *   `\`, `[` and `]`, the escapes above but `\D` and `\W`, and ranges
 *   whose ends are single characters (a literal or an escaped one), the
 *   first not after the last; `-` is literal first, last or escaped.
 *
 * Where the engines would still differ, self::source() writes the pattern
 * as ECMAScript reads it, which PCRE then reads the same way:
 *
 * - PHP's `u` modifier also makes `\d` and `\w` Unicode (PCRE2_UCP), while
 *   ECMAScript's are ASCII: they are written `[0-9]` and `[A-Za-z0-9_]`
 *   (`\D` and `\W` their negations), which is also why neither negation
 *   goes in a class, and why `\b` and `\B`, word boundaries by that `\w`,
 *   are outside the subset;
 * - PCRE's `.` leaves out only `\n`, ECMAScript's also `\r`, U+2028 and
 *   U+2029: it is written as that class;
 * - PCRE's `$` also matches before a final newline unless the `D` modifier
 *   is set, which self::regex() sets: `$` matches only at the very end.
 */
class ThemePattern
{
    /** What `.` matches in ECMAScript. */
    private const DOT = "[^\\n\\r\u{2028}\u{2029}]";

    /** What `\d` and `\w` match in ECMAScript, as a class's members. */
    private const DIGIT = '0-9';
    private const WORD = 'A-Za-z0-9_';

    private const META = '\\^$.|?*+()[]{}';

    /** The largest repetition PCRE compiles. */
    private const MAX_REPEAT = 65535;

    /**
     * The pattern as both engines read it alike (`.`, `\d`, `\D`, `\w` and
     * `\W` written as the classes ECMAScript reads), or null when it is
     * outside the subset.
     */
    public static function source(string $pattern): ?string
    {
        if (!mb_check_encoding($pattern, 'UTF-8')) {
            return null;
        }
        $chars = mb_str_split($pattern, 1, 'UTF-8');
        $count = count($chars);
        $source = '';
        // What the last token was: `none` (the start, after `(` or `|`), `atom` (it repeats), `fixed` (it does not) or `quantifier`.
        $last = 'none';
        // Each open group, true when it is a lookahead.
        $groups = [];

        for ($i = 0; $i < $count;) {
            $char = $chars[$i];
            if ($char === '\\') {
                $escaped = $chars[$i + 1] ?? null;
                if ($escaped === null) {
                    return null;
                }
                $written = match (true) {
                    $escaped === 'd' => '[' . self::DIGIT . ']',
                    $escaped === 'D' => '[^' . self::DIGIT . ']',
                    $escaped === 'w' => '[' . self::WORD . ']',
                    $escaped === 'W' => '[^' . self::WORD . ']',
                    str_contains('nrt/', $escaped) || str_contains(self::META, $escaped) => $char . $escaped,
                    default => null,
                };
                if ($written === null) {
                    return null;
                }
                $last = 'atom';
                $source .= $written;
                $i += 2;
            } elseif ($char === '^' || $char === '$') {
                $last = 'fixed';
                $source .= $char;
                $i++;
            } elseif ($char === '.') {
                $last = 'atom';
                $source .= self::DOT;
                $i++;
            } elseif ($char === '|') {
                $last = 'none';
                $source .= $char;
                $i++;
            } elseif ($char === '(') {
                $open = '(';
                $lookahead = false;
                if (($chars[$i + 1] ?? null) === '?') {
                    $kind = $chars[$i + 2] ?? null;
                    if ($kind !== ':' && $kind !== '=' && $kind !== '!') {
                        return null;
                    }
                    $open .= '?' . $kind;
                    $lookahead = $kind !== ':';
                }
                $groups[] = $lookahead;
                $last = 'none';
                $source .= $open;
                $i += strlen($open);
            } elseif ($char === ')') {
                if ($groups === []) {
                    return null;
                }
                $last = array_pop($groups) ? 'fixed' : 'atom';
                $source .= $char;
                $i++;
            } elseif ($char === '?' || $char === '*' || $char === '+' || $char === '{') {
                $length = $char === '{' ? self::bounds($chars, $i) : 1;
                if ($length === null || $last !== 'atom') {
                    return null;
                }
                if (($chars[$i + $length] ?? null) === '?') {
                    $length++;
                }
                $last = 'quantifier';
                $source .= implode('', array_slice($chars, $i, $length));
                $i += $length;
            } elseif ($char === '[') {
                $written = self::characterClass($chars, $i);
                if ($written === null) {
                    return null;
                }
                $last = 'atom';
                $source .= $written;
            } elseif ($char === ']' || $char === '}' || self::isControl($char)) {
                return null;
            } else {
                $last = 'atom';
                $source .= $char;
                $i++;
            }
        }

        return $groups === [] ? $source : null;
    }

    /**
     * Whether a value matches a pattern of the subset (never one outside
     * it).
     */
    public static function matches(string $pattern, string $value): bool
    {
        $source = self::source($pattern);

        return $source !== null && preg_match(self::regex($source), $value) === 1;
    }

    /**
     * What self::source() answers as PCRE reads it: delimited by a control
     * character, which no pattern of the subset has, in UTF-8 (`u`) and
     * with `$` only at the very end (`D`), as in ECMAScript.
     */
    public static function regex(string $source): string
    {
        return "\x01{$source}\x01uD";
    }

    /**
     * The length of the quantifier `{n}`, `{n,}` or `{n,m}` at $start, or
     * null when the `{` starts none.
     *
     * @param list<string> $chars
     */
    private static function bounds(array $chars, int $start): ?int
    {
        $i = $start + 1;
        $min = self::number($chars, $i);
        if ($min === null) {
            return null;
        }
        $max = $min;
        if (($chars[$i] ?? null) === ',') {
            $i++;
            $max = ($chars[$i] ?? null) === '}' ? self::MAX_REPEAT : self::number($chars, $i);
        }
        if ($max === null || $min > $max || ($chars[$i] ?? null) !== '}') {
            return null;
        }

        return $i + 1 - $start;
    }

    /**
     * The decimal number at $i, which moves past it, or null when there is
     * none or it is above self::MAX_REPEAT.
     *
     * @param list<string> $chars
     */
    private static function number(array $chars, int &$i): ?int
    {
        $start = $i;
        $value = 0;
        while (isset($chars[$i]) && str_contains('0123456789', $chars[$i])) {
            $value = $value * 10 + (int) $chars[$i];
            if ($value > self::MAX_REPEAT) {
                return null;
            }
            $i++;
        }

        return $i > $start ? $value : null;
    }

    /**
     * The class at $i as it is written in the source, $i moved past it, or
     * null when it is outside the subset.
     *
     * @param list<string> $chars
     */
    private static function characterClass(array $chars, int &$i): ?string
    {
        $written = '[';
        $i++;
        if (($chars[$i] ?? null) === '^') {
            $written .= '^';
            $i++;
        }
        $members = 0;
        while (true) {
            $char = $chars[$i] ?? null;
            if ($char === null) {
                return null;
            }
            if ($char === ']') {
                $i++;

                return $members === 0 ? null : "{$written}]";
            }
            if ($char === '-') {
                // A literal `-` only first or last.
                if ($members > 0 && ($chars[$i + 1] ?? null) !== ']') {
                    return null;
                }
                $written .= $char;
                $i++;
                $members++;
                continue;
            }
            $from = self::classMember($chars, $i, $written);
            if ($from === false) {
                return null;
            }
            if (($chars[$i] ?? null) === '-' && ($chars[$i + 1] ?? null) !== ']') {
                $written .= '-';
                $i++;
                $to = ($chars[$i] ?? null) === '-' ? false : self::classMember($chars, $i, $written);
                if ($from === null || $to === null || $to === false || $from > $to) {
                    return null;
                }
            }
            $members++;
        }
    }

    /**
     * The code point of the class member at $i, $i moved past it and the
     * member added to $written: null for `\d` and `\w`, which are no single
     * character, and false when it is outside the subset (`\`, `[`, a
     * control character or another escape).
     *
     * @param list<string> $chars
     */
    private static function classMember(array $chars, int &$i, string &$written): int|false|null
    {
        $char = $chars[$i] ?? null;
        if ($char === null || $char === '[' || self::isControl($char)) {
            return false;
        }
        if ($char !== '\\') {
            $written .= $char;
            $i++;

            return mb_ord($char, 'UTF-8');
        }
        $escaped = $chars[$i + 1] ?? null;
        $i += 2;
        if ($escaped === 'd' || $escaped === 'w') {
            $written .= $escaped === 'd' ? self::DIGIT : self::WORD;

            return null;
        }
        $code = match (true) {
            $escaped === null => false,
            $escaped === 'n' => 10,
            $escaped === 'r' => 13,
            $escaped === 't' => 9,
            $escaped === '/' || $escaped === '-' || str_contains(self::META, $escaped) => mb_ord($escaped, 'UTF-8'),
            default => false,
        };
        if ($code !== false) {
            $written .= $char . $escaped;
        }

        return $code;
    }

    private static function isControl(string $char): bool
    {
        return strlen($char) === 1 && (ord($char) < 0x20 || ord($char) === 0x7f);
    }
}
