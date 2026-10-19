import { asMap, clone, entries, get, has, isArray, isMap, values, type Json, type JsonMap } from './php.js'
import { propertySpec } from './schemas.js'
import type { TupleSchema } from './types.js'

/**
 * Identity's theme-wide choices (ThemeOverall) become the default of every
 * component property that follows them, as the schema declares it
 * (`co.ordering.follows`: `shadow`, `radius` or `border`):
 *
 * - `tokens.shadow_style.size`: the level of every shadow that follows it;
 * - `tokens.border_style.radius`: the level of every rounding, unless the
 *   rounding's options leave it out;
 * - `tokens.border_style.width`: `none` removes every border that follows
 *   it; a level gives one to each, of `{color.border}` when it had none;
 * - `tokens.border_style.style`: the style of every border there is.
 *
 * A schema in nodes always declares what follows (NodeCompiler writes
 * `follows` even when empty), so ThemeOverall::follows(), the rule for
 * schemas in tuples that do not, has no use here.
 */
export interface Overall {
  shadow?: Json
  radius?: Json
  border_width?: Json
  border_style?: Json
}

/** $defaults with the theme-wide choices (a missing one leaves each component's). */
export function applyOverall(defaults: JsonMap, schema: TupleSchema, choices: Overall): JsonMap {
  const overall: Record<string, string> = {}
  for (const [key, value] of Object.entries(choices)) {
    if (typeof value === 'string') {
      overall[key] = value
    }
  }
  if (Object.keys(overall).length === 0) {
    return defaults
  }

  const answer = clone(defaults)
  for (const [path, group] of entries(get(schema, 'follows'))) {
    const segments = path.split('.')
    const spec = propertySpec(schema, segments.slice(1))
    if (spec === null) {
      continue
    }

    // PHP's `$value = &$value[$segment]`: every step created when missing.
    let parent: JsonMap = answer
    for (const segment of segments.slice(0, -1)) {
      const next = parent[segment]
      if (!isMap(next)) {
        if (next !== undefined && next !== null && next !== false && !isArray(next)) {
          throw new TypeError(`Cannot use a scalar value as an array at ${path}.`)
        }
        parent[segment] = asMap(next)
      }
      parent = parent[segment] as JsonMap
    }
    const leaf = segments[segments.length - 1]!
    if (!has(parent, leaf)) {
      parent[leaf] = null
    }

    if (group === 'shadow' && overall.shadow !== undefined) {
      parent[leaf] = `{shadow.${overall.shadow}}`
    } else if (group === 'radius' && overall.radius !== undefined && (!isArray(spec[2]) || values(spec[2]).includes(overall.radius))) {
      parent[leaf] = `{radius.${overall.radius}}`
    } else if (group === 'border') {
      parent[leaf] = border(spec[1] ?? null, overall)
    }
  }

  return answer
}

/**
 * A followed border with Identity's width and style. Its schema default is
 * a border or null; PHP's signature (`?array`) refuses anything else.
 */
function border(declared: Json, overall: Record<string, string>): JsonMap | null {
  if (declared !== null && !isArray(declared)) {
    throw new TypeError(`A border that follows Identity defaults to a border or null, not ${JSON.stringify(declared)}.`)
  }
  let answer: JsonMap | null = declared === null ? null : clone(declared) as JsonMap
  const width = overall.border_width ?? null
  if (width === 'none') {
    return null
  }
  if (width !== null) {
    answer = {
      width: `{border.${width}}`,
      color: get(answer, 'color') ?? '{color.border}',
      style: get(answer, 'style') ?? 'solid',
    }
  }
  if (answer !== null && overall.border_style !== undefined) {
    answer.style = overall.border_style
  }
  return answer
}
