# Writing the product schema

The source of truth for the format is the engine's docs at the pinned version:
`docs/creating-a-schema.md` (CaS) and `docs/schema-format.md` (SF), in
`node_modules/@orderingco/ordering-theme-engine/docs/` or in `docs/` of the engine's repo
(Finitless-com/ordering-theme-engine). This file summarizes what is used most
and does not replace them. When in doubt, open the cited section.

Examples:

- the reference schema with every shape of the format:
  the engine's `meta/reference/reference-1.0.0.json`;
- a complete real schema: `product.schema.json` of Finitless-com/website-marketplace-theming
  (75 components, 197 visibility keys, 39 images, 26 pages). Read it with `jq` or
  `node`; it has 48 000 lines.

## 1. Skeleton

```json
{
  "$schema": "urn:ordering:theme-schema:1",
  "product_type": "<from the list in SF §1>",
  "version": "1.0.0",
  "format": 2,
  "platforms": ["mobile_web"],
  "editor": { "section_groups": [], "sections": [], "parts": [], "labels": {}, "props": {} },
  "tokens": { "…": "the required scales of §2; empty does not validate" },
  "components": {},
  "visibility": { "$type": "boolean" },
  "images": { "$type": "image" },
  "pages": {},
  "site": {}
}
```

- **`$schema`** is exactly that URN. It is not a URL, so editors such as VS Code do not
  autocomplete: they ignore a file association while `$schema` holds the URN. It only
  works in editors that give priority to the association with `meta/theme-schema-1.json`. The
  reference is `ordering-theme validate` (CaS §3).
- **Nodes and groups:** a node has `$value` and can have `$type`, `$description`,
  `$deprecated` and `$extensions`; a group is any other object. The `$type` on the group
  applies to its leaves.
- **Names:** `^[a-z0-9_]+$`. The keys of `visibility` and `images` allow dots, up to
  8 segments.
- **Aliases:** `{group.name}` (`{color.primary}`, `{space.md}`). Only in components,
  pages and `site`, never in `tokens`.
- **`$extensions["co.ordering"]`** is what the engine reads: `scale`, `options`,
  `inherits`, `follows`, `derive`, `matrix`, `platforms`, `formats`, `hints`,
  `max_length`, `pattern`. **`$extensions["co.ordering.editor"]`** is what the editor reads
  (§3).
- **Property types** (SF §3): `color`, `role`, `dimension` (with `scale`: `space`,
  `radius`, `size` or `icon`), `number`, `duration`, `opacity`, `typography`, `fontRole`,
  `border`, `shadow`, `image`, `boolean`, `option`/`layout` (with `options`), `sections`,
  `slot`, `string`, `html` and `id`. A `dimension` without `scale` on a property is not
  valid: use `number`.

## 2. Tokens

Copy the complete scales from CaS §8 (font, radius, size, icon, space, layout, motion,
border, shadow) and set the values approved in phase 3. The rules:

- Each token with the exact `$type` of the catalog, and in `font` the 10 `options` in the
  catalog's order.
- Literal values without units: px on the web.
- Only the colors, typographies and opacities the code reads; at least one of each.
- The Identity groups (`shadow_style`, `border_style`, `motion_style`, `focus_ring`,
  `scrollbar`) are added if the editor offers them, and the properties that follow them carry
  `follows`.
- **Declare your own rules:**
  - `derive` on derived colors (`shade`, `mix`, `legible`, `same`, with `when`);
  - `follows` on properties;
  - `derive`/`matrix` on components.

  If a category declares no rule, the engine applies its own inherited ones and adds
  colors your schema does not have (SF §11.5).
- Derived colors the author must not touch carry `hidden: true` in the editor.
- A translucent color carries `alpha: true` in the editor (`alpha_required`).

## 3. Editor metadata (SF §12)

Per node, in `co.ordering.editor`:

- **`label`:** `{ "key": "BUILDER_THEME_…", "default": "English text" }`, and optionally
  `info` with the same shape. The keys follow a pattern per type:
  `BUILDER_THEME_COMPONENT_<C>`, `BUILDER_THEME_PROP_<P>`, `BUILDER_THEME_VIS_<K>`,
  `BUILDER_THEME_PAGE_<P>`, `BUILDER_THEME_COLOR_<C>`, `BUILDER_THEME_OPTION_<O>`,
  `BUILDER_THEME_GROUP_<G>`. Shared property labels go in
  `editor.props`. **List each new key with its English text for the Dashboard**, which
  translates from its catalog.
- **`group` and `order`:** within a component, `order` follows the sequence box (background,
  border, radius, shadow, padding) → layout → texts (each color right after its
  text) → sizes → animation. If the component has cards (`groups`), **every** node
  carries `order`, and the `paths` of each card go in increasing order.
- **`control`:** `color`, `level` (scales), `range`, `select`, `segmented`, `toggle`,
  `border`, `shadow`, `typography`, `font`, `image`, `sortable_sections`, `text`, `html`,
  `matrix`, `cards` or `scope`.
- **`min`, `max`, `step` and `unit`** (`px`, `ms`, `%`, `x`) on every number. They come from what
  the web can take, and the default must fall inside. **They bind the values:** a default out of
  range is `default_out_of_range` and a stored value out of range is clamped when read.
- **Other keys:** `visible_if`/`disabled_if` (`{ path, op, value }`), `hidden`,
  `readonly`, `related` (related keys the editor links) and `custom` (the component's own
  typography in a `level` control).
