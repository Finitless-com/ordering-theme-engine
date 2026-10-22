import { channels as rgb } from './colors.js'
import { FONT_FACES, TYPOGRAPHY, type FontFace } from './format.js'
import type { Json, JsonMap } from './php.js'
import type { CompleteValues, RuntimeSchema } from './runtime.js'

/**
 * A completed theme as CSS (`@orderingco/ordering-theme-engine/css`), for
 * a web client: toCss() turns every value into the CSS it paints with once
 * per theme, so reading one is a property access, and fonts() gives the
 * Google Fonts stylesheets it loads. Each value is converted by its type in
 * the schema, which the compiled form carries (`ordering-theme compile`):
 *
 *   number                  `"12px"`, `"200ms"`, `"70%"` or unitless (`"0.5"`),
 *                           by its unit (compileRuntime())
 *   color                   as it is
 *   border                  `"1px solid #E9ECEF"`; null `"none"`
 *   shadow                  CssShadow: `css` (`"0px 1px 4px 0px rgba(0,0,0,0.1)"`,
 *                           the opacity in the color; null `"none"`), the same
 *                           shadow cast down, up, right and left, as a `filter`
 *                           (`drop`) and how far it reaches past its box
 *   typography              CssText: the declarations together (`css`) and each
 *                           one apart, the weight the nearest its font has
 *   font                    `"'Inter', sans-serif"`
 *   motion_style.easing     `"ease-in-out"`
 *   an option, a boolean    as it is
 *
 * Every string of the view is safe to interpolate in CSS: a value that
 * would carry `{ } ; < > ' " \`, a comment or a control character (a role
 * such as `{main}` in a matrix's appearances) is null instead. The only
 * quotes and semicolons are the engine's own: a family's quotes and the
 * separators of a CssText's `css`.
 */

/** The compiled form's units this module reads (`css` of compileRuntime()). */
export const CSS_FORMAT = 1

export interface CssShadow {
  /** `box-shadow`: `x y blur spread color`, or `none`. */
  css: string
  /** The shadow cast down as far as it falls, whatever its direction (a bar across the top). */
  down: string
  up: string
  right: string
  left: string
  /** `filter`: one or more `drop-shadow()` (no spread: a solid outline is four), or `none`. */
  drop: string
  /** How far it reaches past each side of its box, in px (0 where it does not fall). */
  reach: { top: number, right: number, bottom: number, left: number }
}

export interface CssText {
  /**
   * The declarations, one per line. `font-family` only where the style or
   * its component names a font (a token's always: its role's); elsewhere
   * the text keeps its parent's.
   */
  css: string
  size: string | null
  /** The line height, a ratio of the size (unitless). */
  line: string | null
  /** The nearest weight its font has. */
  weight: number | null
  /** The family it is painted with: its own, its role's, or the body's. */
  family: string | null
  letter_spacing: string | null
  transform: string | null
}

export interface CssMotion {
  ms: number
  easing: string
}

export type CssValue = string | boolean | null | CssShadow | CssText | CssMap

export interface CssMap {
  [key: string]: CssValue
}

/** A component: its parts as CSS, and how it animates (null: it does not). */
export interface CssComponent {
  motion: CssMotion | null
  [part: string]: CssValue | CssMotion
}

export interface CssTheme {
  /** Every token as CSS, as the theme sets it. */
  tokens: CssMap
  /**
   * The levels as a spot that follows Identity paints them: each radius
   * but `none` and `full` is `border_style.radius`'s, each shadow but `none`
   * is `shadow_style.size`'s, `thin` and `thick` are `border_style.width`'s
   * (`0px` for none). `tokens` keeps them fixed, for states and shapes.
   */
  identity: {
    radius: Record<string, string>
    shadow: Record<string, CssShadow>
    border: Record<string, string>
    border_style: string | null
    focus_ring: { width: string | null, offset: string | null, color: string | null } | null
    scrollbar: { size: string | null, radius: string | null } | null
  }
  /** Every component (a group's `scope` holds whole components too, each with its motion). */
  components: Record<string, CssComponent>
  /** A platform's own values of the components, only those it changes. */
  platforms: Record<string, { components: Record<string, CssMap> }>
  /** The font of each role: its catalog id, Google Fonts family and CSS `font-family`. */
  fonts: Record<string, { id: string, name: string, family: string } | null>
}

