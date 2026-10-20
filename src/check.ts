import {
  LEVEL_OPTION_KINDS, NARROWABLE, OPTION_KINDS, OVERALL_GROUPS, OWN_GROUPS, PRODUCT_TYPES, PROPERTY_KINDS, SLOT_AXES, TOKENS, VERSION, alias, tokenNames, tokenType,
} from './format.js'
import { checkPages } from './pages.js'
import {
  count, entries, get, has, identical, isArray, isEmpty, isInt, isList, phpString, uniqueCount, values, type Json, type JsonMap,
} from './php.js'
import { kindAt, propertySpec } from './schemas.js'
import type { SchemaError, TupleSchema } from './types.js'
import { tokenErrors, validFormats, validHints, validRules, valueError } from './validator.js'

type Fail = (path: string, rule: string) => void

const NAME = /^[a-z0-9_]+\n?$/
const KEY = /^[a-z0-9_]+(\.[a-z0-9_]+){0,7}\n?$/
const SCHEMA_VERSION = /^\d{1,4}(\.\d{1,4}){0,2}\n?$/
const SECTIONS = [
  'product_type', 'version', 'format', 'tokens', 'components', 'inherits', 'visibility', 'images', 'pages', 'platforms',
  'site', 'v1', 'follows', 'derive', 'component_derive', 'matrix', 'migrate', 'alpha', 'token_options',
]

/** PHP's in_array($value, $list, true) for a list of strings. */
const among = (list: readonly string[], value: unknown): boolean => typeof value === 'string' && list.includes(value)

/**
 * What a compiled schema gets wrong (api-core's ThemeSchemas::check()), in
 * the order api-core finds it: the header, the tokens, the components, the
 * inherits, visibility and images, the site, what follows Identity, the
 * derivations, the matrices, the migrations, the pages and the version 1
 * map. compile() answers the tuples it reads.
 */
