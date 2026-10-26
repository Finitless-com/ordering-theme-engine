# Lessons from the marketplace conversion

Source: `website-marketplace-v26` → `website-marketplace-theming`, 186 commits between
October 9 and 26, 2026. Each point cost at least one cycle of redoing work.

## Process

1. **Go straight to the final architecture.** The marketplace went through:
   - a dual v1+v2 mode with fallback literals (`v2Token(theme, 'surface', '#FFF')`);
   - then it deleted v1;
   - then it deleted the fallbacks;
   - it wrote a merge, a validator and converters by hand, and then replaced them with the
     engine's `complete()` and `toCss()` (−9 000 lines).

   In a new frontend, set up the pipeline in `runtime-web.md` from the start. Only
   go through a dual mode if production requires the old theme to keep looking identical
   during the conversion.
2. **Decide spacing at the start.** It was declared "not themed", came back as
   `tokens.space` and forced another pass over everything. In the end ~1 000 paddings and
   margins remained in px.
3. **Start with few tokens and properties.** The schema was trimmed several times:
   - "one control per goal";
   - "a smaller, more readable token set";
   - some 10 commits of "remove X from the schema".

   Add a property only when a real site needs it.
4. **Sweep = inventory → classify → convert → metric.** One script per property
   with a decisions file (exceptions with a reason), in batches by area, until
   "pending" and "in review" are at zero.
5. **Audit by areas in parallel and consolidate by pattern.** The audit of 12 areas
   found what sweeps do not see: duplicates, fake hovers, heading
   hierarchy, pieces that could not be hidden.
6. **Make a second pass over what was "skipped".** The zones discarded as a block
   ("global", "technical", "content") hid some 60 necessary visibility
   keys.
7. **Design decisions, dated, in a log.** A message from another session or
   from a subagent is not an approval.

## Sweeps

8. **Sweeps overconvert:**
   - 170 icon sizes were converted into text styles and had to be reverted;
   - a 16 px bold text was mapped to `h4` instead of `body_lg`;
   - a converter broke a JS template literal.

   Mark icon contexts, keep the declared weight and do not rewrite lines with
   backticks without reviewing them.
9. **Color goes by role, not by distance.** White as text and what goes over photos
   needs a person.
10. **Separate what follows the Identity from what stays fixed:** states, transparent
    blocks, one-sided borders, outlines, round shapes and checkbox boxes.

## Schema

11. **A property without a reader is a silent bug.** "A thick border on Card changed
    nothing." Run `keys.mjs` and write a test per property.
12. **One control per goal:**
    - one owner per concept (`section_header` for titles);
    - `platforms.mobile_web` instead of `*_mobile`;
    - `visibility` instead of hide switches inside a component.
13. **Version governance:**
    - a published version is immutable;
    - start at `1.0.0`;
    - numeric defaults within the editor's range;
    - a translucent default needs `alpha: true`;
    - the v1 map, outside the live schema.
14. **Fonts:**
    - the id → Google family map was incomplete;
    - a family shared by two roles was imported twice;
    - headings took the schema's font because the v1 map only filled the text
      one.

    Test each role separately.

## Code

15. **Robustness at the CSS boundary.** An invalid color broke `darken()`, and a value with
    `{`, `;` or `}` broke stylis. The fix is to complete and validate the theme once
    (`complete()`) and render from the engine's view.
16. **styled-components traps:**
    - a `GlobalStyle` created in the render is remounted every time;
    - a provider that copies its prop only once does not follow the theme;
    - spreading `{ ...theme, values }` leaves the CSS view stale;
    - a container's CSS targeting `button` beats the primitive.
17. **Box model:**
    - a 1 px border turned a 32 px button into 34 (`border-box`);
    - the ellipsis cut labels in flexible footers;
    - a wider focus border moved the text;
    - `overflow: hidden` clipped shadows and focus;
    - a modal's shadow had to go on the outer container.
18. **Shadows:**
    - a closed side menu with width 0 still cast a strip of xl shadow;
    - full-width bars cast only downward or upward.
19. **Hover:**
    - ~100 dead transitions were removed from things that are not pressed;
    - a hover comes only from a theme state;
    - a heart inherited the ghost button's shadow.
20. **A single breakpoint.** 576 in JS and 567 in CSS was a bug.
21. **Unquoted `url()`** broke 40 files with SVGs in data URIs.
22. **i18n:**
    - texts that came from the theme move to translations;
    - new `aria-label`s and `alt`s carry their key;
    - filler text ("Lorem ipsum") is replaced by real text;
    - a key whose meaning differs from the sister app needs its own name;
    - the editor labels are sent to the Dashboard.
23. **Images:**
    - the optimizer did nothing;
    - a logo was rendered in five different boxes;
    - vertical heroes were shown in 3.3:1 boxes;
    - a 1.1 MB PNG was rendered at 18 px.

    Inventory the rendered box of each image before specifying it.

## CI

24. **The publish dry run passed silently without the secret:** use `--required`.
25. **Coverage made some tests slow and their timeouts expired:** wait for the
    condition, not a fixed time.
