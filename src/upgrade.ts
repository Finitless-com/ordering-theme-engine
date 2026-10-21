import { UPGRADE, compile, isNodes } from './compile.js'
import { leaves, platformBase, type Leaf } from './diff.js'
import { COLORS, OPTION_KINDS, OWN_GROUPS, PROPERTY_KINDS, ROLES, alias, tokenType } from './format.js'
import { applyMigrations, dropPaths, mapKey, withoutKey } from './migrations.js'
import { clone, count, dig, entries, get, has, identical, isArray, isEmpty, isInt, isList, phpString, toInt, values, type Json, type JsonMap, type PhpArray } from './php.js'
import { kindAt, specAt } from './schemas.js'
import type { NodeSchema, SchemaError, ThemeValues, TupleSchema } from './types.js'
import { validSettingsTuples, validTuples } from './valid.js'
import { valueError } from './validator.js'

/**
 * How a theme of one base takes its values in the next one (26 → 27),
 * api-core's ThemeUpgrades: the first version of a base declares the step
 * from the previous base in `$extensions["co.ordering.upgrade"]`
 * (docs/schema-format.md §15), and a theme saved with an older base is read
 * through each step in turn (26 → 27 → 28), with the last version of every
 * base on the way.
 *
 * A step over a theme's values, in order:
 *
 * 1. what the last version of the previous base moved (its
 *    `co.ordering.migrate`) where it went;
 * 2. a value whose kind (type or scale) changed at the same path is
 *    dropped, unless a move takes it or the rewrite does;
 * 3. the moves, in order, as a migration moves a key: a value goes to `to`
 *    unless the theme sets it, `from` goes either way, a platform's override
 *    and a token's modes and aliases follow, and a `map` translates the
 *    value (one it has no entry for is dropped);
 * 4. what `drop` names, wherever a migration would find it;
 * 5. the rewrite, a callback the caller passes (api-core runs the class it
 *    registers under that id);
 * 6. the next version's migrations and valid().
 *
 * A site's settings take the same step by their `site.` paths. The schemas
 * may be in nodes or compiled; the callback receives them compiled.
 */

/**
 * The rewrite a base names (`rewrite.id`): what it answers replaces the
 * values, as they are after `drop`. A site's settings arrive as
 * `{ site: settings }`.
 */
export type Rewrite = (id: string, values: JsonMap, previous: TupleSchema, next: TupleSchema) => JsonMap

type Schema = NodeSchema | TupleSchema

/** The sections of a component whose entries may set a property of its base. */
const SECTIONS = ['variants', 'sizes', 'shapes', 'colors', 'appearances']

/** Each node schema compiled once. */
const compiled = new WeakMap<object, TupleSchema>()

function tuplesOf(schema: Schema): TupleSchema {
  if (!isNodes(schema)) {
    return schema as TupleSchema
  }
  let answer = compiled.get(schema)
  if (answer === undefined) {
    answer = compile(schema).schema
    compiled.set(schema, answer)
  }
  return answer
}

/**
 * Whether a compiled schema's `upgrade` has the shape the engine reads
 * (ThemeUpgrades::isBlock()): an integer `from` and the lists `moves` and
 * `drop`.
 */
export function isUpgradeBlock(block: Json): boolean {
  return isArray(block) && isInt(get(block, 'from'))
    && isArray(get(block, 'moves')) && isList(get(block, 'moves'))
    && isArray(get(block, 'drop')) && isList(get(block, 'drop'))
}

/**
 * What a schema's upgrade block gets wrong against the last version of the
 * previous base (null when the product type has none), both in nodes
 * (ThemeUpgrades::check()): `upgrade.required`, `upgrade.from.invalid` (then
 * nothing else), `upgrade.moves.<n>.from.unknown`, `.to.unknown`, `.kind`,
 * `.map.invalid`, `.duplicate`, `upgrade.drop.<n>.unknown` and
 * `upgrade.<path>.unaccounted` (a platform's default of a component's
 * property goes with the property). Whether api-core has the rewrite is
 * api-core's to check.
 */
