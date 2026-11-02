import { channels as rgb } from './colors.js'
import { FONT_FACES, TYPOGRAPHY, type FontFace } from './format.js'
import type { Json, JsonMap } from './php.js'
import type { CompleteValues, RuntimeSchema } from './runtime.js'

/**
 * A completed theme as React Native style values
 * (`@orderingco/ordering-theme-engine/native`), for a native client: what
 * `toCss()` is for the web. toNative() turns every value into what a React
 * Native style takes, once per theme, by its type in the schema (the
 * compiled form carries it, `ordering-theme compile`):
 *
 *   number                  the number (pt, ms or a ratio, as the schema has it)
 *   color                   as it is (hex, rgb(), rgba(), `transparent`)
 *   border                  NativeBorder `{ borderWidth, borderStyle, borderColor }`;
 *                           null `{ borderWidth: 0 }`
 *   shadow                  NativeShadow: iOS `shadow*` and Android `elevation`
 *                           together, `boxShadow` for the new architecture;
 *                           null no shadow
 *   typography              NativeText: `fontFamily` (the file of the nearest
 *                           bundled weight), `fontSize`, `lineHeight` (size ×
 *                           ratio, rounded), `letterSpacing`, `textTransform`;
 *                           never `fontWeight` (the file carries it)
 *   font                    NativeFont of the role
 *   motion_style.easing     the cubic-bezier points `[x1, y1, x2, y2]`
 *   an option, a boolean    as it is
 *
 * A role of a matrix's appearances (`{main}`) is null: the matrix's
 * variants carry the painted colors.
 */

/** The compiled form's units this module reads (`css` of compileRuntime()). */
export const NATIVE_FORMAT = 1

/** The weights a binary bundles of each catalog font unless the client says otherwise. */
export const DEFAULT_WEIGHTS: readonly number[] = [400, 500, 600, 700]

const WEIGHT_NAMES: Record<number, string> = {
  100: 'Thin', 200: 'ExtraLight', 300: 'Light', 400: 'Regular', 500: 'Medium',
  600: 'SemiBold', 700: 'Bold', 800: 'ExtraBold', 900: 'Black',
}
const EASINGS: Record<string, readonly [number, number, number, number]> = {
  linear: [0, 0, 1, 1],
  ease: [0.25, 0.1, 0.25, 1],
  ease_in: [0.42, 0, 1, 1],
  ease_out: [0, 0, 0.58, 1],
  ease_in_out: [0.42, 0, 0.58, 1],
}
const HEADINGS: readonly string[] = TYPOGRAPHY.heading

export interface NativeBorder {
  borderWidth: number
  borderStyle?: 'solid' | 'dashed' | 'dotted'
  borderColor?: string
}

export interface NativeShadow {
  shadowColor: string
  shadowOpacity: number
  shadowRadius: number
  shadowOffset: { width: number, height: number }
  elevation: number
  /** The CSS `box-shadow` the new architecture takes; `none` for no shadow. */
  boxShadow: string
}

export interface NativeText {
  fontFamily: string | null
  fontSize: number | null
  lineHeight: number | null
  letterSpacing?: number
  textTransform?: 'none' | 'uppercase' | 'lowercase' | 'capitalize'
  /** The weight the family's file paints (the nearest bundled one); not a style key. */
  weight: number | null
}

export interface NativeFont {
  /** The catalog id (`open_sans`). */
  id: string
  /** The family name (`Open Sans`). */
  name: string
  /** The file (PostScript name) of each bundled weight. */
  files: Record<number, string>
}

export interface NativeMotion {
  ms: number
  easing: readonly [number, number, number, number]
}

export type NativeValue = string | number | boolean | null | NativeBorder | NativeShadow | NativeText | NativeMap

export interface NativeMap {
  [key: string]: NativeValue
}

export interface NativeComponent {
  motion: NativeMotion | null
  [part: string]: NativeValue | NativeMotion
}

