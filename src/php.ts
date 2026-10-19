/**
 * PHP's semantics where api-core's engine depends on them and JavaScript
 * differs. Every value the engine handles is JSON, so a PHP array is either
 * a JS array (a list) or a plain object (a map).
 *
 * - PHP cannot tell an empty list from an empty map: `[]` and `{}` both
 *   decode to `[]`, which is a list. The engine normalizes every empty array
 *   it reads to `{}` (so a key can be written into it) and treats `{}` as
 *   PHP's `[]` everywhere: isList(), isEmpty().
 * - PHP arrays are values; JS objects are references. Functions that change
 *   what they receive copy it first (clone()).
 */

export type Json = null | boolean | number | string | Json[] | JsonMap
export interface JsonMap {
  [key: string]: Json
}
export type PhpArray = Json[] | JsonMap

const hasOwn = Object.prototype.hasOwnProperty

/** PHP's is_array(). */
export function isArray(value: unknown): value is PhpArray {
  return typeof value === 'object' && value !== null
}

/** A plain object (a PHP array written with keys). */
export function isMap(value: unknown): value is JsonMap {
  return isArray(value) && !Array.isArray(value)
}

/** PHP's array_is_list(): keys 0…n-1 in order. The empty array is a list. */
export function isList(value: unknown): boolean {
  if (Array.isArray(value)) {
    return true
  }
  if (!isMap(value)) {
    return false
  }
  let index = 0
  for (const key in value) {
    if (key !== String(index++)) {
      return false
    }
  }
  return true
}

/** PHP's `$value === []`. */
export function isEmpty(value: unknown): boolean {
  return isArray(value) && count(value) === 0
}

export function count(value: PhpArray): number {
  return Array.isArray(value) ? value.length : Object.keys(value).length
}

/** PHP's array_key_exists() (a null value still exists). */
export function has(array: unknown, key: string | number): boolean {
  if (Array.isArray(array)) {
    const index = typeof key === 'number' ? key : /^(0|[1-9][0-9]*)$/.test(key) ? Number(key) : -1
    return index >= 0 && index < array.length
  }
  return isMap(array) && hasOwn.call(array, key)
}

/** PHP's `$array[$key] ?? null`. */
export function get(array: unknown, key: string | number): Json {
  if (!has(array, key)) {
    return null
  }
  const value = (array as Record<string | number, Json | undefined>)[key]
  return value === undefined ? null : value
}

/** PHP's `$array['a']['b'] ?? null`. */
export function dig(array: unknown, ...keys: Array<string | number>): Json {
  let node: unknown = array
  for (const key of keys) {
    if (!has(node, key)) {
      return null
    }
    node = (node as Record<string | number, unknown>)[key]
  }
  return node === undefined ? null : (node as Json)
}

/** The values of an array, in order (PHP's foreach without keys). */
export function values(array: unknown): Json[] {
  if (Array.isArray(array)) {
    return array
  }
  return isMap(array) ? Object.values(array) : []
}

/** The pairs of an array, in order (PHP's foreach). */
export function entries(array: unknown): Array<[string, Json]> {
  if (Array.isArray(array)) {
    return array.map((value, index) => [String(index), value])
  }
  return isMap(array) ? Object.entries(array) : []
}

/** A map to write into: the array itself when it has keys, else a new one. */
export function asMap(value: unknown): JsonMap {
  if (isMap(value)) {
    return value
  }
  const answer: JsonMap = {}
  if (Array.isArray(value)) {
    value.forEach((item, index) => {
      answer[String(index)] = item
    })
  }
  return answer
}

/** A deep copy, every empty array as `{}` and without `__proto__` keys. */
export function clone<T extends Json>(value: T): T
export function clone(value: unknown): Json
export function clone(value: unknown): Json {
  if (Array.isArray(value)) {
    return value.length === 0 ? {} : value.map(clone)
  }
  if (isMap(value)) {
    const copy: JsonMap = {}
    for (const key of Object.keys(value)) {
      // A key no theme or schema has, which would replace the prototype.
      if (key !== '__proto__') {
        copy[key] = clone(value[key])
      }
    }
    return copy
  }
  return value === undefined ? null : (value as Json)
}

/** PHP's `===` on JSON values: arrays need the same pairs in the same order. */
export function identical(a: unknown, b: unknown): boolean {
  if (!isArray(a) || !isArray(b)) {
    return a === b || (a === undefined && b === null) || (a === null && b === undefined)
  }
  const left = entries(a)
  const right = entries(b)
  if (left.length !== right.length) {
    return false
  }
  return left.every(([key, value], index) => {
    const pair = right[index]
    return pair !== undefined && pair[0] === key && identical(value, pair[1])
  })
}

