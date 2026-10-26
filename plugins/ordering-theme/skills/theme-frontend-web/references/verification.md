# Verification

## 1. Automatic checks (CI)

| Check | What fails |
|---|---|
| `theme:validate` | the schema against the meta-schema, api-core's rules and the editor's |
| `theme:verify` | `theme.compiled.json` is not what the current schema compiles to |
| `guard.mjs --allow theme-exceptions.json [--baseline …]` | new design literals in the code |
| `keys.mjs` | schema keys without a reader (confirm doubtful hits by hand) |
| `theme:publish:dry --required` | what api-core would reject on publish; fails without the secret |
| lint, tests, i18n | the usual |

The guard in CI:

- **While converting:** run it with the baseline, which only goes down. When a batch leaves
  a file below it, regenerate it with `--write-baseline` in that same commit.
- **When finished:** run it without a baseline, only with the recorded exceptions.

## 2. Tests to write

- **Defaults sync:**
  - `theme.compiled.json` is what the schema compiles to;
  - `complete(compiled, null)` has each section the app reads, with no unresolved aliases;
  - the button renders in each `color` × `appearance`.
- **Robustness:** a theme with broken values (unresolved aliases, a `;`, numbers out of
  range, invalid colors) renders the defaults and does not break the CSS.
- **Helpers:** each helper with its default theme and with a changed theme. For that, a
  `themeWithValues(mutate)` utility that starts from the defaults, applies the change and
  rebuilds the theme, so that the CSS view is consistent.
- **Primitives:** changing each property of its component changes the injected CSS
  (radius, border, shadow, padding, typography, states).
- **Per component:** each property you added has a test that changes it and sees the
  effect (`<component>Theme.test`).
- **Visibility:** each key hides what it says and nothing more; the phone override
  applies below the breakpoint.
- **Sections:** pages follow the theme's order within each group, hide what is
  hideable and keep fixed what is `group: null`.
- **Contrast:** with the defaults, `*_text` ≥ 4.5:1 on `background`/`surface`,
  `border_strong` ≥ 3:1 and disabled text ≥ 3:1.

## 3. Extreme themes

Test the app with themes that stress the schema. Apply each theme only inside an iframe or
a test window; never change real data:

| Theme | What it reveals |
|---|---|
| square: radius `none`, no shadows | dependencies on fixed radii or shadows |
| pill: radius `full`, uppercase, serif | overflowing texts, uppercase that spreads (an address button in the header) |
| dark: dark backgrounds, light text | fixed colors, invisible icons, veils |
| light primary (`#FFD600`) | illegible `on_primary` and `primary_text` |
| dark primary | hovers and states without contrast |
| large scales: space and sizes at the maximum | overlaps, horizontal scroll |

Measure on each screen, on desktop and at 390 px:

- the contrast at rest and on hover;
- the focus ring visible and not clipped;
- the radii;
- that there is no horizontal scroll;
- that there are no overlaps.

Use the browser (skill `claude-in-chrome` or `run`) and save screenshots of what fails.
Combinations the schema cannot express become proposals, such as hover
separate from pressed or button text per size, and are presented to the user.

## 4. Visual review with the defaults

With the default theme, the app must look as before except for the approved visible
changes. Compare screen by screen, on desktop and phone, signed in and signed out,
with before and after screenshots. Also check:

- a single `h1` per page and heading levels without gaps;
- visible focus on everything that can be pressed;
- no hover or hand cursor on what cannot be pressed;
- icon buttons with an accessible name.

## 5. Final report

In `docs/theme/report.md`:

- **Before and after metrics:**
  - literals per category;
  - distinct colors versus color tokens;
  - components by function versus theme components;
  - loose `styled.button`s;
  - schema keys per block;
  - new tests.
- **Exceptions**, with their reason, and **visible changes**, with their approval.
- **New editor keys** for the Dashboard.
- **What was not verified** and why.
