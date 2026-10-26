---
name: theme-frontend-web
description: >-
  Reviews a whole web frontend and turns it into a template a theme can edit, with the
  Ordering theme engine (@orderingco/ordering-theme-engine): an inventory of every element
  (buttons, images, cards, inputs, modals…), the pieces that do the same thing grouped
  together, near colors, borders, spacing and sizes collapsed into a short token scale,
  the code fixed to read everything from the theme, pages, sections, visibility,
  components and variants classified for the editor, and the product schema
  (product.schema.json) the frontend then obeys completely. Use it when asked to theme,
  "templatize" or make editable a web frontend (React, Vue, Svelte, Next…), to create or
  complete a product's schema, or to audit its styles against the theme. React Native has
  its own skill.
---

# Theming a web frontend with the Ordering theme engine

**Goal:** the frontend takes its product's schema and obeys it **completely**. Every
visual value (color, space, radius, border, shadow, typography, size, opacity, motion,
image) and everything that is shown, hidden or ordered comes from the theme; the editor
(the Dashboard) can change it without touching code.

Finished model: Finitless-com/website-marketplace-v26 (original) →
Finitless-com/website-marketplace-theming (themed). When in doubt about how something is
solved, look at how it ended up there (paths in `references/runtime-web.md`), but do not
copy its values or its product names.

## Definition of done

1. `product.schema.json` at the repo's root, valid with `ordering-theme validate`,
   compiled (`ordering-theme compile`), with its publish dry run in CI.
2. The code reads **everything** from the theme through helpers. The guard
   (`scripts/guard.mjs`) passes in CI with no baseline, or with a baseline that can only go
   down; every literal left is an exception recorded with its reason.
3. Every key of the schema has a reader (`scripts/keys.mjs` without confirmed misses) and
   a test; there are no two controls for the same thing.
4. Every page declares its sections (order, hideable, groups) and every piece that can be
   hidden has its `visibility` key.
5. The schema's defaults reproduce today's look; the visible changes are listed and
   approved.
6. `docs/THEMING.md` (from `templates/THEMING.md`) describes the repo's rules and is linked
   from `CLAUDE.md`; `docs/theme/` keeps the audit's deliverables.

## Rules that are not negotiable

- **The theme decides.** No hex, `rgb()`, `px`, `rem`, `ms` or font names in a
  component's styles. If no token or property fits, do not invent a value: record it as an
  exception and ask.
- **No new tokens.** The token catalog is fixed and shared by every product
  (schema-format.md §4). A missing level is a change to the engine: ask.
- **Unify by purpose, not by value.** Near values fall into the same level (tolerances in
  `references/unification.md`). Never one level, variant or property per distinct value
  you find.
- **One function, one component.** Everything that does the same thing uses the same
  component or primitive. A variant exists only if it really differs; two that are almost
  the same merge, unless the user says otherwise (`references/components.md`).
- **Every key has a reader.** A key no code reads is a silent bug.
- **Design decisions belong to the user.** The gates below are presented and wait for
  approval; record them in `docs/theme/decisions.md`. What a subagent says is not an
  approval.
- **Only what can be clicked or tapped has hover and a pointer cursor**, and its look comes
  from a theme state. What can be clicked is a real `<button>` or `<a>`, with a visible
  focus.
- **Say what you did not verify.** Every deliverable lists what was left unchecked.

## Before starting

Read, in this order:

