import { LINE_MAX, LINE_MIN, isTypography } from './format.js'
import { dig, entries, get, isArray, type Json, type JsonMap } from './php.js'

/**
 * The numbers a theme or a site's settings may hold (ThemeValidator's
 * ranges): each within its node's `limits` (the editor's `min` and `max`,
 * which the compiled schema carries by path) and, where those say nothing,
 * within the format's range for its kind. A number outside its node's
 * limits is refused when saved (`<path>.out_of_range`; one outside the
 * format's range is `invalid`) and clamped when read (valid()), so a
 * tightened limit keeps the closest value instead of the default.
 */

/** A range: its lowest and highest number, either open. */
export interface Range {
  min: number | null
  max: number | null
}

/** The format's range of a number (`size`, `space`, a token of a scale…). */
export const NUMBER_RANGE: Range = { min: 0, max: 9999 }

/** The format's range of an opacity. */
export const OPACITY_RANGE: Range = { min: 0, max: 1 }

/** The format's range of each numeric field of a typography style. */
export const TYPOGRAPHY_RANGES: Readonly<Record<string, Range>> = {
  size: { min: 0, max: 200 },
  line: { min: LINE_MIN, max: LINE_MAX },
  letter_spacing: { min: -20, max: 20 },
}

/** The property kinds whose value is a number. */
export const NUMBER_KINDS = ['size', 'icon', 'space', 'motion', 'number'] as const

/** The token groups whose values are numbers (`layout` but its `mode`). */
const NUMBER_GROUPS = ['radius', 'size', 'icon', 'space', 'layout', 'motion', 'border', 'scrollbar']

/**
 * What a value of a kind is as a number: `number`, `opacity`, `text` (a
 * typography style, by its fields) or null (not a number).
 */
export function numberKind(kind: string): 'number' | 'opacity' | 'text' | null {
  if ((NUMBER_KINDS as readonly string[]).includes(kind)) {
    return 'number'
  }
  return kind === 'opacity' || kind === 'text' ? kind : null
}

/** numberKind() of the token `<group>.<name>`. */
export function tokenNumberKind(group: string, name: string): 'number' | 'opacity' | 'text' | null {
  if (group === 'typography') {
    return 'text'
  }
  if (group === 'opacity') {
    return 'opacity'
  }
  return NUMBER_GROUPS.includes(group) && !(group === 'layout' && name === 'mode') ? 'number' : null
}

/** The format's range of a numeric kind. */
export function formatRange(kind: 'number' | 'opacity'): Range {
  return kind === 'opacity' ? OPACITY_RANGE : NUMBER_RANGE
}

/** A node's `min` and `max` as `limits` gives them (open where they say nothing). */
export function nodeRange(limits: Json, node: string): Range {
  const own = get(limits, node)
  const bound = (key: string): number | null => {
    const limit = get(own, key)
    return typeof limit === 'number' ? limit : null
  }
  return { min: bound('min'), max: bound('max') }
}

/**
 * The range of each numeric field of a typography style at a node: the
 * node's own `fields`, else those of the `tokens.typography` group (a
 * component's custom style takes them too), field by field.
 */
export function fieldRanges(limits: Json, node: string): Record<string, Range> {
  const answer: Record<string, Range> = {}
  for (const fields of [dig(limits, 'tokens.typography', 'fields'), dig(limits, node, 'fields')]) {
    for (const [field, range] of entries(isArray(fields) ? fields : {})) {
      const min = get(range, 'min')
      const max = get(range, 'max')
      answer[field] = { min: typeof min === 'number' ? min : null, max: typeof max === 'number' ? max : null }
    }
  }
  return answer
}

/** $range with what it leaves open taken from $format. */
export function filled(range: Range, format: Range): Range {
  return { min: range.min ?? format.min, max: range.max ?? format.max }
}

/** Whether a number is outside a range. */
export function outside(number: Json, range: Range): boolean {
  return typeof number === 'number' && ((range.min !== null && number < range.min) || (range.max !== null && number > range.max))
}

/** A number within a range (anything else as it is). */
export function clamp(value: Json, range: Range): Json {
  if (typeof value !== 'number') {
    return value
  }
  if (range.min !== null && value < range.min) {
    return range.min
  }
  return range.max !== null && value > range.max ? range.max : value
}

/**
 * A value of a numeric kind within its node's limits and the format's
 * range (ThemeValidator::clampValue()): a number, or each numeric field of
 * a map with a typography's fields. An alias, or a value of another kind,
 * as it is.
 */
export function clampValue(value: Json, kind: 'number' | 'opacity' | 'text', limits: Json, node: string): Json {
  if (kind !== 'text') {
    return clamp(value, filled(nodeRange(limits, node), formatRange(kind)))
  }
  if (!isArray(value) || Array.isArray(value)) {
    return value
  }
  const ranges = fieldRanges(limits, node)
  const answer: JsonMap = { ...value }
  for (const [field, format] of Object.entries(TYPOGRAPHY_RANGES)) {
    if (typeof answer[field] === 'number') {
      answer[field] = clamp(answer[field]!, filled(ranges[field] ?? { min: null, max: null }, format))
    }
  }
  return answer
}

/**
 * What is outside its node's limits in a value: `<path>.out_of_range` for a
 * number, `<path>.<field>.out_of_range` for each field of a typography
 * style (ThemeValidator::outOfRange()). The format's range is the value's
 * kind's own check (`invalid`), not this one.
 */
export function rangeCodes(value: Json, path: string, limits: Json, node: string): string[] {
  if (typeof value === 'number') {
    return outside(value, nodeRange(limits, node)) ? [`${path}.out_of_range`] : []
  }
  if (!isTypography(value)) {
    return []
  }
  return Object.entries(fieldRanges(limits, node))
    .filter(([field, range]) => outside(get(value, field), range))
    .map(([field]) => `${path}.${field}.out_of_range`)
}
