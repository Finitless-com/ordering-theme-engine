import { EDITOR, EXTENSION, MIGRATE, UPGRADE, editorLimits } from './compile.js'
import { dig, entries, get, has, identical, isArray, isList, isPhpEmpty, isScalar, phpString, values, type Json, type JsonMap } from './php.js'
import type { Changes, NodeSchema, SchemaError } from './types.js'

/**
 * What a new version of a theme schema changes from the last of its base,
 * and whether it may (api-core's SchemaDiff). Both are in nodes.
 *
 * - A patch changes no key, type, option, default or rule: only texts and
 *   the editor metadata that validates no value. What of the editor's
 *   metadata decides how a value is validated (a color's `alpha`, a
 *   typography's `custom`, the numeric `min` and `max` of a control and of
 *   its `fields`) is a rule like any other.
 * - A minor may add keys, options and sections and change defaults and the
 *   rules a product tunes (a string's `max_length` and `pattern`, the
 *   limits, a section's `hideable` and `group`). A key it drops (a page's
 *   section is one) must have been `$deprecated` in the last version, or
 *   moved by a migration (of the key, or of the page or the list that holds
 *   it; a platform's default goes with its property); a key keeps its type,
 *   its scale, every option it had and the `alpha` and `custom` it allowed;
 *   the migrations of the base are never dropped; and its upgrade block only
 *   grows: it keeps its previous base, its rewrite, each move and each path
 *   it drops.
 * - A new base is not compared.
 */

const BLOCKS = ['tokens', 'components', 'visibility', 'images', 'pages', 'site']

export interface Leaf {
  type: string | null
  scale: Json
  options: Json[]
  deprecated: boolean
  default: Json
  rules: JsonMap
  section?: { hideable: Json; group: Json; position: number }
}

export function diff(previous: NodeSchema, next: NodeSchema): { errors: SchemaError[]; changes: Changes } {
  const errors: SchemaError[] = []
  const before = leaves(previous)
  const after = leaves(next)
  const migrations = migrationsOf(next)
  // array_column($migrations, 'to', 'from'): the last of a `from` wins.
  const moved = new Map<string, Json>()
  for (const migration of migrations) {
    const from = migration.from
    if (typeof from === 'string' || typeof from === 'number') {
      moved.set(phpString(from), migration.to)
    }
  }

  const changes: Changes = {
    added: Object.keys(after).filter((path) => !(path in before)),
    removed: [],
    migrated: [],
    deprecated: Object.entries(after).filter(([path, leaf]) => leaf.deprecated && !(before[path]?.deprecated ?? false)).map(([path]) => path),
    options_added: [],
    defaults_changed: [],
    rules_changed: [],
    sections_changed: [],
  }

  const [oldBase, oldMinor] = parts(phpString(get(previous, 'version') ?? ''))
  const [newBase, newMinor] = parts(phpString(get(next, 'version') ?? ''))
  if (oldBase !== newBase) {
    return { errors: [], changes }
  }

  const among = (list: Json[], others: Json[]): boolean => {
    const kept = new Set(others.map(phpString))
    return list.some((option) => !kept.has(phpString(option)))
  }
  for (const [path, leaf] of Object.entries(before)) {
    const now = after[path]
    if (now === undefined) {
      const property = platformBase(path)
      if (isMoved(path, moved)) {
        changes.migrated.push(path)
      } else {
        changes.removed.push(path)
        // A platform's default goes with its property's `$deprecated`.
        if (!leaf.deprecated && !(property !== null && (before[property]?.deprecated ?? false))) {
          errors.push({ path, code: `${path}.removed` })
        }
      }
      continue
    }
    if (leaf.type !== now.type) {
      errors.push({ path, code: `${path}.type_changed` })
    }
    // A stored value names a level of its scale: another scale reads it elsewhere.
    if (!identical(leaf.scale, now.scale)) {
      errors.push({ path, code: `${path}.scale_changed` })
    }
    if (among(leaf.options, now.options)) {
      errors.push({ path, code: `${path}.option_removed` })
    }
    // A translucent color, or a typography's own style, the key took and no longer takes.
    for (const rule of ['alpha', 'custom']) {
      if (get(leaf.rules, rule) === true && get(now.rules, rule) !== true) {
        errors.push({ path, code: `${path}.${rule}_removed` })
      }
    }
    if (among(now.options, leaf.options)) {
      changes.options_added.push(path)
    }
    if (canonical(leaf.default) !== canonical(now.default)) {
      changes.defaults_changed.push(path)
    }
    if (canonical(leaf.rules) !== canonical(now.rules)) {
      changes.rules_changed.push(path)
    }
    if (leaf.section !== undefined && now.section !== undefined
      && (!identical(leaf.section.hideable, now.section.hideable) || !identical(leaf.section.group, now.section.group))) {
      changes.sections_changed.push(path)
    }
  }
  // A page whose sections, those both versions have, come in another order.
  const orders = sectionOrders(after)
  for (const [list, order] of sectionOrders(before)) {
    const now = (orders.get(list) ?? []).filter((key) => order.includes(key))
    const was = order.filter((key) => now.includes(key))
    if (now.some((key, index) => key !== was[index])) {
      changes.sections_changed.push(list)
    }
  }

  for (const migration of migrationsOf(previous)) {
    if (!migrations.some((other) => identical(other as unknown as Json, migration as unknown as Json))) {
      errors.push({ path: 'migrate', code: 'schema.migration_removed' })
      break
    }
  }

  if (upgradeRemoved(previous, next)) {
    errors.push({ path: 'upgrade', code: 'schema.upgrade_removed' })
  }

  if (oldMinor === newMinor && valuesOf(previous) !== valuesOf(next)) {
    errors.push({ path: 'version', code: 'schema.patch_changes_values' })
  }

  return { errors, changes }
}

