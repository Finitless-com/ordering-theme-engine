<?php

namespace Ordering\ThemeEngine\Schema;

/**
 * What a schema's editor metadata gets wrong (`co.ordering.editor` and the
 * root `editor`, docs/schema-format.md §12), which NodeCompiler drops and
 * ThemeSchemas::check() reads here, given the nodes (the TypeScript
 * engine's checkEditor()). The meta-schema checks its shape; these, what it
 * cannot: the colors that need `alpha`, the platforms only a visibility key
 * says, the pages and components a visibility group is shown in, the order
 * of the rows and cards, the custom typographies, the limits of the number
 * controls, the line heights' fields, the scales, the blocks of the pages,
 * the fonts' weights, the text controls, the groups of a page's sections
 * and the type of the visibility keys. What check() already refuses is not
 * repeated.
 */
final class EditorRules
{
    /**
     * The blocks of nodes the editor shows with their own control.
     */
    private const BLOCKS = [ 'tokens', 'components', 'pages', 'site' ];

    private const ALIAS = '/^\{([a-z_]+)\.([a-z0-9_]+)\}$/D';

    private const SNAKE_CASE = '/^[a-z][a-z0-9_]*$/D';

    /**
     * The range a typography's `line` takes: a ratio of its size (ThemeFormat).
     */
    private const LINE_RATIO = [ 0.8, 3 ];

    /**
     * Each error once, in the order of the rules above.
     *
     * @param array<string, mixed> $schema in nodes
     * @return array<int, array{path: string, code: string}>
     */
    public static function check(array $schema): array
    {
        $errors = [];
        $seen = [];
        $fail = function (string $path, string $rule) use (&$errors, &$seen) {
            $code = "{$path}.{$rule}";
            if (!isset($seen[$code])) {
                $seen[$code] = true;
                $errors[] = [ 'path' => $path, 'code' => $code ];
            }
        };
        self::alpha($schema, $fail);
        self::platforms($schema, $fail);
        self::editorPages($schema, $fail);
        self::editorComponents($schema, $fail);
        self::componentOrder($schema, $fail);
        self::rowOrder($schema, $fail);
        self::custom($schema, $fail);
        self::limits($schema, $fail);
        self::lines($schema, $fail);
        self::ordered($schema, $fail);
        self::sectionGroups($schema, $fail);
        self::fonts($schema, $fail);
        self::texts($schema, $fail);
        self::pageSections($schema, $fail);
        self::visibilityTypes($schema, $fail);

        return $errors;
    }

    /**
     * A color node (its own `$type` or its group's) whose default is
     * translucent, or an alias to a token marked `alpha` or translucent,
     * says `alpha: true`; nothing else says it.
     */
    private static function alpha(array $schema, callable $fail): void
    {
        $tokens = $schema['tokens'] ?? null;
        $marked = fn ($node) => self::get(self::editorOf($node), 'alpha') === true;
        $refers = function ($value) use ($tokens, $marked) {
            if (!is_string($value) || !preg_match(self::ALIAS, $value, $match)) {
                return false;
            }
            $token = self::get(self::get($tokens, $match[1]), $match[2]);

            return is_array($token) && ($marked($token) || self::translucent(self::get($token, '$value')));
        };
        $walk = function ($group, string $path, $type) use (&$walk, $fail, $marked, $refers) {
            $own = self::get($group, '$type') ?? $type;
            $said = self::has(self::editorOf($group), 'alpha');
            if (self::has($group, '$value')) {
                if ($own !== 'color') {
                    if ($said) {
                        $fail($path, 'alpha_not_color');
                    }
                } else if (!$marked($group) && (self::translucent($group['$value']) || $refers($group['$value']))) {
                    $fail($path, 'alpha_required');
                }

                return;
            }
            if ($said) {
                $fail($path, 'alpha_not_color');
            }
            foreach (self::children($group) as $key => $child) {
                $walk($child, "{$path}.{$key}", $own);
            }
        };
        foreach (self::BLOCKS as $block) {
            if (is_array($schema[$block] ?? null)) {
                $walk($schema[$block], $block, null);
            }
        }
    }