export interface NativeTheme {
  /** Every token as React Native values, as the theme sets it. */
  tokens: NativeMap
  /**
   * The levels as a spot that follows Identity paints them: each radius but
   * `none` and `full` is `border_style.radius`'s, each shadow but `none` is
   * `shadow_style.size`'s, `thin` and `thick` are `border_style.width`'s (0
   * for none). `tokens` keeps them fixed, for states and shapes.
   */
  identity: {
    radius: Record<string, number>
    shadow: Record<string, NativeShadow>
    border: Record<string, number>
    border_style: string | null
    focus_ring: { width: number | null, offset: number | null, color: string | null } | null
  }
  components: Record<string, NativeComponent>
  platforms: Record<string, { components: Record<string, NativeMap> }>
  fonts: Record<string, NativeFont | null>
  /** The easing of `motion_style` (`ease` when the theme has none). */
  easing: readonly [number, number, number, number]
}

export interface NativeOptions {
  /** The weights the binary bundles of each font (default DEFAULT_WEIGHTS). */
  weights?: readonly number[]
  /** The file (PostScript name) of a font at a bundled weight (default fontFile()). */
  fontFile?: (id: string, weight: number) => string
}

type Rule = Json | undefined

const hasOwn = Object.prototype.hasOwnProperty
const isMap = (value: unknown): value is JsonMap => typeof value === 'object' && value !== null && !Array.isArray(value)
const isNumber = (value: unknown): value is number => typeof value === 'number' && Number.isFinite(value)
const num = (value: unknown): number => (isNumber(value) ? value : 0)
const at = (map: unknown, key: string): Json | undefined => (isMap(map) && hasOwn.call(map, key) ? map[key] : undefined)
const child = (rules: Rule, key: string): Rule => (isMap(rules) ? (at(rules, key) ?? at(rules, '*')) : undefined)
const BORDER_STYLES = ['solid', 'dashed', 'dotted']
const TRANSFORMS = ['none', 'uppercase', 'lowercase', 'capitalize']

/** A string that is a color React Native can paint (not a role such as `{main}`), else null. */
function color(value: unknown): string | null {
  if (typeof value !== 'string') {
    return null
  }
  const text = value.trim()
  return text !== '' && !text.startsWith('{') ? text : null
}

function face(font: unknown): FontFace | null {
  if (typeof font !== 'string') {
    return null
  }
  const id = font.trim().toLowerCase().replace(/\s+/g, '_')
  return hasOwn.call(FONT_FACES, id) ? FONT_FACES[id as keyof typeof FONT_FACES] : null
}

/** The catalog id of a font id or family name, or null. */
function fontId(font: unknown): string | null {
  if (typeof font !== 'string') {
    return null
  }
  const id = font.trim().toLowerCase().replace(/\s+/g, '_')
  return hasOwn.call(FONT_FACES, id) ? id : null
}

/**
 * The file (PostScript name) of a static font file as Google Fonts ships
 * it: the family without spaces, a dash and the weight's name
 * (`Poppins-SemiBold`, `OpenSans-Regular`). A font outside the catalog
 * keeps its own name without spaces.
 */
export function fontFile(font: string, weight: number): string {
  const entry = face(font)
  const family = (entry?.family ?? font).replace(/\s+/g, '')
  return `${family}-${WEIGHT_NAMES[weight] ?? 'Regular'}`
}

/**
 * The bundled weight a text of `weight` is painted with: the nearest of
 * `weights` (on a tie, the heavier from 600, the lighter below) — the rule
 * of snapWeight() for a static font.
 */
export function nearestWeight(weight: unknown, weights: readonly number[] = DEFAULT_WEIGHTS): number | null {
  const value = typeof weight === 'string' && /^\s*\d+(\.\d+)?\s*$/.test(weight) ? Number(weight) : weight
  if (!isNumber(value) || weights.length === 0) {
    return null
  }
  return weights.reduce((best, candidate) => {
    const distance = Math.abs(candidate - value) - Math.abs(best - value)
    if (distance !== 0) {
      return distance < 0 ? candidate : best
    }
    return value >= 600 ? Math.max(best, candidate) : Math.min(best, candidate)
  })
}

/** `{ width, color, style }` as a React Native border; null (no border) `{ borderWidth: 0 }`; null when it is not one. */
export function nativeBorder(border: unknown): NativeBorder | null {
  if (border === null) {
    return { borderWidth: 0 }
  }
  const paint = color(at(border, 'color'))
  const width = at(border, 'width')
  if (!isNumber(width) || paint === null) {
    return null
  }
  const style = at(border, 'style')
  return { borderWidth: width, borderStyle: (BORDER_STYLES.includes(style as string) ? style : 'solid') as NativeBorder['borderStyle'], borderColor: paint }
}