/** PHP's is_numeric(): a number, or a string that is one. */
export function isNumeric(value: unknown): boolean {
  if (typeof value === 'number') {
    return !Number.isNaN(value)
  }
  return typeof value === 'string' && /^\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?\s*$/.test(value)
}

/** PHP's (float) cast of a JSON value. */
export function toFloat(value: unknown): number {
  if (typeof value === 'number') {
    return value
  }
  if (typeof value === 'boolean') {
    return value ? 1 : 0
  }
  if (typeof value === 'string') {
    const match = /^\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?/.exec(value)
    return match ? Number(match[0]) : 0
  }
  return 0
}

/** PHP's (int) cast of a number: toward zero, never -0. */
export function toInt(value: unknown): number {
  const truncated = Math.trunc(toFloat(value))
  return truncated === 0 || !Number.isFinite(truncated) ? 0 : truncated
}

const POWERS = [1e0, 1e1, 1e2, 1e3, 1e4, 1e5, 1e6, 1e7, 1e8, 1e9, 1e10, 1e11, 1e12, 1e13, 1e14, 1e15, 1e16, 1e17, 1e18, 1e19, 1e20, 1e21, 1e22]

function pow10(power: number): number {
  return power < 0 || power > 22 ? Math.pow(10, power) : POWERS[power]!
}

/** Half away from zero, as php_round_helper() in PHP_ROUND_HALF_UP mode. */
function roundHelper(value: number): number {
  return value >= 0 ? Math.floor(value + 0.5) : Math.ceil(value - 0.5)
}

/**
 * PHP 8.3's round() (`_php_math_round`, ext/standard/math.c). It rounds half
 * away from zero, where Math.round() rounds half up (-2.5 → -2), and it
 * first "pre-rounds" the value to the 15 significant digits a double holds,
 * so 127.49999999999997 (a mix of two channels) rounds to 128 and 1.005 to
 * 1.01. api-core runs PHP 8.3; PHP 8.4 dropped the pre-rounding.
 */
export function phpRound(value: number, places = 0): number {
  if (!Number.isFinite(value) || value === 0) {
    return value
  }

  const precision = 14 - Math.floor(Math.log10(Math.abs(value)))
  const factor = pow10(Math.abs(places))
  let rounded: number
  if (precision > places && precision - 15 < places) {
    rounded = roundHelper(precision >= 0 ? value * pow10(precision) : value / pow10(-precision))
    rounded = rounded / pow10(Math.abs(places - precision))
  } else {
    rounded = places >= 0 ? value * factor : value / factor
    if (Math.abs(rounded) >= 1e15) {
      return value
    }
  }

  rounded = roundHelper(rounded)

  return places > 0 ? rounded / factor : rounded * factor
}

/** PHP's sprintf('%02X', $int). */
export function hex2(value: number): string {
  return value.toString(16).toUpperCase().padStart(2, '0')
}

/**
 * PHP's is_int() on a decoded JSON number. JavaScript cannot tell `2` from
 * `2.0`, which PHP decodes as a float: an integer-valued number counts as an
 * int (unless it is beyond PHP's 64-bit ints, which PHP decodes as floats).
 */
export function isInt(value: unknown): value is number {
  return typeof value === 'number' && Number.isInteger(value) && Math.abs(value) < 2 ** 63
}

/** PHP's is_scalar(): a bool, a number or a string (not null). */
export function isScalar(value: unknown): boolean {
  return typeof value === 'boolean' || typeof value === 'number' || typeof value === 'string'
}

/** PHP's empty(): null, false, 0, 0.0, '', '0' and the empty array. */
export function isPhpEmpty(value: unknown): boolean {
  return value === null || value === undefined || value === false || value === 0 || value === '' || value === '0' || isEmpty(value)
}

/**
 * PHP's (string) cast of a JSON scalar: true is '1', false and null are '',
 * a float keeps 14 significant digits (`precision`) and goes exponential as
 * PHP writes it (`1.0E+25`). An array is 'Array'.
 */
export function phpString(value: unknown): string {
  if (typeof value === 'string') {
    return value
  }
  if (value === true) {
    return '1'
  }
  if (value === false || value === null || value === undefined) {
    return ''
  }
  if (typeof value === 'number') {
    if (Number.isInteger(value) && Math.abs(value) < 2 ** 63) {
      return String(value)
    }
    if (!Number.isFinite(value)) {
      return Number.isNaN(value) ? 'NAN' : value > 0 ? 'INF' : '-INF'
    }
    if (value === 0) {
      return Object.is(value, -0) ? '-0' : '0'
    }
    const [mantissa, exponent] = value.toExponential(13).split('e') as [string, string]
    const power = Number(exponent)
    const digits = mantissa.replace(/\.?0+$/, '')
    if (power < -4 || power >= 14) {
      return `${digits.includes('.') ? digits : `${digits}.0`}E${power < 0 ? '-' : '+'}${Math.abs(power)}`
    }
    return String(Number(value.toPrecision(14)))
  }
  return 'Array'
}