    /**
     * Whether a color is translucent: `transparent`, a #RGBA or #RRGGBBAA
     * whose alpha is below its maximum, or rgb()/rgba()/hsl()/hsla() with an
     * alpha (`, a` or `/ a`, a number or a percentage) below 1. An alias is
     * not.
     *
     * @param mixed $value
     */
    public static function translucent($value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $color = strtolower(trim($value));
        if ($color === 'transparent') {
            return true;
        }
        if (preg_match('/^#[0-9a-f]{3}([0-9a-f])$/D', $color, $hex) || preg_match('/^#[0-9a-f]{6}([0-9a-f]{2})$/D', $color, $hex)) {
            return hexdec($hex[1]) < (strlen($hex[1]) === 1 ? 0xf : 0xff);
        }
        if (!preg_match('/^(?:rgb|hsl)a?\((.*)\)$/D', $color, $call)) {
            return false;
        }
        $amount = str_contains($call[1], '/') ? explode('/', $call[1])[1] : (explode(',', $call[1])[3] ?? null);
        if ($amount === null || !preg_match('/^[ \t\n\r\f\x0B]*([+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?)/', $amount, $number)) {
            return false;
        }
        $ratio = (float) $number[1] / (str_ends_with(trim($amount), '%') ? 100 : 1);

        return is_finite($ratio) && $ratio < 1;
    }

    /**
     * `co.ordering.platforms` only on a visibility key: what it says there
     * is check()'s (`visibility.<key>.platforms.invalid`).
     */
    private static function platforms(array $schema, callable $fail): void
    {
        $walk = function ($group, string $path, bool $visibility) use (&$walk, $fail) {
            $own = $group['$extensions'][NodeFormat::EXTENSION] ?? null;
            if (is_array($own) && array_key_exists('platforms', $own) && (!$visibility || !self::has($group, '$value'))) {
                $fail($path, 'platforms_not_visibility');
            }
            if (self::has($group, '$value')) {
                return;
            }
            foreach (self::children($group) as $key => $child) {
                $walk($child, "{$path}.{$key}", $visibility);
            }
        };
        foreach ([ 'tokens', 'components', 'visibility', 'images', 'pages', 'site' ] as $block) {
            if (is_array($schema[$block] ?? null)) {
                $walk($schema[$block], $block, $block === 'visibility');
            }
        }
    }

    /**
     * A group of the visibility section may say the pages it is shown in,
     * pages of the schema, and no page gets two groups of the same key; no
     * other section's group says them. Every visibility key is in exactly
     * one group of the editor (a row, `paths`, or a card's switch,
     * `toggle`), and the groups list only visibility keys that exist.
     */
    private static function editorPages(array $schema, callable $fail): void
    {
        $pages = array_values(array_filter(array_map('strval', array_keys(is_array($schema['pages'] ?? null) ? $schema['pages'] : [])), fn (string $key) => !str_starts_with($key, '$')));
        $keys = [];
        $collect = function ($group, string $path) use (&$collect, &$keys) {
            if (self::has($group, '$value')) {
                $keys[] = $path;

                return;
            }
            foreach (self::children($group) as $key => $child) {
                $collect($child, "{$path}.{$key}");
            }
        };
        if (is_array($schema['visibility'] ?? null)) {
            $collect($schema['visibility'], 'visibility');
        }

        $listed = [];
        $groups_of = [];
        foreach (self::layoutGroups($schema) as [ $s, $section, $g, $group ]) {
            $path = "editor.sections.{$s}.groups.{$g}";
            $toggle = self::get(self::get($group, 'toggle'), 'path');
            foreach ([ ...self::values(self::get($group, 'paths')), ...(is_string($toggle) ? [ $toggle ] : []) ] as $key) {
                if (is_string($key) && ($key === 'visibility' || str_starts_with($key, 'visibility.'))) {
                    $listed[$key] = ($listed[$key] ?? 0) + 1;
                }
            }
            if (!array_key_exists('pages', $group)) {
                continue;
            }
            if (self::get($section, 'key') !== 'visibility') {
                $fail($path, 'pages_not_visibility');
                continue;
            }
            // Its shape (a list of names, at least one, without repeats) is the meta-schema's.
            foreach (self::unique(self::values($group['pages'])) as $page) {
                if (!is_string($page) || !in_array($page, $pages, true)) {
                    $fail($path, 'page_unknown');
                    continue;
                }
                $owned = $groups_of[$page] ?? [];
                if (in_array(self::get($group, 'key'), $owned, true)) {
                    $fail($path, 'page_group_repeated');
                }
                $groups_of[$page] = [ ...$owned, self::get($group, 'key') ];
            }
        }
        foreach ($keys as $key) {
            $times = $listed[$key] ?? 0;
            if ($times !== 1) {
                $fail($key, $times === 0 ? 'in_no_editor_group' : 'in_several_editor_groups');
            }
        }
        foreach (array_keys($listed) as $key) {
            if (!in_array((string) $key, $keys, true)) {
                $fail((string) $key, 'not_visibility_key');
            }
        }
    }

