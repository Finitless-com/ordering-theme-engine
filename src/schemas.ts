import { isNodes } from './compile.js'
import { tokenNames } from './format.js'
import { defaultSections, pageDefaults } from './pages.js'
import { clone, count, dig, entries, get, has, isArray, isList, type Json, type JsonMap } from './php.js'
import type { TupleSchema } from './types.js'

/**
 * A complete theme of the schema: every token, component property,
 * visibility key, image and page with its default (ThemeSchemas::defaults()).
 * A fresh copy: the caller may change it.
 */
export function schemaDefaults(schema: TupleSchema): JsonMap {
  const components: JsonMap = {}
  const platforms: JsonMap = {}
  const component = (name: string): JsonMap => (components[name] ??= {}) as JsonMap
  for (const [name, spec] of entries(schema.components)) {
    for (const [section, props] of entries(spec)) {
      if (section === 'platforms') {
        for (const [platform, platformProps] of entries(props)) {
          const override = (platforms[platform] ??= {}) as JsonMap
          const overridden = (override.components ??= {}) as JsonMap
          overridden[name] = { base: propDefaults(platformProps) }
        }
      } else if (section === 'base') {
        component(name).base = propDefaults(props)
      } else if (section === 'scope') {
        for (const [scoped, changes] of entries(props)) {
          const scope = (component(name).scope ??= {}) as JsonMap
          scope[scoped] = nestedDefaults(changes)
        }
      } else if (section === 'slots') {
        component(name).slots = clone(props)
      } else {
        for (const [variantName, variant] of entries(props)) {
          const own: JsonMap = {}
          let states: Json = null
          for (const [prop, value] of entries(variant)) {
            if (prop === 'states') {
              states = value
            } else {
              own[prop] = specDefault(value)
            }
          }
          const sectionDefaults = (component(name)[section] ??= {}) as JsonMap
          sectionDefaults[variantName] = own
          for (const [state, stateProps] of entries(states)) {
            const ownStates = (own.states ??= {}) as JsonMap
            ownStates[state] = propDefaults(stateProps)
          }
        }
      }
    }
  }

  const defaults: JsonMap = {
    tokens: isArray(schema.tokens) ? clone(schema.tokens) : {},
    components,
  }
  for (const section of ['visibility', 'images']) {
    const answer: JsonMap = {}
    for (const [key, entry] of entries(schema[section])) {
      answer[key] = clone(get(entry, 0))
    }
    defaults[section] = answer
  }
  for (const [key, entry] of entries(schema.visibility)) {
    for (const [platform, visible] of entries(get(entry, 2))) {
      const override = (platforms[platform] ??= {}) as JsonMap
      const shown = (override.visibility ??= {}) as JsonMap
      shown[key] = visible
    }
  }
  defaults.pages = pageDefaults(schema)
  defaults.modes = {}
  defaults.platforms = platforms

  return defaults
}

/**
 * The defaults of the site's settings (the `site` block), which are the
 * site's and not part of a theme (ThemeSchemas::siteDefaults()). It reads
 * the compiled schema, `compile(schema).schema`: given the nodes or what
 * compile() answers (`{ schema, errors }`) it throws a TypeError instead of
 * answering the raw node tree or `{}`.
 */
export function siteDefaults(schema: TupleSchema): JsonMap {
  if (isNodes(schema)) {
    throw new TypeError('siteDefaults() reads a compiled schema, not one in nodes: pass compile(schema).schema.')
  }
  if (has(schema, 'schema') && has(schema, 'errors')) {
    throw new TypeError("siteDefaults() reads a compiled schema, not compile()'s answer: pass compile(schema).schema.")
  }
  return isArray(schema.site) ? nestedDefaults(schema.site) as JsonMap : {}
}

/**
 * The tuple of a property by its full path: of a component (`components.…`)
 * or of the site's settings (`site.…`).
 */