export function check(schema: TupleSchema): SchemaError[] {
  const errors: SchemaError[] = []
  const fail: Fail = (path, rule) => {
    errors.push({ path, code: `${path}.${rule}` })
  }

  if (!among(PRODUCT_TYPES, get(schema, 'product_type'))) {
    fail('product_type', 'invalid')
  }
  const version = get(schema, 'version')
  if (typeof version !== 'string' || !SCHEMA_VERSION.test(version)) {
    fail('version', 'invalid')
  }
  if (get(schema, 'format') !== VERSION) {
    fail('format', 'invalid')
  }
  for (const section of Object.keys(schema)) {
    if (!SECTIONS.includes(section)) {
      fail(section, 'unknown')
    }
  }

  // The defaults of the tokens: complete, and literals only.
  let tokens = get(schema, 'tokens')
  if (!isArray(tokens)) {
    fail('tokens', 'required')
    tokens = {}
  }
  for (const group of ['font', 'typography', ...Object.keys(TOKENS)]) {
    if ((OWN_GROUPS as readonly string[]).includes(group)) {
      if (isEmpty(get(tokens, group) ?? [])) {
        fail(`tokens.${group}`, 'required')
      }
      continue
    }
    for (const name of tokenNames(group)) {
      if (!has(get(tokens, group), name)) {
        fail(`tokens.${group}.${name}`, 'required')
      }
    }
  }
  // What the schema refers to of the groups it names itself, it has.
  const refers = (node: Json, path: string): void => {
    if (isArray(node) && isList(node) && typeof get(node, 0) === 'string' && (Object.hasOwn(PROPERTY_KINDS, get(node, 0) as string) || get(node, 0) === 'role')) {
      // A property in tuples: its default.
      refers(get(node, 1), `${path}.default`)
    } else if (isArray(node)) {
      for (const [key, child] of entries(node)) {
        refers(child, `${path}.${key}`)
      }
    } else {
      const named = alias(node)
      if (named !== null && (OWN_GROUPS as readonly string[]).includes(named[0]) && !has(get(tokens, named[0]), named[1])) {
        fail(path, 'alias')
      }
    }
  }
  for (const section of ['tokens', 'components']) {
    refers(get(schema, section) ?? [], section)
  }
  errors.push(...tokenErrors(tokens, 'tokens'))
  // A token it narrows keeps some of its options, its default among them.
  const tokenOptions = get(schema, 'token_options') ?? []
  for (const [token, options] of isArray(tokenOptions) ? entries(tokenOptions) : [['', null] as [string, Json]]) {
    const [group = '', name = ''] = token.split(/\.(.*)/s)
    const catalog = tokenType(group, name, Object.keys(get(tokens, 'color') ?? {}))?.[1] ?? []
    const value = get(get(tokens, group), name)
    if (!(NARROWABLE as readonly string[]).includes(token) || !isArray(options) || isEmpty(options) || !isList(options)
      || !values(options).every((option) => catalog.some((known) => identical(known, option))) || !values(options).some((option) => identical(option, value))) {
      fail(token === '' ? 'token_options' : `token_options.${token}`, 'invalid')
    }
  }

  let platforms = get(schema, 'platforms') ?? []
  if (!isArray(platforms) || !isList(platforms)) {
    fail('platforms', 'invalid')
    platforms = []
  }
  const platformNames = values(platforms)

  for (const [component, spec] of entries(get(schema, 'components'))) {
    const path = `components.${component}`
    if (!NAME.test(component) || !isArray(spec)) {
      fail(path, 'invalid')
      continue
    }
    for (const [section, props] of entries(spec)) {
      const sectionPath = `${path}.${section}`
      if (!isArray(props)) {
        fail(sectionPath, 'invalid')
        continue
      }
      if (section === 'base') {
        checkProps(props, sectionPath, fail)
      } else if (['variants', 'sizes', 'shapes', 'colors', 'appearances'].includes(section)) {
        for (const [name, variant] of entries(props)) {
          const states = get(variant, 'states') ?? []
          const own: JsonMap = {}
          for (const [prop, value] of entries(variant)) {
            if (prop !== 'states') {
              own[prop] = value
            }
          }
          checkProps(own, `${sectionPath}.${name}`, fail)
          if ((section === 'variants' || section === 'appearances') && isArray(states)) {
            for (const [state, stateProps] of entries(states)) {
              checkProps(isArray(stateProps) ? stateProps : {}, `${sectionPath}.${name}.states.${state}`, fail)
            }
          }
        }
      } else if (section === 'scope') {
        checkScope(schema, component, props, sectionPath, fail)
      } else if (section === 'slots') {
        checkSlots(schema, props, sectionPath, fail)
      } else if (section === 'platforms') {
        for (const [platform, platformProps] of entries(props)) {
          if (!platformNames.some((name) => name === platform)) {
            fail(`${sectionPath}.${platform}`, 'unknown')
            continue
          }
          checkProps(isArray(platformProps) ? platformProps : {}, `${sectionPath}.${platform}`, fail)
          // A platform changes a property of the base, of the same kind.
          for (const [prop, platformSpec] of entries(platformProps)) {
            const own = get(get(spec, 'base'), prop)
            if (!isArray(own) || !isArray(platformSpec) || !identical(get(own, 0), get(platformSpec, 0))) {
              fail(`${sectionPath}.${platform}.${prop}`, 'invalid')
            }
          }
        }
      } else {
        fail(sectionPath, 'unknown')
      }
    }
  }

  // A parent is a component of the schema that inherits from none.
  let inherits = get(schema, 'inherits') ?? []
  if (!isArray(inherits)) {
    fail('inherits', 'invalid')
    inherits = []
  }
  const components = get(schema, 'components')
  const isset = (array: Json, key: string): boolean => get(array, key) !== null
  for (const [component, parent] of entries(inherits)) {
    if (!isset(components, component) || typeof parent !== 'string' || parent === component
      || !isset(components, parent) || isset(inherits, parent)) {
      fail(`inherits.${component}`, 'invalid')
    }
  }

  for (const [section, kind] of [['visibility', 'bool'], ['images', 'image']] as const) {
    for (const [key, entry] of entries(get(schema, section))) {
      const path = `${section}.${key}`
      const second = isArray(entry) ? get(entry, 1) : null
      if (!KEY.test(key) || !isArray(entry) || !isList(entry)
        || !(section === 'images' ? [2, 3, 4] : [2, 3]).includes(count(entry))
        || !(typeof second === 'string' || second === null)) {
        fail(path, 'invalid')
        continue
      }
      if (section === 'visibility' && has(entry, 2)) {
        // Its default on each platform that shows it otherwise: a declared one, a bool.
        const own = get(entry, 2)
        if (!isArray(own) || isEmpty(own) || isList(own)
          || entries(own).some(([platform, visible]) => !platformNames.some((name) => name === platform) || typeof visible !== 'boolean')) {
          fail(`${path}.platforms`, 'invalid')
        }
      } else if (get(entry, 2) !== null && !validFormats(get(entry, 2))) {
        fail(`${path}.formats`, 'invalid')
      }
      if (has(entry, 3) && !validHints(get(entry, 3))) {
        fail(`${path}.hints`, 'invalid')
      }
      if (valueError(kind, get(entry, 0)) !== null) {
        fail(`${path}.default`, 'invalid')
      }
    }
  }

  // The site's settings: groups down to properties.
  const site = get(schema, 'site') ?? []
  if (!isArray(site) || (!isEmpty(site) && isList(site))) {
    fail('site', 'invalid')
  } else {
    checkTree(site, 'site', fail)
  }

  // What follows Identity: a property of the components, of that kind (ThemeOverall).
  let follows = get(schema, 'follows') ?? []
  if (!isArray(follows)) {
    fail('follows', 'invalid')
    follows = []
  }
  for (const [path, group] of entries(follows)) {
    const segments = path.split('.')
    const spec = segments[0] === 'components' ? propertySpec(schema, segments.slice(1)) : null
    if (!among(OVERALL_GROUPS, group) || spec === null || spec[0] !== group || segments.includes('states')) {
      fail(`follows.${path}`, 'invalid')
    }
  }

  // How derived colors derive: from colors of the schema, by an operation it knows.
  let derive = get(schema, 'derive') ?? []
  if (!isArray(derive)) {
    fail('derive', 'invalid')
    derive = []
  }
  const colors = get(tokens, 'color')
  const named = (value: Json): boolean => {
    const parts = alias(value)
    return parts !== null && parts[0] === 'color' && has(colors, parts[1])
  }
  for (const [color, rule] of entries(derive)) {
    const op = get(rule, 'op')
    const amount = get(rule, 'amount')
    if (!has(colors, color) || !isArray(rule) || !among(['same', 'shade', 'mix', 'legible'], op)
      || !named(get(rule, 'from'))
      || ((op === 'mix' || op === 'legible') && !named(get(rule, 'over')))
      || ((op === 'shade' || op === 'mix') && !(typeof amount === 'number' && Math.abs(amount) <= 1))) {
      fail(`derive.${color}`, 'invalid')
    }
  }

  // How components' values derive: a value of the schema, from values of the same variant.
  const componentDerive = get(schema, 'component_derive')
  for (const [component, rules] of entries(isArray(componentDerive) ? componentDerive : [])) {
    for (const [path, rule] of entries(isArray(rules) ? rules : [])) {
      const segments = path.split('.')
      const spec = get(components, component)
      const variant = { ...asMap(get(spec, 'base')), ...asMap(get(get(spec, 'variants'), segments[1] ?? '')) }
      const known = (name: Json): boolean => typeof name === 'string' && has(variant, name)
      const valid = (): boolean => {
        switch (get(rule, 'op')) {
          case 'step': return known(get(rule, 'from')) && isInt(get(rule, 'offset'))
          case 'shade': return known(get(rule, 'from')) && typeof get(rule, 'amount') === 'number'
          case 'inset': return known(get(rule, 'from')) && known(get(rule, 'by'))
          default: return false
        }
      }
      if (!isArray(rule) || segments[0] !== 'variants' || propertySpec(schema, [component, ...segments]) === null || !valid()) {
        fail(`component_derive.${component}.${path}`, 'invalid')
      }
    }
  }
  const matrices = get(schema, 'matrix')
  for (const [component, matrix] of entries(isArray(matrices) ? matrices : [])) {
    const spec = get(components, component)
    const into = get(matrix, 'into')
    if (!isArray(spec) || !isArray(get(spec, 'colors')) || !isArray(get(spec, 'appearances')) || !isArray(get(matrix, 'roles'))
      || typeof into !== 'string' || has(spec, into)) {
      fail(`matrix.${component}`, 'invalid')
    }
  }

  errors.push(...checkMigrations(schema))
  errors.push(...checkPages(schema))

  // Two controls on one version 1 key would fight: whichever is projected
  // last wins and the other does nothing.
  const v1Paths = new Set<string>()
  for (const [v2Path, v1Path] of entries(get(schema, 'v1'))) {
    const list = isArray(v1Path) && isList(v1Path) ? values(v1Path) : [v1Path]
    if (list.length === 0 || !list.every((path) => typeof path === 'string') || kindAt(schema, v2Path) === null) {
      fail(`v1.${v2Path}`, 'invalid')
      continue
    }
    const paths = list as string[]
    if (paths.some((path) => v1Paths.has(path)) || uniqueCount(paths) !== paths.length) {
      fail(`v1.${v2Path}`, 'duplicate')
    }
    paths.forEach((path) => v1Paths.add(path))
  }

  return errors
}