    /**
     * Like `pages`, a group of the visibility section may say the
     * components whose panel shows it (never with `pages` too): components
     * of the schema, and no component gets two groups of the same key. A
     * component's own cards then list none of its paths, unless as a card's
     * switch.
     */
    private static function editorComponents(array $schema, callable $fail): void
    {
        $components = $schema['components'] ?? null;
        $keys_of = [];
        $shown = [];
        foreach (self::layoutGroups($schema) as [ $s, $section, $g, $group ]) {
            if (!array_key_exists('components', $group)) {
                continue;
            }
            $path = "editor.sections.{$s}.groups.{$g}";
            if (self::get($section, 'key') !== 'visibility') {
                $fail($path, 'components_not_visibility');
                continue;
            }
            if (array_key_exists('pages', $group)) {
                $fail($path, 'pages_and_components');
            }
            foreach (self::unique(self::values($group['components'])) as $name) {
                if (!is_string($name) || str_starts_with($name, '$') || !is_array(self::get($components, $name))) {
                    $fail($path, 'component_unknown');
                    continue;
                }
                $owned = $keys_of[$name] ?? [];
                if (in_array(self::get($group, 'key'), $owned, true)) {
                    $fail($path, 'component_group_repeated');
                }
                $keys_of[$name] = [ ...$owned, self::get($group, 'key') ];
                foreach (self::values(self::get($group, 'paths')) as $key) {
                    if (is_string($key)) {
                        $shown["{$name} {$key}"] = true;
                    }
                }
            }
        }
        foreach (is_array($components) ? $components : [] as $name => $component) {
            foreach (self::entries(self::get(self::editorOf($component), 'groups')) as $c => $card) {
                foreach (self::values(self::get($card, 'paths')) as $key) {
                    if (is_string($key) && isset($shown["{$name} {$key}"]) && self::get(self::get($card, 'toggle'), 'path') !== $key) {
                        $fail("components.{$name}.\$extensions." . NodeFormat::EDITOR . ".groups.{$c}", 'shown_by_visibility_group');
                    }
                }
            }
        }
    }