export function checkUpgrade(next: NodeSchema, previous: NodeSchema | null): SchemaError[] {
  const block = dig(next, '$extensions', UPGRADE)
  if (previous === null) {
    return block === null ? [] : [error('upgrade.from', 'invalid')]
  }
  if (block === null) {
    return [error('upgrade', 'required')]
  }
  const from = isArray(block) ? get(block, 'from') : null
  const base = toInt(phpString(get(next, 'version') ?? '').split('.')[0])
  if (!isInt(from) || from >= base || from !== toInt(phpString(get(previous, 'version') ?? '').split('.')[0])) {
    return [error('upgrade.from', 'invalid')]
  }

  const before = compile(previous).schema
  const after = compile(next).schema
  const moves = isArray(get(block, 'moves')) ? entries(get(block, 'moves')) : []
  const drop = isArray(get(block, 'drop')) ? entries(get(block, 'drop')) : []
  const errors: SchemaError[] = []

  const seen = new Set<string>()
  for (const [index, move] of moves) {
    const at = `upgrade.moves.${index}`
    const source = isArray(move) && typeof get(move, 'from') === 'string' ? get(move, 'from') as string : null
    const destination = isArray(move) && typeof get(move, 'to') === 'string' ? follow(get(after, 'migrate'), get(move, 'to') as string) : null
    const map = isArray(move) ? get(move, 'map') : null
    const sourceKind = source === null ? null : kindAt(before, source)
    const destinationKind = destination === null ? null : kindAt(after, destination)
    if (sourceKind === null) {
      errors.push(error(`${at}.from`, 'unknown'))
    }
    if (destinationKind === null) {
      errors.push(error(`${at}.to`, 'unknown'))
    }
    if (sourceKind !== null && destinationKind !== null) {
      if (source!.split('.')[0] !== destination!.split('.')[0] || (sourceKind !== destinationKind && map === null)) {
        errors.push(error(at, 'kind'))
      }
      if (map !== null && !validMap(map, after, destination!, sourceKind)) {
        errors.push(error(`${at}.map`, 'invalid'))
      }
    }
    if (source !== null && seen.has(source)) {
      errors.push(error(at, 'duplicate'))
    }
    if (source !== null) {
      seen.add(source)
    }
  }

  const previousLeaves = leaves(previous)
  for (const [index, path] of drop) {
    if (typeof path !== 'string' || (kindAt(before, path) === null && !holds(Object.keys(previousLeaves), path))) {
      errors.push(error(`upgrade.drop.${index}`, 'unknown'))
    }
  }

  const rewritten = dig(block, 'rewrite', 'paths')
  const taken = [
    ...moves.map(([, move]) => (isArray(move) ? get(move, 'from') : null)),
    ...drop.map(([, path]) => path),
    ...values(isArray(rewritten) ? rewritten : []),
  ].filter((path): path is string => typeof path === 'string')
  const arrived = leaves(next)
  for (const [path, leaf] of Object.entries(previousLeaves)) {
    // A platform's default of a property is no value a theme stores: the
    // property accounts for it, and the theme's override of it.
    const property = platformBase(path)
    if ((property !== null && has(previousLeaves, property)) || arrives(path, leaf, before, after, arrived) || coveredBy(path, taken)) {
      continue
    }
    errors.push(error(`upgrade.${path}`, 'unaccounted'))
  }
  return errors
}

/**
 * A theme's values of the previous base in the next one (one step,
 * ThemeUpgrades::apply()). $rewrite runs the block's `rewrite` when it
 * declares one; without it the rewrite is skipped, and what its paths hold
 * is read as any other value. The values are not changed.
 */
export function upgrade(values: ThemeValues | JsonMap, next: Schema, previous: Schema, rewrite?: Rewrite): JsonMap {
  const after = tuplesOf(next)
  return validTuples(step(theme(values), after, tuplesOf(previous), rewrite, false), after)
}

/**
 * A theme's values through every step (ThemeUpgrades::chain()): $schemas
 * from the last version of the theme's base to the version to read it with,
 * the last of each base on the way between them. With one schema, valid().
 */
