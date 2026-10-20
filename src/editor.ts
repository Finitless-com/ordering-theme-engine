import { EDITOR, EXTENSION } from './compile.js'
import { dig, entries, get, has, identical, isArray, values, type Json } from './php.js'
import type { NodeSchema, SchemaError } from './types.js'

type Fail = (path: string, rule: string) => void

/** The blocks of nodes the editor shows with their own control. */
const BLOCKS = ['tokens', 'components', 'pages', 'site']

const ALIAS = /^\{([a-z_]+)\.([a-z0-9_]+)\}$/

const SNAKE_CASE = /^[a-z][a-z0-9_]*$/

/** The range a typography's `line` takes: a ratio of its size (ThemeFormat). */
const LINE_RATIO = [0.8, 3] as const

/**
 * What a schema's editor metadata gets wrong (`co.ordering.editor` and the
 * root `editor`, docs/schema-format.md §12), which the compiler drops and
 * check() reads here, given the nodes (EditorRules::check()). The
 * meta-schema checks its shape; these, what it cannot: the colors that need
 * `alpha`, the platforms only a visibility key says, the pages and
 * components a visibility group is shown in, the order of the rows and
 * cards, the custom typographies, the limits of the number controls, the
 * line heights' fields, the scales, the blocks of the pages, the fonts'
 * weights, the text controls, the groups of a page's sections and the type
 * of the visibility keys. What check() already refuses is not repeated.
 * Each error once, in that order.
 */
export function checkEditor(schema: NodeSchema): SchemaError[] {
  const errors: SchemaError[] = []
  const seen = new Set<string>()
  const fail: Fail = (path, rule) => {
    const code = `${path}.${rule}`
    if (!seen.has(code)) {
      seen.add(code)
      errors.push({ path, code })
    }
  }
  alpha(schema, fail)
  platforms(schema, fail)
  editorPages(schema, fail)
  editorComponents(schema, fail)
  componentOrder(schema, fail)
  rowOrder(schema, fail)
  custom(schema, fail)
  limits(schema, fail)
  lines(schema, fail)
  ordered(schema, fail)
  sectionGroups(schema, fail)
  fonts(schema, fail)
  texts(schema, fail)
  pageSections(schema, fail)
  visibilityTypes(schema, fail)
  return errors
}

/**
 * A color node (its own `$type` or its group's) whose default is
 * translucent, or an alias to a token marked `alpha` or translucent, says
 * `alpha: true`; nothing else says it.
 */
function alpha(schema: NodeSchema, fail: Fail): void {
  const tokens = get(schema, 'tokens')
  const marked = (node: Json): boolean => get(editorOf(node), 'alpha') === true
  const refers = (value: Json): boolean => {
    const match = typeof value === 'string' ? ALIAS.exec(value) : null
    const token = match === null ? null : dig(tokens, match[1]!, match[2]!)
    return isArray(token) && (marked(token) || translucent(get(token, '$value')))
  }
  const walk = (group: Json, path: string, type: Json): void => {
    const own = get(group, '$type') ?? type
    const said = has(editorOf(group), 'alpha')
    if (has(group, '$value')) {
      if (own !== 'color') {
        if (said) {
          fail(path, 'alpha_not_color')
        }
      } else if (!marked(group) && (translucent(get(group, '$value')) || refers(get(group, '$value')))) {
        fail(path, 'alpha_required')
      }
      return
    }
    if (said) {
      fail(path, 'alpha_not_color')
    }
    for (const [key, child] of children(group)) {
      walk(child, `${path}.${key}`, own)
    }
  }
  for (const block of BLOCKS) {
    if (isArray(get(schema, block))) {
      walk(get(schema, block), block, null)
    }
  }
}

/**
 * Whether a color is translucent: `transparent`, a #RGBA or #RRGGBBAA whose
 * alpha is below its maximum, or rgb()/rgba()/hsl()/hsla() with an alpha
 * (`, a` or `/ a`, a number or a percentage) below 1. An alias is not.
 */
