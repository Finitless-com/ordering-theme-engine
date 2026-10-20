<?php

namespace Ordering\ThemeEngine;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

use Ordering\ThemeEngine\Schema\EditorRules;
use Ordering\ThemeEngine\Schema\NodeCompiler;
use Ordering\ThemeEngine\Schema\NodeConverter;
use Ordering\ThemeEngine\Schema\NodeFormat;

/**
 * The theme schemas of the product types (api-core's docs/THEMES_V2_PLAN.md
 * §2.2), one per base version, as each product's repository ships it
 * (`theme.schema.json`):
 *
 * - `product_type` (self::PRODUCT_TYPES), `version` (its base's) and
 *   `format` (ThemeFormat::VERSION);
 * - `tokens`: the default of every token of ThemeFormat;
 * - `components`: `{ name: { base, variants: { name: { …, states } },
 *   sizes, shapes, platforms } }`, each property `[kind, default]` or
 *   `[kind, default, options]` (ThemeFormat::PROPERTY_KINDS), and a fourth
 *   element when the value must also be something more (ThemeValidator::
 *   rules(): a custom typography, a string's length or pattern); a color's
 *   default may be null, none of its own (a button's hover keeps its
 *   resting colors unless the theme sets them). A component painted in
 *   several colors the same ways (the button) has `colors`
 *   (`{ name: { main, on, ink?, subtle?, pressed? } }`) and `appearances`
 *   (`{ name: { …, states } }`, painting with `{main}`, `{on}`…) instead of
 *   variants: its resolved `variants` are every color in every appearance.
 *   `sizes` (sm, md, lg) and `shapes` (`icon`: square, the icon alone) are
 *   two more axes over any of them;
 * - a group (a component that holds others) may also have `scope` and
 *   `slots`. `scope`: `{ component: changes }`, how the components it
 *   holds look inside it, in the shape of each one and only what changes
 *   (`{}` when the schema changes nothing; a theme may change any of its
 *   properties). `slots`: `{ name: { component, size?, variant?,
 *   appearance?, color?, shape? } }`, one piece of the group, which only
 *   chooses among what its component already has (ThemeFormat::SLOT_AXES;
 *   null is the component's own default). A group is never scoped nor a
 *   slot. A component with only `slots` (a field's trailing button) is no
 *   group: it may be scoped or slotted when its slots name components
 *   without slots;
 * - `inherits`: `{ component: parent }`. What a theme sets on the parent's
 *   base also applies to each property of the same name in the
 *   component's base, unless the theme sets it there (a specific card
 *   follows the generic one);
 * - `visibility`, `images`: `{ key: [default, version 1 path] }`, the path
 *   null when version 1 had no such key; an
 *   image may add the formats it takes and what the editor recommends,
 *   `[default, version 1 path, formats?, hints?]` (formats of
 *   ThemeImage::FORMATS, null or absent for ThemeImage::DEFAULT_FORMATS;
 *   hints, ThemeImage::validHints); a visibility key may add its default
 *   on each platform that shows it otherwise, `[default, version 1 path,
 *   { platform: bool }]`, served at `platforms.<platform>.visibility`;
 * - `pages`: each page's footer and sections, their order and which may
 *   be hidden or moved (ThemePages);
 * - `platforms`: the platforms a component, a page or a visibility key
 *   may override (`mobile_web`);
 * - `v1`: `{ version 2 path: version 1 path }` for tokens and component
 *   properties, so a version 1 theme converts and projects (api-core's
 *   ThemeV1Bridge); a list of version 1 paths when version 1 kept the
 *   value in several places (`tokens.layout.mode`).
 *
 * That is the tuple form the engine reads. A product may also register its
 * schema in the node format (docs/schema-format.md), which self::tuples()
 * checks and compiles into it.
 *
 * Only the rules: the schemas a product registered, and the cache over
 * them, are api-core's (its own App\Themes\ThemeSchemas).
 */
