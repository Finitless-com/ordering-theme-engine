import { deriveColors } from './colors.js'
import { compile, isNodes } from './compile.js'
import { derivationRules, insets, matrices, shades, steps } from './derivations.js'
import { NO_BORDER, alias, typographyRole } from './format.js'
import { applyOverall } from './overall.js'
import { resolvePageSections } from './pages.js'
import { clone, dig, entries, get, has, isArray, isEmpty, isList, isMap, type Json, type JsonMap } from './php.js'
import { kindAt, schemaDefaults } from './schemas.js'
import { applyShadows } from './shadows.js'
import type { NodeSchema, ResolveOptions, ResolvedTheme, ThemeValues, TupleSchema } from './types.js'

/** Each node schema compiled once. */
const compiled = new WeakMap<object, TupleSchema>()

function tuples(schema: NodeSchema): TupleSchema {
  if (!isNodes(schema)) {
    throw new TypeError('The theme engine reads a schema in the node format (`$schema`: urn:ordering:theme-schema:1).')
  }
  let answer = compiled.get(schema)
  if (answer === undefined) {
    answer = compile(schema).schema
    compiled.set(schema, answer)
  }
  return answer
}

/**
 * A version 2 theme over its schema's defaults, every alias resolved: what
 * api-core's ThemeResolver::resolve() answers, step by step.
 *
 * 1. what the theme sets on a parent (`inherits`) also on its children;
 * 2. the schema's defaults, with Identity's shadow style and its shadow,
 *    rounding and border levels over every property that follows them;
 * 3. the theme over them, and every page's sections in order;
 * 4. each group's scope: the global component with the group's changes;
 * 5. `none` borders as null;
 * 6. the states' shadows (`step`) and the derived colors;
 * 7. the aliases, and each typography's family by its role;
 * 8. the states' backgrounds (`shade`), the insets and the matrices.
 *
 * The theme is assumed valid: api-core validates it when it is saved and,
 * when it serves it, drops what no longer fits the schema
 * (ThemeResolver::valid()), which this does not do.
 */
export function resolve(schema: NodeSchema, theme: ThemeValues | null | undefined, options: ResolveOptions = {}): ResolvedTheme {
  const resolved = resolveTuples(tuples(schema), theme ?? {})
  return (options.emptyMaps === 'object' ? resolved : emptyAsArray(resolved)) as ResolvedTheme
}

/** The empty theme resolved: what a site whose theme sets nothing serves. */
export function defaults(schema: NodeSchema, options: ResolveOptions = {}): ResolvedTheme {
  return resolve(schema, {}, options)
}

/** ThemeResolver::resolve() over a compiled schema. */
export function resolveTuples(schema: TupleSchema, theme: unknown): JsonMap {
  const own = inherit(valid(theme), schema)
  let defaults = schemaDefaults(schema)

  // The shadow style, color and direction remake the levels; what the
  // theme sets level by level still goes over them.
  const shadowStyle = overall(defaults, own, 'shadow_style')
  const levels = dig(defaults, 'tokens', 'shadow')
  if (!isEmpty(shadowStyle) && isArray(levels)) {
    const style = get(shadowStyle, 'style') ?? 'normal'
    const color = get(shadowStyle, 'color')
    const direction = get(shadowStyle, 'direction') ?? 'down'
    ;(defaults.tokens as JsonMap).shadow = applyShadows(levels as JsonMap, String(style), typeof color === 'string' ? color : null, String(direction))
  }
  // Identity's shadow size, rounding and border are every following
  // component's default; what the theme sets on a component goes over them.
  const borderStyle = overall(defaults, own, 'border_style')
  defaults = applyOverall(defaults, schema, {
    shadow: get(shadowStyle, 'size'),
    radius: get(borderStyle, 'radius'),
    border_width: get(borderStyle, 'width'),
    border_style: get(borderStyle, 'style'),
  })

  let values = merge(defaults, own)
  values = resolvePages(values, schema)
  const [scoped, scopedOwn] = scope(values, own)
  values = scoped
  if (isArray(values.components)) {
    values.components = noBorders(values.components, schema, 'components') as JsonMap
  }
  // A platform changes properties of a component's base, of the same kinds.
  for (const [platform, override] of entries(values.platforms)) {
    const components = get(override, 'components')
    if (isArray(components)) {
      ;((values.platforms as JsonMap)[platform] as JsonMap).components = noBorders(components, schema, 'components')
    }
  }

  // A state's shadow the theme does not set keeps the schema's distance
  // from the resting one, Identity's or the theme's.
  const specs = isArray(schema.components) ? schema.components as JsonMap : {}
  const rules = derivationRules(schema)
  const ownComponents = isArray(own.components) ? own.components as JsonMap : {}
  if (isArray(values.components)) {
    values.components = steps(values.components as JsonMap, ownComponents, rules.derive, specs)
  }
  for (const [group, set] of Object.entries(scopedOwn)) {
    const component = (values.components as JsonMap)[group] as JsonMap
    component.scope = steps(component.scope as JsonMap, set, rules.derive, specs)
  }
  const colors = dig(values, 'tokens', 'color')
  if (isArray(colors)) {
    const ownColors = dig(own, 'tokens', 'color')
    const defaultColors = dig(defaults, 'tokens', 'color')
    const declared = get(schema, 'derive')
    ;(values.tokens as JsonMap).color = deriveColors(
      colors as JsonMap,
      isArray(ownColors) ? ownColors as JsonMap : {},
      isArray(defaultColors) ? defaultColors as JsonMap : {},
      isArray(declared) ? declared as JsonMap : null,
    )
  }

  values = resolveAliases(values)
  const full = dig(values, 'tokens', 'radius', 'full')
  const resolvedColors = dig(values, 'tokens', 'color')
  const palette = isArray(resolvedColors) ? resolvedColors as JsonMap : {}
  if (isArray(values.components)) {
    let components = shades(values.components as JsonMap, ownComponents, rules.derive, specs)
    components = insets(components, ownComponents, rules.derive, full, specs)
    values.components = matrices(components, palette, ownComponents, rules.matrix)
  }
  for (const [group, set] of Object.entries(scopedOwn)) {
    const component = (values.components as JsonMap)[group] as JsonMap
    let scope = shades(component.scope as JsonMap, set, rules.derive, specs)
    scope = insets(scope, set, rules.derive, full, specs)
    component.scope = matrices(scope, palette, set, rules.matrix)
  }

  return values
}

