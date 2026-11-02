import { describe, expect, it } from 'vitest'
import { DEFAULT_WEIGHTS, fontFile, nativeBorder, nativeShadow, nativeText, nearestWeight, toNative } from '../src/native.js'
import { compileRuntime, serializeRuntime, type NodeSchema } from '../src/index.js'
import { complete, type RuntimeSchema } from '../src/runtime.js'
import { reference } from './fixtures.js'

/**
 * toNative(): a completed theme as the values a React Native style takes,
 * each by its type in the schema, as toCss() does for the web.
 */
type Tree = Record<string, any>

const C = JSON.parse(serializeRuntime(compileRuntime(reference() as unknown as NodeSchema))) as RuntimeSchema
const values = (served: unknown = null) => complete(C, served)
const view = (served: unknown = null): Tree => toNative(C, values(served)) as unknown as Tree

describe('fontFile() and nearestWeight()', () => {
  it('names a static file as Google Fonts ships it', () => {
    expect(fontFile('poppins', 600)).toBe('Poppins-SemiBold')
    expect(fontFile('open_sans', 400)).toBe('OpenSans-Regular')
    expect(fontFile('playfair_display', 700)).toBe('PlayfairDisplay-Bold')
  })

  it('snaps to the nearest bundled weight: heavier on a tie from 600, lighter below', () => {
    expect(nearestWeight(300)).toBe(400)
    expect(nearestWeight(800)).toBe(700)
    expect(nearestWeight(550)).toBe(500)
    expect(nearestWeight(650)).toBe(700)
    expect(nearestWeight('600')).toBe(600)
    expect(nearestWeight('bold')).toBeNull()
    expect(nearestWeight(450, [400, 500])).toBe(400)
    expect(DEFAULT_WEIGHTS).toEqual([400, 500, 600, 700])
  })
})

describe('the converters', () => {
  it('borders: a React Native border, none as width 0, null when not one', () => {
    expect(nativeBorder({ width: 1, color: '#E9ECEF', style: 'dashed' })).toEqual({ borderWidth: 1, borderStyle: 'dashed', borderColor: '#E9ECEF' })
    expect(nativeBorder({ width: 2, color: '#000' })).toEqual({ borderWidth: 2, borderStyle: 'solid', borderColor: '#000' })
    expect(nativeBorder(null)).toEqual({ borderWidth: 0 })
    expect(nativeBorder({ width: 1, color: '{ink}' })).toBeNull()
  })

  it('shadows: iOS shadow, Android elevation and boxShadow from one level', () => {
    expect(nativeShadow({ x: 0, y: 2, blur: 8, spread: 0, color: '#000000', opacity: 0.1, elevation: 3 })).toEqual({
      shadowColor: '#000000',
      shadowOpacity: 0.1,
      shadowRadius: 4,
      shadowOffset: { width: 0, height: 2 },
      elevation: 3,
      boxShadow: '0px 2px 8px 0px rgba(0,0,0,0.1)',
    })
    expect(nativeShadow(null)).toMatchObject({ shadowOpacity: 0, elevation: 0, boxShadow: 'none' })
    expect(nativeShadow({ x: 0, y: 4, blur: 12, color: '#000', opacity: 0.2 })!.elevation).toBe(6)
    expect(nativeShadow('nope')).toBeNull()
  })

  it('texts: the weight\'s file, an absolute line height, never fontWeight', () => {
    const text = nativeText({ size: 14, line: 1.4, weight: 600, transform: 'uppercase', letter_spacing: 0.5 }, 'poppins')!
    expect(text).toEqual({ fontFamily: 'Poppins-SemiBold', fontSize: 14, lineHeight: 20, weight: 600, letterSpacing: 0.5, textTransform: 'uppercase' })
    expect(text).not.toHaveProperty('fontWeight')
    expect(nativeText({ size: 12, line: 1.5, weight: 300, family: 'inter' }, 'poppins')!.fontFamily).toBe('Inter-Regular')
    expect(nativeText({ size: 16, line: 1.5, weight: 700 }, 'lato', { fontFile: (id, w) => `${id}_${w}` })!.fontFamily).toBe('lato_700')
    expect(nativeText('nope')).toBeNull()
  })
})

