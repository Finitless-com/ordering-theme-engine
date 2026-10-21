<?php

namespace Ordering\ThemeEngine;

/**
 * What every version 2 theme shares, whatever its product type
 * (api-core's docs/THEMES_V2_PLAN.md §2.2): the names and kinds of the tokens, the font
 * catalog and the kinds a component property may have (spacing is the
 * layout's, not the theme's: it stays in each product's code). Each product type's
 * schema (ThemeSchemas) gives the defaults and its own components,
 * visibility, images and content.
 */
final class ThemeFormat
{
    public const VERSION = 2;

    /**
     * The font roles: titles and text.
     */
    public const FONT_ROLES = [ 'heading', 'body' ];

    /**
     * The typography styles, by the font role they use.
     */
    public const TYPOGRAPHY = [
        'heading' => [ 'display', 'h1', 'h2', 'h3', 'h4' ],
        'body' => [ 'body_lg', 'body', 'body_sm', 'label', 'caption', 'button', 'overline' ],
    ];

    /**
     * The token groups whose names each schema declares for itself (its
     * palette, its text styles and its opacities), among the names of the
     * catalog (self::tokenNames()); every other group is a scale every
     * schema has whole.
     */
    public const OWN_GROUPS = [ 'color', 'typography', 'opacity' ];

    /**
     * Every color token a schema version names, the ones a later version
     * dropped included, since themes saved with an earlier one keep them.
     */
    public const COLORS = [
        'primary', 'on_primary', 'primary_subtle', 'primary_pressed', 'primary_text',
        'secondary', 'on_secondary', 'secondary_subtle', 'secondary_pressed',
        'background', 'surface', 'surface_alt', 'surface_raised', 'overlay', 'on_overlay', 'inverse_surface', 'on_inverse',
        'text_heading', 'text', 'text_muted', 'text_disabled', 'link',
        'border', 'border_strong', 'divider', 'focus',
        'success', 'on_success', 'success_subtle', 'success_text',
        'warning', 'on_warning', 'warning_subtle', 'warning_text',
        'danger', 'on_danger', 'danger_subtle', 'danger_text',
        'info', 'on_info', 'info_subtle',
        'disabled', 'rating', 'favorite',
    ];

    /**
     * Levels of rounding, from none to full: the editor names them, and a
     * component only refers to a level.
     */
    public const RADIUS = [ 'none', 'xs', 'sm', 'md', 'lg', 'xl', 'xxl', 'full' ];

    /**
     * Heights of the controls.
     */
    public const SIZE = [ 'sm', 'md', 'lg', 'xl' ];

    /**
     * Sizes of the icons, and of the controls drawn like one (a radio's
     * circle, a checkbox's box).
     */
    public const ICON = [ 'sm', 'md', 'lg' ];

    /**
     * Spacing: paddings, gaps and insets.
     */
    public const SPACE = [ 'none', 'xxs', 'xs', 'sm', 'md', 'lg', 'xl', 'xxl', 'xxxl' ];

    /**
     * The page layout: the gutter beside the content, a measure, and the
     * mode the product's pages follow (self::LAYOUT_MODES).
     */
    public const LAYOUT = [ 'page_gutter', 'mode' ];

    /**
     * `appointments`: the listing, orders and favorites read as bookings.
     */
    public const LAYOUT_MODES = [ 'original', 'appointments' ];

    /**
     * Durations of the animations, in milliseconds.
     */
    public const MOTION = [ 'fast', 'base', 'slow' ];

    public const EASINGS = [ 'linear', 'ease', 'ease_in', 'ease_out', 'ease_in_out' ];

    /**
     * What a slot of a group may choose of its component, by the section
     * that holds the choices: a size, a variant, an appearance, a color, a
     * shape.
     */
    public const SLOT_AXES = [ 'size' => 'sizes', 'variant' => 'variants', 'appearance' => 'appearances', 'color' => 'colors', 'shape' => 'shapes' ];

    public const BORDER = [ 'thin', 'thick' ];

    public const SHADOW = [ 'none', 'sm', 'md', 'lg', 'xl' ];

    /**
     * `scrim`: the loading veil over the page (the page's background at
     * this opacity); the veil behind a modal is `color.overlay`.
     */
    public const OPACITY = [ 'disabled', 'pressed', 'scrim' ];

    /**
     * Each token group and the kind of its values.
     */
    public const TOKENS = [
        'color' => 'color',
        'radius' => 'number',
        'size' => 'number',
        'icon' => 'number',
        'space' => 'number',
        'layout' => 'number',
        'motion' => 'number',
        'border' => 'number',
        'shadow' => 'shadow',
        'opacity' => 'opacity',
    ];

