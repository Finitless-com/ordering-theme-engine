<?php

namespace Ordering\ThemeEngine\Schema;

use Ordering\ThemeEngine\ThemeFormat;
use Ordering\ThemeEngine\ThemeShadows;

/**
 * The node format of a theme schema (docs/schema-format.md): each
 * value the schema declares is a node shaped like a DTCG design token,
 * `{ "$type", "$value", "$extensions" }`, instead of the positional tuple
 * `[kind, default, options]` of api-core's docs/THEMES_V2_PLAN.md §2.2.
 *
 * A product repository writes and publishes this format; NodeCompiler turns
 * it back into the tuple form the rest of the engine reads, so changing the
 * format changes nothing that a client receives.
 */
final class NodeFormat
{
    /**
     * The `$schema` of a schema written in this format.
     */
    public const ID = 'urn:ordering:theme-schema:1';

    /**
     * What the engine reads from `$extensions`: `scale`, `options`,
     * `inherits`, `formats` and `hints`.
     */
    public const EXTENSION = 'co.ordering';

    /**
     * What only the editor reads (api-core's docs/THEMES_V2_PLAN.md §6.3.2).
     */
    public const EDITOR = 'co.ordering.editor';

    /**
     * At the root, the renames and moves of a theme's values, `[{ from, to }]`
     * (ThemeMigrations, api-core's docs/THEMES_V2_PLAN.md §6.4.2).
     */
    public const MIGRATE = 'co.ordering.migrate';

    /**
     * At the root of a base's first version, how a theme of the previous
     * base takes its values, `{ from, moves, drop, rewrite? }` (ThemeUpgrades).
     */
    public const UPGRADE = 'co.ordering.upgrade';

    /**
     * The tokens whose options a schema may narrow to some of the ones
     * self::token() gives (the focus ring's colors and distances its editor
     * offers): a theme then takes only those (ThemeValidator).
     */
    public const NARROWABLE = [ 'focus_ring.color', 'focus_ring.offset' ];

    /**
     * The type a group's tokens had before, which a schema published with
     * it may still declare.
     */
    public const FORMER_TYPES = [ 'scrollbar' => 'number' ];

    /**
     * Each property kind as its node type, and, for a dimension, the token
     * group its levels come from. A kind and its pair map one to one.
     */
    public const KINDS = [
        'color' => [ 'color', null ],
        'role' => [ 'role', null ],
        'radius' => [ 'dimension', 'radius' ],
        'size' => [ 'dimension', 'size' ],
        'icon' => [ 'dimension', 'icon' ],
        'space' => [ 'dimension', 'space' ],
        'motion' => [ 'duration', null ],
        'opacity' => [ 'opacity', null ],
        'number' => [ 'number', null ],
        'text' => [ 'typography', null ],
        'font_role' => [ 'fontRole', null ],
        'border' => [ 'border', null ],
        'shadow' => [ 'shadow', null ],
        'image' => [ 'image', null ],
        'bool' => [ 'boolean', null ],
        'option' => [ 'option', null ],
        'layout' => [ 'layout', null ],
        'sections' => [ 'sections', null ],
        'string' => [ 'string', null ],
        'html' => [ 'html', null ],
        'id' => [ 'id', null ],
    ];

    /**
     * The property kind of a node type and scale, or null when the pair is
     * not one of self::KINDS.
     */
    public static function kindOf(string $type, ?string $scale): ?string
    {
        foreach (self::KINDS as $kind => [ $kind_type, $kind_scale ]) {
            if ($kind_type === $type && $kind_scale === $scale) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * The node type of a token and its closed options, as ThemeValidator
     * validates it today. The schema declares them so that it describes
     * itself; NodeCompiler refuses a schema that declares anything else. The
     * focus ring's color is one of $colors, the schema's own colors.
     *
     * @param array<int, string> $colors
     * @return array{0: string, 1: array<int, mixed>|null}|null
     */
    public static function token(string $group, string $name, array $colors = ThemeFormat::COLORS): ?array
    {
        if (!in_array($name, ThemeFormat::tokenNames($group), true)) {
            return null;
        }

        return match ($group) {
            'font' => [ 'font', array_keys(ThemeFormat::FONTS) ],
            'typography' => [ 'typography', null ],
            'color' => [ 'color', null ],
            'radius', 'size', 'icon', 'space', 'border' => [ 'dimension', null ],
            'layout' => $name === 'mode' ? [ 'option', ThemeFormat::LAYOUT_MODES ] : [ 'dimension', null ],
            'motion' => [ 'duration', null ],
            'shadow' => [ 'shadow', null ],
            'opacity' => [ 'opacity', null ],
            'shadow_style' => match ($name) {
                'style' => [ 'option', ThemeShadows::STYLES ],
                'direction' => [ 'option', ThemeShadows::DIRECTIONS ],
                'size' => [ 'option', ThemeFormat::SHADOW ],
                default => [ 'color', null ],
            },
            'border_style' => match ($name) {
                'radius' => [ 'option', ThemeFormat::RADIUS ],
                'width' => [ 'option', array_merge([ ThemeFormat::NO_BORDER ], ThemeFormat::BORDER) ],
                default => [ 'option', ThemeFormat::BORDER_STYLES ],
            },
            'motion_style' => $name === 'easing' ? [ 'option', ThemeFormat::EASINGS ] : [ 'boolean', null ],
            'focus_ring' => match ($name) {
                'width' => [ 'option', ThemeFormat::BORDER ],
                'offset' => [ 'option', ThemeFormat::SPACE ],
                default => [ 'option', $colors ],
            },
            'scrollbar' => [ 'dimension', null ],
            default => null,
        };
    }

    /**
     * The numeric limits an editor block sets on a value: its `min` and
     * `max`, and those of each of its `fields` (a typography's size, line
     * height…), only the numbers. Empty when it sets none.
     *
     * @param mixed $editor
     * @return array{min?: int|float, max?: int|float, fields?: array<string, array{min?: int|float, max?: int|float}>}
     */
    public static function limits($editor): array
    {
        if (!is_array($editor)) {
            return [];
        }
        $range = fn ($spec) => is_array($spec)
            ? array_filter([ 'min' => $spec['min'] ?? null, 'max' => $spec['max'] ?? null ], fn ($limit) => is_int($limit) || is_float($limit))
            : [];
        $limits = $range($editor);
        $fields = [];
        foreach (is_array($editor['fields'] ?? null) ? $editor['fields'] : [] as $field => $spec) {
            if (($own = $range($spec)) !== []) {
                $fields[(string) $field] = $own;
            }
        }
        if ($fields !== []) {
            $limits['fields'] = $fields;
        }

        return $limits;
    }

    /**
     * Whether a value of the node tree is a node (a leaf) rather than a group.
     */
    public static function isNode($value): bool
    {
        return is_array($value) && array_key_exists('$value', $value);
    }
}