export function specAt(schema: TupleSchema, path: string): Json[] | null {
  const segments = path.split('.')
  if (segments[0] === 'components') {
    return propertySpec(schema, segments.slice(1))
  }
  if (segments[0] !== 'site') {
    return null
  }
  let node: Json = schema
  for (const segment of segments) {
    node = isArray(node) ? get(node, segment) : null
  }
  return Array.isArray(node) && typeof node[0] === 'string' ? node : null
}

/**
 * A component property's spec, by its path under `components`
 * (`button.appearances.solid.states.pressed.background`).
 */
export function propertySpec(schema: TupleSchema, segments: string[]): Json[] | null {
  let node: Json = get(schema, 'components')
  if (!isArray(node)) {
    node = {}
  }
  for (const segment of segments) {
    if (!isArray(node) || !has(node, segment)) {
      return null
    }
    node = get(node, segment)
  }
  return Array.isArray(node) && typeof node[0] === 'string' ? node : null
}

/**
 * The kind of the value at a path of the schema's themes: a token's group
 * (`color`, `typography`, `radius`…; `layout_mode` for `tokens.layout.mode`)
 * for a name the schema has, a component property's kind, `border_color` for the color
 * of a border property, `bool` for a visibility key or a page's footer,
 * `image`, `page`, `sections` for a page's list and `section` for one of
 * them (`pages.<page>.sections.<key>`), or null when the path does not exist.
 */
export function kindAt(schema: TupleSchema, path: string): string | null {
  const segments = path.split('.')
  if (segments[0] === 'site') {
    const spec = specAt(schema, path)
    return spec === null ? null : (spec[0] as string)
  }
  if (segments[0] === 'visibility' || segments[0] === 'images') {
    // Their keys hold dots of their own; the schema's own keys.
    return segments.length > 1 && isArray(dig(schema, segments[0], segments.slice(1).join('.')))
      ? (segments[0] === 'visibility' ? 'bool' : 'image')
      : null
  }
  if (segments[0] === 'pages') {
    const page = dig(schema, 'pages', segments[1] ?? '')
    if (segments.length < 2 || !isArray(page)) {
      return null
    }
    if (segments.length === 2) {
      return 'page'
    }
    if (segments.length === 3 && segments[2] === 'footer' && isArray(get(page, 'footer'))) {
      return 'bool'
    }
    if (segments.length === 3 && segments[2] === 'sections' && isArray(get(page, 'sections'))) {
      return 'sections'
    }
    return segments.length === 4 && segments[2] === 'sections' && defaultSections(page).some((section) => get(section, 'key') === segments[3])
      ? 'section'
      : null
  }
  if (segments[0] === 'tokens') {
    if (segments.length !== 3) {
      return null
    }
    const [, group, name] = segments as [string, string, string]
    // The schema's own names, as its themes are validated; the catalog's when it names none.
    const own = dig(schema, 'tokens', group)
    const named = isArray(own) && count(own) > 0 ? has(own, name) : tokenNames(group).includes(name)
    if (!named) {
      return null
    }
    return group === 'layout' && name === 'mode' ? 'layout_mode' : group
  }
  if (segments[0] !== 'components' || segments.length < 4) {
    return null
  }

  const spec = propertySpec(schema, segments.slice(1))
  if (spec !== null) {
    return spec[0] as string
  }

  // The color of a border property.
  const last = segments.pop()
  const border = propertySpec(schema, segments.slice(1))
  return border !== null && border[0] === 'border' && last === 'color' ? 'border_color' : null
}

/** A spec's default: `$spec[1]`. */
function specDefault(spec: Json): Json {
  return clone(get(spec, 1))
}

function propDefaults(props: Json): JsonMap {
  const answer: JsonMap = {}
  for (const [prop, spec] of entries(props)) {
    answer[prop] = specDefault(spec)
  }
  return answer
}

/** The defaults of specs at any depth (a scope's changes, the site's settings). */
function nestedDefaults(node: Json): Json {
  const answer: JsonMap = {}
  for (const [key, value] of entries(node)) {
    answer[key] = isArray(value) && isList(value) && count(value) > 0 && typeof get(value, 0) === 'string'
      ? clone(get(value, 1))
      : isArray(value) ? nestedDefaults(value) : value
  }
  return answer
}
