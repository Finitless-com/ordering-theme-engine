import { describe, expect, it } from 'vitest'
import { resolve, type NodeSchema } from '../src/index.js'
import { contrast, isLight, legible, mix, onColor, rgbOf, shade } from '../src/palette.js'
import { json } from './fixtures.js'

/**
 * The `colors` entry: the engine's color operations for a color a client
 * gets at run time, answering what the schema's rules derive.
 */
type Tree = Record<string, any>

const schema = (): Tree => json<Tree>('schema-26.0.nodes.json')

describe('the colors', () => {
  it('rgbOf() reads every color the engine reads', () => {
    expect(rgbOf('#1E72E3')).toEqual({ r: 30, g: 114, b: 227, a: 1 })
    expect(rgbOf('#abc')).toEqual({ r: 170, g: 187, b: 204, a: 1 })
    expect(rgbOf('#00000080')).toEqual({ r: 0, g: 0, b: 0, a: 0.502 })
    expect(rgbOf('rgba(10, 20, 30, 0.4)')).toEqual({ r: 10, g: 20, b: 30, a: 0.4 })
    expect(rgbOf('transparent')).toEqual({ r: 0, g: 0, b: 0, a: 0 })
    expect([rgbOf('red'), rgbOf('{color.primary}'), rgbOf(null)]).toEqual([null, null, null])
  })

  it('contrast(), isLight() and onColor() pick text by WCAG contrast', () => {
    expect(contrast('#000000', '#FFFFFF')).toBe(21)
    expect(contrast('#777', 'nope')).toBeNull()
    expect([isLight('#FFC700'), isLight('#1E72E3'), isLight('#FFFFFF'), isLight('#000000'), isLight('x')]).toEqual([true, false, true, false, null])
    expect(onColor('#FFC700')).toBe('#000000')
    expect(onColor('#1E72E3', '#FAFAFA', '#111111')).toBe('#FAFAFA')
  })

  it('shade(), mix() and legible() derive a color as the schema\'s rules derive the theme\'s', () => {
    const nodes = schema()
    const primary = '#D9480F'
    const color = (resolve(nodes as NodeSchema, { tokens: { color: { primary } } }) as Tree).tokens.color
    const rules = Object.fromEntries(Object.entries(nodes.tokens.color as Tree)
      .map(([name, node]) => [name, (node as Tree).$extensions?.['co.ordering']?.derive])
      .filter(([, rule]) => rule !== undefined && rule.from === '{color.primary}'))
    const checked: string[] = []
    for (const [name, rule] of Object.entries(rules) as Array<[string, Tree]>) {
      const over = rule.over === undefined ? undefined : color[rule.over.slice(7, -1)]
      const derived = rule.op === 'shade' ? shade(primary, rule.amount) : rule.op === 'mix' ? mix(primary, over, rule.amount) : rule.op === 'legible' ? legible(primary, over, rule.contrast, rule.step) : primary
      expect(derived, name).toBe(color[name])
      checked.push(rule.op)
    }
    expect(checked).toEqual(expect.arrayContaining(['shade', 'mix', 'legible']))
    expect(legible('#FFFFFF', '#FFFFFF')).toBe('#757575')
    expect([shade('x', 0.1), mix('x', '#FFF', 0.5), legible('x', '#FFF')]).toEqual([null, null, null])
  })
})
