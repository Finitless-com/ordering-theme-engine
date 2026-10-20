<?php

namespace Ordering\ThemeEngine;

/**
 * The colors a theme derives from another when it does not set them
 * (api-core's docs/THEMES_V2_PLAN.md §2.4): a product that changes its primary gets a
 * pressed and a subtle primary, and a focus color, that follow it, instead
 * of the defaults the schema made for another primary. A subtle color is a
 * tint over the page's background, so a theme that changes the background
 * also gets its subtle colors derived: the schema's were made for its
 * default background. The primary as text follows both: a theme that
 * changes the primary or the background gets it legible over that
 * background. A color the theme sets itself is never derived.
 *
 * Which colors derive from which, and how, the schema declares
 * (self::rules(), `co.ordering.derive` in the node format); the operations
 * are this class's, and the same for every product.
 */
final class ThemeColors
{
    /**
     * The derived color => [the color it follows, how].
     */
    public const DERIVED = [
        'primary_pressed' => [ 'primary', 'pressed' ],
        'primary_subtle' => [ 'primary', 'subtle' ],
        'focus' => [ 'primary', 'same' ],
        'primary_text' => [ 'primary', 'legible' ],
        'secondary_pressed' => [ 'secondary', 'pressed' ],
        'secondary_subtle' => [ 'secondary', 'subtle' ],
        'success_subtle' => [ 'success', 'subtle' ],
        'warning_subtle' => [ 'warning', 'subtle' ],
        'danger_subtle' => [ 'danger', 'subtle' ],
        'info_subtle' => [ 'info', 'subtle' ],
    ];

    /**
     * How much darker a pressed color is: what the web did with darken().
     */
    private const PRESSED_DARKEN = 0.07;

    /**
     * How much of the color a subtle one keeps over the background: what
     * the web painted as the color at 12% opacity.
     */
    private const SUBTLE_MIX = 0.12;

    /**
     * The contrast a text color keeps over the background (WCAG AA for
     * normal text), and the step of HSL lightness it moves by to get it.
     */
    public const TEXT_CONTRAST = 4.5;

    public const TEXT_STEP = 0.01;

    /**
     * The derivations of self::DERIVED as a schema declares them
     * (`co.ordering.derive` on each derived color token,
     * docs/schema-format.md): an operation over other colors,
     * named by alias.
     *
     * - `same`: the color it follows;
     * - `shade`: it, `amount` lighter (darker when negative) in HSL;
     * - `mix`: `amount` of it over `over`;
     * - `legible`: it, moved toward black or white by `step` until it keeps
     *   `contrast` over `over`.
     *
     * @return array<string, array<string, mixed>> `{ color: rule }`
     */
    public static function rules(): array
    {
        $rules = [];
        foreach (self::DERIVED as $derived => [ $source, $how ]) {
            $from = "{color.{$source}}";
            $rules[$derived] = match ($how) {
                'same' => [ 'op' => 'same', 'from' => $from ],
                'pressed' => [ 'op' => 'shade', 'from' => $from, 'amount' => -self::PRESSED_DARKEN ],
                'subtle' => [ 'op' => 'mix', 'from' => $from, 'over' => '{color.background}', 'amount' => self::SUBTLE_MIX ],
                'legible' => [ 'op' => 'legible', 'from' => $from, 'over' => '{color.background}', 'contrast' => self::TEXT_CONTRAST, 'step' => self::TEXT_STEP ],
            };
        }

        return $rules;
    }

