import { channels, legible as legibleColor, mix as mixColor, ratio, shade as shadeColor, TEXT_CONTRAST, TEXT_STEP } from './colors.js'
import type { Json } from './php.js'

/**
 * The engine's color operations for a color a client gets at run time and
 * not from the theme (a business's ribbon, a map marker):
 * `@orderingco/ordering-theme-engine/colors`. `shade`, `mix` and `legible`
 * are `derive`'s (src/colors.ts), with PHP's rounding: they answer what the
 * schema's rules derive for the theme's colors.
 */

/**
 * `{ r, g, b, a }` of a color the engine reads (hex of 3, 4, 6 or 8 digits,
 * rgb(), rgba(), `transparent`: alpha 0), or null.
 */
export function rgbOf(color: unknown): { r: number, g: number, b: number, a: number } | null {
  if (typeof color === 'string' && color.trim().toLowerCase() === 'transparent') {
    return { r: 0, g: 0, b: 0, a: 0 }
  }
  const own = channels(color)
  return own === null ? null : { r: own[0], g: own[1], b: own[2], a: own[3] }
}

/** The WCAG contrast ratio of two colors (1 to 21, alpha ignored), or null when either is not a color. */
export function contrast(a: unknown, b: unknown): number | null {
  const one = channels(a)
  const two = channels(b)
  return one === null || two === null ? null : ratio([one[0], one[1], one[2]], [two[0], two[1], two[2]])
}

/**
 * Whether text over `color` reads better dark than light: it contrasts at
 * least as much with black as with white (WCAG), the rule `legible` uses
 * to pick its direction. Null when it is not a color.
 */
export function isLight(color: unknown): boolean | null {
  const black = contrast(color, '#000000')
  return black === null ? null : black >= contrast(color, '#FFFFFF')!
}

/**
 * The text color over `background`: `dark` where it is light (isLight()),
 * else `light`; null when it is not a color.
 */
export function onColor(background: unknown, light = '#FFFFFF', dark = '#000000'): string | null {
  const answer = isLight(background)
  return answer === null ? null : (answer ? dark : light)
}

/** `color` `amount` (-1 to 1) lighter in HSL, darker when negative: `derive`'s `shade`. `#RRGGBB`, or null. */
export function shade(color: unknown, amount: number): string | null {
  return shadeColor(color as Json, amount)
}

/** `amount` (0 to 1) of `color` over `over` (white when it is not a color): `derive`'s `mix`. `#RRGGBB`, or null. */
export function mix(color: unknown, over: unknown, amount: number): string | null {
  return mixColor(color as Json, over as Json, amount)
}

const legibles = new Map<string, string | null>()

/**
 * `color` as text over `background` (white when it is not a color), as
 * `derive`'s `legible` makes `primary_text`: itself when it has `ratio`
 * (WCAG, 4.5 by default) over it, else darker (lighter over a dark
 * background, see isLight()) `step` (0.01) of HSL lightness at a time
 * until it does, or as far as it goes (black or white). `#RRGGBB` when it
 * changed; null when `color` is not a color.
 */
export function legible(color: unknown, background: unknown, ratio = TEXT_CONTRAST, step = TEXT_STEP): string | null {
  const key = `${color}|${background}|${ratio}|${step}`
  let answer = legibles.get(key)
  if (answer === undefined) {
    answer = legibleColor(color as Json, background as Json, ratio, step)
    legibles.set(key, answer)
  }
  return answer
}