export function upgradeChain(values: ThemeValues | JsonMap, schemas: Schema[], rewrite?: Rewrite): JsonMap {
  if (schemas.length === 1) {
    return validTuples(theme(values), tuplesOf(schemas[0]!))
  }
  let answer = theme(values)
  for (let index = 1; index < schemas.length; index++) {
    answer = upgrade(answer, schemas[index]!, schemas[index - 1]!, rewrite)
  }
  return answer
}

/**
 * A site's settings of the previous base in the next one
 * (ThemeUpgrades::settings()): the same step by their `site.` paths, ending
 * in what of them fits the next version. $rewrite receives them as
 * `{ site: settings }`, and answers the same.
 */
export function upgradeSettings(settings: JsonMap, next: Schema, previous: Schema, rewrite?: Rewrite): JsonMap {
  const after = tuplesOf(next)
  const document = step({ site: theme(settings) }, after, tuplesOf(previous), rewrite, true)
  return validSettingsTuples(get(document, 'site'), after)
}

/** A site's settings through every step, as upgradeChain() a theme. */
export function upgradeSettingsChain(settings: JsonMap, schemas: Schema[], rewrite?: Rewrite): JsonMap {
  if (schemas.length === 1) {
    return validSettingsTuples(settings, tuplesOf(schemas[0]!))
  }
  let answer = theme(settings)
  for (let index = 1; index < schemas.length; index++) {
    answer = upgradeSettings(answer, schemas[index]!, schemas[index - 1]!, rewrite)
  }
  return answer
}

/**
 * A theme's values as a client of an older version reads them
 * (ThemeUpgrades::cutDown()): $schemas from the version the values are of
 * (any version of its base: they are first read with it, valid()) down to
 * the client's, with the last version of each base on the way between them
 * (28.L, 27.L, 26.L, 26.x). Each step undoes what the newer version renamed
 * or moved (down()), so a value the client's version has under another name
 * keeps it; what has the same path and kind in both survives as it is;
 * anything else, and what a newer version added, takes the client's
 * default. With one schema, valid(). The values are not changed.
 */
export function cutDown(values: ThemeValues | JsonMap, schemas: Schema[]): JsonMap {
  const tuples = schemas.map(tuplesOf)
  let answer = validTuples(values, tuples[0]!)
  for (let index = 1; index < tuples.length; index++) {
    answer = down(answer, tuples[index - 1]!, tuples[index]!, false)
  }
  return tuples.length === 1 ? answer : validTuples(answer, tuples[tuples.length - 1]!)
}

/** A site's settings as a client of an older version reads them, as cutDown() a theme (ThemeUpgrades::cutDownSettings()). */
export function cutDownSettings(settings: JsonMap, schemas: Schema[]): JsonMap {
  const tuples = schemas.map(tuplesOf)
  let answer = validSettingsTuples(settings, tuples[0]!)
  for (let index = 1; index < tuples.length; index++) {
    const document = down({ site: answer }, tuples[index - 1]!, tuples[index]!, true)
    answer = (isArray(get(document, 'site')) ? get(document, 'site') : {}) as JsonMap
  }
  return tuples.length === 1 ? answer : validSettingsTuples(answer, tuples[tuples.length - 1]!)
}

/**
 * One step down, from a version ($newer) to an older one ($older), over a
 * theme's values or `{ site: settings }` (ThemeUpgrades::down()): what
 * $newer moved goes back where $older keeps it, each move undone in reverse
 * order. Within a base, the migrations $newer has and $older has not; from
 * the next base, all of $newer's migrations, then its upgrade block's moves
 * when its `from` is $older's base. A move is undone only when $older has no
 * key at its `to` (a rename, not a merge into a key it already had), or
 * when it stays at its own path; a `map` is undone when it is one to one,
 * otherwise the value is dropped. Then a value whose kind is another in
 * $older at the same path is dropped, unless an undone move put it there.
 */
