import { compile, isNodes } from './compile.js'
import { FONT_ROLES, STRING_MAX_LENGTH, TOKENS, WEIGHTS, tokenType } from './format.js'
import { TYPOGRAPHY_RANGES, fieldRanges, filled, formatRange, nodeRange, numberKind, tokenNumberKind, type Range } from './limits.js'
import { dig, entries, get, isArray, isList, phpString, values, type Json, type JsonMap } from './php.js'
import type { NodeSchema, TupleSchema } from './types.js'

/**
 * The rules a client checks a theme it is served with, without the schema
 * (a website keeps the schema out of its bundle): what each value may be
 * that its default alone does not tell. api-core validates with the
 * compiled schema itself (ThemeValidator); these are for the clients, so
 * there is no PHP counterpart.
 *
 *   {
 *     platforms: ['mobile_web'],             the schema's platforms
 *     scopes: { group: [component, …] },      what each group's `scope` may change
 *     text: { size, line, letter_spacing, weight, transform },
 *                                             a typography style's fields: the
 *                                             `tokens.typography` group's
 *     values: { tokens, components, site },   a rule per value, at its path
 *     platform_values: { <platform>: { components: { <c>: { base: { <prop>: rule } } } } }
 *                                             a platform's own rules where they
 *                                             differ from the component's base
 *   }
 *
 * A rule is a letter or a list:
 *
 *   [min, max]                 a number, clamped to them (see below)
 *   ['o', …values]             one of them: an option, a layout, a font, a font role
 *   'c'                        a color
 *   'r'                        a color or a role of a component's color (`{main}`…)
 *   'b', 's'                   a border, a shadow (their fields are the format's)
 *   't' / ['t', fields]        a typography style: the fields of `text`, or the
 *                              ones of its own that differ from them
 *   'l'                        a boolean that may be null (its default is null)
 *   ['x', max_length, pattern?]  a `string`
 *   'i'                        an `id`: null, a non-negative integer or a text
 *
 * `'*'` stands for any key: a matrix component's variants, built from its
 * colors and its appearances, each take the appearances' rules. A value
 * without a rule is checked against its default (a boolean, an `html`
 * text); `visibility`, `images`, `pages` and the components' `slots` are
 * checked by their defaults' shape.
 *
 * A number's range is its node's `limits` (the editor's `min` and `max`,
 * the same ones api-core binds a saved theme with), the format's range
 * where they say nothing. A client is served the theme resolved, an alias
 * replaced by its level's value, so the range of a property whose kind has
 * a scale (`radius`, `size`, `icon`, `space`, `motion`, `opacity`) also
 * takes in the values its scale's levels may have: each level's own limits,
 * or its default where it has none.
 */
export function runtimeRules(schema: NodeSchema | TupleSchema): JsonMap {
  const tuples = isNodes(schema) ? compile(schema).schema : schema as TupleSchema
  const limits = get(tuples, 'limits')
  const scales = scaleRanges(tuples, limits)
  const text = textRules(limits, 'tokens.typography')

  const rule = (spec: Json, node: string): Json | undefined => propertyRule(spec, node, limits, scales, text)
  const components: JsonMap = {}
  const scopes: JsonMap = {}
  const platformValues: JsonMap = {}
  for (const [name, component] of entries(get(tuples, 'components'))) {
    const rules: JsonMap = {}
    for (const [section, props] of entries(component)) {
      if (section === 'scope' || section === 'slots' || section === 'platforms' || !isArray(props)) {
        continue
      }
      const sectionRules = groupRules(props, `components.${name}.${section}`, rule, section !== 'base')
      if (sectionRules !== undefined) {
        rules[section] = sectionRules
      }
    }
    const matrix = dig(tuples, 'matrix', name)
    const into = get(matrix, 'into')
    if (typeof into === 'string' && rules.appearances !== undefined) {
      rules[into] = { '*': rules.appearances }
    }
    if (Object.keys(rules).length > 0) {
      components[name] = rules
    }
    const scope = get(component, 'scope')
    if (isArray(scope)) {
      scopes[name] = Object.keys(scope)
    }
    for (const [platform, props] of entries(get(component, 'platforms'))) {
      const base = (isArray(rules.base) ? rules.base : {}) as JsonMap
      const differ: JsonMap = {}
      for (const [prop, spec] of entries(props)) {
        const own = rule(spec, `components.${name}.platforms.${platform}.${prop}`)
        if (own !== undefined && !same(own, base[prop])) {
          differ[prop] = own
        }
      }
      if (Object.keys(differ).length > 0) {
        const at = (platformValues[platform] ??= { components: {} }) as JsonMap
        ;(at.components as JsonMap)[name] = { base: differ }
      }
    }
  }

  return {
    platforms: values(get(tuples, 'platforms')),
    scopes,
    text,
    values: {
      tokens: tokenRules(tuples, limits, text),
      components,
      site: groupRules(get(tuples, 'site'), 'site', rule, true) ?? {},
    },
    platform_values: platformValues,
  }
}

