import { kindOf } from './format.js'
import { clone, entries, get, has, isArray, isMap, type Json, type JsonMap } from './php.js'
import type { NodeSchema, SchemaError, TupleSchema } from './types.js'

/** What the engine reads from `$extensions`. */
export const EXTENSION = 'co.ordering'

/** The `$schema` of a schema in the node format. */
export const NODE_FORMAT = 'urn:ordering:theme-schema:1'

type Fail = (path: string, rule: string) => void

/**
 * A schema in nodes as the tuples api-core's engine reads (NodeCompiler).
 *
 * It answers what the engine reads: no version 1 paths (api-core keeps them
 * apart, for ThemeV1Bridge), and it does not check that a token declares
 * the type the validator expects. Like NodeCompiler, it refuses only a node
 * it cannot turn into a tuple; the meta-schema and ThemeSchemas::check()
 * are api-core's, which registers only a valid schema.
 */
export function compile(nodes: NodeSchema): { schema: TupleSchema; errors: SchemaError[] } {
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

  tuples.tokens = tokens(isArray(schema.tokens) ? schema.tokens : {}, fail)

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

  // The version 1 path of each key stays api-core's: null here.
  const visibility: JsonMap = {}
  for (const [key, node] of entries(children(isArray(schema.visibility) ? schema.visibility : {}))) {
    visibility[key] = [get(node, '$value'), null]
  }
  tuples.visibility = visibility

  const images: JsonMap = {}
  for (const [key, node] of entries(children(isArray(schema.images) ? schema.images : {}))) {
    const own = isArray(node) ? dig(node, '$extensions', EXTENSION) : {}
    const entry: Json[] = [get(node, '$value'), null]
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
      spec[field] = tuple(node, `pages.${page}.${field}`, fail)
    }
    pages[page] = spec
  }
  tuples.pages = pages

  if (has(schema, 'platforms')) {
    tuples.platforms = schema.platforms!
  }
  if (isArray(schema.site)) {
    tuples.site = tree(children(schema.site), 'site', fail)
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

function tokens(groups: Json, fail: Fail): JsonMap {
  const answer: JsonMap = {}
  for (const [group, value] of entries(children(groups))) {
    const node = isArray(value) ? value : {}
    const names: JsonMap = {}
    for (const [name, token] of entries(children(node))) {
      if (!isNode(token)) {
        fail(`tokens.${group}.${name}`, 'invalid')
        continue
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
 * A node as its tuple `[kind, default, options?]`. A node without a type
 * of its own is answered as its value (api-core's check refuses it).
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

  const answer: Json[] = [kind, node.$value!]
  if (has(own, 'options')) {
    answer.push(get(own, 'options'))
  }
  return answer
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
      // PHP's isset(): a null `$type` is none.
      answer[key] = get(child, '$type') === null && type !== null ? { $type: type, ...child } : child
    } else {
      answer[key] = inheritTypes(child, type)
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
