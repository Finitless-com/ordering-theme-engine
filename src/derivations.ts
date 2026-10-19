import { legible, mix, shade, TEXT_CONTRAST, TEXT_STEP } from './colors.js'
import { alias, ROLE, SHADOW } from './format.js'
import { asMap, clone, count, dig, entries, get, has, identical, isArray, isList, isMap, isNumeric, toFloat, toInt, type Json, type JsonMap } from './php.js'
import type { TupleSchema } from './types.js'

/**
 * What a component's values derive from its other values when the theme
 * does not set them, as the schema declares it (ThemeDerivations):
 * `co.ordering.derive` on a property of a variant, `co.ordering.matrix` on
 * a component painted in several colors. A rule names the values it
 * derives from by their property in the same variant (its own, else its
 * base's), so it applies the same to a component and to its copy inside a
 * group's scope.
 *
 * - `step` (a state's shadow): `offset` levels from the resting one;
 * - `shade` (a state's background): the resting background `amount`
 *   lighter;
 * - `inset` (a pill tab's rounding): its container's rounding less its
 *   inset, a full rounding staying full;
 * - `matrix`: every color of `colors` in every appearance of `appearances`.
 *
 * A rule's `when` says when it derives a value the theme does not set:
 * `always`; `missing`, when the value is null; `changed` (the default),
 * when it is null or the theme sets what it derives from to something else
 * than its default.
 */

const PRESSED = -0.07
const SUBTLE = 0.12

/** The roles a matrix color derives when it does not have them. */
const ROLES: JsonMap = {
  ink: { op: 'same', from: 'main', when: 'missing' },
  subtle: { op: 'mix', from: 'ink', over: '{color.background}', amount: SUBTLE },
  pressed: { op: 'shade', from: 'main', amount: PRESSED },
}

export interface Rules {
  derive: JsonMap
  matrix: JsonMap
}

/** The rules a schema declares, or the engine's when it declares none (ThemeDerivations::of()). */
export function derivationRules(schema: TupleSchema): Rules {
  if (has(schema, 'component_derive') || has(schema, 'matrix')) {
    const derive = get(schema, 'component_derive')
    const matrix = get(schema, 'matrix')
    return {
      derive: isArray(derive) ? derive as JsonMap : {},
      matrix: isArray(matrix) ? matrix as JsonMap : {},
    }
  }
  return engineRules(schema)
}

/** The rules of a schema that declares none (ThemeDerivations::rules()). */
function engineRules(schema: TupleSchema): Rules {
  const derive: JsonMap = {}
  const matrix: JsonMap = {}
  for (const [component, spec] of entries(get(schema, 'components'))) {
    if (!isArray(spec)) {
      continue
    }
    if (isArray(get(spec, 'colors')) && isArray(get(spec, 'appearances'))) {
      matrix[component] = { into: 'variants', roles: clone(ROLES) }
    }
    for (const [variant, props] of entries(get(spec, 'variants'))) {
      if (!isArray(props)) {
        continue
      }
      const at = `variants.${variant}`
      const rules = (): JsonMap => (derive[component] ??= {}) as JsonMap
      if (has(props, 'tab_radius')) {
        rules()[`${at}.tab_radius`] = { op: 'inset', from: 'radius', by: 'inset', when: 'always' }
      }
      // A resting shadow the schema does not have is no level (false), not `none`.
      const rest = level(has(props, 'shadow') ? get(props, 'shadow') : dig(spec, 'base', 'shadow') ?? false)
      for (const [state, stateProps] of entries(get(props, 'states'))) {
        if (!isArray(stateProps)) {
          continue
        }
        if (has(stateProps, 'shadow')) {
          const index = level(get(stateProps, 'shadow'))
          const offset = rest !== null && index !== null ? index - rest : state === 'hover' ? 1 : null
          if (offset !== null) {
            rules()[`${at}.states.${state}.shadow`] = { op: 'step', from: 'shadow', offset, when: 'always' }
          }
        }
        if (state === 'pressed' && has(stateProps, 'background')) {
          rules()[`${at}.states.${state}.background`] = { op: 'shade', from: 'background', amount: PRESSED }
        }
      }
    }
  }
  return { derive, matrix }
}

/**
 * The shadows of the states the theme does not set (`step`), over the
 * components before their aliases resolve.
 */