    /**
     * A schema in tuples that declares its color derivations (`derive`,
     * self::rules()), as the node format does on each color.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function declare(array $schema): array
    {
        if (!array_key_exists('derive', $schema)) {
            // In the order of the schema's colors, as the node format lists them, and only those it has.
            $rules = self::rules();
            $order = array_keys(is_array($schema['tokens']['color'] ?? null) ? $schema['tokens']['color'] : []);
            $schema['derive'] = array_intersect_key(array_replace(array_flip($order), $rules), $rules, array_flip($order));
        }

        return $schema;
    }

    /**
     * $colors with each color its schema derives, unless the theme sets it,
     * derived again when a color it derives from is no longer its default:
     * a product that changes its primary gets a pressed and a subtle
     * primary that follow it, and one that changes the background to
     * another gets its subtle colors and its primary as text over it. While
     * nothing they derive from changes, they are the schema's defaults,
     * which a designer may have chosen by hand. A derived color counts as
     * changed for what derives from it.
     *
     * @param array<string, string> $colors the resolved colors
     * @param array<string, mixed> $set the colors the theme itself sets
     * @param array<string, mixed> $defaults the schema's colors
     * @param array<string, array<string, mixed>> $rules `{ color: rule }`, self::rules()
     * @return array<string, string>
     */
    public static function derive(array $colors, array $set, array $defaults = [], ?array $rules = null): array
    {
        // By reference: a color derived earlier in the pass counts as changed.
        $changed = function (string $name) use (&$colors, $defaults) {
            return ($colors[$name] ?? null) !== ($defaults[$name] ?? null);
        };
        foreach (self::ordered($rules ?? self::rules()) as $derived => $rule) {
            $from = self::named($rule['from'] ?? null);
            $over = self::named($rule['over'] ?? null);
            $missing = ($colors[$derived] ?? null) === null;
            $derives = match ($rule['when'] ?? 'changed') {
                'always' => true,
                'missing' => $missing,
                default => $missing || ($from !== null && $changed($from)) || ($over !== null && $changed($over)),
            };
            if (array_key_exists($derived, $set) || $from === null || !$derives) {
                continue;
            }

            $color = $colors[$from] ?? null;
            $under = $over === null ? null : ($colors[$over] ?? null);
            $value = match ($rule['op'] ?? null) {
                'same' => self::rgb($color) === null ? null : $color,
                'shade' => self::shade($color, (float) ($rule['amount'] ?? 0)),
                'mix' => self::mix($color, $under, (float) ($rule['amount'] ?? 0)),
                'legible' => self::legible($color, $under, (float) ($rule['contrast'] ?? self::TEXT_CONTRAST), (float) ($rule['step'] ?? self::TEXT_STEP)),
                default => null,
            };
            if ($value !== null) {
                $colors[$derived] = $value;
            }
        }

        return $colors;
    }

    /**
     * The rules with each one after those of the colors it derives from, so
     * a color derived from a derived one sees it already derived.
     *
     * @param array<string, array<string, mixed>> $rules
     * @return array<string, array<string, mixed>>
     */
    private static function ordered(array $rules): array
    {
        $ordered = [];
        while ($rules !== []) {
            $ready = array_filter($rules, function (array $rule) use ($rules) {
                foreach ([ $rule['from'] ?? null, $rule['over'] ?? null ] as $input) {
                    if (array_key_exists((string) self::named($input), $rules)) {
                        return false;
                    }
                }

                return true;
            });
            // A cycle: what is left, as declared.
            $ready = $ready === [] ? $rules : $ready;
            $ordered += $ready;
            $rules = array_diff_key($rules, $ready);
        }

        return $ordered;
    }

    /**
     * The color an alias `{color.name}` names, or null.
     */
    private static function named($alias): ?string
    {
        $parts = ThemeFormat::alias($alias);

        return $parts !== null && $parts[0] === 'color' ? $parts[1] : null;
    }

    /**
     * $color `amount` lighter in HSL (darker when negative), or null when it
     * is not a color.
     */
    public static function shade($color, float $amount): ?string
    {
        $rgb = self::rgb($color);

        return $rgb === null ? null : self::hex(self::darken($rgb, -$amount));
    }

    /**
     * `amount` of $color over $under (white when it is not a color), or null
     * when $color is not one.
     */
    public static function mix($color, $under, float $amount): ?string
    {
        $rgb = self::rgb($color);
        $over = self::rgb($under) ?? [ 255, 255, 255 ];

        return $rgb === null ? null : self::hex(array_map(fn (float $channel, float $below) => $channel * $amount + $below * (1 - $amount), $rgb, $over));
    }

    /**
     * The pressed color of $color (darker, as the web's darken()), or null
     * when it is not a color.
     */
    public static function pressed($color): ?string
    {
        return self::shade($color, -self::PRESSED_DARKEN);
    }

    /**
     * The subtle color of $color (12% of it over $background, or over white
     * when $background is not a color), or null when $color is not one.
     */
    public static function subtle($color, $background = null): ?string
    {
        return self::mix($color, $background, self::SUBTLE_MIX);
    }

