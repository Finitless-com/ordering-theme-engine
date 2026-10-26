# Inventory on React Native

The method is the web skill's: read `../theme-frontend-web/references/inventory.md` (two
layers: scripts find candidates, agents go through the screens; the report format; the
consolidation by pattern). This file is what changes in a React Native app.

## 1. Scripts

`inventory.mjs`, `scales.mjs` and `components.mjs` read React Native too:

- styled-components/native templates (`padding-horizontal: 20px`), `StyleSheet.create`
  objects, inline `style={{ … }}` and style arrays: object values are unitless numbers
  (pt);
- React Native's own props: `paddingHorizontal`/`Vertical`, `marginHorizontal`/`Vertical`,
  `*Start`/`*End`, `border*Width`, `tintColor`, `placeholderTextColor`, `shadowColor`, and
  the shadow split into `shadowOpacity`, `shadowRadius`, `elevation` (category
  `shadow_part`; `scales.md` lists them under "Other": `elevation`, `shadow_radius`,
  `shadow_opacity`). `shadowOffset: { width, height }` is not read as a size;
- JSX props whose meaning depends on the element: `size` on a text component (`OText`,
  `*Text`, `*Title`) is a font size, anywhere else an icon size (check it: most `size`
  props are icons', but not all); `width`/`height` on an icon (`*Icon`, `AntDesign`,
  `Ionicons`, …) are an icon size, elsewhere a box size; `weight` is a font weight,
  `lineHeight` a line height, `activeOpacity` an opacity;
- `Animated.timing({ duration })` and other bare `duration`/`delay` numbers as motion (ms);
- screens: React Navigation's `<X.Screen name="…" component={…}>`, listed with their
  navigator and component under "Routes and screens";
- React Native elements (`TouchableOpacity`, `Pressable`, `Image`, `FastImage`,
  `TextInput`, `Modal`, `ActivityIndicator`…) counted like the web's native tags, and
  styled ones (`styled.TouchableOpacity`) given their role (a styled touchable is a button
  made by hand);
- images: `require('…png')`, `source={{ uri: … }}` and remote URLs.

Run them on `src` with the logic submodule excluded (for example
`--exclude '/src/@/'`). Count apart what is not the app's look: native modules,
`android/` and `ios/` (not scanned), Lottie JSON, SVG illustrations.

## 2. What else to inventory by hand

The scripts cannot see these; the area agents report them:

- **The theme today.** Where it is defined (`theme.json`, a theme file, constants),
  every provider that passes it down (there may be two: one from `styled-components`, one
  from `styled-components/native`; only the second reaches native styled components),
  how a served theme is merged into it and which of its keys survive the merge. List the
  keys the code reads and **keys read but not defined** (they are `undefined` today: a
  bug the theme must not reproduce) and **defined but never read** (they go).
- **What the served theme drives today.** Every `theme.<page>.components.….hidden` flag,
  layout switch (a whole-design variant such as `app_design.components.layout.type`), image path and
  text the app reads from the API: with its path and its reader. It becomes the v1 map
  (`schema.md` §4) and the first visibility keys and sections.
- **Fonts.** The font files bundled (`react-native.config.js` assets, `Info.plist`
  `UIAppFonts`, `android/app/src/main/assets/fonts`), how `fontFamily` is chosen (one
  file per weight, `'Poppins-SemiBold'`; or one family plus `fontWeight`, which is a
  faux bold on Android) and every hard-coded family.
- **Icons.** The icon sets (react-native-vector-icons imports, under any alias), tinted
  PNG icons (`Image` with `tintColor`), SVGs; how size and color are passed.
- **Platform branches that change the look.** `Platform.OS` / `Platform.select` /
  `.ios.tsx` / `.android.tsx`: font weights (`ios ? '600' : 'bold'`), spacing, shadows vs
  elevation, keyboard behaviour. Each is either a theme value on a `platforms` entry, a
  rendering difference the runtime absorbs (shadows), or behaviour (not the theme's).
- **Sizes computed from the screen.** `Dimensions`, `useWindowDimensions`, column counts,
  card widths as a share of the window, splash images by width, tablet splits
  (`DeviceInfo.isTablet()`, width thresholds), orientation.
- **Safe areas, status bar and keyboard.** Who paints behind the notch and the home
  indicator, the status bar style, keyboard avoiding.
- **Lists.** Which screens are a `ScrollView`, a `FlatList` (`ListHeaderComponent`,
  `ListFooterComponent`) or a `SectionList`, and which children are sticky
  (`stickyHeaderIndices` computed from conditional children breaks when a section is
  hidden or moved).
- **Skeletons and placeholders** (`rn-placeholder`, custom ones) and their sizes.
- **Third-party UI** the theme cannot paint or paints only partly: maps, Stripe sheets,
  WebViews, native date pickers, react-native-paper inputs, system alerts
  (`Alert.alert`).

## 3. Going through the screens

An agent reads each screen's render tree in order (navigator → page → UI component →
children), and when a simulator is available, runs it on **iOS and Android** with screenshots of
each state: signed in and out, empty, loading, error, each order type, each design
variant, a small phone (iPhone SE size) and a large one, and a tablet if the app ships
for one. The report per area is the web's (`file:line`, values today, function,
proposal), plus for each screen its root container (ScrollView, FlatList…) and its blocks
in render order.

## 4. Starting metrics

As on the web, plus: inline `style={{` count, `StyleSheet.create` count, `size={N}`
literals on text and on icons, hard-coded `fontFamily`, `Platform.OS` branches that
change the look, and keys read but not defined.
