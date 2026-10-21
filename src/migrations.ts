import { alias } from './format.js'
import { asMap, entries, get, has, isArray, isEmpty, isList, phpString, values, type Json, type JsonMap, type PhpArray } from './php.js'

/**
 * The renames and moves a schema declares (`$extensions["co.ordering.migrate"]`,
 * `[{ from, to }]`, api-core's ThemeMigrations), so that a theme saved
 * before a key changed place keeps its value. They apply in order: a value
 * at `from` goes to `to` unless the theme already sets `to`, and `from` is
 * dropped either way. Each also applies under every platform the theme
 * overrides (`platforms.<platform>.…`), which sets its components,
 * visibility and pages by the same paths. A token's also rewrites every
 * alias to it (`{color.brand}` becomes `{color.primary}`), and a color's
 * moves its value in each mode (`modes.<mode>.color.…`) too.
 *
 * Paths are a theme's: the keys of `visibility` and `images` hold dots of
 * their own (`visibility.business.header.info`), any other path is a key
 * per segment. A page's section (`pages.<page>.sections.<key>`) is an item
 * of its list: it moves with its `visible`, renamed in its place within
 * the page, or to the end of another page's list.
 *
 * $theme is changed in place: pass a copy.
 */
export function applyMigrations(theme: JsonMap, migrations: Json): JsonMap {
  let answer = theme
  for (const migration of values(migrations)) {
    const from = segments(phpString(get(migration, 'from') ?? ''))
    const to = segments(phpString(get(migration, 'to') ?? ''))
    if (from.length < 2 || to.length < 2) {
      continue
    }
    const roots: string[][] = [[]]
    const platforms = get(answer, 'platforms')
    for (const [platform] of entries(isArray(platforms) ? platforms : [])) {
      roots.push(['platforms', platform])
    }
    for (const root of roots) {
      answer = move(answer, [...root, ...from], [...root, ...to], root.length)
    }
    if (from[0] === 'tokens' && to[0] === 'tokens' && from.length === 3 && to.length === 3) {
      answer = moveToken(answer, from, to)
    }
  }
  return answer
}

/**
 * What a theme holds of a token besides its value, once it moved: its value
 * in each mode (a color's, `modes.<mode>.color.<name>`), moved the same
 * way, and every alias to it, which names the new token.
 */
function moveToken(theme: JsonMap, from: string[], to: string[]): JsonMap {
  let answer = theme
  if (from[1] === 'color' && to[1] === 'color') {
    for (const [mode] of entries(get(answer, 'modes'))) {
      answer = move(answer, ['modes', mode, 'color', from[2]!], ['modes', mode, 'color', to[2]!], 0)
    }
  }
  return realias(answer, from[1]!, from[2]!, `{${to[1]}.${to[2]}}`) as JsonMap
}

/** $node with every alias to the token `{group.name}` as $alias. */
function realias(node: Json, group: string, name: string, replacement: string): Json {
  if (!isArray(node)) {
    const parts = alias(node)
    return parts !== null && parts[0] === group && parts[1] === name ? replacement : node
  }
  for (const [key, value] of entries(node)) {
    ;(node as Record<string, Json>)[key] = realias(value, group, name, replacement)
  }
  return node
}

/** $theme with what is at $from moved to $to, both under the same root of $depth segments. */
function move(theme: JsonMap, from: string[], to: string[], depth: number): JsonMap {
  const section = (path: string[]): boolean => path.length === depth + 4 && path[depth] === 'pages' && path[depth + 2] === 'sections'
  if (section(from) || section(to)) {
    return section(from) && section(to) ? moveSection(theme, from, to) : theme
  }
  if (!hasPath(theme, from)) {
    return theme
  }
  const value = getPath(theme, from)
  let answer = forget(theme, from) as JsonMap
  if (!hasPath(answer, to)) {
    answer = put(answer, to, value) as JsonMap
  }
  return answer
}

/**
 * A section from one page's list to another's (or the same one's): the
 * first item of the key goes, renamed, to the place it had when the list
 * is the same and to the end of the other one otherwise, unless that list
 * has the new key already. Every item of the old key is dropped, and a
 * list left empty with it.
 */