export function steps(components: JsonMap, set: JsonMap, rules: JsonMap, specs: JsonMap = {}): JsonMap {
  const answer = clone(components)
  for (const [component, segments, rule, variant] of matching(answer, set, rules, 'step', specs)) {
    const index = level(sibling(answer[component] as JsonMap, variant, get(rule, 'from') as string))
    if (index === null) {
      continue
    }
    const target = SHADOW[Math.max(0, Math.min(index + toInt(get(rule, 'offset') ?? 0), SHADOW.length - 1))]!
    put(answer[component] as JsonMap, segments, `{shadow.${target}}`)
  }
  return answer
}

/**
 * The backgrounds of the states the theme does not set (`shade`), over the
 * resolved components: from the background the theme set, the variant's
 * own or, when it has none, its base's.
 */
export function shades(components: JsonMap, set: JsonMap, rules: JsonMap, specs: JsonMap): JsonMap {
  const answer = clone(components)
  for (const [component, segments, rule, variant] of matching(answer, set, rules, 'shade', specs)) {
    const value = shade(sibling(answer[component] as JsonMap, variant, get(rule, 'from') as string), toFloat(get(rule, 'amount') ?? 0))
    if (value !== null) {
      put(answer[component] as JsonMap, segments, value)
    }
  }
  return answer
}

/**
 * The roundings the theme does not set (`inset`), over the resolved
 * components: `from` less `by`, each the variant's own or its base's.
 */
export function insets(components: JsonMap, set: JsonMap, rules: JsonMap, full: Json, specs: JsonMap = {}): JsonMap {
  const answer = clone(components)
  for (const [component, segments, rule, variant] of matching(answer, set, rules, 'inset', specs)) {
    const radius = sibling(answer[component] as JsonMap, variant, get(rule, 'from') as string)
    const inset = sibling(answer[component] as JsonMap, variant, get(rule, 'by') as string)
    if (!isNumeric(radius) || !isNumeric(inset)) {
      continue
    }
    const value = isNumeric(full) && Number(radius) >= Number(full) ? radius : Math.max(Number(radius) - Number(inset), 0)
    put(answer[component] as JsonMap, segments, value)
  }
  return answer
}

/**
 * Every color of each matrix component in every appearance
 * (`variants[color][appearance]`), over the resolved components, each role
 * a color does not set derived by the matrix's `roles`.
 */
export function matrices(components: JsonMap, colors: JsonMap, set: JsonMap, declared: JsonMap): JsonMap {
  const answer = clone(components)
  for (const [name, matrix] of Object.entries(declared)) {
    const component = get(answer, name)
    if (!isArray(component) || !isArray(get(component, 'colors')) || !isArray(get(component, 'appearances'))) {
      continue
    }

    const variants: JsonMap = {}
    for (const [color, value] of entries(get(component, 'colors'))) {
      if (!isArray(value)) {
        continue
      }
      let roles = clone(value) as JsonMap
      const ownValue = dig(set, name, 'colors', color)
      const own = isArray(ownValue) ? ownValue : {}
      const changed = new Set(entries(own).map(([key]) => key))
      for (const [role, rule] of entries(get(matrix, 'roles'))) {
        if (has(own, role)) {
          continue
        }
        const from = String(get(rule, 'from') ?? '')
        const when = get(rule, 'when') ?? 'changed'
        const inputChanged = changed.has(from)
        if (!(get(roles, role) === null || when === 'always' || (when === 'changed' && inputChanged))) {
          continue
        }
        const over = alias(get(rule, 'over'))
        const overColor = over !== null && over[0] === 'color' ? get(colors, over[1]) : null
        const op = get(rule, 'op')
        const derived = op === 'same'
          ? get(roles, from)
          : op === 'shade'
            ? shade(get(roles, from), toFloat(get(rule, 'amount') ?? 0))
            : op === 'mix'
              ? mix(get(roles, from), overColor, toFloat(get(rule, 'amount') ?? 0))
              : op === 'legible'
                ? legible(get(roles, from), overColor, toFloat(get(rule, 'contrast') ?? TEXT_CONTRAST), toFloat(get(rule, 'step') ?? TEXT_STEP))
                : get(roles, role)
        // Derived again, it goes after the roles that are not.
        if (get(roles, role) !== null) {
          const { [role]: _, ...rest } = roles
          roles = rest
        }
        roles[role] = clone(derived)
        // Derived from what changed, it changes what derives from it.
        if (inputChanged) {
          changed.add(role)
        }
      }
      ;((component as JsonMap).colors as JsonMap)[color] = roles

      const byAppearance: JsonMap = {}
      for (const [appearance, props] of entries(get(component, 'appearances'))) {
        if (isArray(props)) {
          byAppearance[appearance] = withRoles(props, roles)
        }
      }
      if (Object.keys(byAppearance).length > 0) {
        variants[color] = byAppearance
      }
    }
    ;(component as JsonMap)[String(get(matrix, 'into') ?? 'variants')] = variants
  }
  return answer
}