- **Scales:** `ordered` (`ascending`/`descending`) on the editor card that lists the
  levels (`editor.sections[].groups[]`), so that the editor requires them to be monotonic.

The root `editor` block, with the structure that worked in the marketplace:

| `section_groups` | `sections` (key → parent, layout) |
|---|---|
| `identity` | `colors`, `typography`, `border`, `shadows`, `sizes`, `layout`, `motion` → identity, cards |
| `advanced` | `components` → advanced, list (groups: controls and parts of the site) |
| `website` | `site` (`store: "site"`), `pages` (list), `visibility` (cards, one group per zone), `images` (cards) → website |

A section that resets something carries `reset` with the paths, for example
`["visibility", "platforms.{platform}.visibility"]`. `editor.parts` names the
tabs of a component's parts (`base`, `colors`, `variants`, `sizes`, `shapes`,
`slots`, `scope`, `platforms`).

The editor rules that fail most (§12.3), with their code:

| Code | Cause |
|---|---|
| `in_no_editor_group`, `in_several_editor_groups` | a visibility key is not in exactly one group |
| `order_missing`, `order_repeated`, `order_decreasing`, `order_not_consecutive` | `order` incomplete, repeated or out of order |
| `group_mismatch` | the node's `group` does not match the card or the group that lists it |
| `in_no_card` | a `base` node declares a `group` that no card lists |
| `limits_required`, `min_over_max`, `default_out_of_range` | `min`/`max` missing or the default falls outside |
| `alpha_required` | translucent default without `alpha: true` |
| `section_groups_required`, `_mismatch`, `alone_in_group` | section groups of a page |
| `custom_required` | a component typography with a `level` control without `custom: true` |
| `font_unlisted`, `font_listed_twice` | `options` of the font control |

## 4. Components, visibility, pages, images and `site`

- **Components:** `components.md` and SF §5, §11. An example with cards, order, toggle,
  slots, scope and platforms is the reference schema's `header`; one with
  `colors` × `appearances` and `matrix` is its `button`.
- **Visibility, pages, images and `site`:** `pages-and-visibility.md`, and SF §6–§9.
- **Principle (CaS §2, at the end):** everything the code reads from the theme is in the schema
  with its default; a theme stores only what changes.

## 5. CLI and repo scripts

```jsonc
// package.json
"theme:validate": "ordering-theme validate product.schema.json",
"theme:compile":  "ordering-theme compile product.schema.json --out src/theme.compiled.json",
"theme:verify":   "pnpm theme:compile --check",
"theme:publish":  "ordering-theme publish product.schema.json",
"theme:publish:dry": "ordering-theme publish product.schema.json --dry-run"
```

- **v1 map:** if the product has v1 themes, add `--v1-map <map.json>` to validate,
  compile and publish.
- **`compile`:** writes the compiled form (defaults, rules, CSS units,
  `product_type`, `version`) that `complete()` and `toCss()` read. It is committed, and
  `theme:verify` fails if it is stale.
- **Other commands:** `defaults` (the resolved empty theme) and `rules` (rules for a
  client without a schema). The complete list is in the engine's README, section "The
  `ordering-theme` CLI".
- **Publishing:**
  - `publish` needs `THEME_PUBLISH_API` and `THEME_PUBLISH_KEY`;
  - without them it is skipped with exit 0, unless `--required` is passed;
  - in CI, `--dry-run --required` on every PR (otherwise the step passes silently without the
    secret) and `--required` on deploy, each environment with its API and its key;
  - publish for real only with the user's authorization.
- **Exit:** 0 ok, 1 invalid schema or stale file, 2 incorrect usage.

## 6. Versions (CaS §6, SF §13–15)

- A new schema starts at **`1.0.0`**, without `migrate` or `upgrade` blocks.
- A **published version is immutable** (409 `schema.version_exists`). Bump it only
  when the current one is already in production.
- **Patch:** texts and editor metadata that validate nothing.
- **Minor:**
  - adding a key, a component, an option, a section or `alpha`;
  - changing defaults;
  - adjusting `max_length`, `pattern` or `min`/`max`;
  - changing a section's `hideable` or `group`;
  - marking `$deprecated`, or removing a key the previous version already marked so;
  - renaming or moving within its section via `co.ordering.migrate`.
- **New base (major):** what a minor forbids:
  - removing a non-deprecated key;
  - changing the type or the scale;
  - removing an option, `alpha` or `custom`;
  - removing a migration.

  It carries `co.ordering.upgrade`, which says where each previous key goes (SF §13–15).
- **`--development`:** turns version rejections into warnings while iterating.
- **v1 map:** only for products with v1 themes. It is a JSON `v2 path → v1 path` per
  section, outside the live schema, with which api-core migrates old themes. Several
  v2 keys of the same type can share a v1 path. A key without an entry takes its
  default when migrated.

## 7. When adding something later

1. Add the node with `$type`, default (alias to a token), `scale`/`follows`/`alpha`.
2. Add its editor metadata.
3. Add its entry in the v1 map, if it has an equivalent.
4. Bump the version, if the current one is already published.
5. Run `theme:compile`, `theme:validate` and `theme:verify`.
6. Connect its reader, through a helper, and its test.
7. Update `docs/THEMING.md` if anything it describes changes.