    /**
     * The order of a component's props (its `scope` aside): the nodes of
     * one group of the schema that share a card (`group`, or none: the
     * rows) have different `order`s, and in a component with cards every
     * node has one. Each card lists paths of the schema, its component's
     * nodes by their order; a base node it lists says its key as `group`,
     * and a base node that says a `group` is listed by a card.
     */
    private static function componentOrder(array $schema, callable $fail): void
    {
        foreach (self::entries($schema['components'] ?? null) as $name => $component) {
            if (!is_array($component) || str_starts_with((string) $name, '$')) {
                continue;
            }
            $root = "components.{$name}";
            $cards = self::entries(self::get(self::editorOf($component), 'groups'));
            $walk = function (array $group, string $path) use (&$walk, $fail, $root, $cards) {
                $slots = [];
                foreach (self::children($group) as $key => $child) {
                    if (!array_key_exists('$value', $child)) {
                        if ((string) $key !== 'scope' || $path !== $root) {
                            $walk($child, "{$path}.{$key}");
                        }
                        continue;
                    }
                    $editor = self::editorOf($child);
                    if (!array_key_exists('order', $editor)) {
                        if ($cards !== []) {
                            $fail("{$path}.{$key}", 'order_missing');
                        }
                        continue;
                    }
                    $slot = json_encode([ self::get($editor, 'group'), $editor['order'] ]);
                    if (isset($slots[$slot])) {
                        $fail("{$path}.{$key}", 'order_repeated');
                    } else {
                        $slots[$slot] = $key;
                    }
                }
            };
            $walk($component, $root);

            $listed = [];
            foreach ($cards as $c => $card) {
                $path = "{$root}.\$extensions." . NodeFormat::EDITOR . ".groups.{$c}";
                $last = -INF;
                foreach (self::values(self::get($card, 'paths')) as $item) {
                    [ $found, $node ] = self::at($schema, $item);
                    if (!$found) {
                        $fail($path, 'path_unknown');
                        continue;
                    }
                    if (!is_string($item) || !str_starts_with($item, "{$root}.") || !self::has($node, '$value')) {
                        continue;
                    }
                    $listed[$item] = true;
                    $order = self::get(self::editorOf($node), 'order');
                    if (is_int($order) || is_float($order)) {
                        if ($order < $last) {
                            $fail($path, 'order_decreasing');
                        }
                        $last = max($last, $order);
                    }
                    if (str_starts_with($item, "{$root}.base.") && self::get(self::editorOf($node), 'group') !== self::get($card, 'key')) {
                        $fail($item, 'group_mismatch');
                    }
                }
            }
            foreach (self::entries(self::get($component, 'base')) as $key => $node) {
                $path = "{$root}.base.{$key}";
                if (self::has($node, '$value') && array_key_exists('group', self::editorOf($node)) && !isset($listed[$path])) {
                    $fail($path, 'in_no_card');
                }
            }
        }
    }

    /**
     * The rows of a group of the editor's sections: the paths that say an
     * `order` say it in the order they are listed, from 1, each the one
     * before (a pair shares its row) or the next, and the `group` they say
     * is the group's key.
     */
    private static function rowOrder(array $schema, callable $fail): void
    {
        foreach (self::layoutGroups($schema) as [ $s, , $g, $group ]) {
            $path = "editor.sections.{$s}.groups.{$g}";
            $last = 0;
            foreach (self::values(self::get($group, 'paths')) as $item) {
                $editor = self::at($schema, $item)[1]['$extensions'][NodeFormat::EDITOR] ?? null;
                $order = self::get($editor, 'order');
                if (!is_array($editor) || !(is_int($order) || is_float($order))) {
                    continue;
                }
                if ($order != $last && $order != $last + 1) {
                    $fail($path, 'order_not_consecutive');
                }
                $last = max($last, $order);
                if (array_key_exists('group', $editor) && $editor['group'] !== self::get($group, 'key')) {
                    $fail($item, 'group_mismatch');
                }
            }
        }
    }

    /**
     * `custom` only on a typography node chosen by level, and every
     * component's typography chosen by level says `custom: true`. `custom:
     * true` on another type of property is check()'s `<path>.rules.invalid`.
     */
    private static function custom(array $schema, callable $fail): void
    {
        $walk = function ($group, string $path, $type, string $block) use (&$walk, $fail) {
            $own = self::get($group, '$type') ?? $type;
            $editor = self::editorOf($group);
            $said = array_key_exists('custom', $editor);
            if (!self::has($group, '$value')) {
                if ($said) {
                    $fail($path, 'custom_not_level');
                }
                foreach (self::children($group) as $key => $child) {
                    $walk($child, "{$path}.{$key}", $own, $block);
                }

                return;
            }
            $level = $own === 'typography' && self::get($editor, 'control') === 'level';
            if ($said && !$level) {
                if (!($editor['custom'] === true && self::property($path, $own))) {
                    $fail($path, 'custom_not_level');
                }
            } else if ($level && $block === 'components' && self::get($editor, 'custom') !== true) {
                $fail($path, 'custom_required');
            }
        };
        foreach (self::BLOCKS as $block) {
            if (is_array($schema[$block] ?? null)) {
                $walk($schema[$block], $block, null, $block);
            }
        }
    }