type Rule = Json | undefined

// What ends a declaration, opens or closes a block, a string or a comment.
const UNSAFE = /[{};<>'"\\\u0000-\u001f\u007f]|\/\*|\*\//
const EASINGS: Record<string, string> = { linear: 'linear', ease: 'ease', ease_in: 'ease-in', ease_out: 'ease-out', ease_in_out: 'ease-in-out' }
const HEADINGS: readonly string[] = TYPOGRAPHY.heading
const NO_REACH = { top: 0, right: 0, bottom: 0, left: 0 }
const NONE: CssShadow = { css: 'none', down: 'none', up: 'none', right: 'none', left: 'none', drop: 'none', reach: NO_REACH }

const hasOwn = Object.prototype.hasOwnProperty
const isMap = (value: unknown): value is JsonMap => typeof value === 'object' && value !== null && !Array.isArray(value)
const isNumber = (value: unknown): value is number => typeof value === 'number' && Number.isFinite(value)
const num = (value: unknown): number => (isNumber(value) ? value : 0)
const at = (map: unknown, key: string): Json | undefined => (isMap(map) && hasOwn.call(map, key) ? map[key] : undefined)
const child = (rules: Rule, key: string): Rule => (isMap(rules) ? (at(rules, key) ?? at(rules, '*')) : undefined)
const px = (value: number): string => `${value}px`

/** The value when it is a string safe to interpolate in CSS (trimmed), else null. */
function safe(value: unknown): string | null {
  if (typeof value !== 'string') {
    return null
  }
  const text = value.trim()
  return text !== '' && !UNSAFE.test(text) ? text : null
}

/**
 * `color` with the alpha `amount` (0–1), as `rgba(r,g,b,a)`: a hex, rgb()
 * or rgba() color (its own alpha replaced). `transparent`, a color it
 * cannot read or an amount that is not a number give `fallback`.
 */
export function alpha(color: unknown, amount: number, fallback = 'transparent'): string {
  const channels = rgb(color)
  if (channels === null || !isNumber(amount)) {
    return fallback
  }
  const a = Math.round(Math.min(1, Math.max(0, amount)) * 1000) / 1000
  return `rgba(${channels[0]},${channels[1]},${channels[2]},${a})`
}

/** Whether a color paints nothing: `transparent`, or an alpha of 0. */
export function isTransparent(color: unknown): boolean {
  if (typeof color !== 'string') {
    return false
  }
  return color.trim().toLowerCase() === 'transparent' || rgb(color)?.[3] === 0
}

/** The catalog entry of a font id (`open_sans`) or family name (`Open Sans`), or null. */
function face(font: unknown): FontFace | null {
  if (typeof font !== 'string') {
    return null
  }
  const id = font.trim().toLowerCase().replace(/\s+/g, '_')
  return hasOwn.call(FONT_FACES, id) ? FONT_FACES[id as keyof typeof FONT_FACES] : null
}

/**
 * The weight a text of `font` (a catalog id or family name) is painted
 * with: `weight` when the family has it, else its nearest (on a tie, the
 * heavier from 600, the lighter below); within a variable font's range,
 * clamped to it. A numeric string gives a number; a font the catalog does
 * not have, or a weight that is not a number (`bold`), stays as it came.
 */
export function snapWeight<T>(font: unknown, weight: T): T | number {
  const entry = face(font)
  const value = typeof weight === 'string' && /^\s*\d+(\.\d+)?\s*$/.test(weight) ? Number(weight) : weight
  if (entry === null || !isNumber(value)) {
    return weight
  }
  if (entry.range !== undefined) {
    return Math.min(entry.range[1], Math.max(entry.range[0], value))
  }
  return entry.weights!.reduce((best, candidate) => {
    const distance = Math.abs(candidate - value) - Math.abs(best - value)
    if (distance !== 0) {
      return distance < 0 ? candidate : best
    }
    return value >= 600 ? Math.max(best, candidate) : Math.min(best, candidate)
  })
}

/** The Google Fonts family of a font id, or null. */
function familyName(font: unknown): string | null {
  return face(font)?.family ?? null
}

/** `'Inter', sans-serif` of a font id, or null. */
function familyCss(font: unknown): string | null {
  const name = familyName(font)
  return name === null ? null : `'${name}', sans-serif`
}

/** `{ width, color, style }` as `border`; null (no border) `none`; null when it is not one. */
export function cssBorder(border: unknown): string | null {
  if (border === null) {
    return 'none'
  }
  const color = safe(at(border, 'color'))
  const width = at(border, 'width')
  if (!isNumber(width) || color === null || color.startsWith('{')) {
    return null
  }
  return `${px(width)} ${safe(at(border, 'style')) ?? 'solid'} ${color}`
}

/** A shadow's color with its opacity in it, or null when it has no color the CSS can paint. */
function shadowColor(shadow: JsonMap): string | null {
  const color = safe(shadow.color)
  if (color === null || (rgb(color) === null && color !== 'transparent')) {
    return null
  }
  return isNumber(shadow.opacity) ? alpha(color, shadow.opacity, color) : color
}

/** `{ x, y, blur, spread?, color, opacity }` as `box-shadow`; null (no shadow) `none`; null when it is not one. */
export function cssShadow(shadow: unknown): string | null {
  if (shadow === null) {
    return 'none'
  }
  const color = isMap(shadow) ? shadowColor(shadow) : null
  return color === null ? null : `${px(num(at(shadow, 'x')))} ${px(num(at(shadow, 'y')))} ${px(num(at(shadow, 'blur')))} ${px(num(at(shadow, 'spread')))} ${color}`
}

/**
 * A typography style `{ size, line, weight, letter_spacing?, transform?,
 * family? }` as CSS, painted with its `family` or else `font` (a catalog
 * id), its weight the nearest that font has. `named`: `css` declares the
 * family (it always does when the style names one). Null when it is not a
 * style.
 */
export function cssText(style: unknown, font?: string, named = false): CssText | null {
  if (!isMap(style)) {
    return null
  }
  const painted = style.family ?? font
  const family = familyCss(painted)
  const size = isNumber(style.size) ? px(style.size) : null
  const line = isNumber(style.line) ? String(style.line) : null
  const weight = isNumber(style.weight) ? snapWeight(painted, style.weight) as number : null
  const spacing = isNumber(style.letter_spacing) ? px(style.letter_spacing) : null
  const transform = safe(style.transform)
  const css = [
    (named || style.family !== undefined) && family !== null ? `font-family: ${family};` : '',
    size !== null ? `font-size: ${size};` : '',
    line !== null ? `line-height: ${line};` : '',
    weight !== null ? `font-weight: ${weight};` : '',
    spacing !== null ? `letter-spacing: ${spacing};` : '',
    transform !== null ? `text-transform: ${transform};` : '',
  ].filter((declaration) => declaration !== '').join('\n')
  return { css, size, line, weight, family, letter_spacing: spacing, transform }
}

/**
 * The shadow cast down, as api-core casts a level down: the distance it
 * falls (its largest offset) goes to y. One cast around has no offset:
 * without blur it grew its spread by the distance, which falls instead;
 * with blur it falls a quarter of its blur.
 */
function down(shadow: JsonMap): JsonMap {
  const x = Math.abs(num(shadow.x))
  const y = Math.abs(num(shadow.y))
  const blur = num(shadow.blur)
  const spread = num(shadow.spread)
  if (x !== 0 || y !== 0) {
    return { ...shadow, x: 0, y: Math.max(x, y) }
  }
  if (blur === 0 && spread > 0) {
    return { ...shadow, x: 0, y: spread, spread: 0 }
  }
  return { ...shadow, x: 0, y: Math.max(1, Math.round(blur / 4)) }
}

/** `filter` of a shadow: drop-shadow() has no spread, so a solid one around is four. */
function drop(shadow: JsonMap, color: string): string {
  const spread = num(shadow.spread)
  if (spread > 0 && num(shadow.blur) === 0) {
    return [`${px(spread)} 0 0`, `-${px(spread)} 0 0`, `0 ${px(spread)} 0`, `0 -${px(spread)} 0`].map((offset) => `drop-shadow(${offset} ${color})`).join(' ')
  }
  return `drop-shadow(${px(num(shadow.x))} ${px(num(shadow.y))} ${px(num(shadow.blur))} ${color})`
}

function reach(shadow: JsonMap): CssShadow['reach'] {
  const x = num(shadow.x)
  const y = num(shadow.y)
  const grow = Math.max(0, num(shadow.blur) + num(shadow.spread))
  const side = (offset: number): number => Math.max(0, Math.ceil(offset + grow))
  return { top: side(-y), right: side(x), bottom: side(y), left: side(-x) }
}

/**
 * A theme's values as CSS (see the module's comment): `values` is what
 * complete() answers for the same compiled form. Built once per pair:
 * the tokens, `identity` and `fonts` at once, each component (a scope's
 * and a platform's too) the first time it is read, and kept; the same
 * values give the same view (the same object) and the same strings. Do
 * not change it: its objects are shared.
 * Throws a TypeError when `compiled` is not a compiled form with units
 * (`ordering-theme compile` of this version or later).
 */
export function toCss(compiled: RuntimeSchema, values: CompleteValues): CssTheme {
  if (!isMap(compiled) || compiled.css !== CSS_FORMAT || !isMap(compiled.values)) {
    throw new TypeError('toCss() reads the compiled form of a schema with its units: ordering-theme compile <schema.json> (0.9.0 or later).')
  }
  if (!isMap(values)) {
    throw new TypeError('toCss() reads the values complete() answers.')
  }
  let views = built.get(compiled)
  if (views === undefined) {
    views = new WeakMap()
    built.set(compiled, views)
  }
  let view = views.get(values)
  if (view === undefined) {
    view = build(rulesOf(compiled), values)
    views.set(values, view)
  }
  return view
}

const built = new WeakMap<object, WeakMap<object, CssTheme>>()
const rulesCache = new WeakMap<object, JsonMap>()
const weightsOf = new WeakMap<CssTheme, () => Map<string, Set<number>>>()

/** `target[key]` built by `compute` the first time it is read, then a plain property. */
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

/** The rules of the compiled form's values, without the defaults. */
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

function build(rules: JsonMap, values: CompleteValues): CssTheme {
  const tokens = isMap(values.tokens) ? values.tokens : {}
  const fontIds = isMap(tokens.font) ? tokens.font : {}
  const shadows = new Map<string, CssShadow>()
  const textCache = new Map<string, CssText>()
  const motions = new Map<string, CssMotion>()

  const shadow = (value: Json): CssShadow | null => {
    if (value === null) {
      return NONE
    }
    const css = cssShadow(value)
    if (css === null) {
      return null
    }
    let answer = shadows.get(css)
    if (answer === undefined) {
      const own = value as JsonMap
      const fallen = down(own)
      const toward = (x: number, y: number): string => cssShadow({ ...fallen, x, y })!
      const distance = num(fallen.y)
      answer = {
        css,
        down: cssShadow(fallen)!,
        up: toward(0, -distance),
        right: toward(distance, 0),
        left: toward(-distance, 0),
        drop: drop(own, shadowColor(own)!),
        reach: reach(own),
      }
      shadows.set(css, answer)
    }
    return answer
  }

  /** A typography style painted with `font` (an id); `named`: its `css` declares the family. */
  const text = (value: Json, font: Json | undefined, named: boolean): CssText | null => {
    if (!isMap(value)) {
      return null
    }
    const painted = value.family ?? font
    const declares = named || value.family !== undefined
    const key = `${value.size}|${value.line}|${value.weight}|${value.letter_spacing}|${value.transform}|${painted}|${declares}`
    let answer = textCache.get(key)
    if (answer === undefined) {
      answer = cssText(value, typeof painted === 'string' ? painted : undefined, declares)!
      textCache.set(key, answer)
    }
    return answer
  }

  /** A font role's context: the font id it paints with and whether its texts declare it. */
  type Context = [Json | undefined, boolean]
  const roleContext = (role: Json | undefined): Context | null => (typeof role === 'string' && at(fontIds, role) !== undefined ? [fontIds[role], true] : null)
  const BODY: Context = [fontIds.body, false]

  const convert = (value: Json, rule: Rule, context: Context): CssValue => {
    if (Array.isArray(rule) && isNumber(rule[0])) {
      return isNumber(value) ? `${value}${typeof rule[2] === 'string' ? rule[2] : ''}` : null
    }
    switch (Array.isArray(rule) ? rule[0] : rule) {
      case 'c':
      case 'r': return safe(value)
      case 'b': return cssBorder(value)
      case 's': return shadow(value)
      case 't': return text(value, context[0], context[1])
    }
    if (isMap(value)) {
      const inner = roleContext(value.font_role) ?? context
      const answer: CssMap = {}
      for (const key of Object.keys(value)) {
        answer[key] = convert(value[key]!, child(rule, key), inner)
      }
      return answer
    }
    if (typeof value === 'boolean' || value === null) {
      return value
    }
    return isNumber(value) ? String(value) : safe(value)
  }

  const motionStyle = isMap(tokens.motion_style) ? tokens.motion_style : {}
  const easing = EASINGS[String(motionStyle.easing)] ?? 'ease'
  const motionOf = (component: JsonMap): CssMotion | null => {
    const base = isMap(component.base) ? component.base : {}
    if (motionStyle.enabled === false || base.animated !== true || !isNumber(base.speed)) {
      return null
    }
    const key = `${base.speed}|${easing}`
    let answer = motions.get(key)
    if (answer === undefined) {
      answer = { ms: base.speed, easing }
      motions.set(key, answer)
    }
    return answer
  }

  const componentRules = child(rules, 'components')
  const componentContext = (component: JsonMap): Context => roleContext(at(component.base, 'font_role')) ?? BODY
  const component = (name: string, value: Json): CssComponent | null => {
    if (!isMap(value)) {
      return null
    }
    const context = componentContext(value)
    const ownRules = child(componentRules, name)
    const answer: CssComponent = { motion: motionOf(value) }
    for (const part of Object.keys(value)) {
      if (part === 'scope' && isMap(value.scope)) {
        const scope: CssMap = {}
        for (const [scoped, changed] of Object.entries(value.scope)) {
          if (isMap(changed) && Object.keys(changed).length > 0) {
            lazy(scope, scoped, () => component(scoped, changed))
          } else {
            scope[scoped] = {}
          }
        }
        answer.scope = scope
      } else {
        answer[part] = convert(value[part]!, child(ownRules, part), context)
      }
    }
    return answer
  }

  // Tokens
  const tokenRules = child(rules, 'tokens')
  const typography = isMap(tokens.typography) ? tokens.typography : {}
  const cssTokens: CssMap = {}
  for (const [group, own] of Object.entries(tokens)) {
    cssTokens[group] = group === 'typography' && isMap(own)
      ? Object.fromEntries(Object.entries(own).map(([style, value]) => [style, text(value, fontIds[HEADINGS.includes(style) ? 'heading' : 'body'], true)]))
      : convert(own, child(tokenRules, group), BODY)
  }
  if (isMap(cssTokens.font)) {
    for (const role of Object.keys(fontIds)) {
      cssTokens.font[role] = familyCss(fontIds[role])
    }
  }
  if (isMap(cssTokens.motion_style) && hasOwn.call(cssTokens.motion_style, 'easing')) {
    cssTokens.motion_style.easing = EASINGS[String(motionStyle.easing)] ?? null
  }

  // Components and platforms
  const components: Record<string, CssComponent> = {}
  const completeComponents = isMap(values.components) ? values.components : {}
  for (const name of Object.keys(completeComponents)) {
    lazy(components, name, () => component(name, completeComponents[name]!))
  }
  const platforms: CssTheme['platforms'] = {}
  for (const [platform, own] of Object.entries(isMap(values.platforms) ? values.platforms : {})) {
    const overrides: Record<string, CssMap> = {}
    for (const [name, changed] of Object.entries(isMap(at(own, 'components')) ? (own as JsonMap).components as JsonMap : {})) {
      const base = completeComponents[name]
      lazy(overrides, name, () => convert(changed, child(componentRules, name), isMap(base) ? componentContext(base) : BODY))
    }
    platforms[platform] = { components: overrides }
  }

  const view: CssTheme = {
    tokens: cssTokens,
    identity: identity(tokens, cssTokens),
    components,
    platforms,
    fonts: Object.fromEntries(Object.entries(fontIds).map(([role, id]) => {
      const name = familyName(id)
      return [role, name === null ? null : { id: String(id), name, family: familyCss(id)! }]
    })),
  }
  // The weights each family paints with, from the values (fonts() needs no component built).
  let weights: Map<string, Set<number>> | undefined
  weightsOf.set(view, () => {
    if (weights === undefined) {
      weights = new Map()
      const add = (style: Json, font: Json | undefined): void => {
        const painted = at(style, 'family') ?? font
        const family = familyCss(painted)
        const weight = at(style, 'weight')
        if (family !== null && isNumber(weight)) {
          let set = weights!.get(family)
          if (set === undefined) {
            weights!.set(family, set = new Set())
          }
          set.add(snapWeight(painted, weight) as number)
        }
      }
      const walk = (value: Json, rule: Rule, context: Context): void => {
        if (rule === 't' || (Array.isArray(rule) && rule[0] === 't')) {
          add(value, context[0])
        } else if (isMap(value) && !(Array.isArray(rule) && isNumber(rule[0]))) {
          const inner = roleContext(value.font_role) ?? context
          for (const key of Object.keys(value)) {
            walk(value[key]!, child(rule, key), inner)
          }
        }
      }
      for (const [style, value] of Object.entries(typography)) {
        add(value, fontIds[HEADINGS.includes(style) ? 'heading' : 'body'])
      }
      const walkComponent = (name: string, value: Json): void => {
        if (!isMap(value)) {
          return
        }
        for (const part of Object.keys(value)) {
          if (part === 'scope' && isMap(value.scope)) {
            Object.entries(value.scope).forEach(([scoped, changed]) => walkComponent(scoped, changed))
          } else {
            walk(value[part]!, child(child(componentRules, name), part), componentContext(value))
          }
        }
      }
      Object.entries(completeComponents).forEach(([name, value]) => walkComponent(name, value))
      for (const own of Object.values(isMap(values.platforms) ? values.platforms : {})) {
        for (const [name, changed] of Object.entries(isMap(at(own, 'components')) ? (own as JsonMap).components as JsonMap : {})) {
          const base = completeComponents[name]
          walk(changed, child(componentRules, name), isMap(base) ? componentContext(base) : BODY)
        }
      }
    }
    return weights
  })
  return view
}

function identity(tokens: JsonMap, css: CssMap): CssTheme['identity'] {
  const group = (name: string): CssMap => (isMap(css[name]) ? css[name] as CssMap : {})
  const own = (name: string): JsonMap => (isMap(tokens[name]) ? tokens[name] as JsonMap : {})
  const radius = group('radius')
  const shadow = group('shadow')
  const border = group('border')
  const space = group('space')
  const color = group('color')
  const borderStyle = own('border_style')
  const shadowSize = own('shadow_style').size
  const overall = borderStyle.width

  const radii: Record<string, string> = {}
  for (const level of Object.keys(radius)) {
    const follows = level !== 'none' && level !== 'full' && typeof borderStyle.radius === 'string' && radius[borderStyle.radius] !== undefined
    radii[level] = (follows ? radius[borderStyle.radius as string] : radius[level]) as string
  }
  const levels: Record<string, CssShadow> = {}
  for (const level of Object.keys(shadow)) {
    const follows = level !== 'none' && typeof shadowSize === 'string' && shadow[shadowSize] !== undefined
    levels[level] = (follows ? shadow[shadowSize as string] : shadow[level]) as CssShadow
  }
  const widths: Record<string, string> = {}
  for (const level of Object.keys(border)) {
    widths[level] = (overall === 'none' ? '0px' : (typeof overall === 'string' && border[overall] !== undefined ? border[overall] : border[level])) as string
  }
  const ring = own('focus_ring')
  const scrollbar = isMap(tokens.scrollbar) ? group('scrollbar') : null
  const pick = (map: CssMap, key: Json | undefined): string | null => (typeof key === 'string' && typeof map[key] === 'string' ? map[key] as string : null)
  return {
    radius: radii,
    shadow: levels,
    border: widths,
    border_style: pick(group('border_style'), 'style'),
    focus_ring: isMap(tokens.focus_ring) ? { width: pick(border, ring.width), offset: pick(space, ring.offset), color: pick(color, ring.color) } : null,
    scrollbar: scrollbar === null ? null : { size: pick(scrollbar, 'size'), radius: pick(scrollbar, 'radius') },
  }
}

const transitions = new Map<string, string>()

/**
 * The `transition` of `properties` as a component animates (its `motion`
 * in the view): none when it does not, else its speed and easing, only
 * where the system does not ask to reduce motion. The same arguments give
 * the same string, built once.
 */
export function transition(motion: CssMotion | null, properties: readonly string[]): string {
  if (motion === null) {
    return 'transition: none;'
  }
  const key = `${motion.ms}|${motion.easing}|${properties.join(',')}`
  let answer = transitions.get(key)
  if (answer === undefined) {
    const list = properties.map((property) => `${property} ${motion.ms}ms ${motion.easing}`).join(', ')
    answer = `transition: none;\n@media (prefers-reduced-motion: no-preference) { transition: ${list}; }`
    transitions.set(key, answer)
  }
  return answer
}

const animations = new Map<string, string>()

/**
 * The `animation` of a component's keyframes `name` (`rest` follows its
 * duration and easing: `'1 forwards'`) as it animates: none when it does
 * not, else only where the system does not ask to reduce motion. Only for
 * an animation whose end is also the element's resting CSS.
 */
export function animation(motion: CssMotion | null, name: string, rest = ''): string {
  if (motion === null) {
    return 'animation: none;'
  }
  const key = `${motion.ms}|${motion.easing}|${name}|${rest}`
  let answer = animations.get(key)
  if (answer === undefined) {
    answer = `animation: none;\n@media (prefers-reduced-motion: no-preference) { animation: ${name} ${motion.ms}ms ${motion.easing} ${rest}; }`
    animations.set(key, answer)
  }
  return answer
}

export interface CssFont {
  /** The Google Fonts family. */
  name: string
  /** Every weight the page paints it with, each one it has. */
  weights: number[]
  /** Its Google Fonts stylesheet. */
  href: string
}

/**
 * The Google Fonts stylesheet of each font role (`heading`, `body`) of a
 * view: the weights of every text the view paints with its font (the type
 * scale's and the components', each already the nearest it has) and
 * `extra[role]` (the weights a client's own CSS paints with, snapped the
 * same way), 400 when there are none. A variable font with two or more is
 * asked as one span (`wght@300..800`); a static one, weight by weight. One
 * family for both roles: one stylesheet with the weights of both. A role
 * without a font of the catalog is left out.
 */
export function fonts(view: CssTheme, extra: Readonly<Partial<Record<string, readonly number[]>>> = {}): Record<string, CssFont> {
  const painted = weightsOf.get(view)?.() ?? new Map<string, Set<number>>()
  const roles = Object.entries(view.fonts).filter((entry): entry is [string, NonNullable<CssTheme['fonts'][string]>] => entry[1] !== null)
  const roleWeights = (font: { id: string, family: string }, role: string): number[] => {
    const weights = new Set<number>(painted.get(font.family))
    for (const weight of extra[role] ?? []) {
      const snapped = snapWeight(font.id, weight)
      if (isNumber(snapped)) {
        weights.add(snapped)
      }
    }
    return weights.size > 0 ? [...weights].sort((a, b) => a - b) : [snapWeight(font.id, 400) as number]
  }
  const answer: Record<string, CssFont> = {}
  for (const [role, font] of roles) {
    const shared = roles.filter(([, other]) => other.name === font.name)
    const weights = [...new Set(shared.flatMap(([other]) => roleWeights(font, other)))].sort((a, b) => a - b)
    const variable = face(font.id)?.range !== undefined
    const axis = variable && weights.length > 1 ? `${weights[0]}..${weights[weights.length - 1]}` : weights.join(';')
    answer[role] = { name: font.name, weights, href: `https://fonts.googleapis.com/css2?family=${font.name.replace(/ /g, '+')}:wght@${axis}&display=swap` }
  }
  return answer
}
