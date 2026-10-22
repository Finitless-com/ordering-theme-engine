import { FONTS, MODES, ROLE, SLOT_AXES, isColor, isImage } from './format.js'
import { NUMBER_RANGE, OPACITY_RANGE } from './limits.js'
import { matchesPattern } from './pattern.js'
import { isInt, isMap, mbStrlen, strlen, type Json, type JsonMap } from './php.js'

/**
 * What a client needs to read a theme it is served with
 * (`@orderingco/ordering-theme-engine/runtime`): complete() and the
 * compiled form it reads, which `ordering-theme compile` writes
 * (compileRuntime()). No schema, no validator: a website bundles this
 * module and the compiled form instead of the schema and the engine.
 *
 * The compiled form is the schema's defaults, the site's settings
 * included (`ordering-theme defaults --site`), with the rule of each value
 * runtimeRules() gives one written next to it, and the rest of those rules
 * apart:
 *
 *   {
 *     runtime: 1,                            this format
 *     product_type, version,                 the schema's
 *     rules: { platforms, scopes, text, platform_values },
 *                                            runtimeRules() but its `values`
 *     values: { tokens, components, visibility, images, pages, modes, platforms, site? }
 *                                            the defaults; a value with a rule
 *                                            is `{ "$value": default, "$rule": rule }`
 *   }
 *
 * The rules are those of src/rules.ts (docs/schema-format.md §12.4).
 */

/** The compiled form complete() reads (`ordering-theme compile`). */
export interface RuntimeSchema {
  runtime: number
  /** The units the `css` entry reads are in the numbers' rules (compileRuntime()). */
  css?: number
  product_type: string
  version: string
  rules: {
    platforms: string[]
    scopes: Record<string, string[]>
    text: JsonMap
    platform_values: JsonMap
  }
  values: JsonMap
}

/** A theme's values completed: every key of the schema, each one understood. */
export interface CompleteValues {
  tokens: JsonMap
  components: JsonMap
  visibility: JsonMap
  images: JsonMap
  pages: JsonMap
  modes: JsonMap
  platforms: JsonMap
  site?: JsonMap
  [key: string]: Json | undefined
}

/** The compiled form this module reads. */
export const RUNTIME_FORMAT = 1

type Rule = Json | undefined

/** The format's ranges of a shadow's fields (ThemeFormat). */
const OFFSET: Pair = [-128, 128]
const SHADOW_FIELDS: Record<string, Pair> = {
  x: OFFSET,
  y: OFFSET,
  blur: [NUMBER_RANGE.min!, NUMBER_RANGE.max!],
  spread: OFFSET,
  opacity: [OPACITY_RANGE.min!, OPACITY_RANGE.max!],
  elevation: [0, 24],
}
const SHADOW_REQUIRED = ['x', 'y', 'blur', 'color', 'opacity']
const NUMBER: Pair = [NUMBER_RANGE.min!, NUMBER_RANGE.max!]
const BORDER_STYLES: Json[] = ['solid', 'dashed', 'dotted']
const TEXT_FIELDS = ['size', 'line', 'weight', 'letter_spacing', 'transform']
/** Parts of a component that are not properties: checked on their own. */
const COMPONENT_OWN = ['slots', 'scope']

type Pair = [number, number]

const hasOwn = Object.prototype.hasOwnProperty
const own = (map: unknown, key: string): boolean => isMap(map) && hasOwn.call(map, key)
const at = (map: unknown, key: string): Json | undefined => (own(map, key) ? (map as JsonMap)[key] : undefined)
const isNumber = (value: unknown): value is number => typeof value === 'number' && Number.isFinite(value)
const isRange = (rule: Rule): rule is Pair => Array.isArray(rule) && isNumber(rule[0])
const isLeafRule = (rule: Rule): boolean => typeof rule === 'string' || Array.isArray(rule)
const childRule = (rules: Rule, key: string): Rule => (isMap(rules) ? (at(rules, key) ?? at(rules, '*')) : undefined)
const clamp = (value: number, [min, max]: Pair): number => Math.min(max, Math.max(min, value))
const size = (map: JsonMap): number => Object.keys(map).length

