---
name: theme-frontend-native
description: >-
  Reviews a whole React Native app and turns it into a template a theme can edit, with the
  Ordering theme engine (@orderingco/ordering-theme-engine): an inventory of every element
  (buttons, texts, icons, images, cards, inputs, modals, sheets…), the pieces that do the
  same thing grouped together, near colors, borders, spacing, font sizes and shadows
  collapsed into a short token scale, the code fixed to read everything from the theme,
  screens, sections, visibility, components and variants classified for the editor, and
  the product schema (product.schema.json) the app then obeys completely, with the
  defaults built into each binary and the served theme cached. Use it when asked to theme,
  "templatize" or make editable a React Native app (bare or Expo, styled-components/native
  or StyleSheet), to create or complete an app's product schema, or to audit its styles
  against the theme. Web frontends have their own skill, theme-frontend-web.
---

# Theming a React Native app with the Ordering theme engine

**Goal:** the app takes its product's schema and obeys it **completely**. Every visual
value (color, space, radius, border, shadow, typography, icon and control size, opacity,
motion, image) and everything that is shown, hidden or ordered on a screen comes from the
theme; the editor (the Dashboard) changes it without a new build — within what the
installed binary knows.

This skill is the web skill's method, adapted. The shared method lives in the sibling
skill `theme-frontend-web` of this plugin (`<skill>/../theme-frontend-web/`); each
reference here says what changes on native and points to the web one for the rest. The
web conversion that serves as model is Finitless-com/website-marketplace-v26 →
Finitless-com/website-marketplace-theming; the native app reviewed to write this skill is
Finitless-com/ordering-app-marketplace-v26 (not converted yet), and api-core's plan for
themes on the apps is `docs/THEMES_V2_PLAN.md` (§1.8 native limits, §2.5 fonts, §3.7
the marketplace app).

## Definition of done

1. `product.schema.json` at the repo's root, for the app's own `product_type`, valid with
   `ordering-theme validate`, compiled into `src/theme.compiled.json` (committed, checked in
   CI), with its publish dry run in CI.
2. The app requests the version 2 theme with its `product_type` and
   `theme_schema_version`, completes it with the engine's runtime, caches it, and paints
   the build's defaults before it arrives and offline.
3. The code reads **everything** from the theme through helpers. The guard passes in CI
   with no baseline, or a baseline that can only go down; every literal left is an
   exception recorded with its reason.
4. Every key of the schema has a reader and a test; no two controls for the same thing.
5. Every screen declares its sections; every piece that can be hidden has its
   `visibility` key; today's served visibility flags and images are in the v1 map.
6. The defaults reproduce today's look on iOS and Android; the visible changes are listed
   and approved.
7. `docs/THEMING.md` (from `templates/THEMING.md`) describes the app's rules and is linked
   from `CLAUDE.md`; `docs/theme/` keeps the deliverables.

## Rules that are not negotiable

The web skill's rules hold (`../theme-frontend-web/SKILL.md`, "Rules that are not
negotiable"): the theme decides; no new tokens; unify by purpose, not by value; one
function, one component; every key has a reader; design decisions belong to the user,
with gates and a decision log; say what you did not verify. On native also:

- **No literal in a style:** no hex, no number for a space, radius, size, font size, line
  height or shadow, no `fontFamily`, no `fontWeight` in a component — in styled templates,
  `StyleSheet.create`, inline `style={{}}` and props like `size={14}` alike.
- **Every binary is a client for months.** It sends its schema version; each version
  that ships is published before the release; later changes cut down cleanly to it
  (`references/schema.md` §1).
- **What the platform paints differently stays in the runtime** (shadows vs elevation,
  font file per weight), not in the schema twice.
- **Fonts are bundled:** the catalog's 10, static, one file per kept weight (the runtime's
  fallback is only a safety net).
- **Only what can be tapped has press feedback**, from the theme's pressed state, with a
  touch target of at least 44 pt.

## Before starting

Read, in this order:

1. This file, `references/lessons.md` and the web skill's `references/lessons.md`.
2. The engine's docs at the version the app will pin (`creating-a-schema.md`,
   `schema-format.md`; §15.2 on cutting a theme down for an older client), and its
   README sections "Runtime" and "Colors".
3. The app's `CLAUDE.md`/README: stack (bare or Expo, styled-components/native or
   StyleSheet, navigation library, new architecture per platform), commands, tests,
   submodules not to touch.

Ask the user what cannot be deduced (one round, with `AskUserQuestion`):

- the `product_type` (`marketplace_app`, `franchise_app`, `store_app`, `driver_app`,
  `kiosk_app`);
- whether the app reads a served version 1 theme today (then a v1 map is needed);
- which design variants stay (a whole-layout switch such as `original`/`maweja`), and
  whether a `tablet` platform is needed;
- the weights to bundle for each catalog font, and whether dark mode is in scope (it is
  usually not, at first);
- the areas left out (internal screens, third-party sheets, maps, WebViews);
- where the deliverables live (by default `docs/theme/`) and the working branch.

## Phases

The phases, deliverables and gates are the web skill's (`../theme-frontend-web/SKILL.md`,
"Phases"); what changes:

### 1. Inventory (without touching code)

```sh
node <scripts>/inventory.mjs  <repo> --src src --exclude '/src/@/' --out <repo>/.theme-audit
node <scripts>/scales.mjs     <repo>/.theme-audit/inventory.json
node <scripts>/components.mjs <repo> --src src --exclude '/src/@/' --out <repo>/.theme-audit
```

