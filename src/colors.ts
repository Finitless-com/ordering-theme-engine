import { alias } from './format.js'
import { clone, get, has, hex2, phpRound, toFloat, toInt, type Json, type JsonMap } from './php.js'

/**
 * The colors a theme derives from another when it does not set them
 * (ThemeColors): a pressed and a subtle primary, a focus color and the
 * primary as text that follow the theme's primary and background. Which
 * colors derive from which, and how, the schema declares
 * (`co.ordering.derive` on each color token); the operations are these.
 */

type Rgb = [number, number, number]

/** The derived color => [the color it follows, how]: the engine's rules for a schema that declares none. */
const DERIVED: Record<string, [string, 'pressed' | 'subtle' | 'same' | 'legible']> = {
  primary_pressed: ['primary', 'pressed'],
  primary_subtle: ['primary', 'subtle'],
  focus: ['primary', 'same'],
  primary_text: ['primary', 'legible'],
  secondary_pressed: ['secondary', 'pressed'],
  secondary_subtle: ['secondary', 'subtle'],
  success_subtle: ['success', 'subtle'],
  warning_subtle: ['warning', 'subtle'],
  danger_subtle: ['danger', 'subtle'],
  info_subtle: ['info', 'subtle'],
}

const PRESSED_DARKEN = 0.07
const SUBTLE_MIX = 0.12
export const TEXT_CONTRAST = 4.5
export const TEXT_STEP = 0.01

/** The rules of DERIVED as a schema declares them (ThemeColors::rules()). */
export function colorRules(): JsonMap {
  const rules: JsonMap = {}
  for (const [derived, [source, how]] of Object.entries(DERIVED)) {
    const from = `{color.${source}}`
    rules[derived] = how === 'same'
      ? { op: 'same', from }
      : how === 'pressed'
        ? { op: 'shade', from, amount: -PRESSED_DARKEN }
        : how === 'subtle'
          ? { op: 'mix', from, over: '{color.background}', amount: SUBTLE_MIX }
          : { op: 'legible', from, over: '{color.background}', contrast: TEXT_CONTRAST, step: TEXT_STEP }
  }
  return rules
}

/**
 * $colors with each color its schema derives, unless the theme sets it, as
 * its rule's `when` says: `always`; `missing`, when the color is null or
 * absent; `changed` (the default), when it is missing or a color it derives
 * from is no longer its default. A color derived earlier in the pass counts
 * as changed for what derives from it (rules run sources first, ordered()).
 */
export function deriveColors(colors: JsonMap, set: JsonMap, defaults: JsonMap, rules: JsonMap | null): JsonMap {
  const answer = clone(colors)
  const changed = (name: string): boolean => get(answer, name) !== get(defaults, name)
  for (const [derived, rule] of Object.entries(ordered(rules ?? colorRules()))) {
    const from = named(get(rule, 'from'))
    const over = named(get(rule, 'over'))
    const missing = get(answer, derived) === null
    const when = get(rule, 'when') ?? 'changed'
    const derives = when === 'always'
      ? true
      : when === 'missing'
        ? missing
        : missing || (from !== null && changed(from)) || (over !== null && changed(over))
    if (has(set, derived) || from === null || !derives) {
      continue
    }

    const color = get(answer, from)
    const under = over === null ? null : get(answer, over)
    const op = get(rule, 'op')
    const value = op === 'same'
      ? (rgb(color) === null ? null : color)
      : op === 'shade'
        ? shade(color, toFloat(get(rule, 'amount') ?? 0))
        : op === 'mix'
          ? mix(color, under, toFloat(get(rule, 'amount') ?? 0))
          : op === 'legible'
            ? legible(color, under, toFloat(get(rule, 'contrast') ?? TEXT_CONTRAST), toFloat(get(rule, 'step') ?? TEXT_STEP))
            : null
    if (value !== null) {
      answer[derived] = value
    }
  }
  return answer
}

/** The rules with each one after those of the colors it derives from. */
function ordered(rules: JsonMap): JsonMap {
  let remaining: JsonMap = { ...rules }
  const answer: JsonMap = {}
  while (Object.keys(remaining).length > 0) {
    let ready = Object.fromEntries(Object.entries(remaining).filter(([, rule]) =>
      [get(rule, 'from'), get(rule, 'over')].every((input) => !has(remaining, named(input) ?? '')),
    )) as JsonMap
    // A cycle: what is left, as declared.
    if (Object.keys(ready).length === 0) {
      ready = remaining
    }
    for (const [key, rule] of Object.entries(ready)) {
      if (!has(answer, key)) {
        answer[key] = rule
      }
    }
    remaining = Object.fromEntries(Object.entries(remaining).filter(([key]) => !has(ready, key))) as JsonMap
  }
  return answer
}

