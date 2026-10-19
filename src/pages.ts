import { clone, dig, get, has, isArray, values, type Json, type JsonMap } from './php.js'
import type { TupleSchema } from './types.js'

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
