<?php

namespace Ordering\ThemeEngine;

/**
 * What a component's values derive from its other values when the theme
 * does not set them, as its schema declares it (`co.ordering.derive` on
 * each derived property, `co.ordering.matrix` on a component painted in
 * several colors; docs/schema-format.md). A rule names the
 * values it derives from by their property in the same variant (its own,
 * else its base's), so it applies the same to a component and to its copy
 * inside a group's scope.
 *
 * - `step` (a state's shadow): as many shadow levels from the resting one
 *   as `offset`, from `none` to `xl`. Always: it follows Identity's size;
 * - `shade` (a state's background): the resting background `amount`
 *   lighter (darker when negative), when the theme sets that background to
 *   something else than its default;
 * - `inset` (a pill tab's rounding): its container's rounding less its
 *   inset, a full rounding staying full. Always;
 * - `matrix`: every color of `colors` in every appearance of `appearances`,
 *   as `variants[color][appearance]`, each role (`{main}`, `{on}`,
 *   `{ink}`, `{subtle}`, `{pressed}`) by its color. A role the theme does
 *   not set derives (`roles`): when the color has none, and, unless the
 *   rule says `when: missing`, when the theme sets (or a rule derives)
 *   what it derives from.
 *
 * A schema in tuples that declares none of them gets them from
 * self::rules(), the rules the engine applied before the schema said them.
 */
final class ThemeDerivations
{
    private const PRESSED = -0.07;

    private const SUBTLE = 0.12;

    /**
     * The roles a matrix color derives when it does not have them.
     */
    public const ROLES = [
        'ink' => [ 'op' => 'same', 'from' => 'main', 'when' => 'missing' ],
        'subtle' => [ 'op' => 'mix', 'from' => 'ink', 'over' => '{color.background}', 'amount' => self::SUBTLE ],
        'pressed' => [ 'op' => 'shade', 'from' => 'main', 'amount' => self::PRESSED ],
    ];

    /**
     * The rules of a schema in tuples that does not declare them:
     * `{ component: { path in the component: rule } }` and
     * `{ component: matrix }`.
     *
     * @param array<string, mixed> $schema
     * @return array{derive: array<string, array<string, array<string, mixed>>>, matrix: array<string, array<string, mixed>>}
     */
    public static function rules(array $schema): array
    {
        $derive = [];
        $matrix = [];
        foreach (is_array($schema['components'] ?? null) ? $schema['components'] : [] as $component => $spec) {
            if (!is_array($spec)) {
                continue;
            }
            if (is_array($spec['colors'] ?? null) && is_array($spec['appearances'] ?? null)) {
                $matrix[$component] = [ 'into' => 'variants', 'roles' => self::ROLES ];
            }
            foreach (is_array($spec['variants'] ?? null) ? $spec['variants'] : [] as $variant => $props) {
                if (!is_array($props)) {
                    continue;
                }
                $at = "variants.{$variant}";
                if (array_key_exists('tab_radius', $props)) {
                    $derive[$component]["{$at}.tab_radius"] = [ 'op' => 'inset', 'from' => 'radius', 'by' => 'inset', 'when' => 'always' ];
                }
                // A resting shadow the schema does not have is no level (false), not `none`.
                $rest = self::level(array_key_exists('shadow', $props) ? $props['shadow'] : ($spec['base']['shadow'] ?? false));
                foreach (is_array($props['states'] ?? null) ? $props['states'] : [] as $state => $state_props) {
                    if (!is_array($state_props)) {
                        continue;
                    }
                    if (array_key_exists('shadow', $state_props)) {
                        $level = self::level($state_props['shadow']);
                        $offset = $rest !== null && $level !== null ? $level - $rest : ($state === 'hover' ? 1 : null);
                        if ($offset !== null) {
                            $derive[$component]["{$at}.states.{$state}.shadow"] = [ 'op' => 'step', 'from' => 'shadow', 'offset' => $offset, 'when' => 'always' ];
                        }
                    }
                    if ($state === 'pressed' && array_key_exists('background', $state_props)) {
                        $derive[$component]["{$at}.states.{$state}.background"] = [ 'op' => 'shade', 'from' => 'background', 'amount' => self::PRESSED ];
                    }
                }
            }
        }

        return [ 'derive' => $derive, 'matrix' => $matrix ];
    }