function down(document: JsonMap, newer: TupleSchema, older: TupleSchema, site: boolean): JsonMap {
  const base = (schema: TupleSchema): number => toInt(phpString(get(schema, 'version') ?? '').split('.')[0])
  let migrations = within(get(newer, 'migrate'), site)
  let moves: JsonMap[] = []
  const block = get(newer, 'upgrade')
  if (base(newer) === base(older)) {
    const kept = within(get(older, 'migrate'), site)
    migrations = migrations.filter((migration) => !kept.some((other) => identical(other, migration)))
  } else if (isUpgradeBlock(block) && get(block, 'from') === base(older)) {
    moves = within(get(block, 'moves'), site)
  }
  // A key the next base's own move filled is one it had at the start of that base.
  const filled = moves.map((move) => get(move, 'to'))
  const undoable = (move: JsonMap, taken: Json[]): boolean => {
    const to = get(move, 'to')
    return typeof to === 'string' && (to === get(move, 'from') || (kindAt(older, to) === null && !taken.includes(to)))
  }
  const undo = [
    ...migrations.filter((migration) => undoable(migration, filled)).reverse().map(inverse),
    ...moves.filter((move) => undoable(move, [])).reverse().map(inverse),
  ]
  const answer = applyMigrations(document, undo)
  return changed(answer, newer, older, moves.map((move) => get(move, 'from')).filter((path): path is string => typeof path === 'string'))
}

/**
 * A move the other way (ThemeUpgrades::inverse()): from its `to` to its
 * `from`, with its map inverted when it is one to one (each value once, as a
 * key a map can have); otherwise an empty map, which drops what it would
 * move.
 */
function inverse(move: JsonMap): JsonMap {
  const answer: JsonMap = { from: get(move, 'to'), to: get(move, 'from') }
  const map = get(move, 'map')
  if (!isArray(map)) {
    return answer
  }
  let inverted: JsonMap = {}
  for (const [old, value] of entries(map)) {
    const key = mapKey(value)
    if (key === null || has(inverted, key)) {
      inverted = {}
      break
    }
    // The key names the stored value: an integer as PHP keys it, a boolean as `true` or `false`.
    inverted[key] = old === 'true' || old === 'false' ? old === 'true' : /^(0|-?[1-9][0-9]*)$/.test(old) && Number.isSafeInteger(Number(old)) ? Number(old) : old
  }
  answer.map = inverted
  return answer
}

/** A copy to change (what is no array, as `{}`). */
function theme(values: unknown): JsonMap {
  const copy = clone(values as Json)
  return (isArray(copy) ? copy : {}) as JsonMap
}

/** Steps 1 to 5 over a theme's values, or over `{ site: settings }`. */
function step(document: JsonMap, next: TupleSchema, previous: TupleSchema, rewrite: Rewrite | undefined, site: boolean): JsonMap {
  const upgradeBlock = get(next, 'upgrade')
  const block = isUpgradeBlock(upgradeBlock) ? upgradeBlock as JsonMap : { moves: [], drop: [] }
  const ours = (paths: Json): string[] => values(paths).filter((path): path is string => typeof path === 'string' && (path.split('.')[0] === 'site') === site)
  let answer = applyMigrations(document, within(get(previous, 'migrate'), site))

  const moves = within(get(block, 'moves'), site)
  const declared = get(block, 'rewrite')
  const rewritten = isArray(declared) && rewrite !== undefined && isArray(get(declared, 'paths')) ? ours(get(declared, 'paths')) : []
  answer = changed(answer, previous, next, [...moves.map((move) => get(move, 'from') as string), ...rewritten])
  answer = applyMigrations(answer, moves)
  answer = dropPaths(answer, ours(get(block, 'drop')))
  if (isArray(declared) && rewrite !== undefined) {
    answer = rewrite(phpString(get(declared, 'id') ?? ''), answer, previous, next)
  }
  return answer
}

/** The moves or migrations of a theme ($site false) or of a site's settings ($site true). */
function within(moves: Json, site: boolean): JsonMap[] {
  return values(moves).filter((move): move is JsonMap => isArray(move) && typeof get(move, 'from') === 'string'
    && ((get(move, 'from') as string).split('.')[0] === 'site') === site) as JsonMap[]
}

