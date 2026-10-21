import { FORMER_TYPES, NARROWABLE, kindOf, tokenType } from './format.js'
import { asMap, clone, entries, get, has, identical, isArray, isList, isMap, phpString, type Json, type JsonMap } from './php.js'
import { kindAt } from './schemas.js'
import type { NodeSchema, SchemaError, TupleSchema, V1Map } from './types.js'

/** What the engine reads from `$extensions`. */
export const EXTENSION = 'co.ordering'

/** The `$schema` of a schema in the node format. */
export const NODE_FORMAT = 'urn:ordering:theme-schema:1'

/** What only the editor reads. */
export const EDITOR = 'co.ordering.editor'

/** At the root, the renames and moves of a theme's values, `[{ from, to }]`. */
export const MIGRATE = 'co.ordering.migrate'

/**
 * At the root of a base's first version, how a theme of the previous base
 * takes its values, `{ from, moves, drop, rewrite? }` (upgrade.ts).
 */
export const UPGRADE = 'co.ordering.upgrade'

type Fail = (path: string, rule: string) => void

/**
 * A schema in nodes as the tuples api-core's engine reads (NodeCompiler),
 * with the version 1 paths of $v1Map (none by default: api-core keeps them
 * apart, for ThemeV1Bridge; validate() checks them).
 *
 * Like NodeCompiler, it refuses only a node it cannot turn into a tuple and
 * a token that does not declare the type or options the validator reads it
 * with; check() validates the rest, and the meta-schema is the consumer's
 * (meta/theme-schema-1.json).
 */