export function translucent(value: Json): boolean {
  if (typeof value !== 'string') {
    return false
  }
  const color = phpTrim(value).replace(/[A-Z]/g, (letter) => letter.toLowerCase())
  if (color === 'transparent') {
    return true
  }
  const hex = /^#[0-9a-f]{3}([0-9a-f])$/.exec(color) ?? /^#[0-9a-f]{6}([0-9a-f]{2})$/.exec(color)
  if (hex !== null) {
    return parseInt(hex[1]!, 16) < (hex[1]!.length === 1 ? 0xf : 0xff)
  }
  const call = /^(?:rgb|hsl)a?\((.*)\)$/.exec(color)
  if (call === null) {
    return false
  }
  const inside = call[1]!
  const amount = inside.includes('/') ? inside.split('/')[1] : inside.split(',')[3]
  if (amount === undefined) {
    return false
  }
  const number = /^[ \t\n\r\f\x0B]*([+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?)/.exec(amount)
  if (number === null) {
    return false
  }
  const ratio = Number(number[1]) / (phpTrim(amount).endsWith('%') ? 100 : 1)
  return Number.isFinite(ratio) && ratio < 1
}

/**
 * `co.ordering.platforms` only on a visibility key: what it says there is
 * check()'s (`visibility.<key>.platforms.invalid`).
 */
function platforms(schema: NodeSchema, fail: Fail): void {
  const walk = (group: Json, path: string, visibility: boolean): void => {
    const own = dig(group, '$extensions', EXTENSION)
    if (isArray(own) && has(own, 'platforms') && (!visibility || !has(group, '$value'))) {
      fail(path, 'platforms_not_visibility')
    }
    if (has(group, '$value')) {
      return
    }
    for (const [key, child] of children(group)) {
      walk(child, `${path}.${key}`, visibility)
    }
  }
  for (const block of ['tokens', 'components', 'visibility', 'images', 'pages', 'site']) {
    if (isArray(get(schema, block))) {
      walk(get(schema, block), block, block === 'visibility')
    }
  }
}

/**
 * A group of the visibility section may say the pages it is shown in,
 * pages of the schema, and no page gets two groups of the same key; no
 * other section's group says them. Every visibility key is in exactly one
 * group of the editor (a row, `paths`, or a card's switch, `toggle`), and
 * the groups list only visibility keys that exist.
 */
function editorPages(schema: NodeSchema, fail: Fail): void {
  const pages = entries(get(schema, 'pages')).map(([key]) => key).filter((key) => !key.startsWith('$'))
  const keys: string[] = []
  const collect = (group: Json, path: string): void => {
    if (has(group, '$value')) {
      keys.push(path)
      return
    }
    for (const [key, child] of children(group)) {
      collect(child, `${path}.${key}`)
    }
  }
  if (isArray(get(schema, 'visibility'))) {
    collect(get(schema, 'visibility'), 'visibility')
  }

  const listed = new Map<string, number>()
  const groupsOf = new Map<string, Json[]>()
  for (const [s, section, g, group] of layoutGroups(schema)) {
    const path = `editor.sections.${s}.groups.${g}`
    const toggle = dig(group, 'toggle', 'path')
    for (const key of [...values(get(group, 'paths')), ...(typeof toggle === 'string' ? [toggle] : [])]) {
      if (typeof key === 'string' && (key === 'visibility' || key.startsWith('visibility.'))) {
        listed.set(key, (listed.get(key) ?? 0) + 1)
      }
    }
    if (!has(group, 'pages')) {
      continue
    }
    if (get(section, 'key') !== 'visibility') {
      fail(path, 'pages_not_visibility')
      continue
    }
    // Its shape (a list of names, at least one, without repeats) is the meta-schema's.
    for (const page of unique(values(get(group, 'pages')))) {
      if (typeof page !== 'string' || !pages.includes(page)) {
        fail(path, 'page_unknown')
        continue
      }
      const owned = groupsOf.get(page) ?? []
      if (owned.some((key) => identical(key, get(group, 'key')))) {
        fail(path, 'page_group_repeated')
      }
      groupsOf.set(page, [...owned, get(group, 'key')])
    }
  }
  for (const key of keys) {
    const times = listed.get(key) ?? 0
    if (times !== 1) {
      fail(key, times === 0 ? 'in_no_editor_group' : 'in_several_editor_groups')
    }
  }
  for (const key of listed.keys()) {
    if (!keys.includes(key)) {
      fail(key, 'not_visibility_key')
    }
  }
}

/**
 * Like `pages`, a group of the visibility section may say the components
 * whose panel shows it (never with `pages` too): components of the schema,
 * and no component gets two groups of the same key. A component's own
 * cards then list none of its paths, unless as a card's switch.
 */
function editorComponents(schema: NodeSchema, fail: Fail): void {
  const components = get(schema, 'components')
  const keysOf = new Map<string, Json[]>()
  const shown = new Set<string>()
  for (const [s, section, g, group] of layoutGroups(schema)) {
    if (!has(group, 'components')) {
      continue
    }
    const path = `editor.sections.${s}.groups.${g}`
    if (get(section, 'key') !== 'visibility') {
      fail(path, 'components_not_visibility')
      continue
    }
    if (has(group, 'pages')) {
      fail(path, 'pages_and_components')
    }
    for (const name of unique(values(get(group, 'components')))) {
      if (typeof name !== 'string' || name.startsWith('$') || !isArray(get(components, name))) {
        fail(path, 'component_unknown')
        continue
      }
      const owned = keysOf.get(name) ?? []
      if (owned.some((key) => identical(key, get(group, 'key')))) {
        fail(path, 'component_group_repeated')
      }
      keysOf.set(name, [...owned, get(group, 'key')])
      for (const key of values(get(group, 'paths'))) {
        if (typeof key === 'string') {
          shown.add(`${name} ${key}`)
        }
      }
    }
  }
  for (const [name, component] of entries(components)) {
    for (const [c, card] of entries(get(editorOf(component), 'groups'))) {
      for (const key of values(get(card, 'paths'))) {
        if (typeof key === 'string' && shown.has(`${name} ${key}`) && dig(card, 'toggle', 'path') !== key) {
          fail(`components.${name}.$extensions.${EDITOR}.groups.${c}`, 'shown_by_visibility_group')
        }
      }
    }
  }
}

/**
 * The order of a component's props (its `scope` aside): the nodes of one
 * group of the schema that share a card (`group`, or none: the rows) have
 * different `order`s, and in a component with cards every node has one.
 * Each card lists paths of the schema, its component's nodes by their
 * order; a base node it lists says its key as `group`, and a base node that
 * says a `group` is listed by a card.
 */
function componentOrder(schema: NodeSchema, fail: Fail): void {
  for (const [name, component] of entries(get(schema, 'components'))) {
    if (!isArray(component) || name.startsWith('$')) {
      continue
    }
    const root = `components.${name}`
    const cards = entries(get(editorOf(component), 'groups'))
    const walk = (group: Json, path: string): void => {
      const slots = new Map<string, string>()
      for (const [key, child] of children(group)) {
        if (!has(child, '$value')) {
          if (key !== 'scope' || path !== root) {
            walk(child, `${path}.${key}`)
          }
          continue
        }
        const editor = editorOf(child)
        if (!has(editor, 'order')) {
          if (cards.length > 0) {
            fail(`${path}.${key}`, 'order_missing')
          }
          continue
        }
        const slot = JSON.stringify([get(editor, 'group'), get(editor, 'order')])
        if (slots.has(slot)) {
          fail(`${path}.${key}`, 'order_repeated')
        } else {
          slots.set(slot, key)
        }
      }
    }
    walk(component, root)

    const listed = new Set<string>()
    for (const [c, card] of cards) {
      const path = `${root}.$extensions.${EDITOR}.groups.${c}`
      let last = -Infinity
      for (const item of values(get(card, 'paths'))) {
        const [found, node] = at(schema, item)
        if (!found) {
          fail(path, 'path_unknown')
          continue
        }
        if (typeof item !== 'string' || !item.startsWith(`${root}.`) || !has(node, '$value')) {
          continue
        }
        listed.add(item)
        const order = get(editorOf(node), 'order')
        if (typeof order === 'number') {
          if (order < last) {
            fail(path, 'order_decreasing')
          }
          last = Math.max(last, order)
        }
        if (item.startsWith(`${root}.base.`) && !identical(get(editorOf(node), 'group'), get(card, 'key'))) {
          fail(item, 'group_mismatch')
        }
      }
    }
    for (const [key, node] of entries(get(component, 'base'))) {
      const path = `${root}.base.${key}`
      if (has(node, '$value') && has(editorOf(node), 'group') && !listed.has(path)) {
        fail(path, 'in_no_card')
      }
    }
  }
}

/**
 * The rows of a group of the editor's sections: the paths that say an
 * `order` say it in the order they are listed, from 1, each the one before
 * (a pair shares its row) or the next, and the `group` they say is the
 * group's key.
 */
function rowOrder(schema: NodeSchema, fail: Fail): void {
  for (const [s, , g, group] of layoutGroups(schema)) {
    const path = `editor.sections.${s}.groups.${g}`
    let last = 0
    for (const item of values(get(group, 'paths'))) {
      const editor = dig(at(schema, item)[1], '$extensions', EDITOR)
      const order = get(editor, 'order')
      if (!isArray(editor) || typeof order !== 'number') {
        continue
      }
      if (order !== last && order !== last + 1) {
        fail(path, 'order_not_consecutive')
      }
      last = Math.max(last, order)
      if (has(editor, 'group') && !identical(get(editor, 'group'), get(group, 'key'))) {
        fail(item as string, 'group_mismatch')
      }
    }
  }
}

/**
 * `custom` only on a typography node chosen by level, and every
 * component's typography chosen by level says `custom: true`. `custom:
 * true` on another type of property is check()'s `<path>.rules.invalid`.
 */
function custom(schema: NodeSchema, fail: Fail): void {
  const walk = (group: Json, path: string, type: Json, block: string): void => {
    const own = get(group, '$type') ?? type
    const editor = editorOf(group)
    const said = has(editor, 'custom')
    if (!has(group, '$value')) {
      if (said) {
        fail(path, 'custom_not_level')
      }
      for (const [key, child] of children(group)) {
        walk(child, `${path}.${key}`, own, block)
      }
      return
    }
    const level = own === 'typography' && get(editor, 'control') === 'level'
    if (said && !level) {
      if (!(get(editor, 'custom') === true && property(path, own))) {
        fail(path, 'custom_not_level')
      }
    } else if (level && block === 'components' && get(editor, 'custom') !== true) {
      fail(path, 'custom_required')
    }
  }
  for (const block of BLOCKS) {
    if (isArray(get(schema, block))) {
      walk(get(schema, block), block, null, block)
    }
  }
}

/**
 * The limits of the number controls. A field of a composite default (a
 * typography style's) has its `min` at most its `max`, and the default's
 * number within them. A `range`, and a `level` that takes a custom number
 * (it says a `unit`), says a `min` at most its `max`; a default outside
 * them is outOfRange()'s.
 */
function limits(schema: NodeSchema, fail: Fail): void {
  const walk = (group: Json, path: string): void => {
    if (!has(group, '$value')) {
      for (const [key, child] of children(group)) {
        walk(child, `${path}.${key}`)
      }
      return
    }
    const editor = editorOf(group)
    const value = get(group, '$value')
    if (isArray(value)) {
      for (const [name, field] of entries(get(editor, 'fields'))) {
        const number = get(value, name)
        if (!isArray(field) || typeof number !== 'number') {
          continue
        }
        const min = get(field, 'min')
        const max = get(field, 'max')
        if (typeof min === 'number' && typeof max === 'number' && min > max) {
          fail(`${path}.fields.${name}`, 'min_over_max')
        }
        if ((typeof min === 'number' && number < min) || (typeof max === 'number' && number > max)) {
          fail(`${path}.fields.${name}`, 'default_out_of_range')
        }
      }
    }
    const control = get(editor, 'control')
    const unit = get(editor, 'unit')
    if (control !== 'range' && !(control === 'level' && typeof unit === 'string' && unit !== '')) {
      return
    }
    const min = get(editor, 'min')
    const max = get(editor, 'max')
    if (typeof min !== 'number' || typeof max !== 'number') {
      fail(path, 'limits_required')
    } else if (min > max) {
      fail(path, 'min_over_max')
    }
  }
  for (const block of BLOCKS) {
    if (isArray(get(schema, block))) {
      walk(get(schema, block), block)
    }
  }
}

/**
 * The `line` field of a typography's editor (a level's, the group's, a
 * component's) is a ratio of the size: unit `x`, its `min` and `max` within
 * 0.8 and 3. The typography group's fields, the custom typography's, are
 * at least as wide as each level's. A default's `line` is ThemeFormat's.
 */
function lines(schema: NodeSchema, fail: Fail): void {
  const [low, high] = LINE_RATIO
  const ratio = (value: Json): boolean => typeof value === 'number' && Number.isFinite(value) && value >= low && value <= high
  const walk = (group: Json, path: string, type: Json): void => {
    const own = get(group, '$type') ?? type
    const field = dig(editorOf(group), 'fields', 'line')
    if (own === 'typography' && isArray(field)) {
      if (get(field, 'unit') !== 'x') {
        fail(`${path}.fields.line`, 'unit_not_x')
      }
      if (!ratio(get(field, 'min')) || !ratio(get(field, 'max'))) {
        fail(`${path}.fields.line`, 'limits_not_ratio')
      }
    }
    if (has(group, '$value')) {
      return
    }
    for (const [key, child] of children(group)) {
      walk(child, `${path}.${key}`, own)
    }
  }
  for (const block of BLOCKS) {
    if (isArray(get(schema, block))) {
      walk(get(schema, block), block, null)
    }
  }

  const typography = dig(schema, 'tokens', 'typography')
  const widest = get(editorOf(typography), 'fields')
  if (!isArray(typography) || !isArray(widest)) {
    return
  }
  for (const [level, node] of entries(typography)) {
    if (level.startsWith('$') || !isArray(node)) {
      continue
    }
    for (const [name, field] of entries(get(editorOf(node), 'fields'))) {
      const own = get(widest, name)
      if (!isArray(field) || !isArray(own)) {
        continue
      }
      const [min, max, ownMin, ownMax] = [get(field, 'min'), get(field, 'max'), get(own, 'min'), get(own, 'max')]
      if (typeof min === 'number' && typeof ownMin === 'number' && ownMin > min) {
        fail(`tokens.typography.fields.${name}`, 'min_not_widest')
      }
      if (typeof max === 'number' && typeof ownMax === 'number' && ownMax < max) {
        fail(`tokens.typography.fields.${name}`, 'max_not_widest')
      }
    }
  }
}

/**
 * A group of the editor (a section's or a component's card) that says
 * `ordered` lists nodes whose defaults (a number, or a typography's size)
 * are each at least (`ascending`) or at most (`descending`) the one before.
 */
function ordered(schema: NodeSchema, fail: Fail): void {
  const groups: Array<[string, Json]> = layoutGroups(schema).map(([s, , g, group]) => [`editor.sections.${s}.groups.${g}`, group])
  for (const [name, component] of entries(get(schema, 'components'))) {
    for (const [c, card] of entries(get(editorOf(component), 'groups'))) {
      if (isArray(card)) {
        groups.push([`components.${name}.$extensions.${EDITOR}.groups.${c}`, card])
      }
    }
  }
  for (const [path, group] of groups) {
    if (!has(group, 'ordered')) {
      continue
    }
    const ascending = get(group, 'ordered') === 'ascending'
    let previous: number | null = null
    for (const item of values(get(group, 'paths'))) {
      const [found, node] = at(schema, item)
      if (!found || !has(node, '$value')) {
        fail(path, 'ordered_not_node')
        continue
      }
      const own = get(node, '$value')
      const value = typeof own === 'number' ? own : get(own, 'size')
      if (typeof value !== 'number') {
        fail(path, 'ordered_not_number')
        continue
      }
      if (previous !== null && (ascending ? value < previous : value > previous)) {
        fail(path, 'not_ordered')
      }
      previous = value
    }
  }
}

/**
 * `section_groups` only on a sections node, and one whose sections move in
 * blocks (their `group`) has it: one entry per block, in the order the
 * blocks first come in its default list, and none else.
 */
function sectionGroups(schema: NodeSchema, fail: Fail): void {
  for (const [path, node, type] of nodesOf(schema)) {
    const editor = editorOf(node)
    const said = has(editor, 'section_groups')
    if (type !== 'sections') {
      if (said) {
        fail(path, 'section_groups_not_sections')
      }
      continue
    }
    const used: Json[] = []
    for (const section of values(get(node, '$value'))) {
      const group = get(section, 'group')
      if (group !== null && !used.some((other) => identical(other, group))) {
        used.push(group)
      }
    }
    if (!said) {
      if (used.length > 0) {
        fail(path, 'section_groups_required')
      }
      continue
    }
    const keys = values(get(editor, 'section_groups')).map((item) => get(item, 'key'))
    if (unique(keys).length !== keys.length) {
      fail(path, 'section_groups_repeated')
    }
    if (!identical(keys, used)) {
      fail(path, 'section_groups_mismatch')
    }
  }
}

/**
 * `weights` only on an option of a font control. A font control that
 * lists options lists each font of the node's options once, and nothing
 * else, each with its weights going up.
 */
function fonts(schema: NodeSchema, fail: Fail): void {
  for (const [path, node] of nodesOf(schema)) {
    const editor = editorOf(node)
    const options = values(get(editor, 'options'))
    if (get(editor, 'control') !== 'font') {
      if (options.some((option) => has(option, 'weights'))) {
        fail(path, 'weights_not_font')
      }
      continue
    }
    if (options.length === 0) {
      continue
    }
    const known = values(dig(node, '$extensions', EXTENSION, 'options'))
    const listed = options.map((option) => get(option, 'value'))
    const among = (list: Json[], value: Json): boolean => list.some((item) => identical(item, value))
    if (unique(listed).length !== listed.length) {
      fail(path, 'font_listed_twice')
    }
    if (listed.some((value) => !among(known, value))) {
      fail(path, 'font_unknown')
    }
    if (known.some((font) => !among(listed, font))) {
      fail(path, 'font_unlisted')
    }
    for (const option of options) {
      const weights = values(get(option, 'weights'))
      if (weights.some((weight, index) => {
        const before = weights[index - 1]
        return index > 0 && typeof weight === 'number' && typeof before === 'number' && weight <= before
      })) {
        fail(path, 'weights_not_increasing')
      }
    }
  }
}

/**
 * `max_length` and `pattern` only on a string node, and `placeholder` and
 * `multiline` only on a text control. On a property, a rule of another
 * type, a pattern outside the subset (src/pattern.ts) and a default either
 * refuses are check()'s (`.rules.invalid`, `.default.invalid`).
 */
function texts(schema: NodeSchema, fail: Fail): void {
  for (const [path, node, type] of nodesOf(schema)) {
    const engine = dig(node, '$extensions', EXTENSION)
    const editor = editorOf(node)
    if ((has(engine, 'max_length') || has(engine, 'pattern')) && type !== 'string' && !property(path, type)) {
      fail(path, 'rules_not_string')
    }
    for (const key of ['placeholder', 'multiline']) {
      if (has(editor, key) && get(editor, 'control') !== 'text') {
        fail(path, `${key}_not_text`)
      }
    }
  }
}

/**
 * A group of a page's sections holds at least two (one alone cannot move),
 * and a section's key and group start with a letter. The rest of their
 * shape is the meta-schema's and ThemePages::check()'s.
 */
function pageSections(schema: NodeSchema, fail: Fail): void {
  for (const [page, spec] of entries(get(schema, 'pages'))) {
    if (page.startsWith('$')) {
      continue
    }
    const sections = values(dig(spec, 'sections', '$value'))
    const sizes = new Map<string, number>()
    for (const section of sections) {
      const group = get(section, 'group')
      if (typeof group === 'string') {
        sizes.set(group, (sizes.get(group) ?? 0) + 1)
      }
    }
    sections.forEach((section, index) => {
      const key = get(section, 'key')
      const group = get(section, 'group')
      const path = `pages.${page}.sections.${typeof key === 'string' ? key : index}`
      if (typeof group === 'string' && sizes.get(group) === 1) {
        fail(path, 'alone_in_group')
      }
      if ((typeof key === 'string' && !SNAKE_CASE.test(key)) || (typeof group === 'string' && !SNAKE_CASE.test(group))) {
        fail(path, 'not_snake_case')
      }
    })
  }
}

/** A visibility key is a boolean (its own `$type` or its block's); its default is check()'s. */
function visibilityTypes(schema: NodeSchema, fail: Fail): void {
  const visibility = get(schema, 'visibility')
  for (const [key, node] of children(visibility)) {
    if (has(node, '$value') && (get(node, '$type') ?? get(visibility, '$type')) !== 'boolean') {
      fail(`visibility.${key}`, 'not_boolean')
    }
  }
}

/** What only the editor reads of a node or group, `{}` without it. */
function editorOf(node: Json): Json {
  const editor = dig(node, '$extensions', EDITOR)
  return isArray(editor) ? editor : {}
}

/** What a group holds: its members that are arrays, without its `$` keys. */
function children(group: Json): Array<[string, Json]> {
  return entries(group).filter(([key, child]) => isArray(child) && !key.startsWith('$'))
}

/** The values of a list once each, the first of each kept. */
function unique(list: Json[]): Json[] {
  const answer: Json[] = []
  for (const value of list) {
    if (!answer.some((other) => identical(other, value))) {
      answer.push(value)
    }
  }
  return answer
}

/** Every group of the editor's sections: `[section index, section, group index, group]`. */
function layoutGroups(schema: NodeSchema): Array<[string, Json, string, Json]> {
  const answer: Array<[string, Json, string, Json]> = []
  for (const [s, section] of entries(dig(schema, 'editor', 'sections'))) {
    for (const [g, group] of entries(get(section, 'groups'))) {
      if (isArray(group)) {
        answer.push([s, section, g, group])
      }
    }
  }
  return answer
}

/** Every node of the blocks the editor controls: `[path, node, type]`, the type its own or its group's. */
function nodesOf(schema: NodeSchema): Array<[string, Json, Json]> {
  const nodes: Array<[string, Json, Json]> = []
  const walk = (group: Json, path: string, type: Json): void => {
    const own = get(group, '$type') ?? type
    if (has(group, '$value')) {
      nodes.push([path, group, own])
      return
    }
    for (const [key, child] of children(group)) {
      walk(child, `${path}.${key}`, own)
    }
  }
  for (const block of BLOCKS) {
    if (isArray(get(schema, block))) {
      walk(get(schema, block), block, null)
    }
  }
  return nodes
}

/**
 * What a path of the schema holds (`components.card.base.radius`;
 * `visibility.header.cart`, its key a path itself): `[true, value]`, or
 * `[false, null]` when the schema has nothing there.
 */
function at(schema: NodeSchema, path: Json): [boolean, Json] {
  if (typeof path !== 'string') {
    return [false, null]
  }
  const [first = '', ...rest] = path.split('.')
  const keys = (first === 'visibility' || first === 'images') && rest.length > 0 ? [first, rest.join('.')] : [first, ...rest]
  let node: Json = schema as unknown as Json
  for (const key of keys) {
    if (!has(node, key)) {
      return [false, null]
    }
    node = get(node, key)
  }
  return [true, node]
}

/**
 * A node the compiler turns into a property tuple, whose rules check()
 * validates: a typed node of the components (their slots aside), the pages
 * or the site.
 */
function property(path: string, type: Json): boolean {
  const segments = path.split('.')
  return typeof type === 'string' && segments[0] !== 'tokens' && !(segments[0] === 'components' && segments[2] === 'slots')
}

/** PHP's trim(): spaces, tabs, new lines, NUL and vertical tabs at both ends. */
function phpTrim(value: string): string {
  return value.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '')
}
