import { compile, isNodes } from './compile.js'
import { MODES, OWN_GROUPS, ROLES, SLOT_AXES, alias, isTranslucent, tokenNames } from './format.js'
import { applyMigrations, withoutKey } from './migrations.js'
import { defaultSections } from './pages.js'
import { clone, count, dig, entries, get, has, identical, isArray, isEmpty, isList, phpString, values, type Json, type JsonMap } from './php.js'
import { specAt } from './schemas.js'
import type { NodeSchema, SchemaError, ThemeValues, TupleSchema } from './types.js'
import { error, tokenErrors, valueError } from './validator.js'

/**
 * What of a theme fits its schema, as api-core's ThemeValidator::validate()
 * and ThemeResolver::valid() answer: each problem `{ path, code }`, and the
 * theme without what has one.
 */

/** The sections a theme may set (ThemeValidator::SECTIONS). */
export const THEME_SECTIONS = ['tokens', 'components', 'visibility', 'images', 'pages', 'modes', 'platforms'] as const

const PLATFORM_SECTIONS = ['components', 'pages', 'visibility']

const COMPONENT_SECTIONS = ['base', 'variants', 'sizes', 'shapes', 'colors', 'appearances']

const PLATFORM_VISIBILITY = /^platforms\.([a-z0-9_]+)\.visibility\.(.+)$/

/** PHP's in_array($value, $list, true). */
function within(list: readonly Json[], value: unknown): boolean {
  return list.some((item) => identical(item, value))
}

/** A stored map: an array that is empty or has keys (a list is no map). */
function isStoredMap(value: Json): boolean {
  return isArray(value) && (isEmpty(value) || !isList(value))
}

/**
 * What of a theme fits its schema (ThemeResolver::valid()): a value the
 * schema no longer has or that is not valid is left out, so its default
 * shows instead; what the schema renamed or moved (`co.ordering.migrate`)
 * is first taken where it went. A list is no theme: `{}`. Given the nodes,
 * it compiles them (without a version 1 map); the theme is not changed.
 */
export function valid(values: ThemeValues | JsonMap | null | undefined, schema: NodeSchema | TupleSchema): JsonMap {
  return validTuples(values ?? {}, tuplesOf(schema))
}

/** valid() over a compiled schema. */
export function validTuples(values: unknown, schema: TupleSchema): JsonMap {
  const copy = clone(values as Json)
  if (!isArray(copy) || (!isEmpty(copy) && isList(copy))) {
    return {}
  }
  const migrate = get(schema, 'migrate')
  let answer = applyMigrations(copy as JsonMap, isArray(migrate) ? migrate : [])
  for (const problem of themeErrors(answer, schema)) {
    const [section, rest] = splitOnce(problem.path)
    if ((section === 'visibility' || section === 'images') && rest !== null) {
      // Their keys hold dots of their own.
      const set = get(answer, section)
      if (isArray(set)) {
        answer[section] = withoutKey(set, rest)
      }
      continue
    }
    const platform = PLATFORM_VISIBILITY.exec(problem.path)
    if (platform !== null) {
      const set = dig(answer, 'platforms', platform[1]!, 'visibility')
      if (isArray(set)) {
        (((answer.platforms as JsonMap)[platform[1]!] as JsonMap).visibility) = withoutKey(set, platform[2]!)
      }
      continue
    }
    answer = forget(answer, problem.path.split('.')) as JsonMap
  }
  return answer
}

/** A path's first segment and the rest (PHP's explode('.', $path, 2)). */
function splitOnce(path: string): [string, string | null] {
  const at = path.indexOf('.')
  return at < 0 ? [path, null] : [path.slice(0, at), path.slice(at + 1)]
}

/** $tree without the value at $path (PHP's unset(), which leaves its parents as they are). */
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
  ;(tree as Record<string, Json>)[segment] = forget(child, rest)
  return tree
}

function tuplesOf(schema: NodeSchema | TupleSchema): TupleSchema {
  return isNodes(schema) ? compile(schema).schema : schema as TupleSchema
}

/**
 * What is wrong with a theme against its schema (ThemeValidator::validate()):
 * each problem `{ path, code }`, the code being the path and the rule:
 * `unknown` (not in the schema), `invalid` (wrong type, format or option),
 * `alias` (an alias to a token that does not exist or is of another kind)
 * or `alpha` (a translucent color where the schema keeps it opaque). Each
 * code once, in the order found.
 */
