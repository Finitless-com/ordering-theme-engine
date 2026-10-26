# Inventory

The inventory is the basis for everything else: what is not found here ends up as a
stray literal, a piece that cannot be hidden or a fixed image. It is done in two layers:
the scripts find candidates in the code and the agents go through the screens.

## 1. Scripts

| Script | Output | What to look at |
|---|---|---|
| `inventory.mjs` | `inventory.json`, `inventory.csv` | How many literals there are per category and where they are concentrated |
| `scales.mjs` | `scales.md`, `scales.json` | Catalog coverage, fitted levels, values off the scale, color families |
| `components.mjs` | `components.md`, `components.json` | Routes, components and custom styles by function, stray `styled.button`, images |

Columns of `inventory.csv`: `loc, component, selector, category, property, value, norm, fallback`.

- `category`: `color`, `space`, `radius`, `border`, `shadow`, `font_size`, `font_weight`,
  `font_family`, `line_height`, `letter_spacing`, `text_transform`, `size`, `motion`,
  `opacity`, `z_index` or `breakpoint`.
- `norm`: the normalized value (`#RRGGBB[AA]`, px or ms).
- `fallback`: `true` when the value comes after `||`/`??`. A fallback is never seen if the
  theme arrives complete, so it is deleted instead of tokenized.
- In the JSON, `icon: true` marks values inside an `svg` or an icon. Those are icon
  sizes, not typography.

Adjust `--src` and `--exclude` to leave out code that is not the app's: submodules,
SDKs, inline SVG illustrations and generated files. Note what you excluded in the
inventory.

**Tailwind:** classes come out as `property: tw:<prefix>`. Steps are converted with
Tailwind's default scale (`p-4` = 16 px, `rounded-lg` = 8 px, `text-sm` = 14 px); if
`tailwind.config` redefines `theme`, read it before trusting those numbers. Color classes
carry no hex:

- `tailwind_palette` in `scales.md` groups those from Tailwind's own palette
  (`bg-gray-200`), which are literals;
- `tailwind_semantic` groups the semantic ones (`text-muted-foreground`, typical of shadcn),
  which already point to CSS variables and are mapped to a catalog token.

Arbitrary values (`p-[13px]`, `text-[#333]`) are literals.

## 2. Audit by screen (agents)

Split the frontend into areas that can be reviewed in parallel, with 6–12 areas depending
on its size. Each agent receives its list of routes and components, this checklist and the
output format. It is read-only.

Coverage: **every** screen and component the app can show, including those that
depend on:

- flags;
- alternative layouts;
- being signed in or not;
- empty, error and loading states;
- modals, popovers, menus and sheets;
- phone, tablet and desktop sizes;
- right-to-left language, if it applies.

For each element, check:

- **Typography:** family, size, weight, line height, uppercase, and whether it is a heading
  (a real `h1`–`h6`) or text.
- **Color:** of text, background, border and icon, and for what purpose (primary or
  secondary text, surface, line, state, brand).
- **Spacing:** padding, margin and gap, and whether it is separation between page blocks,
  form rhythm or the inside of a component.
- **Box:** radius, border (width and sides), shadow and control height.
- **States:** `hover`, `focus`, `active`/`pressed`, `disabled` and `selected`. Check whether
  the hover is on something that can actually be pressed.
- **Motion:** transitions, animations and durations.
- **Images:** source, painted box (width × height at each breakpoint), `object-fit`,
  fallback and `alt`.
- **What can be hidden or moved** and what would break the page if removed.
- **Duplicates:** another element that does the same thing with its own CSS.

### Format of each area's report

```md
## <Area>
### Elements
| Screen / component | Element | file:line | Values today | Function | Proposal (component, token or key) |
### Duplicates (same function, different implementation)
| Function | Implementations (file:line) | Visible differences |
### Blocks and pieces
| Page | Block or piece | Hideable? Movable? | Why |
### Images
| Key or source | file:line | Painted box | Fit | Fallback | alt |
### Off the scale or without a token
| Value | Where | Why it does not fit |
### Not verified
```

When consolidating, group the proposals by pattern and count how many areas ask for the
same thing. A new level or component is justified when **several areas** need it
separately, not by a single case.

## 3. Images

Make a table per source:

- theme keys (if there is already a theme) and who reads them;
- API data (business logos, product photos): they are not part of the theme;
- bundle assets imported without a key;
- inline SVG or data URIs: usually icons, not images;
- fixed external URLs;
- `public/` and PWA (favicon, app icons, manifest).

Findings to record:

- unused keys;
- fixed images that should be configurable;
- files that are broken or huge for their painted size;
- missing `alt`;
- duplicates;
- inconsistent fallbacks (the same logo painted in different boxes).

For the specification of each key, see `pages-and-visibility.md` §4.

## 4. What is already configurable

If the frontend already reads a theme (v1, a client JSON, CSS variables), inventory each
key it reads, with its path and its reader. That inventory becomes the schema's v1 map
(`schema.md` §6), and no key that works today can be lost without a decision from the
user.

## 5. Starting metrics

Save the initial numbers for the final report:

- literals per category;
- distinct colors;
- files with literals;
- components per function;
- `styled.button` and `styled(Button)` overrides;
- images per source.

The guard with `--write-baseline` leaves the per-file baseline.