class ThemeSchemas
{
    /**
     * What tuples() answers, by revision: raised when it changes (the
     * colors marked `alpha` added the 2nd, a visibility key's platform
     * defaults the 3rd, a property's rules and the narrowed tokens the 4th),
     * so whoever caches what it answers (api-core) never serves a schema an
     * earlier version compiled.
     */
    public const COMPILED = 4;

    public const KEY = '/^[a-z0-9_]+(\.[a-z0-9_]+){0,7}$/';

    public const VERSION = '/^\d{1,4}(\.\d{1,4}){0,2}$/';

    /**
     * The product types of the Builder's catalog: api-core's
     * BuilderProductTypeEnum, which a test there keeps equal to this list.
     */
    public const PRODUCT_TYPES = [
        'marketplace_app',
        'franchise_app',
        'marketplace_website',
        'franchise_website',
        'store_app',
        'driver_app',
        'kiosk_app',
        'call_center',
        'dashboard',
    ];

    /**
     * A schema in the tuples the rest of the engine reads. One written in
     * nodes (NodeFormat, docs/schema-format.md) is first checked against
     * the format's meta-schema, then compiled with the version 1 paths
     * given (none when null); one in tuples is answered as it is.
     *
     * @param array<string, mixed> $schema
     * @return array{schema: array<string, mixed>, errors: array<int, array{path: string, code: string}>}
     */
    public static function tuples(array $schema, ?array $v1_map = null): array
    {
        if (!NodeCompiler::isNodes($schema)) {
            return [ 'schema' => $schema, 'errors' => [] ];
        }

        $errors = self::meta($schema);
        if ($errors !== []) {
            return [ 'schema' => $schema, 'errors' => $errors ];
        }

        return NodeCompiler::compile($schema, is_array($v1_map) ? $v1_map : []);
    }

    /**
     * What a schema in nodes gets wrong against the format's meta-schema
     * (`meta/theme-schema-1.json`, which the TypeScript engine ships too),
     * each problem at the path of the node or group, with the code
     * `<path>.schema`.
     *
     * @param array<string, mixed> $schema
     * @return array<int, array{path: string, code: string}>
     */
    public static function meta(array $schema): array
    {
        $validator = new Validator();
        $validator->setMaxErrors(50);
        $validator->resolver()->registerFile(NodeFormat::ID, self::metaSchema());

        // PHP decodes `{}` as an empty array: written back, a group is an object again.
        $result = $validator->validate(json_decode(NodeConverter::json($schema)), NodeFormat::ID);
        if ($result->isValid()) {
            return [];
        }

        $errors = [];
        foreach (array_keys((new ErrorFormatter())->format($result->error(), true)) as $pointer) {
            $segments = array_map(
                fn (string $segment) => str_replace([ '~1', '~0' ], [ '/', '~' ], rawurldecode($segment)),
                array_values(array_filter(explode('/', (string) $pointer), fn (string $segment) => $segment !== ''))
            );
            $path = implode('.', $segments);
            $errors[] = [ 'path' => $path, 'code' => $path === '' ? 'schema' : "{$path}.schema" ];
        }

        return $errors;
    }

    /**
     * The format's meta-schema, at the root of the package.
     */
    private static function metaSchema(): string
    {
        return dirname(__DIR__, 2) . '/meta/theme-schema-1.json';
    }