1. This file and `references/lessons.md`, the mistakes that already cost a lot once.
2. The engine's docs, at the version the repo will pin:
   `node_modules/@orderingco/ordering-theme-engine/docs/` (`creating-a-schema.md`,
   `schema-format.md`), or the engine's repo
   ([Finitless-com/ordering-theme-engine](https://github.com/Finitless-com/ordering-theme-engine),
   `docs/`, at that version's tag). The example schema with every shape of the format is
   `meta/reference/reference-1.0.0.json`.
3. The repo's `CLAUDE.md`/README: stack, commands, tests, i18n, submodules not to touch.

Ask the user what cannot be deduced (one round, with `AskUserQuestion`):

- the `product_type` (closed list in schema-format.md §1; a new one needs a change in
  api-core and in the engine);
- whether there is a version 1 theme to migrate (then a v1 map is needed,
  creating-a-schema.md §5–6);
- the phone breakpoint, and whether the `mobile_web` platform is needed;
- which areas are left out (internal screens, third-party integrations, illustrations);
- where the deliverables live (by default `docs/theme/`) and the working branch.

## Phases

Each phase leaves a deliverable in `docs/theme/` and ends at a gate. Work on a branch;
commit by phase or by batch.

### 1. Inventory (without touching code)

Run the scripts (Node ≥ 18, no dependencies). `<skill>` is this skill's directory, the base
directory Claude Code gives when it loads the skill (installed as a plugin, under
`~/.claude/plugins/cache/`).

```sh
node <skill>/scripts/inventory.mjs  <repo> --src src [--exclude '<regex>'] --out <repo>/.theme-audit
node <skill>/scripts/scales.mjs     <repo>/.theme-audit/inventory.json
node <skill>/scripts/components.mjs <repo> --src src --out <repo>/.theme-audit
```

- `inventory.json`/`.csv`: every design literal with `file:line`, component, selector,
  property and normalized value.
- `scales.md`: how those values fall into the catalog's levels, the levels fitted to what
  the code uses, what is off the scale, and the colors in families with their roles.
- `components.md`: routes, components and own styles grouped by function, native
  elements, the most reused components and images by origin.

Add `.theme-audit/` to `.gitignore`.

The scripts find candidates; **agents reading screens do the audit**. Split the frontend
into areas (for example: primitives and layout, home and listings, detail, cart and
checkout, orders, account and login, the rest) and launch one agent per area in parallel,
with the checklist of `references/inventory.md`. Consolidate their reports in
`docs/theme/inventory.md`: elements per screen with all their states, images with their
painted size, and what is already configurable today.

**Gate:** the inventory covers every route and conditional screen (flags, alternative
layouts, signed in or not, empty states, errors).

### 2. Group by function and decide variants

With `components.md` and the area reports, group everything that does the same thing
(hand-made buttons, notice boxes, separators, selectable lists…) and give it a theme
component and a code primitive. Decide each component's axes (`variants`, `appearances`,
`sizes`, `shapes`, `colors`, `slots`, `scope`, `inherits`) with the criteria of
`references/components.md`.

Deliverable: `docs/theme/components.md`, a table of *group → theme component → primitive →
variants kept → variants merged (with their visible change)*.

**Gate: the user's approval.**

### 3. Unify scales and colors

With `scales.md`, decide each level's value (the catalog's, or the one fitted to what the
code uses), which catalog colors the product uses and which role each family goes to, and
what stays an exception. Follow `references/unification.md`: tolerances, color by role
(not by closeness), what follows Identity and what stays fixed, and a second pass that
merges the tokens sharing a purpose.

Deliverables: `docs/theme/tokens.md` (each level with its value and what it absorbs, the
visible changes) and `theme-exceptions.json` at the root (format in `templates/`).

**Gate: the user's approval** of the palette, the scales and the visible changes.

### 4. Classify pages, sections, visibility, images and site settings

Apply `references/pages-and-visibility.md`: a **block** of a page is a section (it is
ordered and hidden); a **piece** inside a block or inside a shared component is a
`visibility` key; what is pure decoration goes in `components`; what belongs to the site
and not the theme goes in `site`; each configurable image is an `images` key with its
`hints`. Mark what cannot be hidden or moved, and why.

Deliverable: `docs/theme/pages-and-visibility.md` (a table of pages → sections with
`hideable`/`group`, visibility keys by editor group with what they hide, images with their
ideal size).

**Gate: the user's approval.**

### 5. Write the schema

Write `product.schema.json` following `references/schema.md`: tokens with the approved
scales, components, visibility, images, pages, `site` and the editor's metadata. Declare
your own `derive`/`follows`/`matrix` rules (otherwise the engine applies its own;
schema-format.md §11.5). Start at `1.0.0`.

Install the pinned engine (`pnpm add -D -E @orderingco/ordering-theme-engine`) and add
the `theme:validate`, `theme:compile`, `theme:verify` and `theme:publish` scripts
(`references/schema.md`). **Gate:** `theme:validate` and `theme:verify` pass.