/** The rules as the file a product versions: each rule on one line, the maps that hold them indented. */
export function serializeRules(rules: JsonMap): string {
  const write = (value: Json, indent: string): string => {
    if (!isArray(value) || Array.isArray(value)) {
      return JSON.stringify(value)
    }
    const keys = Object.keys(value)
    if (keys.length === 0) {
      return '{}'
    }
    const inner = `${indent}  `
    return `{\n${keys.map((key) => `${inner}${JSON.stringify(key)}: ${write(value[key]!, inner)}`).join(',\n')}\n${indent}}`
  }
  return `${write(rules, '')}\n`
}

type RuleOf = (spec: Json, node: string) => Json | undefined

/**
 * The rules of a group of specs (a section, a variant and its states, the
 * site's settings), at their paths; a group without any is left out.
 */
function groupRules(group: Json, path: string, rule: RuleOf, nested: boolean): JsonMap | undefined {
  const answer: JsonMap = {}
  for (const [key, spec] of entries(group)) {
    const own = isArray(spec) && isList(spec) && typeof get(spec, 0) === 'string'
      ? rule(spec, `${path}.${key}`)
      : (nested && isArray(spec) ? groupRules(spec, `${path}.${key}`, rule, true) : undefined)
    if (own !== undefined) {
      answer[key] = own
    }
  }
  return Object.keys(answer).length > 0 ? answer : undefined
}

/** The rule of a component property or a site's setting, or undefined when its default says it all. */
function propertyRule(spec: Json, node: string, limits: Json, scales: Record<string, Range>, text: JsonMap): Json | undefined {
  const kind = phpString(get(spec, 0))
  switch (kind) {
    case 'color': return 'c'
    case 'role': return 'r'
    case 'border': return 'b'
    case 'shadow': return 's'
    case 'id': return 'i'
    case 'option':
    case 'layout': return ['o', ...values(get(spec, 2))]
    case 'font_role': return ['o', ...(isArray(get(spec, 2)) ? values(get(spec, 2)) : FONT_ROLES)]
    case 'bool': return get(spec, 1) === null ? 'l' : undefined
    case 'string': {
      const rules = get(spec, 3)
      const maxLength = get(rules, 'max_length')
      const pattern = get(rules, 'pattern')
      return [
        'x',
        typeof maxLength === 'number' ? maxLength : STRING_MAX_LENGTH,
        ...(typeof pattern === 'string' && pattern !== '' ? [pattern] : []),
      ]
    }
    case 'text': {
      const own = differing(textRules(limits, node), text)
      return Object.keys(own).length > 0 ? ['t', own] : 't'
    }
  }
  const number = kind === 'radius' ? 'number' : numberKind(kind)
  if (number === null || number === 'text') {
    return undefined
  }
  const own = nodeRange(limits, node)
  const scale = scales[kind]
  const lowest = [own.min, scale?.min].filter((bound): bound is number => typeof bound === 'number')
  const highest = [own.max, scale?.max].filter((bound): bound is number => typeof bound === 'number')
  return pair(filled({
    min: lowest.length > 0 ? Math.min(...lowest) : null,
    max: highest.length > 0 ? Math.max(...highest) : null,
  }, formatRange(number)))
}

