# Pages, sections, visibility, images and site settings

## 1. What goes where

| It is… | Goes in | Example |
|---|---|---|
| A **block** of a page (hidden or reordered within the page) | `pages.<page>.sections` | `home.sections: hero, featured, promo_banners` |
| A **piece** inside a block, or of a component that appears on several pages | `visibility.<key>` | `header.cart`, `business_card.fee` |
| Pure decoration (color, size, border of something that is always there) | `components.*` | `business_card.base.logo_size` |
| An image the theme can change | `images.<key>` | `home_hero`, `empty.orders` |
| Something of the **site** that does not change when the theme changes (name, HTML texts, third-party codes, mode) | `site` | `site.name`, `site.third_party_code.head` |
| An interface text | i18n, not the theme | titles, buttons |

An element has a single switch. A key with default `false` replaces a fixed `hide*` or
`show*` constant in the code. What changes per device is the key's **value**
(`platforms.mobile_web`), not a second key.

## 2. Pages and sections

Each page in the editor is a route or group of routes with its own layout. Its node has
exactly `footer` and `sections` (schema-format.md §8).

```json
"home": {
  "footer": { "$type": "boolean", "$value": null },
  "sections": { "$type": "sections", "$value": [
    { "key": "hero",          "visible": true, "hideable": false, "group": null },
    { "key": "featured",      "visible": true, "hideable": true,  "group": "main" },
    { "key": "promo_banners", "visible": true, "hideable": true,  "group": "main" }
  ],
  "$extensions": { "co.ordering.editor": {
    "control": "sortable_sections",
    "section_groups": [{ "key": "main", "label": { "key": "BUILDER_THEME_SECTION_GROUP_MAIN", "default": "Under the banner" } }]
  } } }
}
```

- **Order of the list** = default order.
- **`group`**: the section moves only among those of its group, which are siblings in the
  same container (the main and side columns of the checkout, for example). With `null` it
  stays fixed in its place. A group needs at least two sections.
- **`hideable: false`**: the page does not work without it (then `visible: true`). The
  editor shows it with a padlock.
- **`footer`**: `null` follows `visibility.footer`; `true`/`false` force it on that page.
- The `sections` node declares in the editor `control: sortable_sections`, the labels of
  each section (`options`) and `section_groups` with exactly the groups it uses, in order
  of appearance.
- In the code, the page renders its sections **in the theme's order** with a hook
  (`usePageSections(page).render({...}, { group })`, see `runtime-web.md`). A section in the
  schema without a renderer, or a renderer without a section, is an error.

**Cannot be hidden or moved:**

- what has an absolute or full-bleed layout and assumes it is at the top;
- an infinite scroll (with sections below it, pagination is delayed);
- what is needed to pay or complete the flow;
- the order the backend imposes;
- the rows of a table or an invoice (it would falsify the total);
- legal and global elements;
- prices, "sold out" and other real data;
- third-party branded buttons.

## 3. Visibility

- **Flat dotted keys**, up to 8 segments: `<page|component|zone>.<piece>`,
  for example `header.cart`, `business.header.reviews`, `checkout.business.phone`,
  `confirmation.driver.photo`. A key without a dot is the master switch of something
  (`bottom_nav`, `footer`).
- **Always boolean**, default `true` (shown), except what is hidden today.
- **Default per platform** in `co.ordering.platforms: { "mobile_web": false }`, only on
  visibility keys.
- **Editor:** each key in **exactly one** editor group (`in_no_editor_group`,
  `in_several_editor_groups`), by zone (header, menu, business card, checkout…). A group
  can be shown inside the page or the component it belongs to (`pages` or
  `components` in the group).
- **Dependencies:** use `disabled_if` (the counter cannot be hidden if the cart is already
  hidden) and `info` with the consequence ("without the menu the profile cannot be reached").
- **Controls that change order options** (type, time, address) are themed,
  but not hidden.
- **Accessibility:** hiding an `h1` leaves it only for screen readers; it does not delete it.
- **Robustness of the code:**
  - the helper returns `true` for an unknown key;
  - no `?? true` on top of the default;
  - no typos in the key (`keys.mjs` detects them);
  - a key hides no more than it says.

## 4. Images

Each key in `images` carries `formats` and `hints` (schema-format.md §7):

- **Ideal `size: [w, h]`:** the largest size at which it is rendered × 2 (2x screens), or × 3 if
  it measures ≤ 100 px. The ideal is not the current size of the file.
- **`ratio`:** the ratio of the box. If the box crops (`cover`), use a ratio
  between those of the breakpoints and indicate the **safe zone** in `safe`.
- **`alpha`:** whether it needs transparency (logos, empty-state illustrations).
- **`max_kb`:** strict on small and repeated images, loose on heroes. Up to 5000.
- **`formats`:** jpg, png, webp and avif by default; svg for logos; ico/png/svg for the favicon.

Rules worth fixing in the inventory:

- logos are never distorted: `contain`, with the box defined by the height;
- the same logo is rendered with the same box everywhere;
- empty states use a fixed box with `contain`;
- a map marker is square, anchored at the bottom center.

An empty key (`""`) uses the bundle's image. That fallback lives in a single module of the
code; the schema does not copy it.

Organize the images in editor groups: logos (logotype, isotype and their inverse
versions), icons (favicon, app icon), banners, empty states, placeholders and maps.

## 5. Site settings (`site`)

What belongs to the site and not to the theme:

- name and description, with `max_length`;
- home and footer HTML (`html`);
- third-party code in head and body;
- operating modes (`option`);
- app IDs with their `pattern`.

Each node declares `$type`. The editor shows it in a section with `store: "site"`.
Some fields are computed (`mode`, slugs): declare them only to type them (schema-format.md
§9).

## 6. Phone

A `mobile_web` platform (below a single breakpoint, for example 576 px)
allows different values in:

- `base` properties of components (the component's `platforms.mobile_web`);
- visibility keys;
- pages (the theme can store `platforms.mobile_web.pages`).

Use **one** breakpoint in all the code. In the marketplace there were 576 in JS and 567 in CSS, and
it was a bug.
