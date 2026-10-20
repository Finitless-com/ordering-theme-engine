<?php

namespace Ordering\ThemeEngine\Schema;

/**
 * Writes into a schema in nodes what the Dashboard's editor knew about it
 * on its own (labels, groups, order, controls, ranges), as
 * `$extensions["co.ordering.editor"]` on each node and the root `editor`
 * block (docs/schema-format.md §12). It runs once per product, when
 * its schema moves to nodes; from then on the product's repository keeps
 * that metadata in its schema.
 *
 * `$metadata` is `{ nodes: { path: editor }, props: { name: { label } },
 * editor: { sections } }`, all of it or only a part (a block the schema
 * adds later, the site's settings), which is merged into what the schema
 * already has (self::merge()); `$extras` is what the editor knew that has a
 * field of its own in the format (`axes`, `disabled_if`, `params`…), as
 * the Dashboard exported it.
 */
final class EditorMetadata
{
    /**
     * @param array<string, mixed> $nodes a schema in nodes
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $extras
     * @return array{schema: array<string, mixed>, missing: array<int, string>}
     */
    public static function inject(array $nodes, array $metadata, array $extras = []): array
    {
        $missing = [];
        $editor = [];
        foreach (is_array($metadata['nodes'] ?? null) ? $metadata['nodes'] : [] as $path => $meta) {
            $editor[$path] = $meta;
        }
        foreach (is_array($extras['nodes'] ?? null) ? $extras['nodes'] : [] as $path => $extra) {
            $editor[$path] = self::extra($editor[$path] ?? [], $extra);
        }
        foreach (is_array($extras['editor']['cards'] ?? null) ? $extras['editor']['cards'] : [] as $path => $cards) {
            $editor[$path] = ($editor[$path] ?? []) + [ 'groups' => $cards ];
        }

        foreach ($editor as $path => $meta) {
            if (!self::set($nodes, (string) $path, self::withoutNulls($meta))) {
                $missing[] = (string) $path;
            }
        }

        $layout = self::layout($metadata, $extras, $missing);
        $nodes['editor'] = is_array($nodes['editor'] ?? null) ? self::merge($nodes['editor'], $layout) : $layout;

        return [ 'schema' => self::ordered($nodes), 'missing' => $missing ];
    }

    /**
     * A schema's `editor` block with what an export adds to it: a section of
     * the same key replaces its own; a new one goes first among its
     * parent's (the site's settings open Website), or last without any;
     * every other key, its entries over the block's.
     *
     * @param array<string, mixed> $editor
     * @param array<string, mixed> $added
     * @return array<string, mixed>
     */
    private static function merge(array $editor, array $added): array
    {
        $sections = is_array($editor['sections'] ?? null) ? $editor['sections'] : [];
        foreach (is_array($added['sections'] ?? null) ? $added['sections'] : [] as $section) {
            $keys = array_column($sections, 'key');
            $at = array_search($section['key'], $keys, true);
            if ($at !== false) {
                $sections[$at] = $section;
                continue;
            }
            $parents = array_column($sections, 'parent');
            $first = isset($section['parent']) ? array_search($section['parent'], $parents, true) : false;
            array_splice($sections, $first === false ? count($sections) : $first, 0, [ $section ]);
        }
        $editor['sections'] = $sections;

        foreach ($added as $key => $value) {
            if ($key === 'sections') {
                continue;
            }
            $editor[$key] = is_array($value) && !array_is_list($value) && is_array($editor[$key] ?? null) ? array_replace_recursive($editor[$key], $value) : $value;
        }

        return $editor;
    }