/**
 * What a schema's migrations get wrong (ThemeMigrations::check()): each
 * needs a `from` the schema no longer has and a `to` it has, of the same
 * section. Paths go through kindAt(), which knows the tokens, the
 * components and the site.
 */
export function checkMigrations(schema: TupleSchema): SchemaError[] {
  const migrations = get(schema, 'migrate') ?? []
  if (!isArray(migrations) || !isList(migrations)) {
    return [{ path: 'migrate', code: 'migrate.invalid' }]
  }
  const errors: SchemaError[] = []
  for (const [index, migration] of entries(migrations)) {
    const from = isArray(migration) ? get(migration, 'from') : null
    const to = isArray(migration) ? get(migration, 'to') : null
    if (typeof from !== 'string' || typeof to !== 'string' || from === to
      || from.split('.')[0] !== to.split('.')[0]
      || kindAt(schema, from) !== null || kindAt(schema, to) === null) {
      errors.push({ path: `migrate.${index}`, code: `migrate.${index}.invalid` })
    }
  }
  return errors
}

/**
 * A group's scope: each component exists and is not a group, and each
 * property it changes is the component's, of the same kind and options.
 */
function checkScope(schema: TupleSchema, group: string, scope: Json, path: string, fail: Fail): void {
  for (const [scoped, changes] of entries(scope)) {
    const scopedPath = `${path}.${scoped}`
    if (scoped === group || !isArray(changes) || !isComponent(schema, scoped)) {
      fail(scopedPath, 'invalid')
      continue
    }
    for (const [leaf, spec] of Object.entries(leaves(changes))) {
      const own = propertySpec(schema, [scoped, ...leaf.split('.')])
      if (own === null) {
        fail(`${scopedPath}.${leaf}`, 'unknown')
      } else if (!isArray(spec) || !isList(spec) || get(spec, 0) !== own[0] || !identical(get(spec, 2), get(own, 2)) || count(spec) < 2) {
        fail(`${scopedPath}.${leaf}`, 'invalid')
      } else if (valueError(get(spec, 0) as string, get(spec, 1), get(spec, 2), get(own, 3)) !== null) {
        fail(`${scopedPath}.${leaf}.default`, 'invalid')
      }
    }
  }
}