export function compile(nodes: NodeSchema, v1Map: V1Map = {}): { schema: TupleSchema; errors: SchemaError[] } {
  const schema = clone(nodes as unknown as Json) as JsonMap
  const errors: SchemaError[] = []
  const fail: Fail = (path, rule) => {
    errors.push({ path, code: `${path}.${rule}` })
  }
  const follows: JsonMap = {}
  // A group's `$type` is its nodes' unless they declare their own.
  for (const block of ['components', 'pages', 'site']) {
    const tree = schema[block]
    if (isArray(tree)) {
      schema[block] = inheritTypes(tree, null)
    }
  }

  const tuples: JsonMap = {}
  for (const key of ['product_type', 'version', 'format']) {
    if (has(schema, key)) {
      tuples[key] = schema[key]!
    }
  }

  const tokenOptions: JsonMap = {}
  tuples.tokens = tokens(isArray(schema.tokens) ? schema.tokens : {}, fail, tokenOptions)

  const components: JsonMap = {}
  const inherits: JsonMap = {}
  for (const [component, value] of entries(children(isArray(schema.components) ? schema.components : {}))) {
    const node = isArray(value) ? value : {}
    const parent = extension(node, 'inherits')
    if (parent !== null) {
      inherits[component] = parent
    }
    components[component] = componentTuples(children(node), `components.${component}`, fail, follows)
  }
  tuples.components = components
  if (Object.keys(inherits).length > 0) {
    tuples.inherits = inherits
  }

  const visibility: JsonMap = {}
  for (const [key, node] of entries(children(isArray(schema.visibility) ? schema.visibility : {}))) {
    visibility[key] = [get(node, '$value'), dig(v1Map, 'visibility', key)]
    // Its default on a platform that shows it otherwise (`platforms.<platform>.visibility`).
    const own = isArray(node) ? dig(node, '$extensions', EXTENSION) : {}
    if (has(own, 'platforms')) {
      ;(visibility[key] as Json[]).push(get(own, 'platforms'))
    }
  }
  tuples.visibility = visibility

  const images: JsonMap = {}
  for (const [key, node] of entries(children(isArray(schema.images) ? schema.images : {}))) {
    const own = isArray(node) ? dig(node, '$extensions', EXTENSION) : {}
    const entry: Json[] = [get(node, '$value'), dig(v1Map, 'images', key)]
    if (has(own, 'hints')) {
      entry.push(get(own, 'formats'), get(own, 'hints'))
    } else if (has(own, 'formats')) {
      entry.push(get(own, 'formats'))
    }
    images[key] = entry
  }
  tuples.images = images

  const pages: JsonMap = {}
  for (const [page, fields] of entries(children(isArray(schema.pages) ? schema.pages : {}))) {
    const spec: JsonMap = {}
    for (const [field, node] of entries(children(isArray(fields) ? fields : {}))) {
      const answer = tuple(node, `pages.${page}.${field}`, fail)
      const sections = isArray(answer) ? get(answer, 1) : null
      if (field === 'sections' && isArray(sections)) {
        // Each section takes its version 1 path and default from the map.
        for (const [index, section] of entries(sections)) {
          if (!isArray(section)) {
            continue
          }
          const key = get(section, 'key')
          const from = dig(v1Map, 'pages', page, key === null ? '' : phpString(key))
          const own = { ...section } as JsonMap
          for (const [key, value] of entries(from)) {
            if ((key === 'v1' || key === 'v1_default') && !has(own, key)) {
              own[key] = value
            }
          }
          ;(sections as Record<string, Json>)[index] = own
        }
      }
      spec[field] = answer
    }
    pages[page] = spec
  }
  tuples.pages = pages

  if (has(schema, 'platforms')) {
    tuples.platforms = schema.platforms!
  }
  // The colors the editor may make translucent (editor `alpha`), by path:
  // any other color of a theme is opaque (ThemeValidator::translucent()).
  const alpha: string[] = []
  for (const block of ['tokens', 'components']) {
    translucent(isArray(schema[block]) ? schema[block] : {}, block, alpha)
  }
  if (alpha.length > 0) {
    tuples.alpha = alpha
  }
  // The options a schema keeps of a token it narrows (NARROWABLE).
  if (Object.keys(tokenOptions).length > 0) {
    tuples.token_options = tokenOptions
  }
  // The renames and moves a theme's values follow (ThemeMigrations).
  const migrate = dig(schema, '$extensions', MIGRATE)
  if (isArray(migrate)) {
    tuples.migrate = migrate
  }
  // How a theme of the previous base takes its values (upgrade.ts).
  const upgrade = dig(schema, '$extensions', UPGRADE)
  if (isArray(upgrade)) {
    tuples.upgrade = upgrade
  }
  if (isArray(schema.site)) {
    tuples.site = tree(children(schema.site), 'site', fail)
  }
  // The site's settings convert and project through the same `v1` block,
  // under `site.`: each setting the schema has.
  const declared = get(v1Map, 'v1')
  const paths: JsonMap = isArray(declared) ? { ...asMap(declared) } : {}
  for (const [key, path] of entries(get(v1Map, 'site'))) {
    let node: Json = get(tuples, 'site')
    for (const segment of key.split('.')) {
      node = isArray(node) ? get(node, segment) : null
    }
    if (typeof path === 'string' && isArray(node) && isList(node)) {
      paths[`site.${key}`] = path
    }
  }
  // The map outlives what a product removes: only what the schema has.
  // Visibility, images and pages carry theirs in their own entries.
  const v1: JsonMap = {}
  for (const [path, value] of Object.entries(paths)) {
    if (kindAt(tuples, path) !== null && ['tokens', 'components', 'site'].includes(path.split('.')[0]!)) {
      v1[path] = value
    }
  }
  if (Object.keys(v1).length > 0) {
    tuples.v1 = v1
  }
  // A schema that declares nothing follows Identity by the engine's rule (overall.ts follows()).
  if (Object.keys(follows).length > 0) {
    tuples.follows = follows
  }

  // A schema that declares no derivation keeps the engine's (colors.ts rules()).
  const derive: JsonMap = {}
  for (const [color, node] of entries(children(isArray(dig(schema, 'tokens', 'color')) ? dig(schema, 'tokens', 'color') : {}))) {
    const rule = isArray(node) ? extension(node, 'derive') : null
    if (rule !== null) {
      derive[color] = rule
    }
  }
  if (Object.keys(derive).length > 0) {
    tuples.derive = derive
  }

  // Likewise the components' rules (derivations.ts rules()).
  const componentDerive: JsonMap = {}
  const matrix: JsonMap = {}
  for (const [component, node] of entries(children(isArray(schema.components) ? schema.components : {}))) {
    if (!isArray(node)) {
      continue
    }
    const declared = extension(node, 'matrix')
    if (isArray(declared)) {
      matrix[component] = declared
    }
    for (const [path, rule] of Object.entries(derivations(children(asNode(get(node, 'variants'))), 'variants'))) {
      const own = isMap(componentDerive[component]) ? componentDerive[component] as JsonMap : (componentDerive[component] = {})
      own[path] = rule
    }
  }
  if (Object.keys(componentDerive).length > 0 || Object.keys(matrix).length > 0) {
    tuples.component_derive = componentDerive
    tuples.matrix = matrix
  }

  return { schema: tuples, errors }
}

/** Whether a schema is written in nodes rather than in tuples. */
export function isNodes(schema: unknown): schema is NodeSchema {
  return isMap(schema) && schema.$schema === NODE_FORMAT
}