function moveSection(theme: JsonMap, fromPath: string[], toPath: string[]): JsonMap {
  const from = fromPath.slice(0, -1)
  const to = toPath.slice(0, -1)
  const fromKey = fromPath[fromPath.length - 1]!
  const toKey = toPath[toPath.length - 1]!
  const list = hasPath(theme, from) ? getPath(theme, from) : null
  const index = isArray(list) && isList(list) ? find(list, fromKey) : null
  const same = from.length === to.length && from.every((segment, at) => segment === to[at])
  if (index === null || (same && fromKey === toKey)) {
    return theme
  }
  const items = values(list)
  const moved: JsonMap = { key: toKey }
  for (const [field, value] of entries(items[index])) {
    if (field !== 'key') {
      moved[field] = value
    }
  }
  const target = same ? list : hasPath(theme, to) ? getPath(theme, to) : []
  const taken = !isArray(target) || !isList(target) || find(target, toKey) !== null

  const kept: Json[] = []
  items.forEach((item, position) => {
    if (!isArray(item) || get(item, 'key') !== fromKey) {
      kept.push(item)
    } else if (same && position === index && !taken) {
      kept.push(moved)
    }
  })
  let answer = (kept.length === 0 ? forget(theme, from) : put(theme, from, kept)) as JsonMap
  if (!same && !taken) {
    answer = put(answer, to, [...values(target), moved]) as JsonMap
  }
  return answer
}

/** The index of the first item of a section list with that key. */
function find(list: Json, key: string): number | null {
  const index = values(list).findIndex((item) => isArray(item) && get(item, 'key') === key)
  return index < 0 ? null : index
}

function segments(path: string): string[] {
  const parts = path.split('.')
  return (parts[0] === 'visibility' || parts[0] === 'images') && parts.length > 1
    ? [parts[0], parts.slice(1).join('.')]
    : parts
}

function hasPath(tree: Json, path: string[]): boolean {
  let node: Json = tree
  for (const segment of path) {
    if (!isArray(node) || !has(node, segment)) {
      return false
    }
    node = get(node, segment)
  }
  return true
}

function getPath(tree: Json, path: string[]): Json {
  let node: Json = tree
  for (const segment of path) {
    node = get(node, segment)
  }
  return node
}

/**
 * $tree with $value at $path, each missing map on the way created; $tree
 * as it was when a value on the way is not an array.
 */
function put(tree: Json, path: string[], value: Json): Json {
  const [segment, ...rest] = path
  if (segment === undefined) {
    return value
  }
  if (!isArray(tree)) {
    return tree
  }
  const current = has(tree, segment) ? get(tree, segment) : {}
  if (rest.length > 0 && !isArray(current)) {
    return tree
  }
  return withKey(tree, segment, put(current, rest, value))
}

/** $tree without the value at $path, nor each map it leaves empty. */
function forget(tree: PhpArray, path: string[]): PhpArray {
  const [segment, ...rest] = path
  if (rest.length === 0) {
    return withoutKey(tree, segment!)
  }
  const child = forget(get(tree, segment!) as PhpArray, rest)
  return isEmpty(child) ? withoutKey(tree, segment!) : withKey(tree, segment!, child)
}

/** PHP's `$array[$key] = $value`: a list takes the next index and stays one. */
function withKey(array: PhpArray, key: string, value: Json): PhpArray {
  if (Array.isArray(array)) {
    const index = /^(0|[1-9][0-9]*)$/.test(key) ? Number(key) : -1
    if (index >= 0 && index <= array.length) {
      array[index] = value
      return array
    }
  }
  const map = asMap(array)
  map[key] = value
  return map
}

/** PHP's unset($array[$key]): a list without its last index stays one. */
function withoutKey(array: PhpArray, key: string): PhpArray {
  if (!has(array, key)) {
    return array
  }
  if (Array.isArray(array)) {
    if (Number(key) === array.length - 1) {
      array.pop()
      return array
    }
    const map = asMap(array)
    delete map[key]
    return map
  }
  delete array[key]
  return array
}
