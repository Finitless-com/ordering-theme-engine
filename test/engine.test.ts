import { describe, expect, it } from 'vitest'
import { deriveColors } from '../src/colors.js'
import { insets, shades } from '../src/derivations.js'
import { compile, defaults, resolve, type JsonMap, type NodeSchema } from '../src/index.js'
import { cases, differences, goldenSchema } from './fixtures.js'

/** A copy of $schema without the rules it declares under `co.ordering`. */
function without(schema: NodeSchema, keys: string[]): NodeSchema {
  const strip = (node: unknown): unknown => {
    if (Array.isArray(node)) {
      return node.map(strip)
    }
    if (typeof node !== 'object' || node === null) {
      return node
    }
    const answer: Record<string, unknown> = {}
    for (const [key, value] of Object.entries(node)) {
      if (key === 'co.ordering' && typeof value === 'object' && value !== null) {
        answer[key] = Object.fromEntries(Object.entries(value).filter(([rule]) => !keys.includes(rule)))
      } else {
        answer[key] = strip(value)
      }
    }
    return answer
  }
  return strip(schema) as NodeSchema
}

function freeze<T>(value: T): T {
  if (typeof value === 'object' && value !== null) {
    Object.values(value).forEach(freeze)
    Object.freeze(value)
  }
  return value
}

describe('resolve', () => {
  const schema = goldenSchema()

  it('changes neither the schema nor the theme', () => {
    const golden = Object.fromEntries(cases())
    for (const name of ['scope', 'paint', 'inherits', 'combo-square']) {
      const values = freeze(structuredClone(golden[name]!.values))
      expect(() => resolve(freeze(structuredClone(schema)), values)).not.toThrow()
    }
  })

  it('answers a new object every time', () => {
    const first = resolve(schema, {}) as JsonMap
    ;((first.tokens as JsonMap).color as JsonMap).primary = '#000000'
    expect(differences(resolve(schema, {}), defaults(schema))).toEqual([])
    expect(((resolve(schema, {}).tokens as JsonMap).color as JsonMap).primary).not.toBe('#000000')
  })

  it('writes an empty map as api-core does, or as an object when asked', () => {
    expect(resolve(schema, {}).modes).toEqual([])
    expect(resolve(schema, {}, { emptyMaps: 'object' }).modes).toEqual({})
  })

  it('reads a theme that is a list, or none, as the empty theme', () => {
    expect(differences(resolve(schema, [1, 2] as never), defaults(schema))).toEqual([])
    expect(differences(resolve(schema, null), defaults(schema))).toEqual([])
  })

  it('refuses a schema in tuples', () => {
    expect(() => resolve({ ...schema, $schema: 'tuples' }, {})).toThrow(TypeError)
  })

  /**
   * A schema in nodes that declares no `derive` nor `matrix` gets the
   * engine's rules (ThemeColors::rules(), ThemeDerivations::rules()), which
   * are what api-core declared in this one: every case resolves the same.
   */
  const undeclared = without(schema, ['derive', 'matrix'])

  it('reads no rule from a schema that declares none', () => {
    const compiled = compile(undeclared).schema
    for (const key of ['derive', 'component_derive', 'matrix']) {
      expect(compiled).not.toHaveProperty(key)
    }
  })

  it.each(cases())('%s resolves the same with the engine\'s rules instead of the declared ones', (_name, golden) => {
    expect(differences(resolve(undeclared, golden.values), golden.resolved)).toEqual([])
  })
})

describe('compile', () => {
  it('lifts what the engine reads out of the nodes', () => {
    const { schema, errors } = compile(goldenSchema())
    expect(errors).toEqual([])
    expect(Object.keys(schema.follows as JsonMap)).toHaveLength(127)
    expect(schema.inherits).toMatchObject({ business_card: 'card', search_bar: 'input' })
    expect(Object.keys(schema.derive as JsonMap)).toHaveLength(10)
    expect(Object.keys(schema.matrix as JsonMap)).toEqual(['button'])
  })
})