const NO_SHADOW: NativeShadow = Object.freeze({
  shadowColor: 'transparent', shadowOpacity: 0, shadowRadius: 0, shadowOffset: Object.freeze({ width: 0, height: 0 }), elevation: 0, boxShadow: 'none',
}) as NativeShadow

/**
 * A shadow `{ x, y, blur, spread, color, opacity, elevation }` as React
 * Native paints it: iOS takes the color, opacity, offset and half the blur
 * as its radius; Android its `elevation` (no color before API 28, no
 * spread); `boxShadow` is the same shadow for the new architecture. Null
 * (no shadow) paints none; null when it is not a shadow.
 */
export function nativeShadow(shadow: unknown): NativeShadow | null {
  if (shadow === null) {
    return NO_SHADOW
  }
  if (!isMap(shadow)) {
    return null
  }
  const paint = color(shadow.color)
  if (paint === null || (rgb(paint) === null && paint !== 'transparent')) {
    return null
  }
  const opacity = isNumber(shadow.opacity) ? shadow.opacity : 1
  const channels = rgb(paint)
  const css = channels === null
    ? 'none'
    : `${num(shadow.x)}px ${num(shadow.y)}px ${num(shadow.blur)}px ${num(shadow.spread)}px rgba(${channels[0]},${channels[1]},${channels[2]},${Math.round(opacity * (channels[3] ?? 1) * 1000) / 1000})`
  return {
    shadowColor: paint,
    shadowOpacity: opacity,
    shadowRadius: num(shadow.blur) / 2,
    shadowOffset: { width: num(shadow.x), height: num(shadow.y) },
    elevation: isNumber(shadow.elevation) ? shadow.elevation : Math.round(num(shadow.blur) / 2),
    boxShadow: css,
  }
}

/**
 * A typography style `{ size, line, weight, letter_spacing?, transform?,
 * family? }` as React Native paints it with `font` (a catalog id) when the
 * style names none. Null when it is not a style.
 */
export function nativeText(style: unknown, font?: string, options: NativeOptions = {}): NativeText | null {
  if (!isMap(style)) {
    return null
  }
  const id = fontId(style.family ?? font)
  const weight = nearestWeight(style.weight, options.weights ?? DEFAULT_WEIGHTS)
  const size = isNumber(style.size) ? style.size : null
  const answer: NativeText = {
    fontFamily: id === null ? null : (options.fontFile ?? fontFile)(id, weight ?? 400),
    fontSize: size,
    lineHeight: size !== null && isNumber(style.line) ? Math.round(size * style.line) : null,
    weight,
  }
  if (isNumber(style.letter_spacing)) {
    answer.letterSpacing = style.letter_spacing
  }
  if (typeof style.transform === 'string' && TRANSFORMS.includes(style.transform)) {
    answer.textTransform = style.transform as NativeText['textTransform']
  }
  return answer
}

/**
 * A theme's values as React Native values (see the module's comment):
 * `values` is what complete() answers for the same compiled form. Built
 * once per pair (and options): the tokens, `identity` and `fonts` at once,
 * each component the first time it is read, and kept. Do not change it:
 * its objects are shared.
 * Throws a TypeError when `compiled` is not a compiled form with units
 * (`ordering-theme compile` 0.9.0 or later).
 */
export function toNative(compiled: RuntimeSchema, values: CompleteValues, options: NativeOptions = {}): NativeTheme {
  if (!isMap(compiled) || compiled.css !== NATIVE_FORMAT || !isMap(compiled.values)) {
    throw new TypeError('toNative() reads the compiled form of a schema with its units: ordering-theme compile <schema.json> (0.9.0 or later).')
  }
  if (!isMap(values)) {
    throw new TypeError('toNative() reads the values complete() answers.')
  }
  const key = `${(options.weights ?? DEFAULT_WEIGHTS).join(',')}|${options.fontFile === undefined ? '' : 'custom'}`
  let byValues = built.get(compiled)
  if (byValues === undefined) {
    byValues = new WeakMap()
    built.set(compiled, byValues)
  }
  let views = byValues.get(values)
  if (views === undefined) {
    views = new Map()
    byValues.set(values, views)
  }
  let view = options.fontFile === undefined ? views.get(key) : undefined
  if (view === undefined) {
    view = build(rulesOf(compiled), values, options)
    if (options.fontFile === undefined) {
      views.set(key, view)
    }
  }
  return view
}

