import { describe, expect, it } from 'vitest'
import { check, checkEditor, compile, outOfRange, type JsonMap, type NodeSchema } from '../src/index.js'
import { translucent } from '../src/editor.js'
import { json, reference, referenceV1Map } from './fixtures.js'

/**
 * The editor checks (checkEditor(), EditorRules::check() in PHP; the
 * validate() cases in fixtures/validate.json cover each rule against the
 * PHP answer). The same cases as php/tests/EditorRulesTest.php.
 */
const EDITOR = 'co.ordering.editor'

const schema = (blocks: JsonMap): NodeSchema => ({ $schema: 'urn:ordering:theme-schema:1', product_type: 'call_center', version: '26.0.0', ...blocks })

const codes = (nodes: NodeSchema): string[] => checkEditor(nodes).map((error) => error.code)

describe('translucent', () => {
  it.each([
    ['transparent', true],
    [' Transparent ', true],
    ['#0008', true],
    ['#000F', false],
    ['#00000080', true],
    ['#000000FF', false],
    ['#000000', false],
    ['rgba(0,0,0,0.4)', true],
    ['rgba(0, 0, 0, 1)', false],
    ['rgb(0 0 0 / 50%)', true],
    ['hsla(0,0%,0%,.5)', true],
    ['rgba(0,0,0,abc)', false],
    ['rgba(0,0,0)', false],
    ['{color.overlay}', false],
  ] as const)('%s: %s', (value, expected) => {
    expect(translucent(value)).toBe(expected)
  })
})

describe('check()', () => {
  it('checks the editor metadata only given the nodes, after its own rules', () => {
    // A visibility key in no group of the editor: an error of its metadata alone.
    const original = reference()
    ;(original.visibility as JsonMap)['header.glow'] = { $value: true }
    const compiled = compile(original, referenceV1Map()).schema
    expect(check(compiled)).toEqual([])
    expect(check(compiled, original)).toEqual(checkEditor(original))
    expect(checkEditor(original).map((error) => error.code)).toEqual(['visibility.header.glow.in_no_editor_group'])
  })
})

describe('checkEditor', () => {
  it('answers each error once', () => {
    const nodes = schema({
      pages: {},
      editor: { sections: [{ key: 'visibility', groups: [{ key: 'one', pages: ['nowhere', 'elsewhere'] }] }] },
    })
    expect(codes(nodes)).toEqual(['editor.sections.0.groups.0.page_unknown'])
  })

  it('reads a visibility key as a path of its own', () => {
    const nodes = schema({
      visibility: { $type: 'boolean', 'a.b': { $value: true } },
      components: {
        bar: {
          $extensions: { [EDITOR]: { groups: [{ key: 'shown', paths: ['visibility.a.b', 'visibility.a.c'] }] } },
          base: { height: { $type: 'number', $value: 4, $extensions: { [EDITOR]: { order: 1 } } } },
        },
      },
      editor: { sections: [{ key: 'visibility', groups: [{ key: 'a', paths: ['visibility.a.b'] }] }] },
    })
    expect(codes(nodes)).toEqual([`components.bar.$extensions.${EDITOR}.groups.0.path_unknown`])
  })

  it("leaves to check() the rules of a property and to outOfRange() its default", () => {
    const nodes = schema({
      tokens: { color: { $type: 'color', primary: { $value: '#000000', $extensions: { [EDITOR]: { custom: true }, 'co.ordering': { max_length: 3 } } } } },
      components: {
        card: {
          base: {
            gap: { $type: 'number', $value: 20, $extensions: { [EDITOR]: { custom: true, control: 'range', min: 0, max: 10 }, 'co.ordering': { max_length: 3 } } },
          },
        },
      },
    })
    expect(codes(nodes)).toEqual([
      'tokens.color.primary.custom_not_level',
      'tokens.color.primary.rules_not_string',
    ])
    expect(outOfRange(nodes).map((error) => error.code)).toEqual(['components.card.base.gap.default_out_of_range'])
  })

  it('asks a range for its limits, and a typography group to be as wide as its levels', () => {
    const level = (min: number, max: number): JsonMap => ({ $value: { size: 16, line: 1.5, weight: 400 }, $extensions: { [EDITOR]: { fields: { size: { min, max } } } } })
    const nodes = schema({
      tokens: {
        typography: {
          $type: 'typography',
          $extensions: { [EDITOR]: { fields: { size: { min: 10, max: 40 } } } },
          body: level(12, 30),
          display: level(8, 96),
        },
      },
      site: { width: { $type: 'number', $value: 5, $extensions: { [EDITOR]: { control: 'range', min: 10, max: 1 } } } },
    })
    expect(codes(nodes)).toEqual([
      'site.width.min_over_max',
      'tokens.typography.fields.size.min_not_widest',
      'tokens.typography.fields.size.max_not_widest',
    ])
  })
})

describe('the editor metadata the format no longer has, as the PHP ThemeSchemaNodesTest', () => {
  // Prior behavior: `preview` ({ page, path }) passed the meta-schema.
  it('refuses a `preview`, as any key the editor does not read', async () => {
    const { validateShape } = await import('../src/index.js')
    const nodes = reference()
    expect(validateShape(nodes)).toEqual([])
    const width = (((nodes.components as JsonMap).header as JsonMap).base as JsonMap).logo_width as JsonMap
    ;((width.$extensions as JsonMap)[EDITOR] as JsonMap).preview = { page: 'home', path: '/' }
    expect(validateShape(nodes).map((error) => error.code)).toContain('components.header.base.logo_width.$extensions.co.ordering.editor.schema')
  })
})