/** The color an alias `{color.name}` names, or null. */
function named(value: Json): string | null {
  const parts = alias(value)
  return parts !== null && parts[0] === 'color' ? parts[1] : null
}

/** $color `amount` lighter in HSL (darker when negative), or null when it is not a color. */
export function shade(color: Json, amount: number): string | null {
  const channels = rgb(color)
  return channels === null ? null : hex(darken(channels, -amount))
}

/** `amount` of $color over $under (white when it is not a color), or null when $color is not one. */
export function mix(color: Json, under: Json, amount: number): string | null {
  const channels = rgb(color)
  const below = rgb(under) ?? [255, 255, 255]
  return channels === null ? null : hex(channels.map((channel, index) => channel * amount + below[index]! * (1 - amount)) as Rgb)
}

/**
 * $color as text over $background (white when it is not a color): itself
 * when it keeps $contrast over it, else darker (lighter over a dark
 * background) one step at a time until it does, or as far as it goes.
 */
export function legible(color: Json, background: Json, contrast = TEXT_CONTRAST, stepSize = TEXT_STEP): string | null {
  const channels = rgb(color)
  if (channels === null) {
    return null
  }
  const over = rgb(background) ?? [255, 255, 255]
  // Toward black over what contrasts more with black, toward white otherwise.
  const direction = ratio(over, [0, 0, 0]) >= ratio(over, [255, 255, 255]) ? 1 : -1
  let answer: string = color as string
  for (let step = 1; ratio(rgb(answer)!, over) < contrast && step * stepSize <= 1; step++) {
    answer = hex(darken(channels, direction * step * stepSize))
  }
  return answer
}

/** [r, g, b] of a hex or rgb()/rgba() color, or null. */
export function rgb(color: Json): Rgb | null {
  if (typeof color !== 'string') {
    return null
  }
  let match = /^#([0-9a-f]{3,4})$/i.exec(color)
  if (match) {
    const digits = match[1]!
    return [parseInt(digits[0]! + digits[0], 16), parseInt(digits[1]! + digits[1], 16), parseInt(digits[2]! + digits[2], 16)]
  }
  match = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})?$/i.exec(color)
  if (match) {
    return [parseInt(match[1]!, 16), parseInt(match[2]!, 16), parseInt(match[3]!, 16)]
  }
  match = /^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/.exec(color)
  if (match) {
    return [Number(match[1]), Number(match[2]), Number(match[3])]
  }
  return null
}

/** The WCAG contrast ratio between two colors (1–21). */
function ratio(a: Rgb, b: Rgb): number {
  const luminance = (channels: Rgb): number => {
    const [r, g, b] = channels.map((value) => {
      const channel = value / 255
      return channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4
    }) as Rgb
    return 0.2126 * r + 0.7152 * g + 0.0722 * b
  }
  const lighter = Math.max(luminance(a), luminance(b))
  const darker = Math.min(luminance(a), luminance(b))
  return (lighter + 0.05) / (darker + 0.05)
}

/** The color with its HSL lightness lowered by $amount (a negative amount raises it). */
function darken(channels: Rgb, amount: number): Rgb {
  const [r, g, b] = channels.map((channel) => channel / 255) as Rgb
  const max = Math.max(r, g, b)
  const min = Math.min(r, g, b)
  let lightness = (max + min) / 2
  let hue = 0
  let saturation = 0
  if (max !== min) {
    const delta = max - min
    saturation = lightness > 0.5 ? delta / (2 - max - min) : delta / (max + min)
    hue = (max === r ? (g - b) / delta + (g < b ? 6 : 0) : max === g ? (b - r) / delta + 2 : (r - g) / delta + 4) / 6
  }

  lightness = Math.max(0, Math.min(1, lightness - amount))
  if (saturation === 0) {
    return [lightness * 255, lightness * 255, lightness * 255]
  }

  const q = lightness < 0.5 ? lightness * (1 + saturation) : lightness + saturation - lightness * saturation
  const p = 2 * lightness - q
  const channel = (t: number): number => {
    t += t < 0 ? 1 : t > 1 ? -1 : 0
    return t < 1 / 6 ? p + (q - p) * 6 * t : t < 1 / 2 ? q : t < 2 / 3 ? p + (q - p) * (2 / 3 - t) * 6 : p
  }
  return [channel(hue + 1 / 3) * 255, channel(hue) * 255, channel(hue - 1 / 3) * 255]
}

/** `#RRGGBB`, each channel rounded as PHP rounds (phpRound()). */
function hex(channels: Rgb): string {
  return '#' + channels.map((channel) => hex2(toInt(phpRound(Math.max(0, Math.min(255, channel)))))).join('')
}