    /**
     * What a schema document gets wrong. Given the schema in nodes it was
     * compiled from, it then checks the editor metadata NodeCompiler drops
     * (EditorRules::check()).
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed>|null $nodes in nodes, what $schema was compiled from
     * @return array<int, array{path: string, code: string}>
     */
    public static function check(array $schema, ?array $nodes = null): array
    {
        $errors = [];
        $fail = function (string $path, string $rule) use (&$errors) {
            $errors[] = [ 'path' => $path, 'code' => "{$path}.{$rule}" ];
        };

        if (!in_array($schema['product_type'] ?? null, self::PRODUCT_TYPES, true)) {
            $fail('product_type', 'invalid');
        }
        if (!is_string($schema['version'] ?? null) || !preg_match(self::VERSION, $schema['version'])) {
            $fail('version', 'invalid');
        }
        if (($schema['format'] ?? null) !== ThemeFormat::VERSION) {
            $fail('format', 'invalid');
        }
        foreach (array_keys($schema) as $section) {
            if (!in_array($section, [ 'product_type', 'version', 'format', 'tokens', 'components', 'inherits', 'visibility', 'images', 'pages', 'platforms', 'site', 'v1', 'follows', 'derive', 'component_derive', 'matrix', 'migrate', 'alpha', 'token_options' ], true)) {
                $fail((string) $section, 'unknown');
            }
        }

        // The defaults of the tokens: complete, and literals only.
        $tokens = $schema['tokens'] ?? null;
        if (!is_array($tokens)) {
            $fail('tokens', 'required');
            $tokens = [];
        }
        foreach (array_merge([ 'font', 'typography' ], array_keys(ThemeFormat::TOKENS)) as $group) {
            if (in_array($group, ThemeFormat::OWN_GROUPS, true)) {
                if (($tokens[$group] ?? []) === []) {
                    $fail("tokens.{$group}", 'required');
                }
                continue;
            }
            foreach (ThemeFormat::tokenNames($group) as $name) {
                if (!array_key_exists($name, $tokens[$group] ?? [])) {
                    $fail("tokens.{$group}.{$name}", 'required');
                }
            }
        }
        // What the schema refers to of the groups it names itself, it has.
        $refers = function ($node, string $path) use (&$refers, $tokens, $fail) {
            if (is_array($node) && array_is_list($node) && is_string($node[0] ?? null) && array_key_exists($node[0], ThemeFormat::PROPERTY_KINDS + [ 'border' => null, 'role' => null ])) {
                // A property in tuples: its default.
                $refers($node[1] ?? null, "{$path}.default");
            } else if (is_array($node)) {
                foreach ($node as $key => $child) {
                    $refers($child, "{$path}.{$key}");
                }
            } else if (($alias = ThemeFormat::alias($node)) !== null && in_array($alias[0], ThemeFormat::OWN_GROUPS, true) && !array_key_exists($alias[1], $tokens[$alias[0]] ?? [])) {
                $fail($path, 'alias');
            }
        };
        foreach ([ 'tokens', 'components' ] as $section) {
            $refers($schema[$section] ?? [], $section);
        }
        foreach (ThemeValidator::tokens($tokens, 'tokens') as $error) {
            $errors[] = $error;
        }
        // A token it narrows keeps some of its options, its default among them.
        $token_options = $schema['token_options'] ?? [];
        foreach (is_array($token_options) ? $token_options : [ '' => null ] as $token => $options) {
            [ $group, $name ] = array_pad(explode('.', (string) $token, 2), 2, '');
            $catalog = NodeFormat::token($group, $name, array_keys(is_array($tokens['color'] ?? null) ? $tokens['color'] : []))[1] ?? null;
            if (!in_array($token, NodeFormat::NARROWABLE, true) || !is_array($options) || $options === [] || !array_is_list($options)
                || array_diff($options, $catalog ?? []) !== [] || !in_array($tokens[$group][$name] ?? null, $options, true)) {
                $fail($token === '' ? 'token_options' : "token_options.{$token}", 'invalid');
            }
        }

        $platforms = $schema['platforms'] ?? [];
        if (!is_array($platforms) || !array_is_list($platforms)) {
            $fail('platforms', 'invalid');
            $platforms = [];
        }

        foreach ($schema['components'] ?? [] as $component => $spec) {
            $path = "components.{$component}";
            if (!preg_match('/^[a-z0-9_]+$/', (string) $component) || !is_array($spec)) {
                $fail($path, 'invalid');
                continue;
            }
            foreach ($spec as $section => $props) {
                $section_path = "{$path}.{$section}";
                if (!is_array($props)) {
                    $fail($section_path, 'invalid');
                    continue;
                }
                if ($section === 'base') {
                    self::checkProps($props, $section_path, $fail);
                } else if (in_array($section, [ 'variants', 'sizes', 'shapes', 'colors', 'appearances' ], true)) {
                    foreach ($props as $name => $variant) {
                        $states = $variant['states'] ?? [];
                        unset($variant['states']);
                        self::checkProps(is_array($variant) ? $variant : [], "{$section_path}.{$name}", $fail);
                        foreach (in_array($section, [ 'variants', 'appearances' ], true) && is_array($states) ? $states : [] as $state => $state_props) {
                            self::checkProps(is_array($state_props) ? $state_props : [], "{$section_path}.{$name}.states.{$state}", $fail);
                        }
                    }
                } else if ($section === 'scope') {
                    self::checkScope($schema, (string) $component, $props, $section_path, $fail);
                } else if ($section === 'slots') {
                    self::checkSlots($schema, $props, $section_path, $fail);
                } else if ($section === 'platforms') {
                    foreach ($props as $platform => $platform_props) {
                        if (!in_array($platform, $platforms, true)) {
                            $fail("{$section_path}.{$platform}", 'unknown');
                            continue;
                        }
                        self::checkProps(is_array($platform_props) ? $platform_props : [], "{$section_path}.{$platform}", $fail);
                        // A platform changes a property of the base, of the same kind.
                        foreach (is_array($platform_props) ? $platform_props : [] as $prop => $platform_spec) {
                            $own = $spec['base'][$prop] ?? null;
                            if (!is_array($own) || !is_array($platform_spec) || ($own[0] ?? null) !== ($platform_spec[0] ?? null)) {
                                $fail("{$section_path}.{$platform}.{$prop}", 'invalid');
                            }
                        }
                    }
                } else {
                    $fail($section_path, 'unknown');
                }
            }
        }

        // A parent is a component of the schema that inherits from none.
        $inherits = $schema['inherits'] ?? [];
        if (!is_array($inherits)) {
            $fail('inherits', 'invalid');
            $inherits = [];
        }
        foreach ($inherits as $component => $parent) {
            if (!isset($schema['components'][$component]) || !is_string($parent) || $parent === $component
                || !isset($schema['components'][$parent]) || isset($inherits[$parent])) {
                $fail("inherits.{$component}", 'invalid');
            }
        }

        foreach ([ 'visibility' => 'bool', 'images' => 'image' ] as $section => $kind) {
            foreach ($schema[$section] ?? [] as $key => $entry) {
                $path = "{$section}.{$key}";
                if (!preg_match(self::KEY, (string) $key) || !is_array($entry) || !array_is_list($entry)
                    || !in_array(count($entry), $section === 'images' ? [ 2, 3, 4 ] : [ 2, 3 ], true)
                    || !(is_string($entry[1]) || $entry[1] === null)) {
                    $fail($path, 'invalid');
                    continue;
                }
                if ($section === 'visibility' && array_key_exists(2, $entry)) {
                    // Its default on each platform that shows it otherwise: a declared one, a bool.
                    if (!is_array($entry[2]) || $entry[2] === [] || array_is_list($entry[2])
                        || array_diff(array_keys($entry[2]), $platforms) !== [] || array_filter($entry[2], fn ($value) => !is_bool($value)) !== []) {
                        $fail("{$path}.platforms", 'invalid');
                    }
                } else if (($entry[2] ?? null) !== null && !ThemeImage::validFormats($entry[2])) {
                    $fail("{$path}.formats", 'invalid');
                }
                if (array_key_exists(3, $entry) && !ThemeImage::validHints($entry[3])) {
                    $fail("{$path}.hints", 'invalid');
                }
                if (ThemeValidator::value($kind, $entry[0]) !== null) {
                    $fail("{$path}.default", 'invalid');
                }
            }
        }

        // The site's settings: groups down to properties.
        $site = $schema['site'] ?? [];
        if (!is_array($site) || ($site !== [] && array_is_list($site))) {
            $fail('site', 'invalid');
        } else {
            self::checkTree($site, 'site', $fail);
        }

        // What follows Identity: a property of the components, of that kind (ThemeOverall).
        $follows = $schema['follows'] ?? [];
        if (!is_array($follows)) {
            $fail('follows', 'invalid');
            $follows = [];
        }
        foreach ($follows as $path => $group) {
            $segments = explode('.', (string) $path);
            $spec = $segments[0] === 'components' ? self::propertySpec($schema, array_slice($segments, 1)) : null;
            if (!in_array($group, ThemeOverall::GROUPS, true) || $spec === null || $spec[0] !== $group || in_array('states', $segments, true)) {
                $fail("follows.{$path}", 'invalid');
            }
        }

        // How derived colors derive: from colors of the schema, by an operation it knows.
        $derive = $schema['derive'] ?? [];
        if (!is_array($derive)) {
            $fail('derive', 'invalid');
            $derive = [];
        }
        foreach ($derive as $color => $rule) {
            $named = fn ($alias) => ($parts = ThemeFormat::alias($alias)) !== null && $parts[0] === 'color' && array_key_exists($parts[1], $tokens['color'] ?? []);
            $amount = $rule['amount'] ?? null;
            if (!array_key_exists($color, $tokens['color'] ?? []) || !is_array($rule) || !in_array($rule['op'] ?? null, [ 'same', 'shade', 'mix', 'legible' ], true)
                || !$named($rule['from'] ?? null)
                || (in_array($rule['op'], [ 'mix', 'legible' ], true) && !$named($rule['over'] ?? null))
                || (in_array($rule['op'], [ 'shade', 'mix' ], true) && !((is_int($amount) || is_float($amount)) && abs($amount) <= 1))) {
                $fail("derive.{$color}", 'invalid');
            }
        }

        // How components' values derive: a value of the schema, from values of the same variant.
        $component_derive = $schema['component_derive'] ?? [];
        foreach (is_array($component_derive) ? $component_derive : [] as $component => $rules) {
            foreach (is_array($rules) ? $rules : [] as $path => $rule) {
                $segments = explode('.', (string) $path);
                $variant = array_merge($schema['components'][$component]['base'] ?? [], $schema['components'][$component]['variants'][$segments[1] ?? ''] ?? []);
                $known = fn ($name) => is_string($name) && array_key_exists($name, $variant);
                if (!is_array($rule) || ($segments[0] ?? null) !== 'variants' || self::propertySpec($schema, array_merge([ (string) $component ], $segments)) === null
                    || !match ($rule['op'] ?? null) {
                        'step' => $known($rule['from'] ?? null) && is_int($rule['offset'] ?? null),
                        'shade' => $known($rule['from'] ?? null) && (is_int($rule['amount'] ?? null) || is_float($rule['amount'] ?? null)),
                        'inset' => $known($rule['from'] ?? null) && $known($rule['by'] ?? null),
                        default => false,
                    }) {
                    $fail("component_derive.{$component}.{$path}", 'invalid');
                }
            }
        }
        foreach (is_array($schema['matrix'] ?? null) ? $schema['matrix'] : [] as $component => $matrix) {
            $spec = $schema['components'][$component] ?? null;
            if (!is_array($spec) || !is_array($spec['colors'] ?? null) || !is_array($spec['appearances'] ?? null) || !is_array($matrix['roles'] ?? null)
                || !is_string($matrix['into'] ?? null) || array_key_exists($matrix['into'], $spec)) {
                $fail("matrix.{$component}", 'invalid');
            }
        }

        foreach (ThemeMigrations::check($schema) as $error) {
            $errors[] = $error;
        }

        foreach (ThemePages::check($schema) as $error) {
            $errors[] = $error;
        }

        // Two controls on one version 1 key would fight: whichever is
        // projected last wins and the other does nothing.
        $v1_paths = [];
        foreach ($schema['v1'] ?? [] as $v2_path => $v1_path) {
            $list = is_array($v1_path) && array_is_list($v1_path) ? $v1_path : [ $v1_path ];
            if ($list === [] || count(array_filter($list, 'is_string')) !== count($list) || self::kindAt($schema, (string) $v2_path) === null) {
                $fail("v1.{$v2_path}", 'invalid');
                continue;
            }
            if (array_filter($list, fn ($path) => isset($v1_paths[$path])) !== [] || count(array_unique($list)) !== count($list)) {
                $fail("v1.{$v2_path}", 'duplicate');
            }
            $v1_paths += array_fill_keys($list, true);
        }

        if ($nodes !== null) {
            foreach (EditorRules::check($nodes) as $error) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * A group's scope: each component exists and is not a group, and each
     * property it changes is the component's, of the same kind and options.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $scope
     */
    private static function checkScope(array $schema, string $group, array $scope, string $path, callable $fail): void
    {
        foreach ($scope as $scoped => $changes) {
            $scoped_path = "{$path}.{$scoped}";
            if ($scoped === $group || !is_array($changes) || !self::isComponent($schema, (string) $scoped)) {
                $fail($scoped_path, 'invalid');
                continue;
            }
            foreach (self::leaves($changes) as $leaf => $spec) {
                $own = self::propertySpec($schema, array_merge([ (string) $scoped ], explode('.', $leaf)));
                if ($own === null) {
                    $fail("{$scoped_path}.{$leaf}", 'unknown');
                } else if (!is_array($spec) || !array_is_list($spec) || ($spec[0] ?? null) !== $own[0] || ($spec[2] ?? null) !== ($own[2] ?? null) || count($spec) < 2) {
                    $fail("{$scoped_path}.{$leaf}", 'invalid');
                } else if (ThemeValidator::value($spec[0], $spec[1], $spec[2] ?? null, $own[3] ?? null) !== null) {
                    $fail("{$scoped_path}.{$leaf}.default", 'invalid');
                }
            }
        }
    }

    /**
     * A group's slots: each names a component that is not a group, and
     * chooses only among what that component has, or null.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $slots
     */
    private static function checkSlots(array $schema, array $slots, string $path, callable $fail): void
    {
        foreach ($slots as $name => $slot) {
            $slot_path = "{$path}.{$name}";
            if (!preg_match('/^[a-z0-9_]+$/', (string) $name) || !is_array($slot) || !is_string($slot['component'] ?? null)
                || !self::isComponent($schema, $slot['component'])) {
                $fail($slot_path, 'invalid');
                continue;
            }
            $component = $schema['components'][$slot['component']];
            foreach ($slot as $axis => $choice) {
                if ($axis === 'component') {
                    continue;
                }
                $section = ThemeFormat::SLOT_AXES[$axis] ?? null;
                if ($section === null || !is_array($component[$section] ?? null)) {
                    $fail("{$slot_path}.{$axis}", 'unknown');
                } else if ($choice !== null && !(is_string($choice) && array_key_exists($choice, $component[$section]))) {
                    $fail("{$slot_path}.{$axis}", 'invalid');
                }
            }
        }
    }

    /**
     * A component of the schema that a group may scope or slot: not a group
     * (no `scope`). It may have slots of its own (a field's trailing
     * button), as long as they name components without slots, so a slot
     * nests one level at most.
     *
     * @param array<string, mixed> $schema
     */
    public static function isComponent(array $schema, string $name): bool
    {
        $spec = $schema['components'][$name] ?? null;
        if (!is_array($spec) || isset($spec['scope'])) {
            return false;
        }

        foreach (is_array($spec['slots'] ?? null) ? $spec['slots'] : [] as $slot) {
            $nested = $schema['components'][$slot['component'] ?? ''] ?? null;
            if (!is_array($nested) || isset($nested['scope']) || isset($nested['slots'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every property spec under a node, by its dotted path.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function leaves(array $node, string $prefix = ''): array
    {
        $leaves = [];
        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_array($value) && !array_is_list($value) && $value !== []) {
                $leaves += self::leaves($value, $path);
            } else if ($value !== []) {
                $leaves[$path] = $value;
            }
        }

        return $leaves;
    }

    /**
     * @param array<string, mixed> $props
     */
    /**
     * A block of groups down to properties (`site`).
     */
    private static function checkTree(array $tree, string $path, callable $fail): void
    {
        foreach ($tree as $key => $node) {
            $at = "{$path}.{$key}";
            if (!preg_match('/^[a-z0-9_]+$/', (string) $key) || !is_array($node) || $node === []) {
                $fail($at, 'invalid');
            } else if (array_is_list($node)) {
                self::checkProps([ $key => $node ], $path, $fail);
            } else {
                self::checkTree($node, $at, $fail);
            }
        }
    }

    private static function checkProps(array $props, string $path, callable $fail): void
    {
        foreach ($props as $prop => $spec) {
            $prop_path = "{$path}.{$prop}";
            if (!preg_match('/^[a-z0-9_]+$/', (string) $prop) || !is_array($spec) || !array_is_list($spec) || count($spec) < 2 || count($spec) > 4
                || !array_key_exists($spec[0], ThemeFormat::PROPERTY_KINDS)) {
                $fail($prop_path, 'invalid');
                continue;
            }
            if (array_key_exists(3, $spec) && !ThemeValidator::validRules($spec[0], $spec[3])) {
                $fail("{$prop_path}.rules", 'invalid');
                continue;
            }

            $options = $spec[2] ?? null;
            $levels = in_array($spec[0], ThemeFormat::LEVEL_OPTION_KINDS, true)
                && (is_array($options) ? $options !== [] && array_is_list($options) && array_diff($options, ThemeFormat::tokenNames(ThemeFormat::PROPERTY_KINDS[$spec[0]])) === [] : $options === null);
            if (!$levels && in_array($spec[0], ThemeFormat::OPTION_KINDS, true) !== is_array($options)) {
                $fail("{$prop_path}.options", 'invalid');
                continue;
            }
            // A color without a default of its own.
            $unset = $spec[1] === null && in_array($spec[0], [ 'color', 'role' ], true);
            if (!$unset && ThemeValidator::value($spec[0], $spec[1], $options, $spec[3] ?? null) !== null) {
                $fail("{$prop_path}.default", 'invalid');
            }
        }
    }

    /**
     * The kind of the value at a version 2 path of the schema's themes:
     * `color`, `font`, `layout_mode`, a component property's kind,
     * `border_color` for the color of a border property, or null when the
     * path does not exist.
     *
     * @param array<string, mixed> $schema
     */
    public static function kindAt(array $schema, string $path): ?string
    {
        $segments = explode('.', $path);
        if ($segments[0] === 'site') {
            return self::specAt($schema, $path)[0] ?? null;
        }
        if ($segments[0] === 'tokens') {
            if (count($segments) !== 3) {
                return null;
            }
            if ($segments[1] === 'color') {
                // The schema's own colors, as its themes are validated; the catalog's when it names none.
                $colors = is_array($schema['tokens']['color'] ?? null) && $schema['tokens']['color'] !== [] ? array_keys($schema['tokens']['color']) : ThemeFormat::COLORS;

                return in_array($segments[2], $colors, true) ? 'color' : null;
            }
            if ($segments[1] === 'font' && in_array($segments[2], ThemeFormat::FONT_ROLES, true)) {
                return 'font';
            }
            if ($segments[1] === 'layout' && $segments[2] === 'mode') {
                return 'layout_mode';
            }

            return null;
        }
        if ($segments[0] !== 'components' || count($segments) < 4) {
            return null;
        }

        $spec = self::propertySpec($schema, array_slice($segments, 1));
        if ($spec !== null) {
            return $spec[0];
        }

        // The color of a border property.
        $last = array_pop($segments);
        $spec = self::propertySpec($schema, array_slice($segments, 1));

        return $spec !== null && $spec[0] === 'border' && $last === 'color' ? 'border_color' : null;
    }

    /**
     * A component property's spec, by its path under `components`
     * (`button.variants.primary.states.pressed.background`).
     *
     * @param array<string, mixed> $schema
     * @param array<int, string> $segments
     * @return array<int, mixed>|null
     */
    /**
     * A schema in tuples that declares what its engine would otherwise
     * apply by rule: what follows Identity (ThemeOverall::declare()), how its
     * colors derive (ThemeColors::declare()) and how its components' values
     * derive (ThemeDerivations::declare()). It resolves the same.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function declare(array $schema): array
    {
        return ThemeDerivations::declare(ThemeColors::declare(ThemeOverall::declare($schema)));
    }

    /**
     * The tuple of a property by its full path: of a component
     * (`components.…`) or of the site's settings (`site.…`).
     *
     * @return array<int, mixed>|null
     */
    public static function specAt(array $schema, string $path): ?array
    {
        $segments = explode('.', $path);
        if ($segments[0] === 'components') {
            return self::propertySpec($schema, array_slice($segments, 1));
        }
        if ($segments[0] !== 'site') {
            return null;
        }
        $node = $schema;
        foreach ($segments as $segment) {
            $node = is_array($node) ? ($node[$segment] ?? null) : null;
        }

        return is_array($node) && array_is_list($node) && is_string($node[0] ?? null) ? $node : null;
    }

    public static function propertySpec(array $schema, array $segments): ?array
    {
        $node = $schema['components'] ?? [];
        foreach ($segments as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return is_array($node) && array_is_list($node) && isset($node[0]) && is_string($node[0]) ? $node : null;
    }

    /**
     * A complete theme of the schema: every token, component property,
     * visibility key, image and page with its default.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function defaults(array $schema): array
    {
        $components = [];
        $platforms = [];
        foreach ($schema['components'] ?? [] as $component => $spec) {
            foreach ($spec as $section => $props) {
                if ($section === 'platforms') {
                    foreach ($props as $platform => $platform_props) {
                        $platforms[$platform]['components'][$component]['base'] = self::propDefaults($platform_props);
                    }
                } else if ($section === 'base') {
                    $components[$component]['base'] = self::propDefaults($props);
                } else if ($section === 'scope') {
                    foreach ($props as $scoped => $changes) {
                        $components[$component]['scope'][$scoped] = self::nestedDefaults($changes);
                    }
                } else if ($section === 'slots') {
                    $components[$component]['slots'] = $props;
                } else {
                    foreach ($props as $name => $variant) {
                        $states = $variant['states'] ?? null;
                        unset($variant['states']);
                        $components[$component][$section][$name] = self::propDefaults($variant);
                        foreach ($states ?? [] as $state => $state_props) {
                            $components[$component][$section][$name]['states'][$state] = self::propDefaults($state_props);
                        }
                    }
                }
            }
        }

        $defaults = [
            'tokens' => $schema['tokens'] ?? [],
            'components' => $components,
        ];
        foreach ([ 'visibility', 'images' ] as $section) {
            $defaults[$section] = array_map(fn (array $entry) => $entry[0], $schema[$section] ?? []);
        }
        foreach ($schema['visibility'] ?? [] as $key => $entry) {
            foreach (is_array($entry[2] ?? null) ? $entry[2] : [] as $platform => $visible) {
                $platforms[$platform]['visibility'][$key] = $visible;
            }
        }
        $defaults['pages'] = ThemePages::defaults($schema);
        $defaults['modes'] = [];
        $defaults['platforms'] = $platforms;

        return $defaults;
    }

    /**
     * The defaults of specs at any depth (a scope's changes).
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    /**
     * The defaults of the site's settings (the `site` block), which are the
     * site's and not part of a theme (SiteSettings).
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function siteDefaults(array $schema): array
    {
        return is_array($schema['site'] ?? null) ? self::nestedDefaults($schema['site']) : [];
    }

    private static function nestedDefaults(array $node): array
    {
        foreach ($node as $key => $value) {
            $node[$key] = is_array($value) && array_is_list($value) && is_string($value[0] ?? null) ? $value[1] : (is_array($value) ? self::nestedDefaults($value) : $value);
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private static function propDefaults(array $props): array
    {
        return array_map(fn (array $spec) => $spec[1], $props);
    }
}