    /**
     * The limits of the number controls. A field of a composite default (a
     * typography style's) has its `min` at most its `max`, and the default's
     * number within them. A `range`, and a `level` that takes a custom
     * number (it says a `unit`), says a `min` at most its `max`; a default
     * outside them is ThemeSchemaPublisher::outOfRange()'s.
     */
    private static function limits(array $schema, callable $fail): void
    {
        $number = fn ($value) => is_int($value) || is_float($value);
        $walk = function ($group, string $path) use (&$walk, $fail, $number) {
            if (!self::has($group, '$value')) {
                foreach (self::children($group) as $key => $child) {
                    $walk($child, "{$path}.{$key}");
                }

                return;
            }
            $editor = self::editorOf($group);
            $value = $group['$value'];
            if (is_array($value)) {
                foreach (self::entries(self::get($editor, 'fields')) as $name => $field) {
                    $default = $value[$name] ?? null;
                    if (!is_array($field) || !$number($default)) {
                        continue;
                    }
                    $min = $field['min'] ?? null;
                    $max = $field['max'] ?? null;
                    if ($number($min) && $number($max) && $min > $max) {
                        $fail("{$path}.fields.{$name}", 'min_over_max');
                    }
                    if (($number($min) && $default < $min) || ($number($max) && $default > $max)) {
                        $fail("{$path}.fields.{$name}", 'default_out_of_range');
                    }
                }
            }
            $control = self::get($editor, 'control');
            $unit = self::get($editor, 'unit');
            if ($control !== 'range' && !($control === 'level' && is_string($unit) && $unit !== '')) {
                return;
            }
            $min = self::get($editor, 'min');
            $max = self::get($editor, 'max');
            if (!$number($min) || !$number($max)) {
                $fail($path, 'limits_required');
            } else if ($min > $max) {
                $fail($path, 'min_over_max');
            }
        };
        foreach (self::BLOCKS as $block) {
            if (is_array($schema[$block] ?? null)) {
                $walk($schema[$block], $block);
            }
        }
    }

    /**
     * The `line` field of a typography's editor (a level's, the group's, a
     * component's) is a ratio of the size: unit `x`, its `min` and `max`
     * within 0.8 and 3. The typography group's fields, the custom
     * typography's, are at least as wide as each level's. A default's
     * `line` is ThemeFormat's.
     */
    private static function lines(array $schema, callable $fail): void
    {
        [ $low, $high ] = self::LINE_RATIO;
        $ratio = fn ($value) => (is_int($value) || is_float($value)) && is_finite($value) && $value >= $low && $value <= $high;
        $walk = function ($group, string $path, $type) use (&$walk, $fail, $ratio) {
            $own = self::get($group, '$type') ?? $type;
            $field = self::get(self::get(self::editorOf($group), 'fields'), 'line');
            if ($own === 'typography' && is_array($field)) {
                if (self::get($field, 'unit') !== 'x') {
                    $fail("{$path}.fields.line", 'unit_not_x');
                }
                if (!$ratio(self::get($field, 'min')) || !$ratio(self::get($field, 'max'))) {
                    $fail("{$path}.fields.line", 'limits_not_ratio');
                }
            }
            if (self::has($group, '$value')) {
                return;
            }
            foreach (self::children($group) as $key => $child) {
                $walk($child, "{$path}.{$key}", $own);
            }
        };
        foreach (self::BLOCKS as $block) {
            if (is_array($schema[$block] ?? null)) {
                $walk($schema[$block], $block, null);
            }
        }

        $typography = $schema['tokens']['typography'] ?? null;
        $widest = self::get(self::editorOf($typography), 'fields');
        if (!is_array($typography) || !is_array($widest)) {
            return;
        }
        $number = fn ($value) => is_int($value) || is_float($value);
        foreach ($typography as $level => $node) {
            if (str_starts_with((string) $level, '$') || !is_array($node)) {
                continue;
            }
            foreach (self::entries(self::get(self::editorOf($node), 'fields')) as $name => $field) {
                $own = $widest[$name] ?? null;
                if (!is_array($field) || !is_array($own)) {
                    continue;
                }
                [ $min, $max, $own_min, $own_max ] = [ $field['min'] ?? null, $field['max'] ?? null, $own['min'] ?? null, $own['max'] ?? null ];
                if ($number($min) && $number($own_min) && $own_min > $min) {
                    $fail("tokens.typography.fields.{$name}", 'min_not_widest');
                }
                if ($number($max) && $number($own_max) && $own_max < $max) {
                    $fail("tokens.typography.fields.{$name}", 'max_not_widest');
                }
            }
        }
    }

