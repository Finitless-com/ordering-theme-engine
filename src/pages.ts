import { clone, count, dig, entries, get, has, isArray, isEmpty, isList, values, type Json, type JsonMap } from './php.js'
import type { SchemaError, TupleSchema } from './types.js'

/**
 * The pages of a schema (ThemePages): whether each one shows the footer and
 * which of its sections it paints, in what order.
 *
 * - `footer` null follows `visibility.footer`;
 * - a section moves only among those of its `group`; with null it stays in
 *   its place;
 * - `hideable` false: always visible.
 */

export interface Section {
  key: string
  visible: boolean
}

/** Every page of the schema with its defaults. */
export function pageDefaults(schema: TupleSchema): JsonMap {
  const pages: JsonMap = {}
  for (const [page, spec] of Object.entries(isArray(schema.pages) ? schema.pages : {})) {
    pages[page] = {
      footer: clone(dig(spec, 'footer', 1)),
      sections: order(defaultSections(spec), []) as unknown as Json,
    }
  }
  return pages
}

/**
 * Each page's sections, complete and in the order to paint them (order());
 * the rest of each page as it is.
 */
export function resolvePageSections(pages: JsonMap, schema: TupleSchema): JsonMap {
  const answer = clone(pages)
  for (const [page, value] of Object.entries(answer)) {
    const spec = dig(schema, 'pages', page)
    if (isArray(spec) && isArray(value) && has(value, 'sections')) {
      const themed = get(value, 'sections')
      ;(value as JsonMap).sections = order(defaultSections(spec), isArray(themed) ? values(themed) : []) as unknown as Json
    }
  }
  return answer
}

/**
 * A theme's sections as the page paints them: each group fills the places
 * its sections have in the default list, in the theme's order, and a
 * section without a group keeps its place. An unknown or repeated key is
 * dropped (the first counts), and a missing one keeps its default place in
 * its group and its default visibility. A section that cannot be hidden is
 * always visible.
 */
export function order(defaults: JsonMap[], themed: Json[]): Section[] {
  // array_column($sections, null, 'key'): the last of a key wins.
  const byKey = new Map<string, JsonMap>()
  for (const section of defaults) {
    byKey.set(section.key as string, section)
  }
  const ordered: string[] = []
  const visible = new Map<string, boolean | null>()
  for (const item of themed) {
    const key = isArray(item) ? get(item, 'key') : null
    if (typeof key !== 'string' || !byKey.has(key) || visible.has(key)) {
      continue
    }
    ordered.push(key)
    const shown = get(item, 'visible')
    visible.set(key, typeof shown === 'boolean' ? shown : null)
  }

  const queues = new Map<Json, string[]>()
  for (const section of defaults) {
    const group = get(section, 'group')
    if (group === null || queues.has(group)) {
      continue
    }
    const inGroup = defaults.filter((other) => get(other, 'group') === group).map((other) => other.key as string)
    const queue = ordered.filter((key) => get(byKey.get(key), 'group') === group)
    inGroup.forEach((key, index) => {
      if (!queue.includes(key)) {
        queue.splice(index, 0, key)
      }
    })
    queues.set(group, queue)
  }

  return defaults.map((original) => {
    const group = get(original, 'group')
    const section = group === null ? original : byKey.get(queues.get(group)!.shift()!)!
    const key = section.key as string
    return {
      key,
      visible: !hideable(section) || (visible.get(key) ?? get(section, 'visible') !== false),
    }
  })
}

/** A page's sections in the schema, in their default order. */
export function defaultSections(spec: Json): JsonMap[] {
  const sections = dig(spec, 'sections', 1)
  return values(sections).filter((section): section is JsonMap => isArray(section) && !Array.isArray(section) && typeof get(section, 'key') === 'string')
}

function hideable(section: JsonMap): boolean {
  return (get(section, 'hideable') ?? true) !== false
}

const NAME = /^[a-z0-9_]+\n?$/

const V1_PATH = /^[a-z0-9_]+(\.[a-z0-9_]+)*\.hidden\n?$/

const SECTION_FIELDS = ['key', 'visible', 'hideable', 'group', 'v1', 'v1_default']

/**
 * What a compiled schema's pages get wrong (ThemePages::check()). A
 * section's `v1` must not be another control's: whichever is projected last
 * would win.
 */
export function checkPages(schema: TupleSchema): SchemaError[] {
  const errors: SchemaError[] = []
  const fail = (path: string, rule: string): void => {
    errors.push({ path, code: `${path}.${rule}` })
  }

  const pages = get(schema, 'pages') ?? []
  if (!isArray(pages) || (!isEmpty(pages) && isList(pages))) {
    fail('pages', 'invalid')
    return errors
  }

  const v1Paths = new Set<string>()
  for (const entry of values(get(schema, 'visibility'))) {
    const path = get(entry, 1)
    if (typeof path === 'string') {
      v1Paths.add(path)
    }
  }

  for (const [page, spec] of entries(pages)) {
    const path = `pages.${page}`
    if (!NAME.test(page) || !isArray(spec) || (!isEmpty(spec) && isList(spec))) {
      fail(path, 'invalid')
      continue
    }
    for (const [field] of entries(spec)) {
      if (field !== 'footer' && field !== 'sections') {
        fail(`${path}.${field}`, 'unknown')
      }
    }

    const footer = get(spec, 'footer')
    const shown = get(footer, 1)
    if (!isArray(footer) || !isList(footer) || count(footer) !== 2 || get(footer, 0) !== 'bool' || !(shown === null || typeof shown === 'boolean')) {
      fail(`${path}.footer`, 'invalid')
    }

    const sections = get(spec, 'sections')
    const list = get(sections, 1)
    if (!isArray(sections) || !isList(sections) || count(sections) !== 2 || get(sections, 0) !== 'sections' || !isArray(list) || !isList(list)) {
      fail(`${path}.sections`, 'invalid')
      continue
    }
    const keys = new Set<string>()
    for (const [index, section] of entries(list)) {
      const own = get(section, 'key')
      const key = isArray(section) && typeof own === 'string' ? own : index
      const sectionPath = `${path}.sections.${key}`
      const visible = get(section, 'visible')
      const hideable = get(section, 'hideable')
      const group = get(section, 'group')
      if (!isArray(section) || !NAME.test(key)
        || !entries(section).every(([field]) => SECTION_FIELDS.includes(field))
        || (has(section, 'v1_default') && typeof get(section, 'v1_default') !== 'boolean')
        || typeof visible !== 'boolean' || typeof hideable !== 'boolean'
        || !has(section, 'group') || !(group === null || (typeof group === 'string' && NAME.test(group)))
        || (!hideable && !visible)) {
        fail(sectionPath, 'invalid')
        continue
      }
      if (keys.has(key)) {
        fail(sectionPath, 'duplicate')
      }
      keys.add(key)

      if (has(section, 'v1')) {
        const v1 = get(section, 'v1')
        if (typeof v1 !== 'string' || !V1_PATH.test(v1)) {
          fail(`${sectionPath}.v1`, 'invalid')
        } else if (v1Paths.has(v1)) {
          fail(`${sectionPath}.v1`, 'duplicate')
        } else {
          v1Paths.add(v1)
        }
      }
    }
  }

  return errors
}
