import { describe, expect, it } from 'vitest'
import { alpha, animation, cssBorder, cssShadow, cssText, fonts, isTransparent, snapWeight, toCss, transition, type CssShadow, type CssTheme } from '../src/css.js'
import { FONTS, FONT_FACES } from '../src/format.js'
import { compileRuntime, serializeRuntime, type NodeSchema } from '../src/index.js'
import { complete, type RuntimeSchema } from '../src/runtime.js'
import { json, reference } from './fixtures.js'

/**
 * toCss(): a completed theme as the CSS a web client paints with, each
 * value by its type in the schema. The expected strings are the ones the
 * marketplace web's helpers build today (src/utils/themeCss.js,
 * themeTokens.js, themeMotion.js and the fonts of appTheme.js), which the
 * `css` entry replaces.
 */
// A schema in nodes, walked freely to change a node.
type Tree = Record<string, any>

const editor = (node: Tree): Tree => ((node.$extensions ??= {})['co.ordering.editor'] ??= {})

/** The reference schema, with a percentage among its numbers: the header's logo width. */
function schema(): Tree {
  const nodes = reference() as unknown as Tree
  Object.assign(editor(nodes.components.header.base.logo_width), { unit: '%' })
  return nodes
}

const C = JSON.parse(serializeRuntime(compileRuntime(schema() as NodeSchema))) as RuntimeSchema
const view = (served: unknown = null): Tree => toCss(C, complete(C, served)) as unknown as Tree
const D = view()

const shadow = (css: string, extra: Partial<CssShadow> = {}): Partial<CssShadow> => ({ css, ...extra })

describe('compileRuntime() for the css entry', () => {
  it('writes each number\'s unit after its range, and marks the compiled form', () => {
    const values = C.values as Tree
    expect(C.css).toBe(1)
    expect(values.tokens.size.md.$rule[2]).toBe('px')
    expect(values.tokens.motion.base.$rule[2]).toBe('ms')
    expect(values.tokens.opacity.disabled.$rule).toHaveLength(2)
    expect(values.components.button.sizes.md.height.$rule[2]).toBe('px')
    expect(values.components.button.base.icon_gap.$rule[2]).toBe('px')
    expect(values.components.button.base.speed.$rule[2]).toBe('ms')
    // An opacity is unitless; a `%` beyond 1 a percentage; a number without unit, unitless.
    expect(values.components.modal.base.overlay_opacity.$rule).toHaveLength(2)
    expect(values.components.header.base.logo_width.$rule[2]).toBe('%')
    expect(values.components.banner.base.columns.$rule).toHaveLength(2)
  })

  it('complete() reads it as it read the form without units', () => {
    const plain = JSON.parse(JSON.stringify(C), (key, value) => (key === '$rule' && Array.isArray(value) && typeof value[0] === 'number' ? value.slice(0, 2) : value))
    const served = { tokens: { size: { md: 99999 } }, components: { button: { sizes: { md: { height: -4 } } } } }
    expect(complete(C, served)).toEqual(complete(plain, served))
  })
})