/**
 * $over on $base: maps merge key by key, an empty one sets nothing, and
 * anything else (a list, a scalar, null) replaces (ThemeResolver::merge()).
 */
export function merge(base: JsonMap, over: Json): JsonMap {
  const answer = clone(base)
  mergeInto(answer, over)
  return answer
}

function mergeInto(base: JsonMap, over: Json): void {
  for (const [key, value] of entries(over)) {
    const current = has(base, key) ? base[key]! : null
    // An empty map ({} decodes as []) sets nothing.
    if (isEmpty(value) && isArray(current)) {
      continue
    }
    if (isArray(value) && !isList(value) && isArray(current) && !isList(current)) {
      mergeInto(current as JsonMap, value)
    } else {
      base[key] = clone(value)
    }
  }
}

/** What of a stored theme has the shape of one: a list is no theme. */
function valid(theme: unknown): JsonMap {
  const values = clone(theme as Json)
  return isMap(values) && !(count(values) > 0 && isList(values)) ? values : {}
}

function count(map: JsonMap): number {
  return Object.keys(map).length
}

/**
 * $values with what they set on a parent (the schema's `inherits`) also set
 * on each component that inherits from it: its base, its variants, states
 * included, and its sizes, for the properties that component has and the
 * theme does not set there.
 */
function inherit(values: JsonMap, schema: TupleSchema): JsonMap {
  for (const [component, parent] of entries(get(schema, 'inherits'))) {
    for (const section of ['base', 'variants', 'sizes']) {
      const set = dig(values, 'components', String(parent), section)
      const spec = dig(schema, 'components', component, section)
      if (!isArray(set) || !isArray(spec)) {
        continue
      }
      const own = dig(values, 'components', component, section)
      const followed = follow(set, isArray(own) ? own : {}, spec)
      if (!isEmpty(followed)) {
        const components = (values.components = isMap(values.components) ? values.components : {}) as JsonMap
        const child = (components[component] = isMap(components[component]) ? components[component] : {}) as JsonMap
        child[section] = followed
      }
    }
  }
  return values
}

/** $own with what $set has and $own does not, where $spec has a property. */
function follow(set: Json, own: Json, spec: Json): JsonMap {
  const answer = isMap(own) ? clone(own) : {}
  for (const [key, value] of entries(set)) {
    const prop = get(spec, key)
    if (!isArray(prop)) {
      continue
    }
    if (isList(prop)) {
      if (!has(answer, key)) {
        answer[key] = clone(value)
      }
    } else if (isArray(value)) {
      const nested = follow(value, isArray(answer[key]) ? answer[key]! : {}, prop)
      if (!isEmpty(nested)) {
        answer[key] = nested
      }
    }
  }
  return answer
}

/** A theme-wide style group: the theme's choices over the schema's defaults. */
function overall(defaults: JsonMap, own: JsonMap, group: string): JsonMap {
  const schema = dig(defaults, 'tokens', group)
  const theme = dig(own, 'tokens', group)
  return { ...(isMap(schema) ? schema : {}), ...(isMap(theme) ? theme : {}) }
}