/**
 * $document without each value whose kind is another in $after than in
 * $before at the same path, unless $taken covers it (by its path in the
 * schema: a platform's override and a mode's color by their own).
 */
function changed(document: JsonMap, before: TupleSchema, after: TupleSchema, taken: string[]): JsonMap {
  const paths: string[][] = []
  const walk = (node: Json, path: string[]): void => {
    for (const [key, child] of entries(node)) {
      const at = [...path, key]
      const was = storedKind(before, at)
      const is = storedKind(after, at)
      if (was !== null || is !== null) {
        if (was !== null && is !== null && was !== is && !coveredBy(canonical(at), taken)) {
          paths.push(at)
        }
        continue
      }
      if (isArray(child)) {
        walk(child, at)
      }
    }
  }
  walk(document, [])
  let answer: Json = document
  for (const path of paths) {
    answer = forget(answer, path)
  }
  return answer as JsonMap
}

/** The kind a schema gives the value a theme stores at $at (ThemeUpgrades::storedKind()). */
function storedKind(schema: TupleSchema, at: string[]): string | null {
  const length = at.length
  switch (at[0]) {
    case 'tokens':
      return length === 3 ? kindAt(schema, at.join('.')) : null
    case 'modes':
      return length === 4 && at[2] === 'color' ? kindAt(schema, `tokens.color.${at[3]}`) : null
    case 'visibility':
    case 'images':
      return length === 2 ? kindAt(schema, at.join('.')) : null
    case 'site':
      return length >= 2 ? kindAt(schema, at.join('.')) : null
    case 'pages':
      return length === 3 ? kindAt(schema, at.join('.')) : null
    case 'components':
      return componentKind(schema, at.slice(1))
    case 'platforms':
      if (length < 3) {
        return null
      }
      if (at[2] === 'components') {
        return componentKind(schema, at.slice(3))
      }
      return at[2] === 'visibility' || at[2] === 'pages' ? storedKind(schema, at.slice(2)) : null
    default:
      return null
  }
}

/** The kind of a component property a theme stores, by its path under `components`. */
function componentKind(schema: TupleSchema, rest: string[]): string | null {
  const spec = dig(schema, 'components', rest[0] ?? '')
  if (rest.length < 3 || !isArray(spec)) {
    return null
  }
  const section = rest[1]!
  if (section === 'scope') {
    return rest.length >= 4 ? componentKind(schema, rest.slice(2)) : null
  }
  if (section === 'base') {
    return rest.length === 3 ? kind(dig(spec, 'base', rest[2]!)) : null
  }
  const own = dig(spec, section, rest[2]!)
  if (!SECTIONS.includes(section) || !isArray(own)) {
    return null
  }
  // A color may set any of its roles; any other entry, a property of its base.
  const property = (name: string): string | null => kind(get(own, name))
    ?? (section === 'colors' ? ((ROLES as readonly string[]).includes(name) ? 'color' : null) : kind(dig(spec, 'base', name)))
  if (rest.length === 4) {
    return rest[3] === 'states' ? null : property(rest[3]!)
  }
  return rest.length === 6 && rest[3] === 'states' ? kind(dig(own, 'states', rest[4]!, rest[5]!)) ?? property(rest[5]!) : null
}

/** A tuple's kind, or null for anything else. */
function kind(tuple: Json): string | null {
  return isArray(tuple) && isList(tuple) && typeof get(tuple, 0) === 'string' ? get(tuple, 0) as string : null
}

/** The path a schema's paths go by: a platform's override's without the platform, a mode's color's as its token's. */
function canonical(at: string[]): string {
  if (at[0] === 'platforms' && at.length >= 3) {
    return at.slice(2).join('.')
  }
  if (at[0] === 'modes' && at.length === 4 && at[2] === 'color') {
    return `tokens.color.${at[3]}`
  }
  return at.join('.')
}

/** Whether $path is one of $prefixes or under one: a key of visibility or images only is itself. */
function coveredBy(path: string, prefixes: string[]): boolean {
  return prefixes.some((prefix) => path === prefix
    || (!['visibility', 'images'].includes(prefix.split('.')[0]!) && path.startsWith(`${prefix}.`)))
}

