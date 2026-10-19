import {
  BORDER, BORDER_STYLES, COLORS, EASINGS, FONTS, FONT_ROLES, HTML_MAX_LENGTH, ID_MAX_LENGTH, LAYOUT_MODES, LEVEL_OPTION_KINDS,
  NO_BORDER, PROPERTY_KINDS, RADIUS, ROLE, SHADOW, SHADOW_DIRECTIONS, SHADOW_STYLES, SPACE, STRING_MAX_LENGTH, alias,
  isColor, isImage, isNumber, isOpacity, isShadow, isTypography, tokenNames,
} from './format.js'
import { entries, get, has, identical, isArray, isInt, isList, mbStrlen, phpString, strlen, uniqueCount, values, type Json, type JsonMap } from './php.js'
import type { SchemaError } from './types.js'

/**
 * What api-core's ThemeValidator and ThemeImage check of a schema's values:
 * a value of each kind, the tokens, an image's formats and hints. Each
 * problem is `{path, code}`, the code being the path and the rule.
 */

export function error(path: string, rule: string): SchemaError {
  return { path, code: path === '' ? rule : `${path}.${rule}` }
}

/** PHP's in_array($value, $list, true). */
function within(list: readonly Json[], value: unknown): boolean {
  return list.some((item) => identical(item, value))
}

/**
 * What is wrong with a value of a kind (null when nothing): `invalid` or
 * `alias` (ThemeValidator::value()).
 */
export function valueError(kind: string, value: Json, options: Json = null): string | null {
  if (kind === 'role' && typeof value === 'string' && ROLE.test(value)) {
    return null
  }
  let checked = value
  const color = kind === 'border' && isArray(value) ? get(value, 'color') : null
  if (typeof color === 'string' && ROLE.test(color)) {
    // The border of an appearance, in a role of its color.
    checked = { ...(value as JsonMap), color: '#000000' }
  }

  const parts = alias(checked)
  if (parts !== null) {
    const group = has(PROPERTY_KINDS, kind) ? PROPERTY_KINDS[kind] : null
    const [aliasGroup, name] = parts
    if (group !== aliasGroup || !tokenNames(aliasGroup).includes(name)) {
      return 'alias'
    }
    // A level the property leaves out of its options.
    return isArray(options) && (LEVEL_OPTION_KINDS as readonly string[]).includes(kind) && !within(values(options), name) ? 'invalid' : null
  }

  let valid: boolean
  switch (kind) {
    case 'color':
    case 'role':
      valid = isColor(checked)
      break
    // A level of rounding and a typography style, only by alias.
    case 'radius':
    case 'text':
      valid = false
      break
    case 'size':
    case 'icon':
    case 'space':
    case 'motion':
    case 'number':
      valid = isNumber(checked)
      break
    case 'opacity':
      valid = isOpacity(checked)
      break
    case 'font_role':
      valid = within(FONT_ROLES, checked)
      break
    case 'border':
      valid = isBorder(checked)
      break
    case 'shadow':
      valid = isShadow(checked)
      break
    case 'image':
      valid = isImage(checked)
      break
    case 'bool':
      valid = typeof checked === 'boolean'
      break
    case 'option':
    case 'layout':
      valid = isArray(options) && within(values(options), checked)
      break
    case 'string':
      valid = typeof checked === 'string' && mbStrlen(checked) <= STRING_MAX_LENGTH
      break
    case 'html':
      valid = typeof checked === 'string' && strlen(checked) <= HTML_MAX_LENGTH
      break
    case 'id':
      valid = checked === null || (isInt(checked) && checked >= 0) || (typeof checked === 'string' && strlen(checked) <= ID_MAX_LENGTH)
      break
    default:
      valid = false
  }
  return valid ? null : 'invalid'
}

/**
 * None (null, or `none`) or `{ width, color, style? }`, the width a number
 * or a border token, the color a color or a color token.
 */
function isBorder(value: Json): boolean {
  if (value === null || value === NO_BORDER) {
    return true
  }
  if (!isArray(value) || !entries(value).every(([key]) => ['width', 'color', 'style'].includes(key))) {
    return false
  }
  const width = get(value, 'width')
  const color = get(value, 'color')
  // isset(): null counts as missing.
  if (width === null || color === null) {
    return false
  }
  const parts = alias(width)
  const widthOk = parts === null ? isNumber(width) : parts[0] === 'border' && (BORDER as readonly string[]).includes(parts[1])
  return widthOk
    && valueError('color', color) === null
    && (!has(value, 'style') || within(BORDER_STYLES, get(value, 'style')))
}