/** PHP's strlen(): the bytes of the string in UTF-8. */
export function strlen(value: string): number {
  let bytes = 0
  for (const char of value) {
    const point = char.codePointAt(0)!
    bytes += point < 0x80 ? 1 : point < 0x800 ? 2 : point < 0x10000 ? 3 : 4
  }
  return bytes
}

/** PHP's mb_strlen() in UTF-8: the code points of the string. */
export function mbStrlen(value: string): number {
  let length = 0
  for (const _ of value) {
    length++
  }
  return length
}

/** PHP's array_unique() count: values that differ as strings. */
export function uniqueCount(list: Json[]): number {
  return new Set(list.map(phpString)).size
}

const SPECIAL_FORMS: Array<[string, number]> = [
  ['dev', 0], ['alpha', 1], ['a', 1], ['beta', 2], ['b', 2], ['RC', 3], ['rc', 3], ['#', 4], ['pl', 5], ['p', 5],
]

const isDigit = (char: string | undefined): boolean => char !== undefined && char >= '0' && char <= '9'

function specialForm(form: string): number {
  for (const [name, order] of SPECIAL_FORMS) {
    if (form.startsWith(name)) {
      return order
    }
  }
  return -1
}

function compareSpecialForms(left: string, right: string): number {
  return Math.sign(specialForm(left) - specialForm(right))
}

/** php_canonicalize_version(): `-`, `_` and `+` as dots, a dot between digits and the rest. */
function canonicalize(version: string): string {
  const isNotDigit = (char: string): boolean => !isDigit(char) && char !== '.'
  const isAlnum = (char: string): boolean => /^[0-9A-Za-z]$/.test(char)
  let answer = version[0] ?? ''
  let previous = answer
  for (const char of version.slice(1)) {
    const last = answer[answer.length - 1]
    if (char === '-' || char === '_' || char === '+') {
      if (last !== '.') {
        answer += '.'
      }
    } else if ((isNotDigit(previous) && isDigit(char)) || (isDigit(previous) && isNotDigit(char))) {
      if (last !== '.') {
        answer += '.'
      }
      answer += char
    } else if (!isAlnum(char)) {
      if (last !== '.') {
        answer += '.'
      }
    } else {
      answer += char
    }
    previous = char
  }
  return answer
}

/** strtol() of a part that starts with a digit, saturated as a C long. */
function strtol(part: string): number {
  const digits = /^\d+/.exec(part)?.[0] ?? '0'
  return Math.min(Number(digits), 2 ** 63 - 1)
}

/**
 * PHP's version_compare($a, $b) without an operator: -1, 0 or 1
 * (ext/standard/versioning.c), so `26.1.0` comes after `26.1.0-beta`.
 */
export function versionCompare(left: string, right: string): number {
  if (left === '' || right === '') {
    return left === right ? 0 : left !== '' ? 1 : -1
  }
  const one = (left[0] === '#' ? left : canonicalize(left)).split('.')
  const two = (right[0] === '#' ? right : canonicalize(right)).split('.')
  // C walks the parts while neither rest is empty and both had a dot.
  const rest = (parts: string[], index: number): string => parts.slice(index).join('.')
  let i = 0
  let j = 0
  let compare = 0
  let moreOne = true
  let moreTwo = true
  while (rest(one, i) !== '' && rest(two, j) !== '' && moreOne && moreTwo) {
    const p1 = one[i]!
    const p2 = two[j]!
    moreOne = i < one.length - 1
    moreTwo = j < two.length - 1
    if (isDigit(p1[0]) && isDigit(p2[0])) {
      compare = Math.sign(strtol(p1) - strtol(p2))
    } else if (!isDigit(p1[0]) && !isDigit(p2[0])) {
      compare = compareSpecialForms(p1, p2)
    } else {
      compare = isDigit(p1[0]) ? compareSpecialForms('#N#', p2) : compareSpecialForms(p1, '#N#')
    }
    if (compare !== 0) {
      break
    }
    if (moreOne) {
      i++
    }
    if (moreTwo) {
      j++
    }
  }
  if (compare === 0) {
    if (moreOne) {
      compare = isDigit(rest(one, i)[0]) ? 1 : versionCompare(rest(one, i), '#N#')
    } else if (moreTwo) {
      compare = isDigit(rest(two, j)[0]) ? -1 : versionCompare('#N#', rest(two, j))
    }
  }
  return compare
}