export function themeErrors(theme: Json, schema: TupleSchema): SchemaError[] {
  if (isArray(theme) && !isEmpty(theme) && isList(theme)) {
    return [{ path: '', code: 'invalid' }]
  }
  const errors: SchemaError[] = []
  for (const [section, value] of entries(theme)) {
    if (!(THEME_SECTIONS as readonly string[]).includes(section)) {
      errors.push(error(section, 'unknown'))
      continue
    }
    if (!isStoredMap(value)) {
      errors.push(error(section, 'invalid'))
      continue
    }
    switch (section) {
      case 'tokens':
        errors.push(...tokenErrors(value, 'tokens'))
        // Of a token the schema narrows, only the options it keeps.
        for (const [token, options] of entries(get(schema, 'token_options'))) {
          const [group, name] = splitOnce(token)
          const set = get(value, group)
          if (isArray(set) && name !== null && has(set, name) && !within(values(options), get(set, name))) {
            errors.push(error(`tokens.${token}`, 'invalid'))
          }
        }
        // Of the groups each schema names itself, only its own.
        for (const group of OWN_GROUPS) {
          const set = get(value, group)
          for (const [name] of entries(isArray(set) ? set : [])) {
            if (tokenNames(group).includes(name) && !has(dig(schema, 'tokens', group) ?? {}, name)) {
              errors.push(error(`tokens.${group}.${name}`, 'unknown'))
            }
          }
        }
        break
      case 'components':
        errors.push(...componentErrors(value, schema, 'components'))
        break
      case 'visibility':
      case 'images':
        errors.push(...keyErrors(value, schema, section, section))
        break
      case 'pages':
        errors.push(...pageErrors(value, schema, 'pages'))
        break
      case 'modes':
        for (const [mode, groups] of entries(value)) {
          if (!(MODES as readonly string[]).includes(mode) || !isArray(groups)) {
            errors.push(error(`modes.${mode}`, 'unknown'))
            continue
          }
          for (const [group, colors] of entries(groups)) {
            if (group !== 'color' || !isArray(colors)) {
              errors.push(error(`modes.${mode}.${group}`, 'unknown'))
              continue
            }
            errors.push(...tokenErrors({ color: colors }, `modes.${mode}`))
          }
        }
        break
      case 'platforms':
        for (const [platform, override] of entries(value)) {
          const platformPath = `platforms.${platform}`
          const known = get(schema, 'platforms')
          if (!within(values(isArray(known) ? known : []), platform) || !isArray(override)
            || entries(override).some(([key]) => !PLATFORM_SECTIONS.includes(key))) {
            errors.push(error(platformPath, 'unknown'))
            continue
          }
          for (const platformSection of PLATFORM_SECTIONS) {
            const set = get(override, platformSection) ?? {}
            if (!isStoredMap(set)) {
              errors.push(error(`${platformPath}.${platformSection}`, 'invalid'))
            } else if (platformSection === 'components') {
              errors.push(...componentErrors(set, schema, `${platformPath}.components`, false))
            } else if (platformSection === 'visibility') {
              // A platform shows or hides what the schema can, by the same keys.
              errors.push(...keyErrors(set, schema, 'visibility', `${platformPath}.visibility`))
            } else {
              errors.push(...pageErrors(set, schema, `${platformPath}.pages`))
            }
          }
        }
        break
    }
  }

  // A theme refers only to the colors, text styles and opacities its schema names.
  const refers = (node: Json, path: string): void => {
    if (isArray(node)) {
      for (const [key, child] of entries(node)) {
        refers(child, path === '' ? key : `${path}.${key}`)
      }
      return
    }
    const parts = alias(node)
    if (parts !== null && (OWN_GROUPS as readonly string[]).includes(parts[0]) && !has(dig(schema, 'tokens', parts[0]) ?? {}, parts[1])) {
      errors.push(error(path, 'alias'))
    }
  }
  const sections: JsonMap = {}
  for (const [section, value] of entries(theme)) {
    if ((THEME_SECTIONS as readonly string[]).includes(section)) {
      sections[section] = value
    }
  }
  refers(sections, '')
  errors.push(...translucentErrors(theme, schema))

  // Once each, though a value's own check may have said it too.
  const seen = new Set<string>()
  return errors.filter((problem) => !seen.has(problem.code) && seen.add(problem.code) !== undefined)
}

/**
 * A site's settings against its schema's `site` block
 * (ThemeValidator::settings()), each problem at `site.<path>`.
 */
export function settingsErrors(settings: Json, schema: TupleSchema): SchemaError[] {
  if (isArray(settings) && !isEmpty(settings) && isList(settings)) {
    return [error('site', 'invalid')]
  }
  const site = get(schema, 'site')
  return treeErrors(settings, isArray(site) ? site : {}, 'site')
}