/**
 * Tokens: their group and name exist, and each value is a literal of the
 * group's kind (ThemeValidator::tokens()).
 */
export function tokenErrors(tokens: Json, path: string): SchemaError[] {
  const errors: SchemaError[] = []
  for (const [group, names] of entries(tokens)) {
    const groupPath = `${path}.${group}`
    const known = tokenNames(group)
    if (known.length === 0) {
      errors.push(error(groupPath, 'unknown'))
      continue
    }
    if (!isArray(names)) {
      errors.push(error(groupPath, 'invalid'))
      continue
    }
    for (const [name, value] of entries(names)) {
      const tokenPath = `${groupPath}.${name}`
      if (!known.includes(name)) {
        errors.push(error(tokenPath, 'unknown'))
        continue
      }
      if (!validToken(group, name, value)) {
        errors.push(error(tokenPath, 'invalid'))
      }
    }
  }
  return errors
}

function validToken(group: string, name: string, value: Json): boolean {
  switch (group) {
    case 'shadow_style':
      return name === 'style' ? within(SHADOW_STYLES, value)
        : name === 'direction' ? within(SHADOW_DIRECTIONS, value)
          : name === 'size' ? within(SHADOW, value) : isColor(value)
    case 'motion_style':
      return name === 'easing' ? within(EASINGS, value) : typeof value === 'boolean'
    case 'focus_ring':
      return name === 'width' ? within(BORDER, value) : name === 'offset' ? within(SPACE, value) : within(COLORS, value)
    case 'border_style':
      return name === 'radius' ? within(RADIUS, value)
        : name === 'width' ? value === 'none' || within(BORDER, value) : within(BORDER_STYLES, value)
    case 'layout':
      return name === 'mode' ? within(LAYOUT_MODES, value) : isNumber(value)
    case 'color':
      return isColor(value)
    case 'font':
      return typeof value === 'string' && (FONTS as readonly string[]).includes(value)
    case 'typography':
      return isTypography(value)
    case 'shadow':
      return isShadow(value)
    case 'opacity':
      return isOpacity(value)
    default:
      return isNumber(value)
  }
}

/** Every format a schema may give an image key (ThemeImage::FORMATS). */
export const IMAGE_FORMATS = ['jpg', 'png', 'webp', 'avif', 'ico', 'svg'] as const

const MAX_SIDE = 10000
const MAX_BYTES = 5000000

/** A non-empty list of IMAGE_FORMATS, each once (ThemeImage::validFormats()). */
export function validFormats(formats: Json): boolean {
  if (!isArray(formats) || values(formats).length === 0 || !isList(formats)) {
    return false
  }
  const list = values(formats)
  return uniqueCount(list) === list.length && list.every((format) => (IMAGE_FORMATS as readonly string[]).includes(phpString(format)))
}

/**
 * `{ size?: [width, height], ratio?: "w:h", alpha?: bool, max_kb?: n }`,
 * positive integers and nothing else (ThemeImage::validHints()).
 */
export function validHints(hints: Json): boolean {
  if (!isArray(hints) || (values(hints).length > 0 && isList(hints))
    || !entries(hints).every(([key]) => ['size', 'ratio', 'alpha', 'max_kb'].includes(key))) {
    return false
  }
  const positive = (value: Json): value is number => isInt(value) && value > 0
  if (has(hints, 'size')) {
    const size = get(hints, 'size')
    const [width, height] = isArray(size) ? [get(size, 0), get(size, 1)] : [null, null]
    if (!(isArray(size) && isList(size) && values(size).length === 2 && positive(width) && positive(height) && width <= MAX_SIDE && height <= MAX_SIDE)) {
      return false
    }
  }
  if (has(hints, 'ratio')) {
    const ratio = get(hints, 'ratio')
    if (!(typeof ratio === 'string' && /^[1-9]\d{0,3}:[1-9]\d{0,3}\n?$/.test(ratio))) {
      return false
    }
  }
  if (has(hints, 'alpha') && typeof get(hints, 'alpha') !== 'boolean') {
    return false
  }
  const kb = get(hints, 'max_kb')
  return !has(hints, 'max_kb') || (positive(kb) && kb * 1000 <= MAX_BYTES)
}