`<scripts>` is the plugin's `scripts` directory: `${CLAUDE_PLUGIN_ROOT}/scripts`, two
levels above this skill's base directory (`<skill>/../../scripts`). Adjust `--exclude` to
the app's logic submodule. The scripts read React Native (`references/inventory.md` §1).
Then the area agents go through the screens, on iOS and Android when a simulator is
available, and inventory by hand what the scripts cannot see: the theme today and its
merge, the served keys the app reads, fonts, icons, platform branches, sizes from the
window, lists and sticky headers (`references/inventory.md` §2–3).

**Gate:** every screen of every navigator and every conditional state is covered.

### 2. Group by function and decide variants

Follow `references/components.md`: the primitives (`OText`, `OButton`, icons, inputs,
modal and sheet, header, tab bar…) first, the bypasses onto them, the design variants
kept as schema options. **Gate: the user's approval.**

### 3. Unify scales and colors

Follow `references/unification.md`: pt, typography (ratio line heights, size props,
weights, families), shadows read as one level across iOS and Android, borders, sizes,
spacing (the page gutter), colors (legacy duplicates and undefined keys), motion,
pressed opacity. **Gate: the user's approval.**

### 4. Classify screens, sections, visibility, images and site settings

Follow `references/pages-and-visibility.md`. **Gate: the user's approval.**

### 5. Write the schema

Follow the web skill's `references/schema.md` and this skill's `references/schema.md`
(product type, platforms, fonts, v1 map, versions that ship in binaries). Install the
pinned engine as a dev dependency with the app's package manager (yarn, npm or pnpm: the
web skill's examples use pnpm) and add `theme:validate`, `theme:compile` (to
`src/theme.compiled.json`), `theme:verify` and `theme:publish`. **Gate:** they pass.

### 6. Wire the runtime into the app

Follow `references/runtime-native.md`, in this order: the engine resolves in Metro,
TypeScript and Jest; `complete()` with the compiled file; the request with
`theme_version=2`, `product_type` and `theme_schema_version`; the cache; `toNative`; the
font table and the bundled fonts; one provider at the top; the helpers and their template
twins; platform, sections, visibility, images, status bar. Write `docs/THEMING.md` from
`templates/THEMING.md` and link it from `CLAUDE.md`.

Keep the legacy theme keys the code reads today, derived from the completed values,
until phase 7 is done (`references/runtime-native.md` §1).

**Gate:** the helpers' tests pass and the app runs on iOS and Android with the defaults,
without visible changes beyond the approved ones.

### 7. Fix the code, in batches

Primitives first, then screens by area, as on the web. Per batch: module-level
`StyleSheet.create` moved inside the component; inline styles and literal props onto
helpers; bypasses onto primitives; sections and visibility wired; `guard.mjs` with the
baseline, `tsc`, lint and tests; a commit. The guard's default categories leave out
sizes: add `--categories color,space,radius,border,shadow,shadow_part,font_size,font_weight,font_family,line_height,letter_spacing,motion,opacity,size`
once icon and control sizes are converted, with layout sizes that are not design values
(window shares, aspect boxes) as recorded exceptions. `shadowOffset` is not read by the
scripts: check it by hand. One agent per area with disjoint files; only
the main agent touches the primitives, the runtime and the schema.

### 8. Readers and cleanup

`node <scripts>/keys.mjs <repo> --schema product.schema.json --src src --exclude '/src/@/'`;
remove the legacy theme keys and the old theme file, the second provider, the optional
chains on the theme, the duplicated components per variant.

### 9. Verify

Follow `references/verification.md`: the checks in CI, the tests, and the devices (both
platforms, fonts, sizes, text scaling, reduced motion, offline, old binaries).

### 10. Publish and hand over

As on the web, plus: publish the schema version before the build that ships it, in every
environment; list the new editor label keys for the Dashboard; the v1 map; the fonts
bundled and their weights. Publish beyond the dry run only with the user's explicit
authorization.

## Using agents

As on the web: read-only agents per area for the inventory and the verification;
agents per area with disjoint files for the code; one author for the schema, the runtime
and the primitives; every claim checked with the guard, `keys.mjs`, the tests and the
diff. On native, give each agent the platform it must check and whether a simulator is
available.

## References

| File | What for |
|---|---|
| `references/inventory.md` | What the scripts read on React Native and what to inventory by hand |
| `references/unification.md` | Units, typography, shadows, borders, sizes, spacing, colors, motion on native |
| `references/components.md` | Primitives, press feedback, design variants, styles that cannot see the theme |
| `references/pages-and-visibility.md` | Screens as pages, sections in scrolling screens, visibility, images |
| `references/schema.md` | Binaries in the field, product type and platforms, fonts, the v1 map |
| `references/runtime-native.md` | The pipeline, the engine under Metro/Hermes/Jest, `toNative`, fonts, helpers |
| `references/verification.md` | Checks, tests and device runs |
| `references/lessons.md` | What reviewing a real app and api-core's plan taught |
| `templates/THEMING.md` | The app's rules, for `docs/THEMING.md` |
| `../theme-frontend-web/references/*` | The shared method each reference above builds on |
| `../theme-frontend-web/templates/theme-exceptions.json`, `decisions.md` | Recorded exceptions and the decision log, as on the web |