describe('toCss()', () => {
  it('needs the compiled form with units and the completed values', () => {
    const { css: _, ...withoutUnits } = C
    expect(() => toCss(withoutUnits as RuntimeSchema, complete(C, null))).toThrow(TypeError)
    expect(() => toCss(C, null as never)).toThrow(TypeError)
  })

  it('builds once per theme: the same values give the same view, equal values equal strings', () => {
    const values = complete(C, null)
    expect(toCss(C, values)).toBe(toCss(C, values))
    expect(JSON.stringify(toCss(C, complete(C, null)))).toBe(JSON.stringify(toCss(C, values)))
  })

  it('turns numbers into CSS by their unit, not by their name', () => {
    expect(D.tokens.size.md).toBe('40px')
    expect(D.tokens.motion.base).toBe('200ms')
    expect(D.tokens.opacity.disabled).toBe(String((complete(C, null).tokens as Tree).opacity.disabled))
    expect(D.components.button.sizes.md.height).toBe('40px')
    expect(D.components.modal.base.overlay_opacity).toBe('0.5')
    expect(D.components.header.base.logo_width).toBe('160%')
    expect(D.components.banner.base.columns).toBe('3')
  })

  it('turns borders, shadows, fonts and the easing into CSS', () => {
    expect(D.components.card.base.border).toBe('none')
    expect(view({ components: { card: { base: { border: { width: 2, color: '#E9ECEF' } } } } }).components.card.base.border).toBe('2px solid #E9ECEF')
    expect(D.tokens.shadow.sm).toMatchObject(shadow('0px 1px 4px 0px rgba(0,0,0,0.07)'))
    expect(D.tokens.shadow.none.css).toBe('none')
    expect(D.tokens.font).toEqual({ heading: "'Poppins', sans-serif", body: "'Inter', sans-serif" })
    expect(D.tokens.motion_style.easing).toBe('ease')
    expect(view({ tokens: { motion_style: { easing: 'ease_in_out' } } }).tokens.motion_style.easing).toBe('ease-in-out')
  })

  it('casts every shadow down, up and to each side, as a filter and with its reach', () => {
    const offset = view({ tokens: { shadow: { lg: { x: 4, y: -6, blur: 12, spread: 2, color: '#000000', opacity: 1 } } } }).tokens.shadow.lg
    expect(offset).toEqual({
      css: '4px -6px 12px 2px rgba(0,0,0,1)',
      down: '0px 6px 12px 2px rgba(0,0,0,1)',
      up: '0px -6px 12px 2px rgba(0,0,0,1)',
      right: '6px 0px 12px 2px rgba(0,0,0,1)',
      left: '-6px 0px 12px 2px rgba(0,0,0,1)',
      drop: 'drop-shadow(4px -6px 12px rgba(0,0,0,1))',
      reach: { top: 20, right: 18, bottom: 8, left: 10 },
    })
    // Around without blur: a solid outline, which falls by its spread and is four drop-shadows.
    const solid = view({ tokens: { shadow: { md: { x: 0, y: 0, blur: 0, spread: 3, color: '#112233', opacity: 0.4 } } } }).tokens.shadow.md
    expect(solid.down).toBe('0px 3px 0px 0px rgba(17,34,51,0.4)')
    expect(solid.drop).toBe('drop-shadow(3px 0 0 rgba(17,34,51,0.4)) drop-shadow(-3px 0 0 rgba(17,34,51,0.4)) drop-shadow(0 3px 0 rgba(17,34,51,0.4)) drop-shadow(0 -3px 0 rgba(17,34,51,0.4))')
    // Around with blur: it falls a quarter of its blur.
    const soft = view({ tokens: { shadow: { md: { x: 0, y: 0, blur: 10, color: '#000000', opacity: 0.2 } } } }).tokens.shadow.md
    expect(soft.down).toBe('0px 3px 10px 0px rgba(0,0,0,0.2)')
  })

  it('gives each typography its declarations and its parts, the weight one its font has', () => {
    expect(D.tokens.typography.h1).toEqual({
      css: "font-family: 'Poppins', sans-serif;\nfont-size: 32px;\nline-height: 1.25;\nfont-weight: 700;",
      size: '32px',
      line: '1.25',
      weight: 700,
      family: "'Poppins', sans-serif",
      letter_spacing: null,
      transform: null,
    })
    // Lato has no 600: a heading without a family of its own, at 600, paints at 700 with its role's font.
    const lato = view({ tokens: { font: { heading: 'lato', body: 'open_sans' }, typography: { h1: { size: 32, line: 1.25, weight: 600 }, body: { size: 14, line: 1.5, weight: 400 } } } })
    expect(lato.tokens.typography.h1.weight).toBe(700)
    expect(lato.tokens.typography.body.family).toBe("'Open Sans', sans-serif")
  })

  it('applies Identity to the levels and keeps them fixed in the tokens', () => {
    const css = view({ tokens: { shadow_style: { size: 'lg' }, border_style: { radius: 'xl', width: 'thick' } } })
    expect(css.identity.radius.sm).toBe(css.tokens.radius.xl)
    expect(css.identity.radius.none).toBe(css.tokens.radius.none)
    expect(css.identity.radius.full).toBe(css.tokens.radius.full)
    expect(css.identity.shadow.sm).toBe(css.tokens.shadow.lg)
    expect(css.identity.shadow.none).toBe(css.tokens.shadow.none)
    expect(css.identity.border).toEqual({ thin: css.tokens.border.thick, thick: css.tokens.border.thick })
    expect(view({ tokens: { border_style: { width: 'none' } } }).identity.border).toEqual({ thin: '0px', thick: '0px' })
    expect(D.identity.focus_ring).toEqual({ width: D.tokens.border[D.tokens.focus_ring.width], offset: D.tokens.space[D.tokens.focus_ring.offset], color: D.tokens.color[D.tokens.focus_ring.color] })
    expect(D.identity.scrollbar).toEqual({ size: D.tokens.scrollbar.size, radius: D.tokens.scrollbar.radius })
  })

  it('resolves each component\'s motion, its groups\' scope and the platforms', () => {
    expect(D.components.button.motion).toBeNull()
    const animated = view({ components: { button: { base: { animated: true, speed: 150 } } } })
    expect(animated.components.button.motion).toEqual({ ms: 150, easing: 'ease' })
    expect(view({ tokens: { motion_style: { enabled: false } }, components: { button: { base: { animated: true } } } }).components.button.motion).toBeNull()
    // A group's scope holds whole components, each with its motion.
    expect(D.components.header.scope.button.base.radius).toBe('9999px')
    expect(D.components.header.scope.button).toHaveProperty('motion')
    // A platform's own values, converted as their component's.
    expect(D.platforms.mobile_web.components.card.base.padding).toBe('8px')
  })

  it('leaves no string that could break out of a declaration', () => {
    // A matrix's appearances keep their roles (`{ink}`): no CSS.
    expect(D.components.button.appearances.outline.color).toBeNull()
    expect(D.components.button.appearances.outline.border).toBeNull()
    const strings: string[] = []
    const walk = (node: unknown, key = ''): void => {
      if (typeof node === 'string' && key !== 'css' && key !== 'family' && key !== 'drop' && !/^(heading|body)$/.test(key)) {
        strings.push(node)
      } else if (node !== null && typeof node === 'object') {
        Object.entries(node).forEach(([child, value]) => walk(value, child))
      }
    }
    walk(D)
    expect(strings.length).toBeGreaterThan(300)
    expect(strings.filter((value) => /[{};<>'"\\]/.test(value))).toEqual([])
  })
})

describe('the conversions apart', () => {
  it('alpha() replaces a color\'s alpha', () => {
    expect(alpha('#1E72E3', 0.25)).toBe('rgba(30,114,227,0.25)')
    expect(alpha('#abc', 0.5)).toBe('rgba(170,187,204,0.5)')
    expect(alpha('#00000080', 1)).toBe('rgba(0,0,0,1)')
    expect(alpha('rgba(10, 20, 30, 0.4)', 0.1)).toBe('rgba(10,20,30,0.1)')
    expect(alpha('transparent', 0.5)).toBe('transparent')
    expect(alpha('red', 0.5, 'none')).toBe('none')
  })

  it('isTransparent() tells a color that paints nothing', () => {
    expect([isTransparent('transparent'), isTransparent('rgba(0,0,0,0)'), isTransparent('#ffffff00'), isTransparent('#fff0')]).toEqual([true, true, true, true])
    expect([isTransparent('#fff'), isTransparent('rgba(0,0,0,0.1)'), isTransparent(null)]).toEqual([false, false, false])
  })

  it('snapWeight() paints with the nearest weight the font has', () => {
    expect(snapWeight('lato', 600)).toBe(700)
    expect(snapWeight('lato', 500)).toBe(400)
    expect(snapWeight('Lato', '200')).toBe(100)
    expect(snapWeight('open_sans', 200)).toBe(300)
    expect(snapWeight('inter', 950)).toBe(900)
    expect(snapWeight('custom', 600)).toBe(600)
    expect(snapWeight('lato', 'bold')).toBe('bold')
  })

  it('cssBorder(), cssShadow() and cssText() as the view builds them', () => {
    expect(cssBorder({ width: 1, color: '#DBDCDB', style: 'dashed' })).toBe('1px dashed #DBDCDB')
    expect(cssBorder(null)).toBe('none')
    expect(cssBorder({ width: 1, color: 'red; } body {' })).toBeNull()
    expect(cssShadow({ x: 0, y: 2, blur: 8, color: '#000', opacity: 0.1 })).toBe('0px 2px 8px 0px rgba(0,0,0,0.1)')
    expect(cssShadow(null)).toBe('none')
    expect(cssShadow({ x: 0, y: 2, blur: 8, color: '{color.primary}', opacity: 0.1 })).toBeNull()
    expect(cssText({ size: 14, line: 1.5, weight: 600, transform: 'uppercase' }, 'lato')).toEqual({
      css: 'font-size: 14px;\nline-height: 1.5;\nfont-weight: 700;\ntext-transform: uppercase;',
      size: '14px',
      line: '1.5',
      weight: 700,
      family: "'Lato', sans-serif",
      letter_spacing: null,
      transform: 'uppercase',
    })
  })

  it('animation() plays the keyframes only without reduced motion', () => {
    expect(animation(null, 'fade')).toBe('animation: none;')
    expect(animation({ ms: 200, easing: 'ease' }, 'fade', '1 forwards')).toBe('animation: none;\n@media (prefers-reduced-motion: no-preference) { animation: fade 200ms ease 1 forwards; }')
  })

  it('transition() animates the properties only without reduced motion, one string per arguments', () => {
    expect(transition(null, ['color'])).toBe('transition: none;')
    const css = transition({ ms: 150, easing: 'ease-out' }, ['color', 'box-shadow'])
    expect(css).toBe('transition: none;\n@media (prefers-reduced-motion: no-preference) { transition: color 150ms ease-out, box-shadow 150ms ease-out; }')
    expect(transition({ ms: 150, easing: 'ease-out' }, ['color', 'box-shadow'])).toBe(css)
  })
})

describe('building on demand', () => {
  it('builds a component, a scope\'s and a platform\'s the first time it is read, and keeps it', () => {
    const css = toCss(C, complete(C, null))
    const unread = (owner: object, key: string): boolean => typeof Object.getOwnPropertyDescriptor(owner, key)!.get === 'function'
    expect(unread(css.components, 'button')).toBe(true)
    const button = css.components.button
    expect(unread(css.components, 'button')).toBe(false)
    expect(css.components.button).toBe(button)
    const group = css.components.header as Tree
    expect(unread(group.scope, 'button')).toBe(true)
    expect(group.scope.button).toBe(group.scope.button)
    expect(unread(css.platforms.mobile_web!.components, 'card')).toBe(true)
    // fonts() reads the values: it builds no component.
    fonts(css)
    expect(unread(css.components, 'card')).toBe(true)
    expect(Object.keys(css.components)).toEqual(Object.keys(complete(C, null).components))
  })
})

describe('fonts()', () => {
  it('asks for the same weights whether or not the components were read', () => {
    const fresh = toCss(C, complete(C, { tokens: { font: { heading: 'lato' } } }))
    const before = fonts(fresh)
    const read = toCss(C, complete(C, { tokens: { font: { heading: 'lato' } } }))
    JSON.stringify(read)
    expect(fonts(read)).toEqual(before)
  })

  // Poppins has a file per weight; Inter is variable, one range.
  it('asks each role\'s font for every weight the view paints with it and the client\'s own', () => {
    const answer = fonts(D as unknown as CssTheme, { heading: [300, 800], body: [500] })
    expect(answer.heading).toEqual({
      name: 'Poppins',
      weights: [300, 600, 700, 800],
      href: 'https://fonts.googleapis.com/css2?family=Poppins:wght@300;600;700;800&display=swap',
    })
    expect(answer.body!.weights).toContain(500)
    expect(answer.body!.href).toMatch(/family=Inter:wght@\d+\.\.\d+&display=swap$/)
  })

  it('asks a static font weight by weight, and one family for both roles once', () => {
    const lato = toCss(C, complete(C, { tokens: { font: { heading: 'lato', body: 'lato' } } }))
    const answer = fonts(lato, { body: [600] })
    expect(answer.heading).toEqual(answer.body)
    expect(answer.body!.weights).toEqual(expect.arrayContaining([400, 700]))
    expect(answer.body!.href).toMatch(/family=Lato:wght@(\d+;)+\d+&display=swap$/)
  })

  it('has a face for every font of the format', () => {
    expect(Object.keys(FONT_FACES)).toEqual([...FONTS])
  })
})
