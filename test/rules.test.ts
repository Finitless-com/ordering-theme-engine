import { describe, expect, it } from 'vitest'
import { compile, runtimeRules, serializeRules, type JsonMap, type NodeSchema } from '../src/index.js'
import { json, reference } from './fixtures.js'

/**
 * runtimeRules(): what a client checks a theme it is served with, without
 * the schema, from the compiled schema and its limits. A client had its own
 * reading of the format until then; these rules are that reading's, and
 * these tests pin what each one comes from.
 */
// A schema in nodes, walked freely to change a node.
type Tree = Record<string, any>

const schema = (): Tree => (reference() as unknown as Tree)
const editor = (node: Tree): Tree => ((node.$extensions ??= {})['co.ordering.editor'] ??= {})
const rules = (nodes: Tree): Tree => runtimeRules(nodes as NodeSchema) as Tree

describe('runtimeRules()', () => {
  const answer = rules(schema())

  it("takes a typography's fields from the group's limits, the format's where it says none", () => {
    expect(answer.text).toEqual({
      size: [10, 72],
      line: [1, 2],
      weight: ['o', 100, 200, 300, 400, 500, 600, 700, 800, 900],
      letter_spacing: [-2, 4],
      transform: ['o', 'none', 'uppercase'],
    })
    // A level's own fields, where they differ from the group's.
    expect(answer.values.tokens.typography.h1).toEqual(['t', { size: [20, 56], line: [1, 1.6] }])
    const nodes = schema()
    editor(nodes.tokens.typography).fields = { size: { min: 8, max: 96 } }
    expect(rules(nodes).text).toMatchObject({ size: [8, 96], line: [0.8, 3], letter_spacing: [-20, 20] })
  })

  it("gives each token its group's rule: a range, an opacity, a color, a shadow, an option", () => {
    const tokens = answer.values.tokens
    expect([tokens.size.lg, tokens.size.sm]).toEqual([[40, 64], [0, 9999]])
    expect(tokens.radius.md).toEqual([0, 9999])
    expect([tokens.opacity.disabled, tokens.opacity.pressed]).toEqual([[0.1, 0.9], [0, 1]])
    expect(tokens.color.primary).toBe('c')
    expect(tokens.shadow.md).toBe('s')
    expect(tokens.layout).toEqual({ page_gutter: [0, 9999], mode: ['o', 'original', 'appointments'] })
    // The focus ring's color is one of the schema's own colors, or of those it narrows to.
    expect(tokens.focus_ring.color).toEqual(['o', 'primary', 'text', 'danger'])
    const nodes = schema()
    delete nodes.tokens.focus_ring.color.$extensions['co.ordering'].options
    expect(rules(nodes).values.tokens.focus_ring.color).toEqual(['o', ...Object.keys((compile(nodes as NodeSchema).schema.tokens as Tree).color)])
    expect(tokens.motion_style).toEqual({ easing: ['o', 'linear', 'ease', 'ease_in', 'ease_out', 'ease_in_out'] })
  })

  it("widens a property of a scale to its levels' values, which a resolved theme serves, and not a plain number", () => {
    const nodes = schema()
    Object.assign(editor(nodes.components.button.base.icon_gap), { min: 2, max: 10 })
    Object.assign(editor(nodes.components.banner.base.columns), { min: 2, max: 3 })
    const narrowed = rules(nodes).values.components
    // The space levels go from 0 to 64.
    expect(narrowed.button.base.icon_gap).toEqual([0, 64])
    expect(narrowed.banner.base.columns).toEqual([2, 3])
    expect(answer.values.components.banner.base.columns).toEqual([1, 4])
    delete editor(nodes.components.banner.base.columns).min
    delete editor(nodes.components.banner.base.columns).max
    expect(rules(nodes).values.components.banner.base.columns).toEqual([0, 9999])
  })

  it("gives a matrix component's variants its appearances' rules, and lists each group's scope", () => {
    const button = answer.values.components.button
    expect(button.variants).toEqual({ '*': button.appearances })
    expect(Object.keys(button).at(-1)).toBe('variants')
    expect(answer.scopes).toEqual({ header: ['button', 'input', 'tabs'] })
    expect(answer.platforms).toEqual(['mobile_web'])
  })

  it("gives a platform only the rules that differ from the component's base", () => {
    // The banner's columns have limits of their own there; the header's
    // height and logo width none, so a size's levels and the format's range.
    expect(answer.platform_values).toEqual({ mobile_web: { components: {
      banner: { base: { columns: [1, 2] } },
      header: { base: { height: [32, 64], logo_width: [0, 9999] } },
    } } })
    const nodes = schema()
    Object.assign(editor(nodes.components.header.platforms.mobile_web.logo_width), { min: 40, max: 320 })
    Object.assign(editor(nodes.components.banner.platforms.mobile_web.columns), { min: 1, max: 4 })
    expect(rules(nodes).platform_values).toEqual({ mobile_web: { components: { header: { base: { height: [32, 64] } } } } })
  })

  it("gives the site's settings their options, texts, ids and nullable booleans", () => {
    const nodes = schema()
    nodes.site.tracking_only.$value = null
    Object.assign((nodes.site.name.$extensions ??= {})['co.ordering'] ??= {}, { max_length: 60 })
    const site = rules(nodes).values.site
    expect(site.mode).toEqual(['o', 'marketplace', 'single_store', 'franchise'])
    expect(site.franchise_id).toBe('i')
    expect(site.name).toEqual(['x', 60])
    expect(site.tracking_only).toBe('l')
    expect(answer.values.site).not.toHaveProperty('tracking_only')
  })

  it('reads a compiled schema as its nodes', () => {
    expect(runtimeRules(compile(schema() as NodeSchema).schema)).toEqual(answer)
  })

  it('writes each rule on one line, the maps that hold them indented', () => {
    const text = serializeRules({ a: { b: [0, 1], c: 'c', d: {} } } as JsonMap)
    expect(text).toBe('{\n  "a": {\n    "b": [0,1],\n    "c": "c",\n    "d": {}\n  }\n}\n')
    expect(JSON.parse(serializeRules(answer as JsonMap))).toEqual(answer)
  })
})