    /**
     * The font catalog: id => family. Every base bundles these files, with
     * the weights its typography uses (§2.5).
     */
    public const FONTS = [
        'archivo' => 'Archivo',
        'inter' => 'Inter',
        'lato' => 'Lato',
        'montserrat' => 'Montserrat',
        'nunito' => 'Nunito',
        'open_sans' => 'Open Sans',
        'playfair_display' => 'Playfair Display',
        'poppins' => 'Poppins',
        'raleway' => 'Raleway',
        'roboto' => 'Roboto',
    ];

    public const WEIGHTS = [ 100, 200, 300, 400, 500, 600, 700, 800, 900 ];

    /**
     * A typography's line height, a ratio of its size (unitless, as CSS's
     * `line-height: 1.5`).
     */
    public const LINE_MIN = 0.8;

    public const LINE_MAX = 3;

    /** The format's range of a number (`size`, `space`, a token of a scale…). */
    public const NUMBER_RANGE = [ 'min' => 0, 'max' => 9999 ];

    /** The format's range of an opacity. */
    public const OPACITY_RANGE = [ 'min' => 0, 'max' => 1 ];

    /** The format's range of each numeric field of a typography style. */
    public const TYPOGRAPHY_RANGES = [
        'size' => [ 'min' => 0, 'max' => 200 ],
        'line' => [ 'min' => self::LINE_MIN, 'max' => self::LINE_MAX ],
        'letter_spacing' => [ 'min' => -20, 'max' => 20 ],
    ];

    /** The property kinds whose value is a number. */
    public const NUMBER_KINDS = [ 'size', 'icon', 'space', 'motion', 'number' ];

    /** The token groups whose values are numbers (`layout` but its `mode`). */
    public const NUMBER_GROUPS = [ 'radius', 'size', 'icon', 'space', 'layout', 'motion', 'border', 'scrollbar' ];

    /**
     * The kinds a component property may have, and the token group an
     * alias in it may name. `radius` and `text` take only an alias: a level
     * of rounding, a typography style.
     */
    public const PROPERTY_KINDS = [
        'color' => 'color',
        'radius' => 'radius',
        'size' => 'size',
        'icon' => 'icon',
        'space' => 'space',
        'motion' => 'motion',
        'opacity' => 'opacity',
        'number' => null,
        'text' => 'typography',
        'font_role' => null,
        'border' => null,
        'shadow' => 'shadow',
        'image' => null,
        'bool' => null,
        'option' => null,
        'layout' => null,
        // A site's settings (the `site` block): plain text, HTML the client
        // paints as it is, and a slug or a numeric id as the site keeps it.
        'string' => null,
        'html' => null,
        'id' => null,
        // A color of an appearance: a role of the color it is applied to
        // (self::ROLES), or a color.
        'role' => 'color',
    ];

    /**
     * The roles of a component's color (`colors`), which its appearances
     * (`appearances`) paint with as `{main}`, `{on}`…: `main` fills, `on`
     * is the text on it, `ink` the text and border when nothing is filled
     * (`main` unless set), `subtle` a soft background and `pressed` the
     * filled one pressed (both derived when not set; §2.2).
     */
    public const ROLES = [ 'main', 'on', 'ink', 'subtle', 'pressed' ];

    public const ROLE = '/^\{(main|on|ink|subtle|pressed)\}$/';

    /**
     * The kinds whose value is one of the property's options.
     */
    public const OPTION_KINDS = [ 'option', 'layout' ];

    /**
     * The kinds that may narrow their levels with options (a rounding that
     * is never `none`).
     */
    public const LEVEL_OPTION_KINDS = [ 'radius' ];

    public const BORDER_STYLES = [ 'solid', 'dashed', 'dotted' ];

    /**
     * The border a theme sets to have none: saving a null removes the key
     * (back to the default), so "none" is a value of its own. The API
     * serves it as null.
     */
    public const NO_BORDER = 'none';

    public const MODES = [ 'dark' ];

    public const IMAGE_MAX_LENGTH = 2048;

    public const STRING_MAX_LENGTH = 1000;

    public const HTML_MAX_LENGTH = 200000;

    public const ID_MAX_LENGTH = 255;

    /**
     * An alias: `{group.name}`.
     */
    public const ALIAS = '/^\{([a-z_]+)\.([a-z0-9_]+)\}$/';

    /**
     * The names of a token group.
     *
     * @return array<int, string>
     */
    public static function tokenNames(string $group): array
    {
        return match ($group) {
            'color' => self::COLORS,
            'radius' => self::RADIUS,
            'size' => self::SIZE,
            'icon' => self::ICON,
            'space' => self::SPACE,
            'layout' => self::LAYOUT,
            'motion' => self::MOTION,
            'border' => self::BORDER,
            'shadow' => self::SHADOW,
            'opacity' => self::OPACITY,
            'typography' => array_merge(...array_values(self::TYPOGRAPHY)),
            'font' => self::FONT_ROLES,
            'shadow_style' => [ 'style', 'color', 'direction', 'size' ],
            'border_style' => [ 'radius', 'width', 'style' ],
            'motion_style' => [ 'easing', 'enabled' ],
            // The focus ring: a border width, a space and a color, by name.
            'focus_ring' => [ 'width', 'offset', 'color' ],
            'scrollbar' => [ 'size', 'radius' ],
            default => [],
        };
    }