/** A block of settings in groups, against the schema's. */
function treeErrors(settings: Json, specs: Json, path: string): SchemaError[] {
  const errors: SchemaError[] = []
  for (const [key, value] of entries(settings)) {
    const at = `${path}.${key}`
    const spec = get(specs, key)
    if (!isArray(spec)) {
      errors.push(error(at, 'unknown'))
    } else if (!isList(spec)) {
      errors.push(...(isStoredMap(value) ? treeErrors(value, spec, at) : [error(at, 'invalid')]))
    } else {
      const rule = valueError(phpString(get(spec, 0)), value, get(spec, 2), get(spec, 3))
      if (rule !== null) {
        errors.push(error(at, rule))
      }
    }
  }
  return errors
}

/** A theme's visibility or images (or a platform's visibility): each key the schema's, of its kind. */
function keyErrors(set: Json, schema: TupleSchema, section: string, path: string): SchemaError[] {
  const errors: SchemaError[] = []
  const kind = section === 'visibility' ? 'bool' : 'image'
  for (const [key, item] of entries(set)) {
    if (!has(get(schema, section) ?? {}, key)) {
      errors.push(error(`${path}.${key}`, 'unknown'))
      continue
    }
    const rule = valueError(kind, item)
    if (rule !== null) {
      errors.push(error(`${path}.${key}`, rule))
    }
  }
  return errors
}

/**
 * Component properties (ThemeValidator::components()): the component,
 * section and property exist in the schema (a variant, state, size or
 * shape may also set a property of the component's base), and each value
 * fits the property's kind. A group's scope changes its components the
 * same way, and its slots choose only among what each slot's component
 * has; a platform override sets neither.
 */
function componentErrors(components: Json, schema: TupleSchema, path: string, groups = true): SchemaError[] {
  const errors: SchemaError[] = []
  for (const [component, sections] of entries(components)) {
    const componentPath = `${path}.${component}`
    const spec = dig(schema, 'components', component)
    if (spec === null || !isArray(sections)) {
      errors.push(error(componentPath, 'unknown'))
      continue
    }
    const base = get(spec, 'base') ?? {}

    for (const [section, props] of entries(sections)) {
      const sectionPath = `${componentPath}.${section}`
      const group = groups && (section === 'scope' || section === 'slots') && isArray(get(spec, section))
      if (!isArray(props) || (!group && !COMPONENT_SECTIONS.includes(section))) {
        errors.push(error(sectionPath, 'unknown'))
        continue
      }

      if (section === 'scope') {
        for (const [scoped, changes] of entries(props)) {
          if (!has(get(spec, 'scope'), scoped)) {
            errors.push(error(`${sectionPath}.${scoped}`, 'unknown'))
            continue
          }
          errors.push(...componentErrors({ [scoped]: changes }, schema, sectionPath, false))
        }
        continue
      }
      if (section === 'slots') {
        for (const [name, choices] of entries(props)) {
          const slotPath = `${sectionPath}.${name}`
          const slot = dig(spec, 'slots', name)
          if (!isArray(slot) || !isArray(choices)) {
            errors.push(error(slotPath, 'unknown'))
            continue
          }
          const slotComponent = get(slot, 'component')
          const choosable = dig(schema, 'components', slotComponent === null ? '' : phpString(slotComponent)) ?? {}
          for (const [axis, choice] of entries(choices)) {
            // Its component is the schema's: a theme may repeat it, never change it.
            if (axis === 'component' && identical(choice, slotComponent)) {
              continue
            }
            if (axis === 'component' || !has(slot, axis)) {
              errors.push(error(`${slotPath}.${axis}`, 'unknown'))
            } else if (choice !== null && !(typeof choice === 'string' && has(get(choosable, SLOT_AXES[axis] ?? '') ?? {}, choice))) {
              errors.push(error(`${slotPath}.${axis}`, 'invalid'))
            }
          }
        }
        continue
      }

      if (section === 'base') {
        errors.push(...propErrors(props, base, sectionPath))
        continue
      }
      for (const [name, variant] of entries(props)) {
        const variantPath = `${sectionPath}.${name}`
        const variantSpec = dig(spec, section, name)
        if (variantSpec === null || !isArray(variant)) {
          errors.push(error(variantPath, 'unknown'))
          continue
        }
        const states = get(variant, 'states') ?? {}
        const stateSpecs = get(variantSpec, 'states') ?? {}
        // A color may set any of its roles, declared or not.
        const own: JsonMap = section === 'colors'
          ? union(without(variantSpec, 'states'), Object.fromEntries(ROLES.map((role) => [role, ['color', null]])))
          : union(without(variantSpec, 'states'), base)
        errors.push(...propErrors(without(variant, 'states'), own, variantPath))

        for (const [state, stateProps] of entries((section === 'variants' || section === 'appearances') && isArray(states) ? states : {})) {
          const statePath = `${variantPath}.states.${state}`
          if (!has(stateSpecs, state) || !isArray(stateProps)) {
            errors.push(error(statePath, 'unknown'))
            continue
          }
          errors.push(...propErrors(stateProps, union(get(stateSpecs, state), own), statePath))
        }
      }
    }
  }
  return errors
}