    /**
     * A group of the editor (a section's or a component's card) that says
     * `ordered` lists nodes whose defaults (a number, or a typography's
     * size) are each at least (`ascending`) or at most (`descending`) the
     * one before.
     */
    private static function ordered(array $schema, callable $fail): void
    {
        $groups = array_map(fn (array $entry) => [ "editor.sections.{$entry[0]}.groups.{$entry[2]}", $entry[3] ], self::layoutGroups($schema));
        foreach (self::entries($schema['components'] ?? null) as $name => $component) {
            foreach (self::entries(self::get(self::editorOf($component), 'groups')) as $c => $card) {
                if (is_array($card)) {
                    $groups[] = [ "components.{$name}.\$extensions." . NodeFormat::EDITOR . ".groups.{$c}", $card ];
                }
            }
        }
        foreach ($groups as [ $path, $group ]) {
            if (!array_key_exists('ordered', $group)) {
                continue;
            }
            $ascending = $group['ordered'] === 'ascending';
            $previous = null;
            foreach (self::values(self::get($group, 'paths')) as $item) {
                [ $found, $node ] = self::at($schema, $item);
                if (!$found || !self::has($node, '$value')) {
                    $fail($path, 'ordered_not_node');
                    continue;
                }
                $own = $node['$value'];
                $value = is_int($own) || is_float($own) ? $own : self::get($own, 'size');
                if (!is_int($value) && !is_float($value)) {
                    $fail($path, 'ordered_not_number');
                    continue;
                }
                if ($previous !== null && ($ascending ? $value < $previous : $value > $previous)) {
                    $fail($path, 'not_ordered');
                }
                $previous = $value;
            }
        }
    }

    /**
     * `section_groups` only on a sections node, and one whose sections move
     * in blocks (their `group`) has it: one entry per block, in the order
     * the blocks first come in its default list, and none else.
     */
    private static function sectionGroups(array $schema, callable $fail): void
    {
        foreach (self::nodesOf($schema) as [ $path, $node, $type ]) {
            $editor = self::editorOf($node);
            $said = array_key_exists('section_groups', $editor);
            if ($type !== 'sections') {
                if ($said) {
                    $fail($path, 'section_groups_not_sections');
                }
                continue;
            }
            $used = [];
            foreach (self::values($node['$value']) as $section) {
                $group = self::get($section, 'group');
                if ($group !== null && !in_array($group, $used, true)) {
                    $used[] = $group;
                }
            }
            if (!$said) {
                if ($used !== []) {
                    $fail($path, 'section_groups_required');
                }
                continue;
            }
            $keys = array_map(fn ($item) => self::get($item, 'key'), self::values($editor['section_groups']));
            if (count(self::unique($keys)) !== count($keys)) {
                $fail($path, 'section_groups_repeated');
            }
            if ($keys !== $used) {
                $fail($path, 'section_groups_mismatch');
            }
        }
    }