/**
 * The path of the property a platform's default
 * (`components.<c>.platforms.<p>.<prop>`) is the default of
 * (`components.<c>.base.<prop>`), or null for any other path
 * (SchemaDiff::platformBase()).
 */
export function platformBase(path: string): string | null {
  const segments = path.split('.')
  return segments.length === 5 && segments[0] === 'components' && segments[2] === 'platforms'
    ? `components.${segments[1]}.base.${segments[4]}`
    : null
}

/**
 * Whether a migration moves a leaf (SchemaDiff::isMoved()): the leaf
 * itself, the page that holds it (`pages.<page>` moves its footer and its
 * sections), the list that holds a section (`pages.<page>.sections`) and,
 * for a platform's default, its property. A key of visibility or images
 * holds dots of its own: only itself. isset(): a migration to null moves
 * nothing.
 */
function isMoved(path: string, moved: Map<string, Json>): boolean {
  const segments = path.split('.')
  const property = platformBase(path)
  const set = (key: string): boolean => (moved.get(key) ?? null) !== null
  return set(path)
    || (segments[0] === 'pages' && segments.length > 2 && set(`pages.${segments[1]}`))
    || (segments[0] === 'pages' && segments.length === 4 && set(`pages.${segments[1]}.sections`))
    || (property !== null && set(property))
}

/** The keys of each page's sections in their default order, by the path of their list. */
function sectionOrders(all: Record<string, Leaf>): Map<string, string[]> {
  const orders = new Map<string, Array<[number, string]>>()
  for (const [path, leaf] of Object.entries(all)) {
    if (leaf.section !== undefined) {
      const at = path.lastIndexOf('.')
      const list = path.slice(0, at)
      orders.set(list, [...(orders.get(list) ?? []), [leaf.section.position, path.slice(at + 1)]])
    }
  }
  return new Map([...orders].map(([list, keys]) => [list, keys.sort(([a], [b]) => a - b).map(([, key]) => key)]))
}

/**
 * Each value of a schema by the path a theme stores it at, with its type
 * (its own or its closest group's), its own scale, its options, whether it
 * or a group above it is deprecated, its default and its rules: `max_length`
 * and `pattern`, and what of its editor validates a value (editorRules()).
 * A page's sections are a leaf each (`pages.<page>.sections.<key>`, of type
 * `section`), whose default is its `visible`, with its `hideable`, its
 * `group` and its place in the list.
 */
export function leaves(schema: NodeSchema): Record<string, Leaf> {
  const answer: Record<string, Leaf> = {}
  const walk = (group: Json, path: string, inherited: string | null, deprecatedAbove: boolean): void => {
    const own = get(group, '$type')
    const type = typeof own === 'string' ? own : inherited
    const deprecated = deprecatedAbove || !isPhpEmpty(get(group, '$deprecated'))
    if (has(group, '$value')) {
      const value = get(group, '$value')
      if (type === 'sections' && isArray(value) && isList(value)) {
        values(value).forEach((section, position) => {
          const key = get(section, 'key')
          if (isArray(section) && typeof key === 'string') {
            answer[`${path}.${key}`] = {
              type: 'section',
              scale: null,
              options: [],
              deprecated,
              default: get(section, 'visible'),
              rules: {},
              section: { hideable: get(section, 'hideable'), group: get(section, 'group'), position },
            }
          }
        })
        return
      }
      const extension = dig(group, '$extensions', EXTENSION)
      const options = get(extension, 'options') ?? []
      const rules: JsonMap = {}
      for (const key of ['max_length', 'pattern']) {
        const rule = get(isArray(extension) ? extension : {}, key)
        if (rule !== null) {
          rules[key] = rule
        }
      }
      answer[path] = {
        type,
        scale: get(isArray(extension) ? extension : {}, 'scale'),
        options: isArray(options)
          ? values(options).map((option) => isArray(option) ? get(option, 'value') : option).filter(isScalar)
          : [],
        deprecated,
        default: value,
        rules: { ...rules, ...editorRules(dig(group, '$extensions', EDITOR)) },
      }
      return
    }
    for (const [key, child] of entries(group)) {
      if (isArray(child) && !key.startsWith('$')) {
        walk(child, `${path}.${key}`, type, deprecated)
      }
    }
  }
  for (const block of BLOCKS) {
    const group = get(schema, block)
    if (isArray(group)) {
      walk(group, block, null, false)
    }
  }
  return answer
}