describe('toNative()', () => {
  it('converts tokens by type: numbers stay numbers, texts with their role\'s font', () => {
    const D = view()
    const tokens = (values().tokens as Tree)
    expect(D.tokens.space.md).toBe(tokens.space.md)
    expect(D.tokens.color.primary).toBe(tokens.color.primary)
    expect(D.tokens.typography.body.fontSize).toBe(tokens.typography.body.size)
    expect(D.tokens.typography.body.lineHeight).toBe(Math.round(tokens.typography.body.size * tokens.typography.body.line))
    expect(D.tokens.typography.body).not.toHaveProperty('fontWeight')
    expect(typeof D.tokens.shadow.sm.shadowRadius).toBe('number')
  })

  it('converts components lazily and keeps the same view for the same values', () => {
    const v = values()
    const a = toNative(C, v)
    expect(toNative(C, v)).toBe(a)
    const button = (a.components as Tree).button
    expect(button.motion === null || typeof button.motion.ms === 'number').toBe(true)
    expect(typeof button.sizes.md.height).toBe('number')
    // every text of a component paints with a file name, never with fontWeight
    const texts: Tree[] = []
    const walk = (node: unknown): void => {
      if (node && typeof node === 'object') {
        if ('fontSize' in node) texts.push(node as Tree)
        else Object.values(node).forEach(walk)
      }
    }
    Object.keys(a.components).forEach((name) => walk((a.components as Tree)[name]))
    expect(texts.length).toBeGreaterThan(0)
    for (const text of texts) {
      expect(text.fontFamily).toMatch(/^[A-Za-z]+-[A-Za-z]+$/)
      expect(text).not.toHaveProperty('fontWeight')
    }
  })

  it('applies Identity to levels, not to tokens', () => {
    const D = view({ tokens: { border_style: { radius: 'xl', width: 'none' }, shadow_style: { size: 'lg' } } })
    expect(D.identity.radius.sm).toBe(D.tokens.radius.xl)
    expect(D.identity.radius.none).toBe(D.tokens.radius.none)
    expect(D.identity.radius.full).toBe(D.tokens.radius.full)
    expect(D.identity.border.thin).toBe(0)
    expect(D.identity.shadow.sm).toEqual(D.tokens.shadow.lg)
  })

  it('lists the font of each role with the file of every bundled weight', () => {
    const D = view({ tokens: { font: { heading: 'montserrat', body: 'open_sans' } } })
    expect(D.fonts.heading).toEqual({ id: 'montserrat', name: 'Montserrat', files: { 400: 'Montserrat-Regular', 500: 'Montserrat-Medium', 600: 'Montserrat-SemiBold', 700: 'Montserrat-Bold' } })
    const typography = (values({ tokens: { font: { heading: 'montserrat', body: 'open_sans' } } }).tokens as Tree).typography
    for (const [style, value] of Object.entries<Tree>(typography)) {
      // a style that names its own family keeps it; the rest take their role's
      const expected = value.family ?? (['display', 'h1', 'h2', 'h3', 'h4'].includes(style) ? 'montserrat' : 'open_sans')
      expect(D.tokens.typography[style].fontFamily).toBe(fontFile(expected, nearestWeight(value.weight)!))
    }
  })

  it('gives the easing as cubic-bezier points', () => {
    const D = view({ tokens: { motion_style: { easing: 'ease_in_out' } } })
    expect(D.easing).toEqual([0.42, 0, 0.58, 1])
  })

  it('refuses what is not a compiled form or completed values', () => {
    expect(() => toNative({} as RuntimeSchema, values())).toThrow(TypeError)
    expect(() => toNative(C, null as never)).toThrow(TypeError)
  })
})