    /**
     * A schema in tuples that declares its components' rules
     * (`component_derive`, `matrix`), as the node format does on each node.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function declare(array $schema): array
    {
        if (!array_key_exists('component_derive', $schema) && !array_key_exists('matrix', $schema)) {
            $rules = self::rules($schema);
            $schema['component_derive'] = $rules['derive'];
            $schema['matrix'] = $rules['matrix'];
        }

        return $schema;
    }

    /**
     * The rules a schema declares, or the engine's when it declares none.
     *
     * @param array<string, mixed>|null $schema
     * @return array{derive: array<string, array<string, array<string, mixed>>>, matrix: array<string, array<string, mixed>>}
     */
    public static function of(?array $schema): array
    {
        if ($schema !== null && (array_key_exists('component_derive', $schema) || array_key_exists('matrix', $schema))) {
            return [
                'derive' => is_array($schema['component_derive'] ?? null) ? $schema['component_derive'] : [],
                'matrix' => is_array($schema['matrix'] ?? null) ? $schema['matrix'] : [],
            ];
        }

        return self::rules($schema ?? []);
    }

    /**
     * The shadows of the states the theme does not set (`step`), over the
     * components before their aliases resolve.
     *
     * @param array<string, mixed> $components
     * @param array<string, mixed> $set what the theme sets of them
     * @param array<string, array<string, array<string, mixed>>> $rules
     * @param array<string, mixed> $specs the schema's components, for `when: changed`
     * @return array<string, mixed>
     */
    public static function steps(array $components, array $set, array $rules, array $specs = []): array
    {
        foreach (self::matching($components, $set, $rules, 'step', $specs) as [ $component, $segments, $rule, $variant ]) {
            $index = self::level(self::sibling($components[$component], $variant, $rule['from']));
            if ($index === null) {
                continue;
            }
            $level = ThemeFormat::SHADOW[max(0, min($index + (int) ($rule['offset'] ?? 0), count(ThemeFormat::SHADOW) - 1))];
            self::put($components[$component], $segments, "{shadow.{$level}}");
        }

        return $components;
    }

    /**
     * The backgrounds of the states the theme does not set (`shade`), over
     * the resolved components: from the background the theme set, the
     * variant's own or, when it has none, its base's.
     *
     * @param array<string, mixed> $components resolved
     * @param array<string, mixed> $set what the theme sets of them
     * @param array<string, array<string, array<string, mixed>>> $rules
     * @param array<string, mixed> $specs the schema's components
     * @return array<string, mixed>
     */
    public static function shades(array $components, array $set, array $rules, array $specs): array
    {
        foreach (self::matching($components, $set, $rules, 'shade', $specs) as [ $component, $segments, $rule, $variant ]) {
            $value = ThemeColors::shade(self::sibling($components[$component], $variant, $rule['from']), (float) ($rule['amount'] ?? 0));
            if ($value !== null) {
                self::put($components[$component], $segments, $value);
            }
        }

        return $components;
    }

    /**
     * The roundings the theme does not set (`inset`), over the resolved
     * components.
     *
     * @param array<string, mixed> $components resolved
     * @param array<string, mixed> $set what the theme sets of them
     * @param array<string, array<string, array<string, mixed>>> $rules
     * @param mixed $full the full rounding's number
     * @param array<string, mixed> $specs the schema's components, for `when: changed`
     * @return array<string, mixed>
     */
    public static function insets(array $components, array $set, array $rules, $full, array $specs = []): array
    {
        foreach (self::matching($components, $set, $rules, 'inset', $specs) as [ $component, $segments, $rule, $variant ]) {
            $radius = self::sibling($components[$component], $variant, $rule['from']);
            $inset = self::sibling($components[$component], $variant, $rule['by']);
            if (!is_numeric($radius) || !is_numeric($inset)) {
                continue;
            }
            self::put($components[$component], $segments, is_numeric($full) && $radius >= $full ? $radius : max($radius - $inset, 0));
        }

        return $components;
    }