/**
 * A group's slots: each names a component that is not a group, and
 * chooses only among what that component has, or null.
 */
function checkSlots(schema: TupleSchema, slots: Json, path: string, fail: Fail): void {
  for (const [name, slot] of entries(slots)) {
    const slotPath = `${path}.${name}`
    const named = get(slot, 'component')
    if (!NAME.test(name) || !isArray(slot) || typeof named !== 'string' || !isComponent(schema, named)) {
      fail(slotPath, 'invalid')
      continue
    }
    const component = get(get(schema, 'components'), named)
    for (const [axis, choice] of entries(slot)) {
      if (axis === 'component') {
        continue
      }
      const section = has(SLOT_AXES, axis) ? SLOT_AXES[axis]! : null
      const choices = section === null ? null : get(component, section)
      if (section === null || !isArray(choices)) {
        fail(`${slotPath}.${axis}`, 'unknown')
      } else if (choice !== null && !(typeof choice === 'string' && has(choices, choice))) {
        fail(`${slotPath}.${axis}`, 'invalid')
      }
    }
  }
}

/**
 * A component of the schema that a group may scope or slot: not a group
 * (no `scope`), and its own slots, if any, name components without slots
 * (ThemeSchemas::isComponent()).
 */
export function isComponent(schema: TupleSchema, name: string): boolean {
  const components = get(schema, 'components')
  const spec = get(components, name)
  // isset(): a null value counts as missing.
  if (!isArray(spec) || get(spec, 'scope') !== null) {
    return false
  }
  const slots = get(spec, 'slots')
  for (const slot of values(isArray(slots) ? slots : [])) {
    const nested = get(components, phpString(get(slot, 'component') ?? ''))
    if (!isArray(nested) || get(nested, 'scope') !== null || get(nested, 'slots') !== null) {
      return false
    }
  }
  return true
}