    /**
     * $color as text over $background (white when it is not a color): the
     * color itself when it keeps a contrast of 4.5:1 over it, else darker
     * (lighter over a dark background) one step at a time until it does, or
     * as far as it goes. Null when $color is not one.
     */
    public static function legible($color, $background = null, float $contrast = self::TEXT_CONTRAST, float $step_size = self::TEXT_STEP): ?string
    {
        $rgb = self::rgb($color);
        if ($rgb === null) {
            return null;
        }
        $over = self::rgb($background) ?? [ 255, 255, 255 ];
        // Toward black over what contrasts more with black, toward white otherwise.
        $direction = self::contrast($over, [ 0, 0, 0 ]) >= self::contrast($over, [ 255, 255, 255 ]) ? 1 : -1;
        $legible = $color;
        for ($step = 1; self::contrast(self::rgb($legible), $over) < $contrast && $step * $step_size <= 1; $step++) {
            $legible = self::hex(self::darken($rgb, $direction * $step * $step_size));
        }

        return $legible;
    }

    /**
     * [r, g, b] of a hex or rgb()/rgba() color, or null.
     *
     * @return array{0: float, 1: float, 2: float}|null
     */
    public static function rgb($color): ?array
    {
        if (!is_string($color)) {
            return null;
        }
        if (preg_match('/^#([0-9a-f]{3,4})$/i', $color, $match)) {
            $digits = str_split($match[1]);

            return [ hexdec($digits[0] . $digits[0]), hexdec($digits[1] . $digits[1]), hexdec($digits[2] . $digits[2]) ];
        }
        if (preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})?$/i', $color, $match)) {
            return [ hexdec($match[1]), hexdec($match[2]), hexdec($match[3]) ];
        }
        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/', $color, $match)) {
            return [ (float) $match[1], (float) $match[2], (float) $match[3] ];
        }

        return null;
    }

    /**
     * The WCAG contrast ratio between two colors (1–21).
     *
     * @param array{0: float, 1: float, 2: float} $a
     * @param array{0: float, 1: float, 2: float} $b
     */
    private static function contrast(array $a, array $b): float
    {
        $luminance = function (array $rgb): float {
            [ $r, $g, $b ] = array_map(function (float $channel): float {
                $channel /= 255;

                return $channel <= 0.03928 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
            }, $rgb);

            return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        };
        [ $lighter, $darker ] = [ max($luminance($a), $luminance($b)), min($luminance($a), $luminance($b)) ];

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * The color with its HSL lightness lowered by $amount (-1–1; a
     * negative amount raises it).
     *
     * @param array{0: float, 1: float, 2: float} $rgb
     * @return array{0: float, 1: float, 2: float}
     */
    private static function darken(array $rgb, float $amount): array
    {
        [ $r, $g, $b ] = array_map(fn ($channel) => $channel / 255, $rgb);
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $lightness = ($max + $min) / 2;
        $hue = 0.0;
        $saturation = 0.0;
        if ($max !== $min) {
            $delta = $max - $min;
            $saturation = $lightness > 0.5 ? $delta / (2 - $max - $min) : $delta / ($max + $min);
            $hue = match (true) {
                $max === $r => ($g - $b) / $delta + ($g < $b ? 6 : 0),
                $max === $g => ($b - $r) / $delta + 2,
                default => ($r - $g) / $delta + 4,
            } / 6;
        }

        $lightness = max(0.0, min(1.0, $lightness - $amount));
        if ($saturation === 0.0) {
            return [ $lightness * 255, $lightness * 255, $lightness * 255 ];
        }

        $q = $lightness < 0.5 ? $lightness * (1 + $saturation) : $lightness + $saturation - $lightness * $saturation;
        $p = 2 * $lightness - $q;
        $channel = function (float $t) use ($p, $q): float {
            $t += $t < 0 ? 1 : ($t > 1 ? -1 : 0);
            return match (true) {
                $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
                $t < 1 / 2 => $q,
                $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
                default => $p,
            };
        };

        return [ $channel($hue + 1 / 3) * 255, $channel($hue) * 255, $channel($hue - 1 / 3) * 255 ];
    }

    /**
     * @param array{0: float, 1: float, 2: float} $rgb
     */
    private static function hex(array $rgb): string
    {
        return sprintf('#%02X%02X%02X', ...array_map(fn (float $channel) => (int) round(max(0, min(255, $channel))), $rgb));
    }
}