    /**
     * `weights` only on an option of a font control. A font control that
     * lists options lists each font of the node's options once, and nothing
     * else, each with its weights going up.
     */
    private static function fonts(array $schema, callable $fail): void
    {
        foreach (self::nodesOf($schema) as [ $path, $node ]) {
            $editor = self::editorOf($node);
            $options = self::values(self::get($editor, 'options'));
            if (self::get($editor, 'control') !== 'font') {
                foreach ($options as $option) {
                    if (self::has($option, 'weights')) {
                        $fail($path, 'weights_not_font');
                        break;
                    }
                }
                continue;
            }
            if ($options === []) {
                continue;
            }
            $known = self::values($node['$extensions'][NodeFormat::EXTENSION]['options'] ?? null);
            $listed = array_map(fn ($option) => self::get($option, 'value'), $options);
            if (count(self::unique($listed)) !== count($listed)) {
                $fail($path, 'font_listed_twice');
            }
            foreach ($listed as $value) {
                if (!in_array($value, $known, true)) {
                    $fail($path, 'font_unknown');
                }
            }
            foreach ($known as $font) {
                if (!in_array($font, $listed, true)) {
                    $fail($path, 'font_unlisted');
                }
            }
            foreach ($options as $option) {
                $weights = self::values(self::get($option, 'weights'));
                foreach ($weights as $index => $weight) {
                    $before = $weights[$index - 1] ?? null;
                    if ($index > 0 && (is_int($weight) || is_float($weight)) && (is_int($before) || is_float($before)) && $weight <= $before) {
                        $fail($path, 'weights_not_increasing');
                    }
                }
            }
        }
    }

    /**
     * `max_length` and `pattern` only on a string node, and `placeholder`
     * and `multiline` only on a text control. On a property, a rule of
     * another type, a pattern outside the subset (ThemePattern) and a
     * default either refuses are check()'s (`.rules.invalid`, `.default.invalid`).
     */
    private static function texts(array $schema, callable $fail): void
    {
        foreach (self::nodesOf($schema) as [ $path, $node, $type ]) {
            $engine = $node['$extensions'][NodeFormat::EXTENSION] ?? null;
            $editor = self::editorOf($node);
            if ((self::has($engine, 'max_length') || self::has($engine, 'pattern')) && $type !== 'string' && !self::property($path, $type)) {
                $fail($path, 'rules_not_string');
            }
            foreach ([ 'placeholder', 'multiline' ] as $key) {
                if (array_key_exists($key, $editor) && self::get($editor, 'control') !== 'text') {
                    $fail($path, "{$key}_not_text");
                }
            }
        }
    }

    /**
     * A group of a page's sections holds at least two (one alone cannot
     * move), and a section's key and group start with a letter. The rest of
     * their shape is the meta-schema's and ThemePages::check()'s.
     */
    private static function pageSections(array $schema, callable $fail): void
    {
        foreach (self::entries($schema['pages'] ?? null) as $page => $spec) {
            if (str_starts_with((string) $page, '$')) {
                continue;
            }
            $sections = self::values(self::get(self::get($spec, 'sections'), '$value'));
            $sizes = [];
            foreach ($sections as $section) {
                $group = self::get($section, 'group');
                if (is_string($group)) {
                    $sizes[$group] = ($sizes[$group] ?? 0) + 1;
                }
            }
            foreach ($sections as $index => $section) {
                $key = self::get($section, 'key');
                $group = self::get($section, 'group');
                $path = "pages.{$page}.sections." . (is_string($key) ? $key : $index);
                if (is_string($group) && $sizes[$group] === 1) {
                    $fail($path, 'alone_in_group');
                }
                if ((is_string($key) && !preg_match(self::SNAKE_CASE, $key)) || (is_string($group) && !preg_match(self::SNAKE_CASE, $group))) {
                    $fail($path, 'not_snake_case');
                }
            }
        }
    }

    /**
     * A visibility key is a boolean (its own `$type` or its block's); its
     * default is check()'s.
     */
    private static function visibilityTypes(array $schema, callable $fail): void
    {
        $visibility = $schema['visibility'] ?? null;
        foreach (self::children($visibility) as $key => $node) {
            if (array_key_exists('$value', $node) && (self::get($node, '$type') ?? self::get($visibility, '$type')) !== 'boolean') {
                $fail("visibility.{$key}", 'not_boolean');
            }
        }
    }

    /**
     * What only the editor reads of a node or group, `[]` without it.
     *
     * @param mixed $node
     * @return array<string, mixed>
     */
    private static function editorOf($node): array
    {
        $editor = $node['$extensions'][NodeFormat::EDITOR] ?? null;

        return is_array($node) && is_array($editor) ? $editor : [];
    }

