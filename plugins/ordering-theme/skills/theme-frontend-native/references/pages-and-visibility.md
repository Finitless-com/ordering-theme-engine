# Screens, sections, visibility and images on React Native

The rules are the web skill's: read `../theme-frontend-web/references/pages-and-visibility.md`
(what goes where; sections with `hideable` and `group`; visibility keys; image hints;
`site`). This file is what changes on React Native.

## 1. Pages are screens

- A page of the schema is a screen (or a group of screens sharing a layout) of the
  navigators: `Home`, `BusinessesListing`, `Business`, `Cart`, `Checkout`,
  `OrderDetails`, the account screens… `components.md` (the script's output) lists each
  `<X.Screen name>` once per navigator, with its component. Name pages by the product's
  concepts, not by the route names, and keep the web's page keys where the product is
  the same (a marketplace app and a marketplace website both have `business`,
  `checkout`).
- Screens draw their own header (`headerShown: false` is common), so the header is a
  component and its pieces are visibility keys, not navigator options.
- A page has `footer` in the format (`null` follows `visibility.footer`); an app usually
  has no footer: declare it `false` and leave it out of the editor, or use it for the
  bottom tab bar if the product decides so.

## 2. Sections in a scrolling screen

- A screen's blocks in render order are its sections: the children of its `ScrollView`,
  the pieces of a `FlatList`'s `ListHeaderComponent`, the screen's floating bottom button
  (usually `hideable: false`, `group: null`).
- The block that **is** the list (the businesses of a listing, the products of a menu) is
  one section, usually not hideable; it may not move below others if it scrolls
  infinitely.
- `stickyHeaderIndices` computed from conditional children breaks when a section is
  hidden or moved: compute it from the rendered sections.
- Render through one hook (`usePageSections(page).render({...}, { group })`, the web's
  shape) so the order and visibility come from the theme.

## 3. Visibility

- Today's served flags (`theme.<page>.components.<block>.components.<piece>.hidden`) are
  the first visibility keys: one flat key per piece (`business_card.fee`,
  `business.header.reviews`, `orders.reorder_button`), default `true`, and their v1 paths
  in the v1 map (`schema.md` §4). Read by the helper, never by optional chains on the
  theme.
- Bottom tabs: each tab can be a key (`tab_bar.offers`), but the tab that holds the main
  flow is not hideable.
- An older binary never receives the keys its version lacks (the cut-down leaves them
  out, `schema.md` §1); for the keys it knows, what the theme leaves out or what cannot
  be moved back shows its compiled default. So each key's default must look right in
  every binary that ships it.

## 4. Images

- A theme image is an `https` URL or `''`. On native it renders with `{ uri }` (FastImage
  or `Image`), and `''` falls back to the bundled asset (`require`), in one module.
- **Hints:** the painted size is in pt; the ideal upload is the largest painted size × 3
  (phones are 2x–3x), and the safe zone matters more because the same image is cropped
  to many aspect ratios across phone widths.
- **Formats:** `svg` needs `react-native-svg` to render remotely; leave it out of an
  image's `formats` unless the app renders SVG URIs.
- **Not themable at run time:** the native splash screen and the app icon (they are in
  the binary); a JS splash after it can read a theme image.
- **Icons are not images:** PNG icon manifests (`theme.images.general.arrow_down`,
  `option_checked`) are icons of the app, not theme images: they stay bundled and take
  their color from the theme. Only images an author would replace (logos, heroes, empty
  states, placeholders, order types, category images) become `images` keys.

## 5. Site settings

As on the web: what belongs to the app's site and not to the theme (name, description,
store IDs, third-party code) goes in `site`. App-only configuration that comes from other
APIs (feature flags, `configs`) is not the theme's.
