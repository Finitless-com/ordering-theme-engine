import { clone, entries, get, isArray, isMap, phpRound, toInt, type Json, type JsonMap } from './php.js'

/**
 * Shadows as the editor offers them instead of each level's numbers
 * (ThemeShadows): a style (`none`, `soft`, `normal`, `strong`, `solid`), a
 * color and a direction (`down`, `down_right`, `down_left`, `around`) for
 * the whole theme remake the schema's normal levels.
 */

/** The schema's levels in a style, a color (null keeps each level's) and a direction. */
export function applyShadows(levels: JsonMap, style: string, color: string | null, direction = 'down'): JsonMap {
  const styled = styles(levels, style) ?? clone(levels)
  const answer: JsonMap = {}
  for (const [level, shadow] of Object.entries(styled)) {
    if (!isMap(shadow)) {
      answer[level] = shadow
      continue
    }
    if (color !== null) {
      shadow.color = color
    }
    answer[level] = direct(shadow, direction)
  }
  return answer
}

/** The levels in one style, or null when it is no style. */
function styles(levels: JsonMap, style: string): JsonMap | null {
  const map = (fn: (shadow: Json) => Json): JsonMap => {
    const answer: JsonMap = {}
    for (const [level, shadow] of entries(levels)) {
      answer[level] = fn(clone(shadow))
    }
    return answer
  }
  switch (style) {
    case 'none':
      return map(() => null)
    case 'soft':
      return map((shadow) => scale(shadow, 0.75, 1.3, 0.6))
    case 'normal':
      return clone(levels)
    case 'strong':
      return map((shadow) => scale(shadow, 1.5, 1.25, 1.6))
    case 'solid':
      return map((shadow) => {
        const scaled = scale(shadow, 1.5, 0, 1)
        return scaled === null ? null : { ...scaled, opacity: 1 }
      })
    default:
      return null
  }
}

/**
 * $shadow cast in $direction, as far as it falls. Around, a shadow without
 * blur grows by that distance instead, or it would not show.
 */
function direct(shadow: JsonMap, direction: string): JsonMap {
  const distance = Math.max(Math.abs(num(get(shadow, 'x'))), Math.abs(num(get(shadow, 'y'))))
  // PHP's -0 is 0: an integer.
  const negative = distance === 0 ? 0 : -distance
  const [x, y] = direction === 'down_right'
    ? [distance, distance]
    : direction === 'down_left' ? [negative, distance] : direction === 'around' ? [0, 0] : [0, distance]
  shadow.x = x
  shadow.y = y
  // PHP's loose `== 0`.
  if (direction === 'around' && num(get(shadow, 'blur')) == 0) {
    shadow.spread = num(get(shadow, 'spread')) + distance
  }
  return shadow
}

/**
 * $shadow with its offset, blur and opacity scaled (and its Android
 * elevation with the offset), or null for none. Rounded as PHP rounds
 * (phpRound()), its opacity capped at 0.5.
 */
function scale(shadow: Json, offset: number, blur: number, opacity: number): JsonMap | null {
  if (!isArray(shadow)) {
    return null
  }
  const scaled = shadow as JsonMap
  for (const axis of ['x', 'y']) {
    if (get(shadow, axis) !== null) {
      scaled[axis] = toInt(phpRound(num(get(shadow, axis)) * offset))
    }
  }
  if (get(shadow, 'blur') !== null) {
    scaled.blur = toInt(phpRound(num(get(shadow, 'blur')) * blur))
  }
  if (get(shadow, 'opacity') !== null) {
    scaled.opacity = phpRound(Math.min(0.5, num(get(shadow, 'opacity')) * opacity), 2)
  }
  if (get(shadow, 'elevation') !== null) {
    scaled.elevation = toInt(Math.min(24, phpRound(num(get(shadow, 'elevation')) * offset)))
  }
  return scaled
}

/** A shadow's number: `?? 0`. */
function num(value: Json): number {
  return typeof value === 'number' ? value : 0
}