    /**
     * A node's editor metadata with what the export kept apart, under the
     * names the format gives it.
     *
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function extra(array $meta, array $extra): array
    {
        foreach ($extra as $key => $value) {
            switch ($key) {
                case 'axes':
                case 'fields':
                    $meta['fields'] = ($meta['fields'] ?? []) + $value;
                    break;
                case 'disabled_if':
                case 'pair':
                    $meta[$key] = $value;
                    break;
                case 'hidden_note':
                    $meta['info_off'] = $value;
                    break;
                case 'info_platform':
                    $meta['platform']['info'] = $value;
                    break;
                case 'none_label':
                    $meta['labels']['none'] = $value;
                    break;
                case 'unset_label':
                    $meta['labels']['unset'] = $value;
                    break;
                case 'params':
                    if (isset($meta['label'])) {
                        $meta['label']['params'] = $value;
                    }
                    break;
                case 'derived':
                    $meta['hidden'] = true;
                    break;
                case 'options':
                    $meta['options'] = self::options($meta['options'] ?? [], $value);
                    break;
            }
        }

        return $meta;
    }

    /**
     * The options with what the export kept apart of each, by value: a
     * label on a platform carries the params its key interpolates.
     *
     * @param array<int, array<string, mixed>> $options
     * @param array<int, array<string, mixed>> $extra
     * @return array<int, array<string, mixed>>
     */
    private static function options(array $options, array $extra): array
    {
        foreach ($extra as $item) {
            if (isset($item['label_platform'], $item['params'])) {
                $item['label_platform']['params'] = $item['params'];
            }
            unset($item['params']);

            $index = null;
            foreach ($options as $at => $option) {
                if (array_key_exists('value', $option) && $option['value'] === ($item['value'] ?? null)) {
                    $index = $at;
                    break;
                }
            }
            if ($index === null) {
                $options[] = $item;
            } else {
                $options[$index] += $item;
            }
        }

        return $options;
    }

    /**
     * The metadata without the texts the editor had none for (`label: null`):
     * absent, the editor humanizes the key, as it did.
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private static function withoutNulls(array $meta): array
    {
        foreach ($meta as $key => $value) {
            if ($value === null && !in_array($key, [ 'value' ], true)) {
                unset($meta[$key]);
            } else if (is_array($value) && !in_array($key, [ 'value', 'paths', 'related' ], true)) {
                $meta[$key] = self::withoutNulls($value);
            }
        }

        return $meta;
    }

    /**
     * The root `editor` block.
     *
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $extras
     * @param array<int, string> $missing
     * @return array<string, mixed>
     */
    private static function layout(array $metadata, array $extras, array &$missing): array
    {
        $parents = $extras['editor']['section_parents'] ?? [];
        $info = $extras['editor']['group_info'] ?? [];
        $sections = [];
        foreach (is_array($metadata['editor']['sections'] ?? null) ? $metadata['editor']['sections'] : [] as $section) {
            if (isset($parents[$section['key']])) {
                $section['parent'] = $parents[$section['key']];
            }
            foreach ($section['groups'] ?? [] as $at => $group) {
                $key = "{$section['key']}.{$group['key']}";
                if (isset($info[$key])) {
                    $section['groups'][$at] += $info[$key];
                    unset($info[$key]);
                }
            }
            $sections[] = $section;
        }
        // What the editor knew of a group it builds on its own (the images by prefix).
        foreach (array_keys($info) as $key) {
            $missing[] = "editor.group_info.{$key}";
        }

        // The export may already carry the rest of the block in the format's names.
        $layout = [ 'sections' => $sections ] + (is_array($metadata['editor'] ?? null) ? $metadata['editor'] : []);
        foreach ([ 'section_groups', 'parts' ] as $key) {
            if (isset($extras['editor'][$key])) {
                $layout[$key] = $extras['editor'][$key];
            }
        }
        foreach ([ 'platforms', 'page_section_groups' ] as $key) {
            if (isset($extras['editor'][$key])) {
                $layout['labels'][$key] = $extras['editor'][$key];
            }
        }
        if (is_array($metadata['props'] ?? null)) {
            $layout['props'] = $metadata['props'];
        }

        return $layout;
    }

    /**
     * Sets the editor metadata of the node or group at a path. The keys of
     * `visibility` and `images` are paths themselves.
     *
     * @param array<string, mixed> $nodes
     * @param array<string, mixed> $meta
     */
    private static function set(array &$nodes, string $path, array $meta): bool
    {
        $segments = explode('.', $path);
        if (in_array($segments[0], [ 'visibility', 'images' ], true)) {
            $segments = [ $segments[0], implode('.', array_slice($segments, 1)) ];
        }

        $node = &$nodes;
        foreach ($segments as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node) || !is_array($node[$segment])) {
                return false;
            }
            $node = &$node[$segment];
        }
        $node['$extensions'][NodeFormat::EDITOR] = $meta;

        return true;
    }

    /**
     * The root in the order of the format: `editor` after `platforms`.
     *
     * @param array<string, mixed> $nodes
     * @return array<string, mixed>
     */
    private static function ordered(array $nodes): array
    {
        return array_intersect_key($nodes, array_flip([ '$schema', 'product_type', 'version', 'format', 'platforms', 'editor' ])) + $nodes;
    }
}