    /**
     * Every color of each matrix component in every appearance
     * (`variants[color][appearance]`), over the resolved components, each
     * role a color does not set derived by the matrix's `roles`.
     *
     * @param array<string, mixed> $components resolved
     * @param array<string, mixed> $colors the resolved colors, for `{color.x}`
     * @param array<string, mixed> $set what the theme sets of them
     * @param array<string, array<string, mixed>> $matrices `{ component: matrix }`
     * @return array<string, mixed>
     */
    public static function matrices(array $components, array $colors, array $set, array $matrices): array
    {
        foreach ($matrices as $name => $matrix) {
            $component = $components[$name] ?? null;
            if (!is_array($component) || !is_array($component['colors'] ?? null) || !is_array($component['appearances'] ?? null)) {
                continue;
            }

            $variants = [];
            foreach ($component['colors'] as $color => $roles) {
                if (!is_array($roles)) {
                    continue;
                }
                $own = is_array($set[$name]['colors'][$color] ?? null) ? $set[$name]['colors'][$color] : [];
                $changed = array_fill_keys(array_keys($own), true);
                foreach (is_array($matrix['roles'] ?? null) ? $matrix['roles'] : [] as $role => $rule) {
                    if (array_key_exists($role, $own)) {
                        continue;
                    }
                    $from = (string) ($rule['from'] ?? '');
                    $when = $rule['when'] ?? 'changed';
                    $input_changed = isset($changed[$from]);
                    if (!(($roles[$role] ?? null) === null || $when === 'always' || ($when === 'changed' && $input_changed))) {
                        continue;
                    }
                    $over = ThemeFormat::alias($rule['over'] ?? null);
                    $over_color = $over !== null && $over[0] === 'color' ? ($colors[$over[1]] ?? null) : null;
                    $value = match ($rule['op'] ?? null) {
                        'same' => $roles[$from] ?? null,
                        'shade' => ThemeColors::shade($roles[$from] ?? null, (float) ($rule['amount'] ?? 0)),
                        'mix' => ThemeColors::mix($roles[$from] ?? null, $over_color, (float) ($rule['amount'] ?? 0)),
                        'legible' => ThemeColors::legible($roles[$from] ?? null, $over_color, (float) ($rule['contrast'] ?? ThemeColors::TEXT_CONTRAST), (float) ($rule['step'] ?? ThemeColors::TEXT_STEP)),
                        default => $roles[$role] ?? null,
                    };
                    // Derived again, it goes after the roles that are not.
                    if (($roles[$role] ?? null) !== null) {
                        unset($roles[$role]);
                    }
                    $roles[$role] = $value;
                    // Derived from what changed, it changes what derives from it.
                    if ($input_changed) {
                        $changed[$role] = true;
                    }
                }
                $components[$name]['colors'][$color] = $roles;

                foreach ($component['appearances'] as $appearance => $props) {
                    if (is_array($props)) {
                        $variants[$color][$appearance] = self::withRoles($props, $roles);
                    }
                }
            }
            $components[$name][$matrix['into'] ?? 'variants'] = $variants;
        }

        return $components;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $roles
     * @return array<string, mixed>
     */
    private static function withRoles(array $node, array $roles): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = self::withRoles($value, $roles);
            } else if (is_string($value) && preg_match(ThemeFormat::ROLE, $value, $match)) {
                $node[$key] = $roles[$match[1]] ?? null;
            }
        }

        return $node;
    }

    /**
     * Each rule of an operation over a component the values have, whose
     * target exists, the theme does not set, and its `when` says derives:
     * `always`; `missing`, when the target is null; `changed` (the
     * default), when the target is null or the theme sets one of the
     * values it derives from (`from`, `by`) to something else than its
     * default. A base's value counts only for a variant without one of its
     * own, the one the variant shows.
     * `[ component, the target's segments, rule, variant ]`.
     *
     * @param array<string, mixed> $specs the schema's components
     * @return array<int, array{0: string, 1: array<int, string>, 2: array<string, mixed>, 3: string}>
     */
    private static function matching(array $components, array $set, array $rules, string $op, array $specs = []): array
    {
        $matching = [];
        foreach ($components as $component => $sections) {
            foreach (is_array($sections) ? ($rules[$component] ?? []) : [] as $path => $rule) {
                if (($rule['op'] ?? null) !== $op) {
                    continue;
                }
                $segments = explode('.', (string) $path);
                if (($segments[0] ?? null) !== 'variants' || !self::has($sections, $segments) || self::has(is_array($set[$component] ?? null) ? $set[$component] : [], $segments)) {
                    continue;
                }
                $when = $rule['when'] ?? 'changed';
                $missing = self::get($sections, $segments) === null;
                if (($when === 'missing' && !$missing)
                    || ($when === 'changed' && !$missing && !self::changed($sections, $set[$component] ?? [], $specs[$component] ?? [], $segments[1], [ $rule['from'] ?? null, $rule['by'] ?? null ]))) {
                    continue;
                }
                $matching[] = [ (string) $component, $segments, $rule, $segments[1] ];
            }
        }

        return $matching;
    }

    /**
     * Whether the theme sets a value a rule derives from to something else
     * than its default: the variant's own, or its base's when the variant
     * has none.
     *
     * @param array<string, mixed> $sections the component, resolved
     * @param mixed $set what the theme sets of it
     * @param mixed $spec its schema
     * @param array<int, string|null> $inputs
     */
    private static function changed(array $sections, $set, $spec, string $variant, array $inputs): bool
    {
        $own = is_array($set['variants'][$variant] ?? null) ? $set['variants'][$variant] : [];
        $base = is_array($set['base'] ?? null) ? $set['base'] : [];
        $resolved = is_array($sections['variants'][$variant] ?? null) ? $sections['variants'][$variant] : [];
        foreach (array_filter($inputs, 'is_string') as $input) {
            if (array_key_exists($input, $own) && $own[$input] !== self::specDefault($spec['variants'][$variant][$input] ?? null)) {
                return true;
            }
            if (!array_key_exists($input, $resolved) && array_key_exists($input, $base) && $base[$input] !== self::specDefault($spec['base'][$input] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private static function get(array $tree, array $segments): mixed
    {
        foreach ($segments as $segment) {
            if (!is_array($tree) || !array_key_exists($segment, $tree)) {
                return null;
            }
            $tree = $tree[$segment];
        }

        return $tree;
    }

    /**
     * A variant's value of a property: its own, else its base's.
     */
    private static function sibling(array $sections, string $variant, string $property): mixed
    {
        $props = $sections['variants'][$variant] ?? [];

        return is_array($props) && array_key_exists($property, $props) ? $props[$property] : ($sections['base'][$property] ?? null);
    }

    private static function has(array $tree, array $segments): bool
    {
        foreach ($segments as $segment) {
            if (!is_array($tree) || !array_key_exists($segment, $tree)) {
                return false;
            }
            $tree = $tree[$segment];
        }

        return true;
    }

    private static function put(array &$tree, array $segments, mixed $value): void
    {
        $node = &$tree;
        foreach ($segments as $segment) {
            $node = &$node[$segment];
        }
        $node = $value;
    }

    /**
     * A shadow's level index: `none` for null, or null when it is no level.
     */
    private static function level(mixed $shadow): ?int
    {
        if (is_array($shadow) && array_is_list($shadow)) {
            $shadow = count($shadow) >= 2 ? $shadow[1] : false;
        }
        $alias = ThemeFormat::alias($shadow);
        $level = $shadow === null ? 'none' : (($alias[0] ?? null) === 'shadow' ? $alias[1] : null);
        $index = array_search($level, ThemeFormat::SHADOW, true);

        return $index === false ? null : $index;
    }

    private static function specDefault(mixed $spec): mixed
    {
        return is_array($spec) && array_is_list($spec) && count($spec) >= 2 ? $spec[1] : null;
    }
}