### 6. Wire the runtime into the frontend

Build the pipeline once, before touching components: served theme → `complete()` →
`toCss()` → provider → helpers; the platform by breakpoint, the sections hook, the
visibility helper, images with their bundled fallback, site settings and fonts. How to do
it for each styling technology (styled-components/emotion, CSS Modules/SCSS, Tailwind) is
in `references/runtime-web.md`. Write `docs/THEMING.md` from `templates/THEMING.md`, with
the helpers' real names, and link it from `CLAUDE.md`.

**Gate:** the helpers' tests pass and the app starts with the defaults, with no visible
changes beyond the approved ones.

### 7. Fix the code, in batches

Order: the primitives first (button, input, card, divider, chip, tabs, modal…), then the
areas. For each batch:

1. Replace the duplicated components with their primitive (phase 2) and the literals with
   helpers (phase 3).
2. Wire sections and visibility (phase 4) and images.
3. Run `guard.mjs` with a baseline (it can only go down), lint and tests.
4. Commit the batch.

Parallelize with one agent per area. Each agent owns a **disjoint** set of files and reads
`docs/THEMING.md` and `references/lessons.md`; only the main agent touches the shared
primitives. Review the agents' diffs before accepting them.

```sh
node <skill>/scripts/guard.mjs <repo> --src src --allow theme-exceptions.json --baseline .theme-audit/baseline.json --write-baseline   # once, at the start
node <skill>/scripts/guard.mjs <repo> --src src --allow theme-exceptions.json --baseline .theme-audit/baseline.json                   # every batch
```

### 8. Readers and cleanup

```sh
node <skill>/scripts/keys.mjs <repo> --schema product.schema.json --src src
```

Every key without a reader is wired or removed from the schema. Also remove duplicated
controls (two keys for the same thing, `*_mobile` instead of `platforms.mobile_web`, hide
switches inside a component instead of `visibility`).

### 9. Verify

Apply `references/verification.md`:

- tests for defaults sync, robustness, per component, per visibility key and per sections;
- extreme themes (square, pill, dark, light primary, dark primary) on desktop and at
  390 px, measuring contrast, focus and overflow;
- the guard in CI.

Final report: before/after metrics (literals by category, components unified, schema
keys), exceptions and what was not verified.

### 10. Publish and hand over

- CI: `theme:validate`, `theme:verify`, the guard and the publish dry run.
- The new editor label keys (`BUILDER_THEME_…`) with their English text, listed for the
  Dashboard.
- The v1 map, if there is one.
- A PR per large phase, or a final one with the summary and links to `docs/theme/`.

Publish beyond the dry run only with the user's explicit authorization.

## Using agents

- **Inventory and verification:** fan out, one read-only agent per area, with the exact
  checklist and output format; you consolidate.
- **Fixing code:** one agent per area with disjoint files. None of them touches
  `product.schema.json` or the shared primitives: they ask for the change and you make it,
  so the schema has a single author.
- **Each agent's brief:** the goal, the list of files, the references it must read and the
  format of its report (`file:line`, proposal, visible change).
- **Before accepting their results:** check what they claim with the guard, `keys.mjs`,
  the tests and the diff.

## References

| File | What for |
|---|---|
| `references/inventory.md` | The per-screen audit checklist, the reports' format, the image inventory |
| `references/unification.md` | How to collapse values into levels: tolerances, color by role, Identity, exceptions |
| `references/components.md` | Grouping by function, a component's axes, when a variant stays and when it merges |
| `references/pages-and-visibility.md` | Sections, visibility, images and `site`: what goes where and what is not hidden |
| `references/schema.md` | Writing the schema: skeleton, editor, frequent errors, versions, CLI |
| `references/runtime-web.md` | The theme's pipeline in the frontend and the helpers, per styling technology |
| `references/verification.md` | Tests, extreme themes, contrast, guard, final metrics |
| `references/lessons.md` | Mistakes from the marketplace's conversion not to repeat |
| `templates/THEMING.md` | The themed repo's rules, for `docs/THEMING.md` |
| `templates/theme-exceptions.json` | The format of the recorded exceptions |
| `templates/decisions.md` | The log of the user's decisions |