/** A deep copy: what complete() answers shares nothing with the compiled form. */
function copy<T extends Json | undefined>(value: T): T {
  if (Array.isArray(value)) {
    return value.map(copy) as T
  }
  if (isMap(value)) {
    const answer: JsonMap = {}
    for (const key of Object.keys(value)) {
      answer[key] = copy(value[key]!)
    }
    return answer as T
  }
  return value
}

/** The defaults and the rules of the compiled form's `values`, apart. */
function split(node: Json): [Json, Rule] {
  if (!isMap(node)) {
    return [node, undefined]
  }
  if (own(node, '$rule')) {
    return [node.$value ?? null, node.$rule]
  }
  const values: JsonMap = {}
  const rules: JsonMap = {}
  for (const [key, child] of Object.entries(node)) {
    const [value, rule] = split(child)
    values[key] = value
    if (rule !== undefined) {
      rules[key] = rule
    }
  }
  return [values, size(rules) > 0 ? rules : undefined]
}

type Reader = (served: unknown) => CompleteValues

const readers = new WeakMap<object, Reader>()

/**
 * The values of a theme as api-core serves them (`theme.values`: resolved,
 * maybe partial, of another version of the schema, or broken) completed
 * and checked against the compiled schema, as the client may use them:
 *
 * - every key of the schema, with its default where the theme leaves it
 *   out; a key the schema lacks is dropped;
 * - a value that is not understood is the default: of another kind than
 *   the default (a number for a text, a map for a color), an option the
 *   schema does not list, an invalid color (an alias is one too), a null
 *   where the default is not null (a border and a shadow may always be
 *   null: none);
 * - a number is clamped to its rule's range (the editor's `min` and
 *   `max`, a level's widened to its scale's levels, else the format's);
 * - a text style, a border and a shadow are checked field by field: an
 *   invalid or missing field is the default's (a text style without a
 *   `family` keeps none: a component's own style takes the font of where
 *   it is);
 * - the open blocks are checked by what they hold: `platforms.<platform>`
 *   by the rules of the component property, visibility key or page it
 *   changes; a group's `scope` by those of each component it may change,
 *   completed from the theme's own component (already checked); a page's
 *   `sections` in the theme's order with only the page's sections, once
 *   each; a slot's axes, those its schema declares, by its component's
 *   parts; an image, an http(s) URL of up to 2048 bytes (or '', none); a
 *   site's setting, by its options, `max_length` and `pattern`; a mode's
 *   colors, of the schema's own colors.
 *
 * `complete(compiled, null)` is the defaults (`ordering-theme defaults
 * --site`), to paint with before a theme arrives. Neither argument is
 * changed, and the answer shares no object with them. Throws a TypeError
 * when `compiled` is not the compiled form (`ordering-theme compile`).
 */
export function complete(compiled: RuntimeSchema, served: unknown): CompleteValues {
  if (!isMap(compiled) || compiled.runtime !== RUNTIME_FORMAT || !isMap(compiled.values) || !isMap(compiled.rules)) {
    throw new TypeError('complete() reads the compiled form of a schema: ordering-theme compile <schema.json>.')
  }
  let reader = readers.get(compiled)
  if (reader === undefined) {
    reader = createReader(compiled)
    readers.set(compiled, reader)
  }
  return reader(served)
}