const built = new WeakMap<object, WeakMap<object, Map<string, NativeTheme>>>()
const rulesCache = new WeakMap<object, JsonMap>()

function lazy(target: object, key: string, compute: () => unknown): void {
  Object.defineProperty(target, key, {
    enumerable: true,
    configurable: true,
    get() {
      const value = compute()
      Object.defineProperty(target, key, { value, enumerable: true, writable: true, configurable: true })
      return value
    },
  })
}

function rulesOf(compiled: RuntimeSchema): JsonMap {
  let rules = rulesCache.get(compiled)
  if (rules === undefined) {
    const split = (node: Json): Rule => {
      if (!isMap(node)) {
        return undefined
      }
      if (hasOwn.call(node, '$rule')) {
        return node.$rule
      }
      const answer: JsonMap = {}
      for (const key of Object.keys(node)) {
        const rule = split(node[key]!)
        if (rule !== undefined) {
          answer[key] = rule
        }
      }
      return answer
    }
    rules = split(compiled.values as unknown as Json) as JsonMap
    rulesCache.set(compiled, rules)
  }
  return rules
}

function build(rules: JsonMap, values: CompleteValues, options: NativeOptions): NativeTheme {
  const tokens = isMap(values.tokens) ? values.tokens : {}
  const fontIds = isMap(tokens.font) ? tokens.font : {}
  const weights = options.weights ?? DEFAULT_WEIGHTS
  const fileOf = options.fontFile ?? fontFile

  type Context = string | undefined
  const roleFont = (role: Json | undefined): Context | null => (typeof role === 'string' && typeof at(fontIds, role) === 'string' ? fontIds[role] as string : null)
  const BODY: Context = typeof fontIds.body === 'string' ? fontIds.body : undefined

  const convert = (value: Json, rule: Rule, font: Context): NativeValue => {
    if (Array.isArray(rule) && isNumber(rule[0])) {
      return isNumber(value) ? value : null
    }
    switch (Array.isArray(rule) ? rule[0] : rule) {
      case 'c':
      case 'r': return color(value)
      case 'b': return nativeBorder(value)
      case 's': return nativeShadow(value)
      case 't': return nativeText(value, font, options)
    }
    if (isMap(value)) {
      const inner = roleFont(value.font_role) ?? font
      const answer: NativeMap = {}
      for (const key of Object.keys(value)) {
        answer[key] = convert(value[key]!, child(rule, key), inner)
      }
      return answer
    }
    if (typeof value === 'boolean' || value === null || isNumber(value)) {
      return value
    }
    return typeof value === 'string' ? value : null
  }

  const motionStyle = isMap(tokens.motion_style) ? tokens.motion_style : {}
  const easing = EASINGS[String(motionStyle.easing)] ?? EASINGS.ease!
  const motionOf = (component: JsonMap): NativeMotion | null => {
    const base = isMap(component.base) ? component.base : {}
    return motionStyle.enabled === false || base.animated !== true || !isNumber(base.speed) ? null : { ms: base.speed, easing }
  }

  const componentRules = child(rules, 'components')
  const componentFont = (component: JsonMap): Context => roleFont(at(component.base, 'font_role')) ?? BODY
  const component = (name: string, value: Json): NativeComponent | null => {
    if (!isMap(value)) {
      return null
    }
    const font = componentFont(value)
    const ownRules = child(componentRules, name)
    const answer: NativeComponent = { motion: motionOf(value) }
    for (const part of Object.keys(value)) {
      if (part === 'scope' && isMap(value.scope)) {
        const scope: NativeMap = {}
        for (const [scoped, changed] of Object.entries(value.scope)) {
          if (isMap(changed) && Object.keys(changed).length > 0) {
            lazy(scope, scoped, () => component(scoped, changed))
          } else {
            scope[scoped] = {}
          }
        }
        answer.scope = scope
      } else {
        answer[part] = convert(value[part]!, child(ownRules, part), font)
      }
    }
    return answer
  }

  // Tokens
  const tokenRules = child(rules, 'tokens')
  const nativeTokens: NativeMap = {}
  for (const [group, own] of Object.entries(tokens)) {
    if (group === 'typography' && isMap(own)) {
      nativeTokens[group] = Object.fromEntries(Object.entries(own).map(([style, value]) => {
        const role = HEADINGS.includes(style) ? 'heading' : 'body'
        return [style, nativeText(value, typeof fontIds[role] === 'string' ? fontIds[role] as string : undefined, options)]
      }))
    } else if (group === 'motion_style' && isMap(own)) {
      nativeTokens[group] = { ...(convert(own, child(tokenRules, group), BODY) as NativeMap), easing: own.easing === undefined ? null : [...easing] as unknown as NativeValue }
    } else {
      nativeTokens[group] = convert(own, child(tokenRules, group), BODY)
    }
  }

  // Components and platforms
  const components: Record<string, NativeComponent> = {}
  const completeComponents = isMap(values.components) ? values.components : {}
  for (const name of Object.keys(completeComponents)) {
    lazy(components, name, () => component(name, completeComponents[name]!))
  }
  const platforms: NativeTheme['platforms'] = {}
  for (const [platform, own] of Object.entries(isMap(values.platforms) ? values.platforms : {})) {
    const overrides: Record<string, NativeMap> = {}
    for (const [name, changed] of Object.entries(isMap(at(own, 'components')) ? (own as JsonMap).components as JsonMap : {})) {
      const base = completeComponents[name]
      lazy(overrides, name, () => convert(changed, child(componentRules, name), isMap(base) ? componentFont(base) : BODY) as NativeMap)
    }
    platforms[platform] = { components: overrides }
  }

  return {
    tokens: nativeTokens,
    identity: identity(tokens, nativeTokens),
    components,
    platforms,
    fonts: Object.fromEntries(Object.entries(fontIds).map(([role, raw]) => {
      const id = fontId(raw)
      if (id === null) {
        return [role, null]
      }
      return [role, { id, name: FONT_FACES[id as keyof typeof FONT_FACES].family, files: Object.fromEntries(weights.map((w) => [w, fileOf(id, w)])) }]
    })),
    easing,
  }
}

