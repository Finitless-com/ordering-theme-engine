import { asMap, get, has, isArray, isEmpty, phpString, values, type Json, type JsonMap, type PhpArray } from './php.js'

/**
 * The renames and moves a schema declares (`$extensions["co.ordering.migrate"]`,
 * `[{ from, to }]`, api-core's ThemeMigrations), so that a theme saved
 * before a key changed place keeps its value. They apply in order: a value
 * at `from` goes to `to` unless the theme already sets `to`, and `from` is
 * dropped either way.
 *
 * Paths are a theme's: the keys of `visibility` and `images` hold dots of
 * their own (`visibility.business.header.info`), any other path is a key
 * per segment.
 *
 * $theme is changed in place: pass a copy.
 */
export function applyMigrations(theme: JsonMap, migrations: Json): JsonMap {
  let answer = theme
  for (const migration of values(migrations)) {
    const from = segments(phpString(get(migration, 'from') ?? ''))
    const to = segments(phpString(get(migration, 'to') ?? ''))
    if (from.length < 2 || to.length < 2 || !hasPath(answer, from)) {
      continue
    }
    const value = getPath(answer, from)
    answer = forget(answer, from) as JsonMap
    if (!hasPath(answer, to)) {
      answer = put(answer, to, value) as JsonMap
    }
  }
  return answer
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
