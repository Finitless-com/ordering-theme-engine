import { EDITOR, EXTENSION, MIGRATE } from './compile.js'
import { dig, entries, get, has, identical, isArray, isList, isPhpEmpty, isScalar, phpString, values, type Json, type JsonMap } from './php.js'
import type { Changes, NodeSchema, SchemaError } from './types.js'

/**
 * What a new version of a theme schema changes from the last of its base,
 * and whether it may (api-core's SchemaDiff). Both are in nodes.
 *
 * - A patch changes no key, type, option, default or rule: only texts and
 *   editor metadata.
 * - A minor may add keys and options and change defaults. A key it drops
 *   must have been `$deprecated` in the last version, or moved by a
 *   migration (of the key, or of the page that holds it); a key keeps
 *   its type, its scale and every option it had; and the migrations of
 *   the base are never dropped.
 * - A new base is not compared.
 */

const BLOCKS = ['tokens', 'components', 'visibility', 'images', 'pages', 'site']

export interface Leaf {
  type: string | null
  scale: Json
  options: Json[]
  deprecated: boolean
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
  }

  const [oldBase, oldMinor] = parts(phpString(get(previous, 'version') ?? ''))
  const [newBase, newMinor] = parts(phpString(get(next, 'version') ?? ''))
  if (oldBase !== newBase) {
    return { errors: [], changes }
  }

  for (const [path, leaf] of Object.entries(before)) {
    const now = after[path]
    if (now === undefined) {
      if (isMoved(path, moved)) {
        changes.migrated.push(path)
      } else {
        changes.removed.push(path)
        if (!leaf.deprecated) {
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
    const kept = new Set(now.options.map(phpString))
    if (leaf.options.some((option) => !kept.has(phpString(option)))) {
      errors.push({ path, code: `${path}.option_removed` })
    }
  }

  for (const migration of migrationsOf(previous)) {
    if (!migrations.some((other) => identical(other as unknown as Json, migration as unknown as Json))) {
      errors.push({ path: 'migrate', code: 'schema.migration_removed' })
      break
    }
  }

  if (oldMinor === newMinor && valuesOf(previous) !== valuesOf(next)) {
    errors.push({ path: 'version', code: 'schema.patch_changes_values' })
  }

  return { errors, changes }
}

/**
 * Whether a migration moves a leaf (SchemaDiff::isMoved()): the leaf
 * itself, or the page that holds it (`pages.<page>` moves its footer and
 * its sections). A key of visibility or images holds dots of its own: only
 * itself. isset(): a migration to null moves nothing.
 */
function isMoved(path: string, moved: Map<string, Json>): boolean {
  const segments = path.split('.')
  return (moved.get(path) ?? null) !== null
    || (segments[0] === 'pages' && segments.length > 2 && (moved.get(`pages.${segments[1]}`) ?? null) !== null)
}

/**
 * Each value of a schema by the path a theme stores it at, with its type
 * (its own or its closest group's), its own scale, its options and whether
 * it or a group above it is deprecated.
 */
export function leaves(schema: NodeSchema): Record<string, Leaf> {
  const answer: Record<string, Leaf> = {}
  const walk = (group: Json, path: string, inherited: string | null, deprecatedAbove: boolean): void => {
    const own = get(group, '$type')
    const type = typeof own === 'string' ? own : inherited
    const deprecated = deprecatedAbove || !isPhpEmpty(get(group, '$deprecated'))
    if (has(group, '$value')) {
      const options = dig(group, '$extensions', EXTENSION, 'options') ?? []
      answer[path] = {
        type,
        scale: dig(group, '$extensions', EXTENSION, 'scale'),
        options: isArray(options)
          ? values(options).map((option) => isArray(option) ? get(option, 'value') : option).filter(isScalar)
          : [],
        deprecated,
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
 * version, its texts and what only the editor reads, each map's keys
 * sorted as strings.
 */
function valuesOf(schema: NodeSchema): string {
  const root: JsonMap = {}
  for (const [key, value] of entries(schema)) {
    if (key !== 'version' && key !== 'editor' && key !== '$schema') {
      root[key] = value
    }
  }
  return encode(root)
}

function encode(value: Json): string {
  if (!isArray(value)) {
    return JSON.stringify(value)
  }
  if (isList(value)) {
    return `[${values(value).map(encode).join(',')}]`
  }

  const pairs: Array<[string, Json]> = []
  for (const [key, child] of entries(value)) {
    if (key === '$description') {
      continue
    }
    if (key === '$extensions' && isArray(child)) {
      const extensions = entries(child).filter(([name]) => name !== EDITOR)
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
    return `[${pairs.map(([, child]) => encode(child)).join(',')}]`
  }
  return `{${pairs.map(([key, child]) => `${JSON.stringify(key)}:${encode(child)}`).join(',')}}`
}
