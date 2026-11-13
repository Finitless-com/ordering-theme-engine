# One look: the pass after the guard

The guard at zero and a reader for every key prove that every value comes from the theme.
They do not prove that the same thing looks the same on every screen, nor that the
defaults fit a phone. The marketplace app needed four more review rounds after its
conversion was "done" (`docs/theme/decisions.md` of
Finitless-com/ordering-app-marketplace-theming, from "Web comparison" down). Plan this
phase; do not discover it.

Run it on a simulator of each platform, with screenshots before and after, and bring each
visible change to the user as a decision.

## 1. Phone defaults

A native schema that starts from the web's names and role model keeps the products
alike and reuses the editor's labels. Its **sizes** are a desktop's.

- Go through every component's defaults against today's look on a phone: the web's
  header (bar 70, logo 45, title `h4`) became bar 48, logo 25, title `h3`; the store
  header, the product card, the order card, the empty state and the tab bar all needed
  their own numbers.
- A preset that drops today's weight (a 16/600 card name mapped to `body_lg`, 16/400) is
  a visible change nobody asked for: keep the weight with a custom style and list it.
- Colors, radii, shadows and motion can follow the web's values when the user decides
  the products should match (a large visible change: record it). Sizes cannot.

## 2. The web's schema, key by key, both ways

When a website of the same product is already themed, compare the two schemas once the
code reads the theme:

- **Keys the app has no piece for go.** A schema written from the web's carried 100 keys
  a phone never draws (desktop widths, drawers, hover states, the web's hero card, a
  gallery, spinner diameters). `keys.mjs` finds them; remove them before the first
  version is published.
- **Keys of the web whose piece the app draws are added.** 31 visibility keys were
  missing, and tab slots the schema already had were read by nobody. Use the web's names
  and groups; the default of a key the web hides by default is the user's decision.
- **Not added, on purpose:** a key that would cut the navigation (the tab bar itself, the
  tab of the main flow), or one whose piece the app shows somewhere a section already
  hides.

## 3. One role, one place

Texts of the same role must read one place of the theme, never a preset picked by each
screen. Before this pass the app had three card-title styles and three section-title
sizes, all "from the theme". Write the table for the app and put it in `docs/THEMING.md`:

| Role | Reads |
|---|---|
| Screen title | the header's title |
| Section title (page sections, checkout sections, menu categories) | one `section_header.title`, through one component |
| Card title (business, product, cart, order) | the card's `name`: one style shared by every card |
| Metadata of a card (time, fee, distance, rating) | the card's `meta`, in the muted color |
| A highlighted value (a status, an estimate) | at most a small heading |
| Body copy and leads | `body` |
| Tab bar labels, badges, captions | their own small style |

Dates and times are part of it: one formatter (the project's format) for a date wherever
it shows. The same order read "08:50am" on one screen and "08:50AM" on another.

## 4. One primitive per function, again

Phase 2 groups what the inventory saw. After the code is converted, the same function
still hides in several shapes. The ones found late:

- the back button of every header (one component, its glyph on the page gutter);
- chips: filters, address tags, payment methods, driver tips, review choices;
- radio and check marks, and the row of a list of choices;
- section titles, and the separator between a screen's sections;
- a business's logo and its frame (card, order, order details, reviews);
- the order type and moment control, in the head and in the checkout;
- wallet rows of the two checkouts;
- every select, picker and date wheel: one sheet.

Libraries that paint their own UI (pickers, dropdowns, skeletons, pagers, an OTP field, a
phone field, a chat thread) cannot read the theme: each was replaced by a primitive of the
app or by a library that takes styles. Only what the platform or a provider paints stays
out (maps, payment sheets, WebViews, system alerts).

## 5. Layout rules no token gives

- **The page gutter on every screen**, headers included: the back glyph, the titles, the
  cards and the bottom bar's content start on the same edge.
- **A state never moves its control.** A selected chip with a border and an unselected
  one without shift the row: reserve the widest border of the states in every state. A
  label that turns bold when selected keeps its bold width.
- **A row of chips is one row that scrolls**, never wrapped.
- **A section owns no outer margin**, and one with nothing to show renders no view: a
  blank band between two sections is an empty wrapper with a margin.
- **A skeleton has the loaded piece's size.** Measure the shift when the content
  arrives (it was 57 pt on one carousel, under 1 after).
- **Every bar pinned to the bottom** keeps the system inset or a minimum of the theme
  under its content, whichever is larger: the inset is 0 on Android with gesture
  navigation when the activity pads the content itself.
- **A card carries its radius on the box that paints its background and shadow**; a photo
  inside takes the inner radius (the radius minus the border).
- **A card that opens something is pressable as a whole**; a row never trades its title's
  room for a button.

## 6. What each platform paints

- **A shadow needs an opaque box under it.** On a transparent image or a view without a
  background Android paints a grey box and iOS warns it cannot compute the shadow: carry
  it on a box with a background and put the picture inside. A translucent or see-through
  look drops its shadow in the box helper.
- **Android's elevation paints nothing above the box.** Cards with a shadow and no
  border had no top edge on Android. The app's cards default to a thin border that does
  not follow Identity's width; a theme can still remove it.
- **Grids in whole points.** A width with a fraction wrapped the third card of a row on
  Android only.
- **Android text fields:** a one-line field needs its text centered explicitly, a text
  area needs to start at its top.
- **Identity radius `full`** turns large boxes into ovals and crops their content. Raise
  it with the user (limit the editor's options, or cap boxes).

## 7. Narrow phones

Check at 320, 360 and 411–430 pt wide, on both platforms. What broke at 360: a button
that left 85 pt for a business name, pills that cut "Delivery", a title that wrapped and
grew its bar, a price that wrapped under its button.

## 8. Running it

- One agent per area with a simulator of its own, before and after screenshots saved by
  area, and a list of what it did not see.
- The coordinator opens every screenshot and reads every diff: an agent's "looks right"
  is not a check.
- Each choice that changes the look goes to the user as a question with its options;
  approved ones go in `docs/theme/decisions.md`, by round.
- A decision already in the log is not reopened by a later round: when a finding
  contradicts one, ask.