/** The tokens' rules: each token the schema has, by its group's kind. */
function tokenRules(tuples: TupleSchema, limits: Json, text: JsonMap): JsonMap {
  const answer: JsonMap = {}
  const narrowed = get(tuples, 'token_options')
  const colors = Object.keys(isArray(dig(tuples, 'tokens', 'color')) ? dig(tuples, 'tokens', 'color') as JsonMap : {})
  for (const [group, names] of entries(get(tuples, 'tokens'))) {
    const rules: JsonMap = {}
    for (const [name] of entries(names)) {
      const path = `tokens.${group}.${name}`
      const kind = tokenNumberKind(group, name)
      if (kind === 'text') {
        const own = differing(textRules(limits, path), text)
        rules[name] = Object.keys(own).length > 0 ? ['t', own] : 't'
      } else if (kind !== null) {
        rules[name] = pair(filled(nodeRange(limits, path), formatRange(kind)))
      } else if (TOKENS[group] === 'color') {
        rules[name] = 'c'
      } else if (TOKENS[group] === 'shadow') {
        rules[name] = 's'
      } else {
        // The focus ring's color is one of the schema's own.
        const type = tokenType(group, name, colors)
        const options = get(narrowed, `${group}.${name}`) ?? (type?.[1] ? [...type[1]] : null)
        if (type?.[0] === 'color') {
          rules[name] = 'c'
        } else if (options !== null) {
          rules[name] = ['o', ...values(options)]
        }
      }
    }
    if (Object.keys(rules).length > 0) {
      answer[group] = rules
    }
  }
  return answer
}

/** A typography style's fields at a node: each numeric one's range (the format's where it says nothing), its weights and transforms. */
function textRules(limits: Json, node: string): JsonMap {
  const ranges = fieldRanges(limits, node)
  const range = (field: string): Json => pair(filled(ranges[field] ?? { min: null, max: null }, TYPOGRAPHY_RANGES[field]!))
  // In the order of a style's fields.
  return {
    size: range('size'),
    line: range('line'),
    weight: ['o', ...WEIGHTS],
    letter_spacing: range('letter_spacing'),
    transform: ['o', 'none', 'uppercase'],
  }
}

/**
 * The range each scale's levels may have in a resolved theme: of its
 * tokens, each one's own limits, or its default where it has none.
 */
function scaleRanges(tuples: TupleSchema, limits: Json): Record<string, Range> {
  const answer: Record<string, Range> = {}
  for (const scale of ['radius', 'size', 'icon', 'space', 'motion', 'opacity']) {
    let min = Infinity
    let max = -Infinity
    for (const [name, value] of entries(dig(tuples, 'tokens', scale))) {
      const own = nodeRange(limits, `tokens.${scale}.${name}`)
      const low = own.min ?? value
      const high = own.max ?? value
      if (typeof low === 'number') {
        min = Math.min(min, low)
      }
      if (typeof high === 'number') {
        max = Math.max(max, high)
      }
    }
    if (Number.isFinite(min) && Number.isFinite(max)) {
      answer[scale] = { min, max }
    }
  }
  return answer
}

/** The fields of $own whose rule is not $base's. */
function differing(own: JsonMap, base: JsonMap): JsonMap {
  return Object.fromEntries(Object.entries(own).filter(([field, rule]) => !same(rule, base[field])))
}

function same(a: Json | undefined, b: Json | undefined): boolean {
  return JSON.stringify(a) === JSON.stringify(b)
}

function pair(range: Range): Json {
  return [range.min, range.max]
}