describe('deriveColors', () => {
  // What api-core's ThemeColors::derive() answers for the same input (PHP 8.3).
  it('derives again what derives from a color derived earlier in the pass', () => {
    const defaults = { primary: '#0000FF', primary_pressed: '#0000CC', focus: '#0000CC', info_subtle: null }
    const colors = { ...defaults, primary: '#FF0000', info: '#00AA00' }
    const rules = {
      primary_pressed: { op: 'shade', from: '{color.primary}', amount: -0.07 },
      focus: { op: 'same', from: '{color.primary_pressed}' },
      info_subtle: { op: 'mix', from: '{color.info}', over: '{color.background}', amount: 0.12, when: 'missing' },
    }
    expect(deriveColors(colors, {}, defaults, rules)).toEqual({
      primary: '#FF0000',
      primary_pressed: '#DB0000',
      focus: '#DB0000',
      info_subtle: '#E0F5E0',
      info: '#00AA00',
    })
  })
})

describe('deriveColors, as api-core\'s ThemeColorsTest::testADerivedColorChangesWhatDerivesFromIt', () => {
  const defaults = { primary: '#2C7BE5', primary_pressed: '#1A69D3', focus: '#1A69D3' }
  const set = { primary: '#FF5500' }

  it('derives what derives from a derived color', () => {
    const rules = {
      primary_pressed: { op: 'shade', from: '{color.primary}', amount: -0.07 },
      focus: { op: 'same', from: '{color.primary_pressed}' },
    }
    const colors = deriveColors({ ...defaults, ...set }, set, defaults, rules)
    expect([colors.primary_pressed, colors.focus]).toEqual(['#DB4900', '#DB4900'])
  })

  it('follows `when`', () => {
    const missing = { focus: { op: 'same', from: '{color.primary}', when: 'missing' } }
    expect(deriveColors({ ...defaults, ...set }, set, defaults, missing).focus).toBe('#1A69D3')
    expect(deriveColors({ ...defaults, ...set, focus: null }, set, defaults, missing).focus).toBe('#FF5500')
    const always = { focus: { op: 'same', from: '{color.primary}', when: 'always' } }
    expect(deriveColors(defaults, {}, defaults, always).focus).toBe('#2C7BE5')
  })
})

describe('component derivations', () => {
  // The same input and answers as api-core's ThemeDerivations (PHP 8.3).
  const specs = {
    tabs: {
      base: { radius: ['radius', '{radius.md}'], inset: ['space', '{space.xs}'] },
      variants: { pill: { tab_radius: ['radius', '{radius.sm}'] }, line: { radius: ['radius', '{radius.md}'], tab_radius: ['radius', null] } },
    },
    chip: {
      base: { background: ['color', '#EEEEEE'] },
      variants: {
        default: { states: { pressed: { background: ['color', '#DDDDDD'] } } },
        other: { background: ['color', '#FFFFFF'], states: { pressed: { background: ['color', null] } } },
      },
    },
  } as unknown as JsonMap
  const components = {
    tabs: { base: { radius: 12, inset: 4 }, variants: { pill: { tab_radius: 6 }, line: { radius: 10, tab_radius: null } } },
    chip: {
      base: { background: '#FDE68A' },
      variants: {
        default: { states: { pressed: { background: '#DDDDDD' } } },
        other: { background: '#FFFFFF', states: { pressed: { background: null } } },
      },
    },
  } as JsonMap
  const rules = {
    tabs: {
      'variants.pill.tab_radius': { op: 'inset', from: 'radius', by: 'inset', when: 'always' },
      'variants.line.tab_radius': { op: 'inset', from: 'radius', by: 'inset', when: 'missing' },
    },
    chip: {
      'variants.default.states.pressed.background': { op: 'shade', from: 'background', amount: -0.07 },
      'variants.other.states.pressed.background': { op: 'shade', from: 'background', amount: -0.07 },
    },
  } as JsonMap

  it('reads an inset\'s values from the variant, else from its base, as `when` says', () => {
    const answer = insets(components, {}, rules, 9999, specs)
    expect(answer.tabs).toEqual({ base: { radius: 12, inset: 4 }, variants: { pill: { tab_radius: 8 }, line: { radius: 10, tab_radius: 6 } } })
  })

  it('shades a missing target, and one whose source the theme changed', () => {
    const chip = (answer: JsonMap) => (answer.chip as JsonMap).variants
    expect(chip(shades(components, {}, rules, specs))).toEqual({
      default: { states: { pressed: { background: '#DDDDDD' } } },
      other: { background: '#FFFFFF', states: { pressed: { background: '#EDEDED' } } },
    })
    expect(chip(shades(components, { chip: { base: { background: '#FDE68A' } } }, rules, specs))).toEqual({
      default: { states: { pressed: { background: '#FCDF67' } } },
      other: { background: '#FFFFFF', states: { pressed: { background: '#EDEDED' } } },
    })
  })
})