/** Whether a value of the node tree is a node (a leaf) rather than a group. */
export function isNode(value: unknown): value is JsonMap {
  return isArray(value) && has(value, '$value')
}

function tokens(groups: Json, fail: Fail, narrowed: JsonMap): JsonMap {
  const answer: JsonMap = {}
  const colors = Object.keys(children(get(groups, 'color') ?? {}))
  for (const [group, value] of entries(children(groups))) {
    const node = isArray(value) ? value : {}
    const shared = get(node, '$type')
    const names: JsonMap = {}
    for (const [name, token] of entries(children(node))) {
      const path = `tokens.${group}.${name}`
      if (!isNode(token)) {
        fail(path, 'invalid')
        continue
      }
      // The type and options the validator reads the token with.
      const expected = tokenType(group, name, colors)
      const options = extension(token, 'options')
      const type = get(token, '$type') ?? shared
      const catalog = expected === null ? [] : (expected[1] ?? []) as readonly Json[]
      const narrows = expected !== null && (NARROWABLE as readonly string[]).includes(`${group}.${name}`) && !identical(options, expected[1] as Json)
        && isArray(options) && isList(options) && Object.keys(options).length > 0
        && new Set(Object.values(options).map((option) => JSON.stringify(option))).size === Object.keys(options).length
        && Object.values(options).every((option) => catalog.some((known) => identical(known, option)))
      if (expected !== null && ((type !== expected[0] && type !== (FORMER_TYPES[group] ?? null)) || (!identical(options, expected[1] as Json) && !narrows))) {
        fail(path, 'type')
      } else if (narrows) {
        narrowed[`${group}.${name}`] = options
      }
      names[name] = token.$value!
    }
    answer[group] = names
  }
  return answer
}

function componentTuples(parts: JsonMap, path: string, fail: Fail, follows: JsonMap): JsonMap {
  const spec: JsonMap = {}
  for (const [section, value] of Object.entries(parts)) {
    const node = children(isArray(value) ? value : {})
    const sectionPath = `${path}.${section}`
    if (section === 'base') {
      spec[section] = props(node, sectionPath, fail)
      addFollows(follows, node, sectionPath)
    } else if (section === 'platforms') {
      const platforms: JsonMap = {}
      for (const [platform, platformProps] of Object.entries(node)) {
        platforms[platform] = props(children(isArray(platformProps) ? platformProps : {}), `${sectionPath}.${platform}`, fail)
      }
      spec[section] = platforms
    } else if (section === 'slots') {
      const slots: JsonMap = {}
      for (const [name, slot] of Object.entries(node)) {
        slots[name] = isArray(slot) && isNode(slot) ? slot.$value! : slot
      }
      spec[section] = slots
    } else if (section === 'scope') {
      const scope: JsonMap = {}
      for (const [scoped, changes] of Object.entries(node)) {
        scope[scoped] = tree(children(isArray(changes) ? changes : {}), `${sectionPath}.${scoped}`, fail)
      }
      spec[section] = scope
    } else {
      const variants: JsonMap = {}
      for (const [name, variant] of Object.entries(node)) {
        const variantPath = `${sectionPath}.${name}`
        const tuples: JsonMap = {}
        for (const [prop, propNode] of Object.entries(children(isArray(variant) ? variant : {}))) {
          if (prop !== 'states') {
            tuples[prop] = tuple(propNode, `${variantPath}.${prop}`, fail)
            addFollows(follows, { [prop]: propNode }, variantPath)
            continue
          }
          const states: JsonMap = {}
          for (const [state, stateProps] of Object.entries(children(isArray(propNode) ? propNode : {}))) {
            states[state] = props(children(isArray(stateProps) ? stateProps : {}), `${variantPath}.states.${state}`, fail)
          }
          tuples.states = states
        }
        variants[name] = tuples
      }
      spec[section] = variants
    }
  }
  return spec
}

function props(nodes: JsonMap, path: string, fail: Fail): JsonMap {
  const tuples: JsonMap = {}
  for (const [prop, node] of Object.entries(nodes)) {
    tuples[prop] = tuple(node, `${path}.${prop}`, fail)
  }
  return tuples
}

function tree(changes: JsonMap, path: string, fail: Fail): JsonMap {
  const answer: JsonMap = {}
  for (const [key, value] of Object.entries(children(changes))) {
    answer[key] = isNode(value)
      ? tuple(value, `${path}.${key}`, fail)
      : isArray(value) ? tree(children(value), `${path}.${key}`, fail) : value
  }
  return answer
}