function withRoles(node: Json, roles: JsonMap): Json {
  if (Array.isArray(node)) {
    return node.map((value) => withRoles(value, roles))
  }
  if (!isArray(node)) {
    const match = typeof node === 'string' ? ROLE.exec(node) : null
    return match ? clone(get(roles, match[1]!)) : node
  }
  const answer: JsonMap = {}
  for (const [key, value] of Object.entries(node)) {
    answer[key] = withRoles(value, roles)
  }
  return answer
}

type Match = [string, string[], JsonMap, string]

/**
 * Each rule of an operation over a component the values have, whose target
 * exists, the theme does not set, and its `when` says derives: `always`;
 * `missing`, when the target is null; `changed` (the default), when the
 * target is null or the theme sets one of the values it derives from
 * (`from`, `by`) to something else than its default.
 */
function matching(components: JsonMap, set: JsonMap, rules: JsonMap, op: string, specs: JsonMap): Match[] {
  const answer: Match[] = []
  for (const [component, sections] of Object.entries(components)) {
    if (!isArray(sections)) {
      continue
    }
    for (const [path, rule] of entries(get(rules, component))) {
      if (get(rule, 'op') !== op) {
        continue
      }
      const segments = path.split('.')
      const own = get(set, component)
      if (segments[0] !== 'variants' || !hasPath(sections, segments) || hasPath(isArray(own) ? own : {}, segments)) {
        continue
      }
      const when = get(rule, 'when') ?? 'changed'
      const missing = dig(sections, ...segments) === null
      if ((when === 'missing' && !missing)
        || (when === 'changed' && !missing && !changed(sections as JsonMap, own, get(specs, component), segments[1]!, [get(rule, 'from'), get(rule, 'by')]))) {
        continue
      }
      answer.push([component, segments, rule as JsonMap, segments[1]!])
    }
  }
  return answer
}

/**
 * Whether the theme sets a value a rule derives from to something else than
 * its default: the variant's own, or its base's when the variant has none.
 */
function changed(sections: JsonMap, set: Json, spec: Json, variant: string, inputs: Json[]): boolean {
  const ownValue = dig(set, 'variants', variant)
  const own = isArray(ownValue) ? ownValue : {}
  const baseValue = dig(set, 'base')
  const base = isArray(baseValue) ? baseValue : {}
  const resolvedValue = dig(sections, 'variants', variant)
  const resolved = isArray(resolvedValue) ? resolvedValue : {}
  for (const input of inputs) {
    if (typeof input !== 'string') {
      continue
    }
    if (has(own, input) && !identical(get(own, input), specDefault(dig(spec, 'variants', variant, input)))) {
      return true
    }
    if (!has(resolved, input) && has(base, input) && !identical(get(base, input), specDefault(dig(spec, 'base', input)))) {
      return true
    }
  }
  return false
}

/** A variant's value of a property: its own, else its base's. */
function sibling(sections: JsonMap, variant: string, property: string): Json {
  const props = dig(sections, 'variants', variant) ?? {}
  return isArray(props) && has(props, property) ? get(props, property) : dig(sections, 'base', property)
}

function hasPath(tree: Json, segments: string[]): boolean {
  let node = tree
  for (const segment of segments) {
    if (!isArray(node) || !has(node, segment)) {
      return false
    }
    node = get(node, segment)
  }
  return true
}

/** PHP's `$node = &$node[$segment]` down to the target, then the value. */
function put(tree: JsonMap, segments: string[], value: Json): void {
  let node: JsonMap = tree
  for (const segment of segments.slice(0, -1)) {
    if (!isMap(node[segment])) {
      node[segment] = asMap(node[segment])
    }
    node = node[segment] as JsonMap
  }
  node[segments[segments.length - 1]!] = value
}

/** A shadow's level index: `none` for null, or null when it is no level. */
function level(value: Json | false): number | null {
  let shadow: Json | false = value
  if (isArray(shadow) && isList(shadow)) {
    shadow = count(shadow) >= 2 ? get(shadow, 1) : false
  }
  const parts = alias(shadow)
  const name = shadow === null ? 'none' : parts !== null && parts[0] === 'shadow' ? parts[1] : null
  const index = name === null ? -1 : (SHADOW as readonly string[]).indexOf(name)
  return index === -1 ? null : index
}

function specDefault(spec: Json): Json {
  return isArray(spec) && isList(spec) && count(spec) >= 2 ? get(spec, 1) : null
}