/** PHP's `$left + $right`: the left's pairs, then the right's it does not have. */
function union(left: Json, right: Json): JsonMap {
  const answer: JsonMap = {}
  for (const [key, value] of entries(left)) {
    answer[key] = value
  }
  for (const [key, value] of entries(right)) {
    if (!has(answer, key)) {
      answer[key] = value
    }
  }
  return answer
}

/** The pairs of $map but $key's. */
function without(map: Json, key: string): JsonMap {
  const answer: JsonMap = {}
  for (const [other, value] of entries(map)) {
    if (other !== key) {
      answer[other] = value
    }
  }
  return answer
}

function propErrors(props: Json, specs: Json, path: string): SchemaError[] {
  const errors: SchemaError[] = []
  for (const [prop, value] of entries(props)) {
    const propPath = `${path}.${prop}`
    const spec = get(specs, prop)
    if (spec === null) {
      errors.push(error(propPath, 'unknown'))
      continue
    }
    const kind = phpString(get(spec, 0))
    // A color without a default of its own, as its default says.
    if (value === null && get(spec, 1) === null && (kind === 'color' || kind === 'role')) {
      continue
    }
    const rule = valueError(kind, value, get(spec, 2), get(spec, 3))
    if (rule !== null) {
      errors.push(error(propPath, rule))
    }
  }
  return errors
}

/**
 * What is wrong with a theme's pages (ThemePages::validate()), each problem
 * at its path: an unknown page or field, a footer that is not a bool or
 * null, sections that are not `[{ key, visible }]`, a section the page does
 * not have or repeated, or one hidden that cannot be.
 */
export function pageErrors(pages: Json, schema: TupleSchema, path: string): SchemaError[] {
  const errors: SchemaError[] = []
  const fail = (at: string, rule: string): void => {
    errors.push({ path: at, code: `${at}.${rule}` })
  }
  for (const [page, value] of entries(pages)) {
    const pagePath = `${path}.${page}`
    const spec = dig(schema, 'pages', page)
    if (!isArray(spec) || !isStoredMap(value)) {
      fail(pagePath, 'unknown')
      continue
    }
    for (const [field, item] of entries(value)) {
      if (field === 'footer') {
        if (!(item === null || typeof item === 'boolean')) {
          fail(`${pagePath}.footer`, 'invalid')
        }
      } else if (field === 'sections') {
        if (!isArray(item) || !isList(item)) {
          fail(`${pagePath}.sections`, 'invalid')
          continue
        }
        // array_column($sections, null, 'key'): the last of a key wins.
        const defaults = new Map<string, JsonMap>()
        for (const section of defaultSections(spec)) {
          defaults.set(section.key as string, section)
        }
        const seen = new Set<string>()
        entries(item).forEach(([index, section]) => {
          const sectionPath = `${pagePath}.sections.${index}`
          const key = get(section, 'key')
          const visible = get(section, 'visible')
          if (!isArray(section) || count(section) !== 2 || typeof key !== 'string' || typeof visible !== 'boolean') {
            fail(sectionPath, 'invalid')
          } else if (!defaults.has(key)) {
            fail(sectionPath, 'unknown')
          } else if (seen.has(key)) {
            fail(sectionPath, 'duplicate')
          } else if (!visible && (get(defaults.get(key), 'hideable') ?? true) === false) {
            fail(sectionPath, 'invalid')
          }
          if (isArray(section) && typeof key === 'string') {
            seen.add(key)
          }
        })
      } else {
        fail(`${pagePath}.${field}`, 'unknown')
      }
    }
  }
  return errors
}

/**
 * The colors a theme makes translucent where its schema keeps them opaque
 * (ThemeValidator::translucent()): a node the editor does not mark `alpha`
 * takes no `transparent`, `#RGBA`, `#RRGGBBAA` or `rgba()` below full
 * alpha, nor an alias to a color that is one (as the theme sets it, else as
 * the schema). The node that declares a property decides: a variant's own,
 * else its base's. A schema that marks none is not checked.
 */