function createReader(compiled: RuntimeSchema): Reader {
  const [defaultValues, valueRules] = split(compiled.values as unknown as Json) as [JsonMap, JsonMap | undefined]
  const meta = compiled.rules as unknown as JsonMap
  const textRules = isMap(meta.text) ? meta.text : {}
  const platforms = Array.isArray(meta.platforms) ? meta.platforms : []
  const scopes = isMap(meta.scopes) ? meta.scopes : {}
  const platformValues = isMap(meta.platform_values) ? meta.platform_values : {}
  const fontRule = childRule(childRule(childRule(valueRules, 'tokens'), 'font'), 'body')
  const fonts: Json[] = Array.isArray(fontRule) ? fontRule.slice(1) : [...FONTS]

  /** A field of a text style or a shadow: the value checked by its rule (clamped), or undefined. */
  const field = (rule: Rule, value: Json | undefined): Json | undefined => {
    if (isRange(rule)) {
      return isNumber(value) ? clamp(value, rule) : undefined
    }
    return Array.isArray(rule) && rule[0] === 'o' && rule.slice(1).includes(value as Json) ? value : undefined
  }

  const text = (fallback: Json | undefined, value: Json, ownRules: Rule, base: Json | undefined): Json | undefined => {
    if (!isMap(value)) {
      return fallback
    }
    const rules: JsonMap = { ...textRules, ...(isMap(ownRules) ? ownRules : {}) }
    const from = isMap(base) ? base : {}
    const answer: JsonMap = {}
    for (const name of TEXT_FIELDS) {
      const checked = field(rules[name], value[name])
      if (checked !== undefined) {
        answer[name] = checked
      } else if (from[name] !== undefined) {
        answer[name] = from[name]!
      }
    }
    if (own(value, 'family')) {
      const family = fonts.includes(value.family!) ? value.family : from.family
      if (family !== undefined) {
        answer.family = family
      }
    }
    return answer
  }

  const border = (fallback: Json | undefined, value: Json, base: Json | undefined): Json | undefined => {
    if (value === null) {
      return null
    }
    if (!isMap(value)) {
      return fallback
    }
    const from = isMap(base) ? base : {}
    const width = isNumber(value.width) ? clamp(value.width, NUMBER) : from.width
    // The border of an appearance may take a role of its color.
    const color = isColor(value.color) || (typeof value.color === 'string' && ROLE.test(value.color)) ? value.color : from.color
    const style = BORDER_STYLES.includes(value.style as Json) ? value.style : from.style
    if (width === undefined || color === undefined) {
      return fallback
    }
    return style === undefined ? { width, color } : { width, color, style }
  }

  const shadow = (fallback: Json | undefined, value: Json, base: Json | undefined): Json | undefined => {
    if (value === null) {
      return null
    }
    if (!isMap(value)) {
      return fallback
    }
    const from = isMap(base) ? base : {}
    const answer: JsonMap = {}
    for (const [name, range] of Object.entries(SHADOW_FIELDS)) {
      const checked = field(range, value[name])
      if (checked !== undefined) {
        answer[name] = checked
      } else if (from[name] !== undefined) {
        answer[name] = from[name]!
      }
    }
    const color = isColor(value.color) ? value.color : from.color
    if (color !== undefined) {
      answer.color = color
    }
    return SHADOW_REQUIRED.every((name) => answer[name] !== undefined) ? answer : fallback
  }

  /** A `string` setting: within its length and pattern (an empty one is unset, so any pattern takes it). */
  const isText = (rule: Json[], value: Json): boolean => {
    if (typeof value !== 'string') {
      return false
    }
    if (value === '') {
      return true
    }
    return mbStrlen(value) <= (rule[1] as number) && (typeof rule[2] !== 'string' || matchesPattern(rule[2], value))
  }

  /**
   * One value by its rule. `fallback` is what an invalid value gives (the
   * default; undefined where nothing is set, a platform's own); `base` is
   * the default the value completes field by field and that says whether
   * null is allowed (the default itself, or the component's for a
   * platform's override).
   */
  const leaf = (rule: Rule, fallback: Json | undefined, value: Json | undefined, base: Json | undefined = fallback): Json | undefined => {
    if (value === undefined) {
      return fallback
    }
    if (isRange(rule)) {
      return isNumber(value) ? clamp(value, rule) : fallback
    }
    const kind = Array.isArray(rule) ? rule[0] : rule
    switch (kind) {
      case 'o': return (rule as Json[]).slice(1).includes(value) ? value : fallback
      case 'c': return isColor(value) || (value === null && base === null) ? value : fallback
      case 'r': return isColor(value) || (typeof value === 'string' && ROLE.test(value)) || (value === null && base === null) ? value : fallback
      case 'b': return border(fallback, value, base)
      case 's': return shadow(fallback, value, base)
      case 't': return text(fallback, value, Array.isArray(rule) ? rule[1] : undefined, base)
      case 'l': return typeof value === 'boolean' || value === null ? value : fallback
      case 'x': return isText(rule as Json[], value) ? value : fallback
      case 'i': return value === null || (isInt(value) && value >= 0) || (typeof value === 'string' && strlen(value) <= 255) ? value : fallback
      default: return fallback
    }
  }

  /** A value without a rule: of its default's kind (a boolean, a text, a number). */
  const sameKind = (fallback: Json | undefined, value: Json | undefined, base: Json | undefined = fallback): Json | undefined => {
    if (value === undefined || base === null || base === undefined || typeof base === 'object') {
      return fallback
    }
    if (typeof base === 'number') {
      return isNumber(value) ? value : fallback
    }
    return typeof value === typeof base ? value : fallback
  }

  /** A map of the defaults with the theme's values: only the defaults' keys, each by its rule, or as a map, or by its default's kind. */
  const mergeMap = (defaults: JsonMap, theme: Json | undefined, rules: Rule, skip: string[] = []): JsonMap => {
    if (!isMap(theme)) {
      return defaults
    }
    const answer: JsonMap = { ...defaults }
    for (const key of Object.keys(defaults)) {
      if (!skip.includes(key) && own(theme, key)) {
        answer[key] = mergeValue(defaults[key]!, theme[key]!, childRule(rules, key))!
      }
    }
    return answer
  }

  const mergeValue = (fallback: Json, value: Json, rule: Rule): Json | undefined => {
    if (isLeafRule(rule)) {
      return leaf(rule, fallback, value)
    }
    return isMap(fallback) ? mergeMap(fallback, value, rule) : sameKind(fallback, value)
  }

  // Components

  const componentRules = (name: string): Rule => childRule(childRule(valueRules, 'components'), name)

  /** A slot: the axes it declares, each null or one of its component's keys of that part. */
  const mergeSlot = (fallback: Json, value: Json | undefined, components: JsonMap): Json => {
    if (!isMap(fallback) || !isMap(value)) {
      return fallback
    }
    const target = at(components, String(fallback.component))
    const answer: JsonMap = { ...fallback }
    for (const [axis, part] of Object.entries(SLOT_AXES)) {
      if (!own(value, axis) || !own(fallback, axis)) {
        continue
      }
      const choice = value[axis]!
      if (choice === null || (typeof choice === 'string' && own(at(target, part), choice))) {
        answer[axis] = choice
      }
    }
    return answer
  }

  /** Component `name`: its properties by its rules and its slots (a group's scope apart: mergeComponents). */
  const mergeComponent = (name: string, fallback: JsonMap, value: Json | undefined, components: JsonMap): JsonMap => {
    if (!isMap(value)) {
      return fallback
    }
    const answer = mergeMap(fallback, value, componentRules(name), COMPONENT_OWN)
    if (isMap(fallback.slots) && isMap(value.slots)) {
      const slots: JsonMap = {}
      for (const [slot, ownSlot] of Object.entries(fallback.slots)) {
        slots[slot] = mergeSlot(ownSlot, at(value.slots, slot), components)
      }
      answer.slots = slots
    }
    return answer
  }

  /**
   * A group's `scope`: each component its rule lists, as the API serves it
   * (the theme's whole component with the group's changes), checked as
   * that component; what it lacks comes from the theme's own component
   * (`merged`, already checked). An empty one changes nothing and stays
   * empty.
   */
  const mergeScope = (fallback: Json | undefined, value: JsonMap, allowed: Json[], components: JsonMap, merged: JsonMap): JsonMap => {
    const answer: JsonMap = isMap(fallback) ? { ...fallback } : {}
    for (const name of allowed) {
      const key = String(name)
      const ownScope = at(value, key)
      const base = at(merged, key)
      if (isMap(ownScope) && isMap(base)) {
        answer[key] = size(ownScope) > 0 ? mergeComponent(key, base, ownScope, components) : {}
      }
    }
    return answer
  }

  /** Every component, then each group's scope over the components already merged (a scoped component has no scope of its own). */
  const mergeComponents = (defaults: JsonMap, theme: Json): JsonMap => {
    if (!isMap(theme)) {
      return defaults
    }
    const answer: JsonMap = { ...defaults }
    for (const name of Object.keys(defaults)) {
      if (own(theme, name)) {
        answer[name] = mergeComponent(name, defaults[name] as JsonMap, theme[name], defaults)
      }
    }
    for (const name of Object.keys(defaults)) {
      const allowed = at(scopes, name)
      const ownScope = at(at(theme, name), 'scope')
      if (Array.isArray(allowed) && isMap(ownScope)) {
        const scope = mergeScope(at(defaults[name], 'scope'), ownScope, allowed, defaults, answer)
        if (size(scope) > 0) {
          answer[name] = { ...(answer[name] as JsonMap), scope }
        }
      }
    }
    return answer
  }

  /**
   * A platform's overrides of a component (open: the platform's defaults
   * list only some): each property of the component's parts, checked by
   * the platform's rule or the component's; an invalid one keeps the
   * platform's default, or is left out where the platform has none (the
   * component's value applies). Slots and scope are not overridden by
   * platform.
   */
  const mergeOverrides = (fallback: Json | undefined, value: JsonMap, component: JsonMap, rules: Rule): JsonMap => {
    const answer: JsonMap = isMap(fallback) ? { ...fallback } : {}
    for (const key of Object.keys(value)) {
      if (COMPONENT_OWN.includes(key) || !own(component, key)) {
        continue
      }
      const ownValue = value[key]!
      const rule = childRule(rules, key)
      const platformDefault = answer[key]
      const base = platformDefault !== undefined ? platformDefault : component[key]
      let merged: Json | undefined
      if (isLeafRule(rule)) {
        merged = leaf(rule, platformDefault, ownValue, base)
      } else if (isMap(component[key])) {
        merged = isMap(ownValue) ? mergeOverrides(platformDefault, ownValue, component[key] as JsonMap, rule) : platformDefault
      } else {
        merged = sameKind(platformDefault, ownValue, base)
      }
      if (merged === undefined || (isMap(merged) && !isLeafRule(rule) && size(merged) === 0)) {
        delete answer[key]
      } else {
        answer[key] = merged
      }
    }
    return answer
  }

  const platformRules = (platform: string, name: string): Rule => {
    const ownRules = at(at(at(platformValues, platform), 'components'), name)
    const rules = componentRules(name)
    if (!isMap(ownRules)) {
      return rules
    }
    return { ...(isMap(rules) ? rules : {}), base: { ...(isMap(at(rules, 'base')) ? at(rules, 'base') as JsonMap : {}), ...(isMap(ownRules.base) ? ownRules.base : {}) } }
  }

  // Visibility, pages, platforms, modes

  const mergeVisibility = (defaults: JsonMap, theme: Json): JsonMap => {
    if (!isMap(theme)) {
      return defaults
    }
    const answer: JsonMap = { ...defaults }
    for (const key of Object.keys(defaults)) {
      if (typeof theme[key] === 'boolean' && own(theme, key)) {
        answer[key] = theme[key]!
      }
    }
    return answer
  }

  /** A page's sections in the theme's order: the page's own, once each, with a boolean `visible`. */
  const mergeSections = (defaults: Json[], list: Json[]): Json[] => {
    const visible = new Map<Json | undefined, Json | undefined>(defaults.map((section) => [at(section, 'key'), at(section, 'visible')]))
    const seen = new Set<Json | undefined>()
    const answer: Json[] = []
    for (const item of list) {
      const key = at(item, 'key')
      if (isMap(item) && typeof key === 'string' && visible.has(key) && !seen.has(key)) {
        seen.add(key)
        answer.push({ key, visible: typeof item.visible === 'boolean' ? item.visible : visible.get(key)! })
      }
    }
    return answer
  }

  /** A page: `footer` (true, false or null) and `sections`. `open`: only what the theme sets (a platform's page). */
  const mergePage = (fallback: JsonMap, value: Json, open: boolean): JsonMap | undefined => {
    if (!isMap(value)) {
      return open ? undefined : fallback
    }
    const answer: JsonMap = open ? {} : { ...fallback }
    if (own(value, 'footer') && (typeof value.footer === 'boolean' || value.footer === null)) {
      answer.footer = value.footer
    }
    if (Array.isArray(value.sections) && Array.isArray(fallback.sections)) {
      answer.sections = mergeSections(fallback.sections, value.sections)
    }
    return open && size(answer) === 0 ? undefined : answer
  }

  /** The pages of `schemaPages` (the defaults' pages) with the theme's; `open`: a platform's, which holds only what the theme sets. */
  const mergePages = (current: JsonMap, theme: Json, schemaPages: JsonMap, open: boolean): JsonMap => {
    if (!isMap(theme)) {
      return current
    }
    const answer: JsonMap = { ...current }
    for (const page of Object.keys(schemaPages)) {
      if (own(theme, page)) {
        const merged = mergePage(schemaPages[page] as JsonMap, theme[page]!, open)
        if (merged !== undefined) {
          answer[page] = merged
        }
      }
    }
    return answer
  }

  const mergePlatform = (fallback: Json | undefined, value: JsonMap, platform: string, values: JsonMap): JsonMap => {
    const answer: JsonMap = isMap(fallback) ? { ...fallback } : {}
    const components = values.components as JsonMap
    if (isMap(value.components)) {
      const overrides: JsonMap = isMap(answer.components) ? { ...answer.components } : {}
      for (const [name, ownValue] of Object.entries(value.components)) {
        const component = at(components, name)
        if (!isMap(ownValue) || !isMap(component)) {
          continue
        }
        const merged = mergeOverrides(overrides[name], ownValue, component, platformRules(platform, name))
        if (size(merged) > 0) {
          overrides[name] = merged
        } else {
          delete overrides[name]
        }
      }
      answer.components = overrides
    }
    if (isMap(value.visibility)) {
      const visibility: JsonMap = isMap(answer.visibility) ? { ...answer.visibility } : {}
      for (const [key, shown] of Object.entries(value.visibility)) {
        if (own(values.visibility, key) && typeof shown === 'boolean') {
          visibility[key] = shown
        }
      }
      answer.visibility = visibility
    }
    if (isMap(value.pages)) {
      const pages = mergePages(isMap(answer.pages) ? answer.pages : {}, value.pages, isMap(values.pages) ? values.pages : {}, true)
      if (size(pages) > 0) {
        answer.pages = pages
      }
    }
    return answer
  }

  const mergePlatforms = (defaults: JsonMap, theme: Json, values: JsonMap): JsonMap => {
    if (!isMap(theme)) {
      return defaults
    }
    const answer: JsonMap = { ...defaults }
    for (const platform of platforms) {
      const value = at(theme, String(platform))
      if (isMap(value)) {
        answer[String(platform)] = mergePlatform(defaults[String(platform)], value, String(platform), values)
      }
    }
    return answer
  }

  /** The colors a theme sets per mode: of the schema's own colors, each a color. */
  const mergeModes = (defaults: JsonMap, theme: Json, colors: Json | undefined): JsonMap => {
    if (!isMap(theme)) {
      return defaults
    }
    const answer: JsonMap = { ...defaults }
    for (const mode of MODES) {
      const set = at(at(theme, mode), 'color')
      const kept: JsonMap = {}
      for (const [name, color] of Object.entries(isMap(set) ? set : {})) {
        if (own(colors, name) && isColor(color)) {
          kept[name] = color
        }
      }
      if (size(kept) > 0) {
        answer[mode] = { color: kept }
      }
    }
    return answer
  }

  return (served) => {
    const defaults = copy(defaultValues)
    if (!isMap(served)) {
      return defaults as CompleteValues
    }
    const answer: JsonMap = { ...defaults }
    for (const block of Object.keys(defaults)) {
      if (!own(served, block)) {
        continue
      }
      const value = served[block]!
      const fallback = defaults[block]!
      switch (block) {
        case 'components':
          answer.components = mergeComponents(fallback as JsonMap, value)
          break
        case 'visibility':
          answer.visibility = mergeVisibility(fallback as JsonMap, value)
          break
        case 'pages':
          answer.pages = mergePages(fallback as JsonMap, value, fallback as JsonMap, false)
          break
        case 'platforms':
          answer.platforms = mergePlatforms(fallback as JsonMap, value, defaults)
          break
        case 'modes':
          answer.modes = mergeModes(fallback as JsonMap, value, at(defaults.tokens, 'color'))
          break
        case 'images':
          answer.images = isMap(value)
            ? Object.fromEntries(Object.entries(fallback as JsonMap).map(([key, image]) => [key, own(value, key) && isImage(value[key]) ? value[key]! : image]))
            : fallback
          break
        default:
          answer[block] = mergeValue(fallback, value, childRule(valueRules, block))!
      }
    }
    return answer as CompleteValues
  }
}