/** The sections of every page, and of each page a platform overrides, in order. */
function resolvePages(values: JsonMap, schema: TupleSchema): JsonMap {
  if (isArray(values.pages)) {
    values.pages = resolvePageSections(values.pages as JsonMap, schema)
  }
  for (const [platform, override] of entries(values.platforms)) {
    const pages = get(override, 'pages')
    if (isArray(pages)) {
      ;((values.platforms as JsonMap)[platform] as JsonMap).pages = resolvePageSections(pages as JsonMap, schema)
    }
  }
  return values
}

/**
 * Each component a group changes (its `scope`): the component as it is
 * everywhere, with the group's changes over it. A component the group does
 * not change is left out of its scope. Answers the values and, by group,
 * what the theme sets on each scoped component.
 */
function scope(values: JsonMap, own: JsonMap): [JsonMap, Record<string, JsonMap>] {
  const scopedOwn: Record<string, JsonMap> = {}
  const components = values.components
  for (const [group, component] of entries(components)) {
    const changesByComponent = get(component, 'scope')
    if (!isArray(changesByComponent)) {
      continue
    }
    const target = (components as JsonMap)[group] as JsonMap
    for (const [scoped, changes] of entries(changesByComponent)) {
      const global = get(components, scoped)
      if (!isArray(changes) || isEmpty(changes) || !isArray(global)) {
        delete (target.scope as JsonMap)[scoped]
        continue
      }
      ;(target.scope as JsonMap)[scoped] = merge(global as JsonMap, changes)
      const ownGlobal = dig(own, 'components', scoped)
      const ownChanges = dig(own, 'components', group, 'scope', scoped)
      ;(scopedOwn[group] ??= {})[scoped] = merge(isMap(ownGlobal) ? ownGlobal : {}, isArray(ownChanges) ? ownChanges : {})
    }
    if (isEmpty(target.scope)) {
      delete target.scope
      delete scopedOwn[group]
    }
  }
  return [values, scopedOwn]
}

/**
 * The borders set to `none` (by the theme, or by a group's scope in the
 * schema) as null, the way a client reads them. A group's scope holds its
 * component's paths.
 */
function noBorders(values: Json, schema: TupleSchema, path: string): Json {
  if (!isArray(values)) {
    return values
  }
  const answer = (Array.isArray(values) ? [...values] : { ...values }) as Record<string, Json>
  for (const [key, value] of entries(values)) {
    const at = `${path}.${key}`
    if (isArray(value)) {
      answer[key] = noBorders(value, schema, at)
    } else if (value === NO_BORDER && kindAt(schema, at.replace(/^components\.[a-z0-9_]+\.scope\./, 'components.')) === 'border') {
      answer[key] = null
    }
  }
  return answer as Json
}

/**
 * Every alias of the components (and of their platform overrides) replaced
 * by its token's value, and each typography with the `family` of its font
 * role.
 */
function resolveAliases(values: JsonMap): JsonMap {
  const tokens: JsonMap = isMap(values.tokens) ? values.tokens : {}
  const typography = get(tokens, 'typography')
  for (const [style, value] of entries(typography)) {
    if (isMap(value)) {
      const fonts = get(tokens, 'font')
      value.family = isArray(fonts) ? get(fonts, typographyRole(style)) : null
    }
  }
  if (has(values, 'tokens') && values.tokens !== null) {
    values.tokens = tokens
  }

  if (isArray(values.components)) {
    values.components = resolveIn(values.components, tokens)
  }
  for (const [platform, override] of entries(values.platforms)) {
    const components = get(override, 'components')
    if (isArray(components)) {
      ;((values.platforms as JsonMap)[platform] as JsonMap).components = resolveIn(components, tokens)
    }
  }
  return values
}

function resolveIn(node: Json, tokens: JsonMap): Json {
  if (!isArray(node)) {
    return node
  }
  const answer = (Array.isArray(node) ? [...node] : { ...node }) as Record<string, Json>
  for (const [key, value] of entries(node)) {
    if (isArray(value)) {
      answer[key] = resolveIn(value, tokens)
      continue
    }
    const parts = alias(value)
    if (parts !== null) {
      const group = get(tokens, parts[0])
      answer[key] = isArray(group) ? clone(get(group, parts[1])) : null
    }
  }
  return answer as Json
}

/** Every empty map as `[]`, as PHP's json_encode() writes it. */
function emptyAsArray(value: Json): Json {
  if (Array.isArray(value)) {
    return value.map(emptyAsArray)
  }
  if (!isMap(value)) {
    return value
  }
  const keys = Object.keys(value)
  if (keys.length === 0) {
    return []
  }
  const answer: JsonMap = {}
  for (const key of keys) {
    answer[key] = emptyAsArray(value[key]!)
  }
  return answer
}