function translucentErrors(theme: Json, schema: TupleSchema): SchemaError[] {
  const marked = get(schema, 'alpha')
  if (!isArray(marked)) {
    return []
  }
  const alpha = new Set(values(marked).map(phpString))
  const themeColors = dig(theme, 'tokens', 'color')
  const schemaColors = dig(schema, 'tokens', 'color')
  const colors = union(isArray(themeColors) ? themeColors : {}, isArray(schemaColors) ? schemaColors : {})
  const errors: SchemaError[] = []

  const check = (value: Json, path: string, candidates: string[]): void => {
    let node = candidates[0]!
    for (const candidate of candidates) {
      if (specAt(schema, candidate) !== null) {
        node = candidate
        break
      }
    }
    const spec = specAt(schema, node)
    if (spec !== null && !['color', 'role', 'border'].includes(phpString(spec[0]))) {
      return
    }
    if (isArray(value)) {
      // A border with no width shows no color.
      const width = get(value, 'width')
      if (has(value, 'color') && !(identical(width, 0) || width === 'none')) {
        check(get(value, 'color'), `${path}.color`, [node])
      }
      return
    }
    const parts = alias(value)
    const color = parts !== null && parts[0] === 'color' ? get(colors, parts[1]) : value
    if (isTranslucent(color) && !alpha.has(node)) {
      errors.push(error(path, 'alpha'))
    }
  }

  // A component's values, by the nodes that may declare each: $at gives,
  // for a path under the component, its candidates.
  const component = (sections: Json, path: string, at: (rest: string) => string[]): void => {
    for (const [section, props] of entries(sections)) {
      if (!isArray(props) || section === 'slots') {
        continue
      }
      if (section === 'scope') {
        for (const [scoped, changes] of entries(props)) {
          if (isArray(changes)) {
            component(changes, `${path}.scope.${scoped}`, (rest) => [...at(`scope.${scoped}.${rest}`), `components.${scoped}.${rest}`])
          }
        }
        continue
      }
      if (section === 'base') {
        for (const [prop, value] of entries(props)) {
          check(value, `${path}.base.${prop}`, at(`base.${prop}`))
        }
        continue
      }
      for (const [name, variant] of entries(props)) {
        if (!isArray(variant)) {
          continue
        }
        for (const [prop, value] of entries(variant)) {
          if (prop === 'states' && isArray(value)) {
            for (const [state, stateProps] of entries(value)) {
              for (const [stateProp, stateValue] of entries(isArray(stateProps) ? stateProps : {})) {
                check(stateValue, `${path}.${section}.${name}.states.${state}.${stateProp}`,
                  [...at(`${section}.${name}.states.${state}.${stateProp}`), ...at(`${section}.${name}.${stateProp}`), ...at(`base.${stateProp}`)])
              }
            }
            continue
          }
          check(value, `${path}.${section}.${name}.${prop}`,
            section === 'colors' ? at(`colors.${name}.${prop}`) : [...at(`${section}.${name}.${prop}`), ...at(`base.${prop}`)])
        }
      }
    }
  }

  for (const [name, value] of entries(isArray(themeColors) ? themeColors : {})) {
    check(value, `tokens.color.${name}`, [`tokens.color.${name}`])
  }
  for (const [mode, groups] of entries(get(theme, 'modes'))) {
    const modeColors = get(groups, 'color')
    for (const [name, value] of entries(isArray(modeColors) ? modeColors : {})) {
      check(value, `modes.${mode}.color.${name}`, [`tokens.color.${name}`])
    }
  }
  const components = get(theme, 'components')
  for (const [name, sections] of entries(isArray(components) ? components : {})) {
    if (isArray(sections)) {
      component(sections, `components.${name}`, (rest) => [`components.${name}.${rest}`])
    }
  }
  const platforms = get(theme, 'platforms')
  for (const [platform, override] of entries(isArray(platforms) ? platforms : {})) {
    const overridden = get(override, 'components')
    for (const [name, sections] of entries(isArray(overridden) ? overridden : {})) {
      if (isArray(sections)) {
        component(sections, `platforms.${platform}.components.${name}`, (rest) => rest.startsWith('base.')
          ? [`components.${name}.platforms.${platform}.${rest.slice(5)}`, `components.${name}.${rest}`]
          : [`components.${name}.${rest}`])
      }
    }
  }
  return errors
}