/** Every property spec under a node, by its dotted path (the first of a path wins). */
function leaves(node: Json, prefix = ''): Record<string, Json> {
  const answer: Record<string, Json> = {}
  for (const [key, value] of entries(node)) {
    const path = prefix === '' ? key : `${prefix}.${key}`
    if (isArray(value) && !isList(value) && !isEmpty(value)) {
      for (const [nested, spec] of Object.entries(leaves(value, path))) {
        if (!(nested in answer)) {
          answer[nested] = spec
        }
      }
    } else if (!isEmpty(value)) {
      answer[path] = value
    }
  }
  return answer
}

/** A block of groups down to properties (`site`). */
function checkTree(tree: Json, path: string, fail: Fail): void {
  for (const [key, node] of entries(tree)) {
    const at = `${path}.${key}`
    if (!NAME.test(key) || !isArray(node) || isEmpty(node)) {
      fail(at, 'invalid')
    } else if (isList(node)) {
      checkProps({ [key]: node }, path, fail)
    } else {
      checkTree(node, at, fail)
    }
  }
}

function checkProps(props: Json, path: string, fail: Fail): void {
  for (const [prop, spec] of entries(props)) {
    const propPath = `${path}.${prop}`
    const kind = isArray(spec) ? get(spec, 0) : null
    if (!NAME.test(prop) || !isArray(spec) || !isList(spec) || count(spec) < 2 || count(spec) > 4
      || typeof kind !== 'string' || !has(PROPERTY_KINDS, kind)) {
      fail(propPath, 'invalid')
      continue
    }
    if (has(spec, 3) && !validRules(kind, get(spec, 3))) {
      fail(`${propPath}.rules`, 'invalid')
      continue
    }

    const options = get(spec, 2)
    const levelGroup = PROPERTY_KINDS[kind]
    const levels = among(LEVEL_OPTION_KINDS, kind)
      && (isArray(options)
        ? !isEmpty(options) && isList(options) && values(options).every((option) => tokenNames(levelGroup ?? '').includes(phpString(option)))
        : options === null)
    if (!levels && among(OPTION_KINDS, kind) !== isArray(options)) {
      fail(`${propPath}.options`, 'invalid')
      continue
    }
    // A color without a default of its own.
    const unset = get(spec, 1) === null && (kind === 'color' || kind === 'role')
    if (!unset && valueError(kind, get(spec, 1), options, get(spec, 3)) !== null) {
      fail(`${propPath}.default`, 'invalid')
    }
  }
}

/** A map of an array's pairs, for PHP's array_merge() of two maps. */
function asMap(value: Json): JsonMap {
  const answer: JsonMap = {}
  for (const [key, item] of entries(isArray(value) ? value : [])) {
    answer[key] = item
  }
  return answer
}