    /**
     * What a group holds: its members that are arrays, without its `$` keys.
     *
     * @param mixed $group
     * @return array<string|int, array<mixed>>
     */
    private static function children($group): array
    {
        return array_filter(is_array($group) ? $group : [], fn ($child, $key) => is_array($child) && !str_starts_with((string) $key, '$'), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The values of a list once each, the first of each kept.
     *
     * @param array<int, mixed> $list
     * @return array<int, mixed>
     */
    private static function unique(array $list): array
    {
        $answer = [];
        foreach ($list as $value) {
            if (!in_array($value, $answer, true)) {
                $answer[] = $value;
            }
        }

        return $answer;
    }

    /**
     * Every group of the editor's sections: `[section index, section, group index, group]`.
     *
     * @return array<int, array{0: string|int, 1: mixed, 2: string|int, 3: array<mixed>}>
     */
    private static function layoutGroups(array $schema): array
    {
        $answer = [];
        foreach (self::entries($schema['editor']['sections'] ?? null) as $s => $section) {
            foreach (self::entries(self::get($section, 'groups')) as $g => $group) {
                if (is_array($group)) {
                    $answer[] = [ $s, $section, $g, $group ];
                }
            }
        }

        return $answer;
    }

    /**
     * Every node of the blocks the editor controls: `[path, node, type]`,
     * the type its own or its group's.
     *
     * @return array<int, array{0: string, 1: array<mixed>, 2: mixed}>
     */
    private static function nodesOf(array $schema): array
    {
        $nodes = [];
        $walk = function (array $group, string $path, $type) use (&$walk, &$nodes) {
            $own = $group['$type'] ?? $type;
            if (array_key_exists('$value', $group)) {
                $nodes[] = [ $path, $group, $own ];

                return;
            }
            foreach (self::children($group) as $key => $child) {
                $walk($child, "{$path}.{$key}", $own);
            }
        };
        foreach (self::BLOCKS as $block) {
            if (is_array($schema[$block] ?? null)) {
                $walk($schema[$block], $block, null);
            }
        }

        return $nodes;
    }

    /**
     * What a path of the schema holds (`components.card.base.radius`;
     * `visibility.header.cart`, its key a path itself): `[true, value]`, or
     * `[false, null]` when the schema has nothing there.
     *
     * @param mixed $path
     * @return array{0: bool, 1: mixed}
     */
    private static function at(array $schema, $path): array
    {
        if (!is_string($path)) {
            return [ false, null ];
        }
        [ $first, $rest ] = [ explode('.', $path)[0], array_slice(explode('.', $path), 1) ];
        $keys = in_array($first, [ 'visibility', 'images' ], true) && $rest !== [] ? [ $first, implode('.', $rest) ] : [ $first, ...$rest ];
        $node = $schema;
        foreach ($keys as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return [ false, null ];
            }
            $node = $node[$key];
        }

        return [ true, $node ];
    }

    /**
     * A node NodeCompiler turns into a property tuple, whose rules check()
     * validates: a typed node of the components (their slots aside), the
     * pages or the site.
     *
     * @param mixed $type
     */
    private static function property(string $path, $type): bool
    {
        $segments = explode('.', $path);

        return is_string($type) && $segments[0] !== 'tokens' && !($segments[0] === 'components' && ($segments[2] ?? null) === 'slots');
    }

    /**
     * @param mixed $array
     * @return mixed
     */
    private static function get($array, string|int $key)
    {
        return is_array($array) ? ($array[$key] ?? null) : null;
    }

    /**
     * @param mixed $array
     */
    private static function has($array, string|int $key): bool
    {
        return is_array($array) && array_key_exists($key, $array);
    }

    /**
     * @param mixed $array
     * @return array<int, mixed>
     */
    private static function values($array): array
    {
        return is_array($array) ? array_values($array) : [];
    }

    /**
     * @param mixed $array
     * @return array<string|int, mixed>
     */
    private static function entries($array): array
    {
        return is_array($array) ? $array : [];
    }
}