/**
 * A node as its tuple `[kind, default, options?, rules?]`, the rules what
 * else its value must be: `custom` (a typography that may hold its own
 * values, from its editor), `max_length` and `pattern` (a string). A node
 * without a type of its own is answered as its value (api-core's check
 * refuses it).
 */
function tuple(node: Json, path: string, fail: Fail): Json {
  if (!isNode(node)) {
    fail(path, 'invalid')
    return node
  }
  if (typeof node.$type !== 'string') {
    return node.$value!
  }

  const own = dig(node, '$extensions', EXTENSION)
  const kind = kindOf(node.$type, get(own, 'scale'))
  if (kind === null) {
    fail(path, 'type')
    return node.$value!
  }

  const rules: JsonMap = {}
  if (dig(node, '$extensions', EDITOR, 'custom') === true) {
    rules.custom = true
  }
  for (const key of ['max_length', 'pattern']) {
    const rule = get(own, key)
    if (rule !== null) {
      rules[key] = rule
    }
  }
  const answer: Json[] = [kind, node.$value!]
  const ruled = Object.keys(rules).length > 0
  if (has(own, 'options') || ruled) {
    answer.push(get(own, 'options'))
  }
  if (ruled) {
    answer.push(rules)
  }
  return answer
}

/** The paths of the nodes under $tree whose editor marks them `alpha`, added to $paths. */
function translucent(tree: Json, path: string, paths: string[]): void {
  for (const [key, child] of Object.entries(children(tree))) {
    if (!isArray(child)) {
      continue
    }
    if (isNode(child)) {
      if (dig(child, '$extensions', EDITOR, 'alpha') === true) {
        paths.push(`${path}.${key}`)
      }
    } else {
      translucent(child, `${path}.${key}`, paths)
    }
  }
}

/** $tree with each node that has no `$type` of its own taking its group's. */
function inheritTypes(tree: Json, inherited: string | null): Json {
  if (!isArray(tree)) {
    return tree
  }
  const own = get(tree, '$type')
  const type = typeof own === 'string' ? own : inherited
  const answer: JsonMap = {}
  for (const [key, child] of entries(tree)) {
    if (key.startsWith('$') || !isArray(child)) {
      answer[key] = child
    } else if (isNode(child)) {
      // PHP's isset(): a null `$type` is none, and `+` keeps the group's.
      answer[key] = get(child, '$type') === null && type !== null ? withType(child, type) : child
    } else {
      answer[key] = inheritTypes(child, type)
    }
  }
  return answer
}

/** PHP's `['$type' => $type] + $node`: the type first, the node's own `$type` (null) dropped. */
function withType(node: JsonMap, type: string): JsonMap {
  const answer: JsonMap = { $type: type }
  for (const [key, value] of Object.entries(node)) {
    if (key !== '$type') {
      answer[key] = value
    }
  }
  return answer
}

/** The `derive` of the nodes under a group, by their path from it. */
function derivations(group: JsonMap, path: string): Record<string, Json> {
  const rules: Record<string, Json> = {}
  for (const [key, node] of Object.entries(group)) {
    if (!isArray(node)) {
      continue
    }
    if (isNode(node)) {
      const rule = extension(node, 'derive')
      if (isArray(rule)) {
        rules[`${path}.${key}`] = rule
      }
    } else {
      for (const [nested, rule] of Object.entries(derivations(children(node), `${path}.${key}`))) {
        if (!(nested in rules)) {
          rules[nested] = rule
        }
      }
    }
  }
  return rules
}

/** Which of a block's nodes follow Identity, by path (first one wins, as PHP's `+=`). */
function addFollows(follows: JsonMap, nodes: JsonMap, path: string): void {
  for (const [prop, node] of Object.entries(nodes)) {
    const group = isArray(node) ? extension(node, 'follows') : null
    const at = `${path}.${prop}`
    if (group !== null && !has(follows, at)) {
      follows[at] = group
    }
  }
}

/** What a group holds: everything but its `$` keys. */
function children(node: Json): JsonMap {
  const answer: JsonMap = {}
  for (const [key, value] of entries(node)) {
    if (!key.startsWith('$')) {
      answer[key] = value
    }
  }
  return answer
}

function asNode(value: Json): Json {
  return isArray(value) ? value : {}
}

function extension(node: Json, key: string): Json {
  return get(dig(node, '$extensions', EXTENSION), key)
}

function dig(node: Json, ...keys: string[]): Json {
  let value: Json = node
  for (const key of keys) {
    value = get(value, key)
  }
  return value
}