/**
 * What of a node's or group's editor metadata decides how a value is
 * validated (SchemaDiff::editorRules()): `alpha` and `custom` when they are
 * true, and its numeric limits (editorLimits()).
 */
export function editorRules(editor: Json): JsonMap {
  if (!isArray(editor)) {
    return {}
  }
  const rules: JsonMap = {}
  for (const mark of ['alpha', 'custom']) {
    if (get(editor, mark) === true) {
      rules[mark] = true
    }
  }
  return { ...rules, ...editorLimits(editor) }
}

/** A value as a string two values share only when PHP's json_encode() of them, each map's keys sorted, would (SchemaDiff::canonical()). */
export function canonical(value: Json): string {
  return encode(value, false)
}

/**
 * Whether a version drops anything of its base's upgrade block
 * (SchemaDiff::upgradeRemoved()): the block itself, its previous base, its
 * rewrite, a move or a path it drops (a minor may add moves and paths).
 */
function upgradeRemoved(previous: NodeSchema, next: NodeSchema): boolean {
  const was = dig(previous, '$extensions', UPGRADE)
  if (!isArray(was)) {
    return false
  }
  const now = dig(next, '$extensions', UPGRADE)
  if (!isArray(now) || !identical(get(was, 'from'), get(now, 'from')) || !identical(get(was, 'rewrite'), get(now, 'rewrite'))) {
    return true
  }
  return ['moves', 'drop'].some((list) => {
    const kept = get(now, list)
    return values(isArray(get(was, list)) ? get(was, list) : []).some((item) => !values(isArray(kept) ? kept : []).some((other) => identical(other, item)))
  })
}

interface Migration {
  from: Json
  to: Json
}

function migrationsOf(schema: NodeSchema): Migration[] {
  const migrations = dig(schema, '$extensions', MIGRATE) ?? []
  return isArray(migrations)
    ? values(migrations).filter(isArray).map((migration) => ({ from: get(migration, 'from'), to: get(migration, 'to') }))
    : []
}

/** A version's base and minor: `26.0` is `26.0.0`. */
function parts(version: string): [string, string] {
  const segments = version.split('.')
  return [segments[0]!, segments[1] ?? '0']
}

/**
 * What a schema says that a theme resolves with, as a string two schemas
 * share only when PHP's json_encode() of it would: everything but its
 * version, its texts and the editor metadata that validates no value
 * (editorRules() is what stays of it), each map's keys sorted as strings.
 */
function valuesOf(schema: NodeSchema): string {
  const root: JsonMap = {}
  for (const [key, value] of entries(schema)) {
    if (key !== 'version' && key !== 'editor' && key !== '$schema') {
      root[key] = value
    }
  }
  return encode(root, true)
}

/** $value as PHP's json_encode() of it, each map's keys sorted; $strip leaves out texts and what the editor reads that validates no value. */
function encode(value: Json, strip: boolean): string {
  if (!isArray(value)) {
    return JSON.stringify(value)
  }
  if (isList(value)) {
    return `[${values(value).map((child) => encode(child, strip)).join(',')}]`
  }

  const pairs: Array<[string, Json]> = []
  for (const [key, child] of entries(value)) {
    if (strip && key === '$description') {
      continue
    }
    if (strip && key === '$extensions' && isArray(child)) {
      const extensions: Array<[string, Json]> = []
      for (const [name, extension] of entries(child)) {
        const kept = name === EDITOR ? editorRules(extension) : extension
        if (name !== EDITOR || Object.keys(kept as JsonMap).length > 0) {
          extensions.push([name, kept])
        }
      }
      if (extensions.length > 0) {
        pairs.push([key, Object.fromEntries(extensions) as JsonMap])
      }
      continue
    }
    pairs.push([key, child])
  }
  // A map left with keys 0…n-1 in order is a list, which ksort() does not touch.
  const listed = pairs.every(([key], index) => key === String(index))
  if (!listed) {
    pairs.sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
  }
  // json_encode() writes a list for keys 0…n-1 in order, whatever produced them.
  if (pairs.every(([key], index) => key === String(index))) {
    return `[${pairs.map(([, child]) => encode(child, strip)).join(',')}]`
  }
  return `{${pairs.map(([key, child]) => `${JSON.stringify(key)}:${encode(child, strip)}`).join(',')}}`
}