    /**
     * The font role of a typography style.
     */
    public static function typographyRole(string $style): string
    {
        return in_array($style, self::TYPOGRAPHY['heading'], true) ? 'heading' : 'body';
    }

    /**
     * A hex color (#rgb, #rgba, #rrggbb, #rrggbbaa), rgb() / rgba(), or
     * `transparent`: what both CSS and React Native read.
     */
    public static function isColor($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return $value === 'transparent'
            || preg_match('/^#([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value) === 1
            || preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/', $value) === 1;
    }

    /**
     * Whether a color lets what is under it show: `transparent`, or an
     * alpha below the full one (`#RGBA`, `#RRGGBBAA`, `rgba()`).
     */
    public static function isTranslucent($value): bool
    {
        if (!self::isColor($value)) {
            return false;
        }
        if ($value === 'transparent') {
            return true;
        }
        if (preg_match('/^#([0-9a-fA-F]{4}|[0-9a-fA-F]{8})$/', $value, $match) === 1) {
            return strtolower(strlen($match[1]) === 4 ? substr($match[1], 3) : substr($match[1], 6)) !== (strlen($match[1]) === 4 ? 'f' : 'ff');
        }
        if (preg_match('/,\s*(0|1|0?\.\d+)\s*\)$/', $value, $match) === 1 && str_starts_with($value, 'rgba')) {
            return (float) $match[1] < 1;
        }

        return false;
    }

    /**
     * None ('') or an http(s) URL.
     */
    public static function isImage($value): bool
    {
        if ($value === '') {
            return true;
        }

        return is_string($value)
            && strlen($value) <= self::IMAGE_MAX_LENGTH
            && preg_match('#^https?://[^\s]+$#', $value) === 1;
    }

    public static function isNumber($value): bool
    {
        return (is_int($value) || is_float($value)) && $value >= 0 && $value <= 9999;
    }

    /**
     * [group, name] of an alias, or null.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function alias($value): ?array
    {
        if (!is_string($value) || !preg_match(self::ALIAS, $value, $match)) {
            return null;
        }

        return [ $match[1], $match[2] ];
    }

    /**
     * Whether $value is a shadow: null for none, or its offsets, blur,
     * color and opacity (React Native's shadow*), plus Android's elevation.
     */
    public static function isShadow($value): bool
    {
        if ($value === null) {
            return true;
        }
        if (!is_array($value) || array_diff(array_keys($value), [ 'x', 'y', 'blur', 'spread', 'color', 'opacity', 'elevation' ]) !== []) {
            return false;
        }

        foreach ([ 'x', 'y', 'blur', 'color', 'opacity' ] as $required) {
            if (!array_key_exists($required, $value)) {
                return false;
            }
        }

        foreach ([ 'x', 'y', 'spread' ] as $offset) {
            if (array_key_exists($offset, $value) && !((is_int($value[$offset]) || is_float($value[$offset])) && abs($value[$offset]) <= 128)) {
                return false;
            }
        }

        return self::isNumber($value['blur'])
            && self::isColor($value['color'])
            && self::isOpacity($value['opacity'])
            && (!array_key_exists('elevation', $value) || (self::isNumber($value['elevation']) && $value['elevation'] <= 24));
    }

    public static function isOpacity($value): bool
    {
        return (is_int($value) || is_float($value)) && $value >= 0 && $value <= 1;
    }

    /**
     * Whether $value is a typography style: its size in px, its line height
     * a ratio of it (self::LINE_MIN to self::LINE_MAX).
     */
    public static function isTypography($value): bool
    {
        if (!is_array($value) || array_diff(array_keys($value), [ 'size', 'line', 'weight', 'letter_spacing', 'transform' ]) !== []) {
            return false;
        }

        return isset($value['size'], $value['line'], $value['weight'])
            && self::isNumber($value['size']) && $value['size'] <= 200
            && (is_int($value['line']) || is_float($value['line'])) && $value['line'] >= self::LINE_MIN && $value['line'] <= self::LINE_MAX
            && in_array($value['weight'], self::WEIGHTS, true)
            && (!array_key_exists('letter_spacing', $value) || ((is_int($value['letter_spacing']) || is_float($value['letter_spacing'])) && abs($value['letter_spacing']) <= 20))
            && (!array_key_exists('transform', $value) || in_array($value['transform'], [ 'none', 'uppercase' ], true));
    }
}