function identity(tokens: JsonMap, native: NativeMap): NativeTheme['identity'] {
  const group = <T>(name: string): Record<string, T> => (isMap(native[name]) ? native[name] as unknown as Record<string, T> : {})
  const own = (name: string): JsonMap => (isMap(tokens[name]) ? tokens[name] as JsonMap : {})
  const radius = group<number>('radius')
  const shadow = group<NativeShadow>('shadow')
  const border = group<number>('border')
  const space = group<number>('space')
  const colors = group<string>('color')
  const borderStyle = own('border_style')
  const shadowSize = own('shadow_style').size
  const overall = borderStyle.width

  const radii: Record<string, number> = {}
  for (const level of Object.keys(radius)) {
    const follows = level !== 'none' && level !== 'full' && typeof borderStyle.radius === 'string' && radius[borderStyle.radius] !== undefined
    radii[level] = follows ? radius[borderStyle.radius as string]! : radius[level]!
  }
  const levels: Record<string, NativeShadow> = {}
  for (const level of Object.keys(shadow)) {
    const follows = level !== 'none' && typeof shadowSize === 'string' && shadow[shadowSize] !== undefined
    levels[level] = follows ? shadow[shadowSize as string]! : shadow[level]!
  }
  const widths: Record<string, number> = {}
  for (const level of Object.keys(border)) {
    widths[level] = overall === 'none' ? 0 : (typeof overall === 'string' && border[overall] !== undefined ? border[overall]! : border[level]!)
  }
  const ring = own('focus_ring')
  const pick = <T>(map: Record<string, T>, key: Json | undefined): T | null => (typeof key === 'string' && map[key] !== undefined ? map[key]! : null)
  return {
    radius: radii,
    shadow: levels,
    border: widths,
    border_style: typeof borderStyle.style === 'string' ? borderStyle.style : null,
    focus_ring: isMap(tokens.focus_ring) ? { width: pick(border, ring.width), offset: pick(space, ring.offset), color: pick(colors, ring.color) } : null,
  }
}
