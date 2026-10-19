import { alias } from './format.js'
import { clone, dig, entries, get, has, isArray, isMap, values, type Json, type JsonMap } from './php.js'
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
 * A schema that declares none of them follows the engine's rule (follows()).
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

  const declared = get(schema, 'follows')
  const answer = clone(defaults)
  for (const [path, group] of entries(isArray(declared) ? declared : follows(schema))) {
    const segments = path.split('.')
    const spec = propertySpec(schema, segments.slice(1))
    if (spec === null) {
      continue
    }

    // A path the defaults do not have is left alone, not created.
    let parent: JsonMap | null = null
    let node: Json = answer
    let found = true
    for (const segment of segments) {
      if (!isMap(node) || !has(node, segment)) {
        found = false
        break
      }
      parent = node
      node = get(node, segment)
    }
    if (!found || parent === null) {
      continue
    }
    const leaf = segments[segments.length - 1]!

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
 * Which properties of a compiled schema follow Identity when it declares
 * none (ThemeOverall::follows()): the rule the engine applied before the
 * schema said it.
 *
 * - a state keeps the schema's default, and so do the platforms' overrides;
 * - so does a shadow or border the schema gives as numbers;
 * - and a shadow of what has a transparent background, except a tab bar's
 *   variants;
 * - and a rounding of `none` or `full`;
 * - and the borders of the appearances.
 */
export function follows(schema: TupleSchema): JsonMap {
  const answer: JsonMap = {}
  const add = (found: JsonMap): void => {
    for (const [path, group] of Object.entries(found)) {
      if (!has(answer, path)) {
        answer[path] = group
      }
    }
  }
  for (const [component, spec] of entries(get(schema, 'components'))) {
    for (const [section, props] of entries(isArray(spec) ? spec : {})) {
      if (section === 'base') {
        add(followed(`components.${component}.base`, isArray(props) ? props : {}, false))
      } else if (['variants', 'sizes', 'shapes', 'colors', 'appearances'].includes(section)) {
        for (const [name, variant] of entries(isArray(props) ? props : {})) {
          const own: JsonMap = {}
          for (const [prop, value] of entries(variant)) {
            if (prop !== 'states') {
              own[prop] = value
            }
          }
          add(followed(`components.${component}.${section}.${name}`, own, section === 'appearances', component === 'tabs' && section === 'variants'))
        }
      }
    }
  }
  return answer
}

/** The properties of one block that follow Identity (ThemeOverall::props()). */
function followed(path: string, props: Json, appearance: boolean, shadowed = false): JsonMap {
  const answer: JsonMap = {}
  const transparent = !shadowed && dig(props, 'background', 1) === 'transparent'
  for (const [prop, spec] of entries(props)) {
    if (!isArray(spec) || !has(spec, 1)) {
      continue
    }
    const kind = get(spec, 0)
    const fallback = get(spec, 1)
    const parts = alias(fallback)
    if (kind === 'shadow' && !transparent && (fallback === null || parts?.[0] === 'shadow')) {
      answer[`${path}.${prop}`] = 'shadow'
    } else if (kind === 'radius' && parts?.[0] === 'radius' && !['none', 'full'].includes(parts[1])) {
      answer[`${path}.${prop}`] = 'radius'
    } else if (kind === 'border' && !appearance && (fallback === null || (isMap(fallback) && alias(get(fallback, 'width'))?.[0] === 'border'))) {
      answer[`${path}.${prop}`] = 'border'
    }
  }
  return answer
}

/** A followed border with Identity's width and style; a default that is no border (null, `none`) is none. */
function border(declared: Json, overall: Record<string, string>): JsonMap | null {
  let answer: JsonMap | null = isArray(declared) ? clone(declared) as JsonMap : null
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