/** Whether $prefix is a group of $paths (one of them is under it). */
function holds(paths: string[], prefix: string): boolean {
  return paths.some((path) => path !== prefix && coveredBy(path, [prefix]))
}

/** Where a version's migrations take a path, in order: a migration of a group (a page) takes what is under it too. */
function follow(migrations: Json, path: string): string {
  let answer = path
  for (const migration of values(migrations)) {
    const from = get(migration, 'from')
    const to = get(migration, 'to')
    if (typeof from === 'string' && typeof to === 'string' && coveredBy(answer, [from])) {
      answer = to + answer.slice(from.length)
    }
  }
  return answer
}

/** Whether a key of the previous base arrives at its own path with the same kind (ThemeUpgrades::arrives()). */
function arrives(path: string, leaf: Leaf, before: TupleSchema, after: TupleSchema, arrived: Record<string, Leaf>): boolean {
  const destination = follow(get(after, 'migrate'), path)
  const previousKind = kindAt(before, path)
  if (previousKind !== null) {
    return previousKind === kindAt(after, destination)
  }
  const now = Object.prototype.hasOwnProperty.call(arrived, destination) ? arrived[destination] : undefined
  return now !== undefined && now.type === leaf.type && identical(now.scale, leaf.scale)
}

/**
 * Whether every value of a move's `map` is an option or a level of its
 * destination (ThemeUpgrades::validMap()). A boolean's map (a source of kind
 * `bool`) has only the keys `true` and `false`; one to a boolean only boolean
 * values.
 */
function validMap(map: Json, schema: TupleSchema, destination: string, sourceKind: string | null): boolean {
  if (!isArray(map)) {
    return false
  }
  if (sourceKind === 'bool' && entries(map).some(([key]) => key !== 'true' && key !== 'false')) {
    return false
  }
  if (kindAt(schema, destination) === 'bool') {
    return values(map).every((value) => typeof value === 'boolean')
  }
  const segments = destination.split('.')
  const within = (list: Json, value: Json): boolean => values(list).some((option) => identical(option, value))
  if (segments[0] === 'tokens') {
    const own = dig(schema, 'tokens', 'color')
    const colors = isArray(own) && count(own) > 0 ? entries(own).map(([name]) => name) : COLORS
    const token = segments.length === 3 ? tokenType(segments[1]!, segments[2]!, colors) : null
    const narrowed = dig(schema, 'token_options', `${segments[1]}.${segments[2]}`)
    const options = narrowed ?? (token === null ? null : token[1] as Json)
    return isArray(options) && values(map).every((value) => within(options, value))
  }
  const spec = specAt(schema, destination)
  if (spec === null) {
    return false
  }
  const kindOf = phpString(spec[0])
  const options = spec[2] ?? null
  const rules = spec[3] ?? null
  for (const value of values(map)) {
    if ((OPTION_KINDS as readonly string[]).includes(kindOf)) {
      if (!isArray(options) || !within(options, value)) {
        return false
      }
      continue
    }
    const parts = alias(value)
    if ((PROPERTY_KINDS[kindOf] ?? null) === null || parts === null
      || valueError(kindOf, value, isArray(options) ? options : null, isArray(rules) ? rules : null) !== null
      || ((OWN_GROUPS as readonly string[]).includes(parts[0]) && !has(dig(schema, 'tokens', parts[0]) ?? {}, parts[1]))) {
      return false
    }
  }
  return true
}

/** Drops the value, and each map it leaves empty. */
function forget(tree: Json, path: string[]): Json {
  const [segment, ...rest] = path
  if (!isArray(tree) || segment === undefined) {
    return tree
  }
  if (rest.length === 0) {
    return withoutKey(tree, segment)
  }
  const child = get(tree, segment)
  if (!isArray(child)) {
    return tree
  }
  const left = forget(child, rest) as PhpArray
  if (isEmpty(left)) {
    return withoutKey(tree, segment)
  }
  ;(tree as Record<string, Json>)[segment] = left
  return tree
}

function error(path: string, rule: string): SchemaError {
  return { path, code: `${path}.${rule}` }
}
