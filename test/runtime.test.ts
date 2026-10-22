import { describe, expect, it } from 'vitest'
import { themeDefaults } from '../src/cli.js'
import { compile, compileRuntime, runtimeRules, serializeRuntime, type NodeSchema } from '../src/index.js'
import { complete, type RuntimeSchema } from '../src/runtime.js'
import { json } from './fixtures.js'

/**
 * complete(): a served theme read with the compiled form, as a website
 * reads it without the schema. These are the cases of the marketplace
 * web's own merge (src/utils/themeMerge.js and its tests), which the
 * runtime entry replaces, over the product's schema with the limits the
 * web's has; each range is the one runtimeRules() gives.
 */
// A schema in nodes, walked freely to change a node.
type Tree = Record<string, any>

const editor = (node: Tree): Tree => ((node.$extensions ??= {})['co.ordering.editor'] ??= {})
const ordering = (node: Tree): Tree => ((node.$extensions ??= {})['co.ordering'] ??= {})

/**
 * The product's schema made valid as the validate() cases make it (their
 * `base`: two defaults out of their range, among others), with the limits
 * of the web's.
 */
function schema(): Tree {
  const nodes = json<Tree>('schema-26.0.nodes.json')
  for (const [op, path, value] of json<{ base: Array<[string, string[], unknown]> }>('validate.json').base) {
    const parent = path.slice(0, -1).reduce((node: Tree, key) => (node[key] ??= {}), nodes)
    if (op === 'set') {
      parent[path[path.length - 1]!] = value
    } else {
      delete parent[path[path.length - 1]!]
    }
  }
  editor(nodes.tokens.typography).fields = { size: { min: 10, max: 72 }, line: { min: 1, max: 2 }, letter_spacing: { min: -2, max: 4 } }
  Object.assign(editor(nodes.components.product_section.base.columns), { min: 1, max: 4 })
  // The platform's own limit: 1–2 columns on phones.
  nodes.components.product_section.platforms = {
    mobile_web: { columns: { $type: 'number', $value: 1, $extensions: { 'co.ordering.editor': { control: 'range', min: 1, max: 2 } } } },
  }
  ordering(nodes.visibility.footer).platforms = { mobile_web: false }
  ordering(nodes.site.name).max_length = 60
  ordering(nodes.site.smart_banner.apple_id).pattern = '^[0-9]+$'
  return nodes
}

const nodes = schema()
const compiled = compileRuntime(nodes as NodeSchema)
// What the file a product versions gives back.
const C = JSON.parse(serializeRuntime(compiled)) as RuntimeSchema
const D = complete(C, null) as Tree
const RULES = runtimeRules(nodes as NodeSchema) as Tree
const merge = (values: unknown): Tree => complete(C, values) as Tree
const min = (rule: number[]): number => rule[0]!
const max = (rule: number[]): number => rule[1]!

describe('compileRuntime()', () => {
  it('compiles without errors, and its defaults are the empty theme with the site', () => {
    expect(compile(nodes as NodeSchema).errors).toEqual([])
    expect(D).toEqual(themeDefaults(nodes as NodeSchema, true))
    expect(C).toMatchObject({ runtime: 1, product_type: 'marketplace_website', version: nodes.version })
  })

  it("carries runtimeRules() but its values, and each value's rule next to its default", () => {
    const { values, ...rest } = RULES
    expect(C.rules).toEqual(rest)
    expect(C.values.tokens).toMatchObject({ color: { primary: { $value: D.tokens.color.primary, $rule: 'c' } } })
    // A matrix component's variants take their appearances' rules.
    const variant = (C.values.components as Tree).button.variants.primary.outline
    expect(variant.border.$rule).toEqual(values.components.button.appearances.outline.border)
    // Only what the editor validates with: no labels, controls or version 1 paths.
    const text = JSON.stringify(C)
    expect(text).not.toContain('BUILDER_THEME')
    expect(text).not.toContain('"control"')
    expect(text).not.toContain('"v1"')
  })

  it('refuses a schema that is not in nodes, and complete() anything but the compiled form', () => {
    expect(() => compileRuntime(compile(nodes as NodeSchema).schema as NodeSchema)).toThrow(TypeError)
    expect(() => complete(nodes as unknown as RuntimeSchema, null)).toThrow(TypeError)
    expect(() => complete(RULES as unknown as RuntimeSchema, null)).toThrow(TypeError)
  })
})

describe('complete(): a value that is not understood is the default', () => {
  it('a value of another kind', () => {
    const v = merge({
      tokens: { color: { primary: { r: 1 } }, space: { md: '16px' }, motion_style: { enabled: 'yes' }, typography: { body: 'big' } },
      components: { button: { base: { mobile_full_width: 1, radius: '8px' } }, card: 'x' },
    })
    expect(v.tokens.color.primary).toBe(D.tokens.color.primary)
    expect(v.tokens.space.md).toBe(D.tokens.space.md)
    expect(v.tokens.motion_style.enabled).toBe(D.tokens.motion_style.enabled)
    expect(v.tokens.typography.body).toEqual(D.tokens.typography.body)
    expect(v.components.button.base.mobile_full_width).toBe(D.components.button.base.mobile_full_width)
    expect(v.components.button.base.radius).toBe(D.components.button.base.radius)
    expect(v.components.card).toEqual(D.components.card)
  })

  it('an option the schema does not list', () => {
    const v = merge({
      tokens: { layout: { mode: 'grid' }, font: { body: 'comic_sans' }, shadow_style: { style: 'huge' } },
      components: { header: { base: { logo_position: 'top' } }, home_hero: { base: { fullscreen: 'yes' } }, card: { base: { font_role: 'display' } } },
    })
    expect(v.tokens.layout.mode).toBe(D.tokens.layout.mode)
    expect(v.tokens.font.body).toBe(D.tokens.font.body)
    expect(v.tokens.shadow_style.style).toBe(D.tokens.shadow_style.style)
    expect(v.components.header.base.logo_position).toBe(D.components.header.base.logo_position)
    expect(v.components.home_hero.base.fullscreen).toBe(D.components.home_hero.base.fullscreen)
    expect(v.components.card.base.font_role).toBe(D.components.card.base.font_role)
    // The listed ones pass.
    const ok = merge({ tokens: { layout: { mode: 'appointments' } }, components: { home_hero: { base: { fullscreen: true } }, card: { base: { font_role: 'heading' } } } })
    expect(ok.tokens.layout.mode).toBe('appointments')
    expect(ok.components.home_hero.base.fullscreen).toBe(true)
    expect(ok.components.card.base.font_role).toBe('heading')
  })

  it('null only where the default is null, or a border or a shadow', () => {
    const v = merge({
      tokens: { color: { primary: null }, space: { md: null } },
      components: {
        button: { appearances: { solid: { states: { hover: { background: null }, pressed: { background: null } } } } },
        card: { base: { shadow: null, border: null } },
      },
    })
    expect(v.tokens.color.primary).toBe(D.tokens.color.primary)
    expect(v.tokens.space.md).toBe(D.tokens.space.md)
    expect(D.components.button.appearances.solid.states.hover.background).toBeNull()
    expect(v.components.button.appearances.solid.states.hover.background).toBeNull()
    expect(v.components.button.appearances.solid.states.pressed.background).toBe(D.components.button.appearances.solid.states.pressed.background)
    expect(v.components.card.base.shadow).toBeNull()
    expect(v.components.card.base.border).toBeNull()
    // A section is never null.
    expect(merge({ tokens: null, components: { button: null } }).components.button).toEqual(D.components.button)
  })

  it('an invalid color, an unresolved alias included; a valid one, or a role where the property takes one, passes', () => {
    const bad = ['azul', '#12', '{color.primary}', 'red; } body { display: none', 12, '', 'red', 'hsl(0, 0%, 0%)']
    for (const color of bad) {
      const v = merge({ tokens: { color: { primary: color } }, components: { button: { appearances: { solid: { background: color } } } } })
      expect(v.tokens.color.primary, String(color)).toBe(D.tokens.color.primary)
      expect(v.components.button.appearances.solid.background, String(color)).toBe(D.components.button.appearances.solid.background)
    }
    for (const color of ['transparent', 'rgba(0,0,0,0.5)', '#FFF8', '#1E72E3', 'rgb(1, 2, 3)']) {
      expect(merge({ tokens: { color: { overlay: color } } }).tokens.color.overlay).toBe(color)
    }
    expect(merge({ components: { button: { appearances: { solid: { background: '{subtle}' } } } } }).components.button.appearances.solid.background).toBe('{subtle}')
    // A role only where the property is a role.
    expect(merge({ tokens: { color: { primary: '{main}' } } }).tokens.color.primary).toBe(D.tokens.color.primary)
  })

  it("a text style field by field: an invalid or missing field is the default's", () => {
    const body = D.tokens.typography.body
    const v = merge({ tokens: { typography: { body: { size: 'big', line: 1.4, weight: 'bold', family: 'comic_sans', transform: 'lowercase', color: 'red' } } } })
    expect(v.tokens.typography.body).toEqual({ size: body.size, line: 1.4, weight: body.weight, family: body.family })
    expect(merge({ tokens: { typography: { body: { size: 15, weight: 450, family: 'inter' } } } }).tokens.typography.body)
      .toEqual({ size: 15, line: body.line, weight: body.weight, family: 'inter' })
    // A component's own style without a family keeps none.
    expect(merge({ components: { product_card: { base: { name: { size: 17 } } } } }).components.product_card.base.name)
      .toEqual({ size: 17, line: D.components.product_card.base.name.line, weight: D.components.product_card.base.name.weight })
  })

  it('a border and a shadow field by field', () => {
    const outline = D.components.button.appearances.outline
    const v = merge({
      components: {
        button: { appearances: { outline: { border: { width: 'thick', color: 'azul', style: 'wavy' } } } },
        card: { base: { shadow: { x: 'a', y: 3, blur: 6, color: '#112233', opacity: 0.3 } } },
      },
    })
    expect(v.components.button.appearances.outline.border).toEqual(outline.border)
    expect(v.components.card.base.shadow).toEqual({ ...D.components.card.base.shadow, y: 3, blur: 6, color: '#112233', opacity: 0.3 })
    // A border's color may be a role of the component's color; a shadow's may not.
    expect(merge({ components: { button: { appearances: { outline: { border: { width: 2, color: '{main}' } } } } } }).components.button.appearances.outline.border)
      .toEqual({ width: 2, color: '{main}', style: outline.border.style })
    expect(merge({ components: { card: { base: { shadow: { ...D.components.card.base.shadow, color: '{main}' } } } } }).components.card.base.shadow)
      .toEqual(D.components.card.base.shadow)
    // A border or a shadow the default does not complete (its default is null) stays the default.
    expect(outline.shadow).toBeNull()
    expect(merge({ components: { button: { appearances: { outline: { shadow: { y: 2 } } } } } }).components.button.appearances.outline.shadow).toBeNull()
    expect(merge({ components: { input: { base: { border: { width: 'thick', color: 'azul' } } } } }).components.input.base.border).toBeNull()
  })

  it("an id, a nullable boolean and a value without a rule, by its default's kind", () => {
    const site = merge({ site: { franchise_id: 1.5, franchise_slug: 'x'.repeat(256), home_html: 3, footer_html: '<p>hi</p>', tracking_only: 1 } }).site
    expect(site).toMatchObject({ franchise_id: D.site.franchise_id, franchise_slug: D.site.franchise_slug, home_html: D.site.home_html, footer_html: '<p>hi</p>', tracking_only: D.site.tracking_only })
    expect(merge({ site: { franchise_id: 12, franchise_slug: 'ñ'.repeat(127) } }).site).toMatchObject({ franchise_id: 12, franchise_slug: 'ñ'.repeat(127) })
  })
})

describe('complete(): numbers are clamped to their range', () => {
  it("a token, a component property, a text style's fields, opacity, a shadow and a border", () => {
    const rules = RULES.values
    const v = merge({
      tokens: {
        space: { md: 999 },
        opacity: { disabled: 2 },
        typography: { body: { size: -4, line: 5, letter_spacing: 30 }, h1: { size: 999, line: 0.5 } },
        layout: { page_gutter: -10 },
      },
      components: {
        button: { sizes: { md: { height: 9999, text: { size: 1000 } } }, appearances: { outline: { border: { width: -1, color: '#000000', style: 'solid' } } } },
        card: { base: { shadow: { ...D.components.card.base.shadow, x: 500, opacity: 3, elevation: 99 } } },
      },
    })
    expect(v.tokens.space.md).toBe(max(rules.tokens.space.md))
    expect(v.tokens.opacity.disabled).toBe(max(rules.tokens.opacity.disabled))
    expect(v.tokens.typography.body.size).toBe(min(rules.tokens.typography.body[1]?.size ?? RULES.text.size))
    expect(v.tokens.typography.body.line).toBe(max(rules.tokens.typography.body[1]?.line ?? RULES.text.line))
    expect(v.tokens.typography.body.letter_spacing).toBe(max(rules.tokens.typography.body[1]?.letter_spacing ?? RULES.text.letter_spacing))
    expect(v.tokens.typography.h1.size).toBe(max(rules.tokens.typography.h1[1]?.size ?? RULES.text.size))
    expect(v.tokens.typography.h1.line).toBe(min(rules.tokens.typography.h1[1]?.line ?? RULES.text.line))
    expect(v.tokens.layout.page_gutter).toBe(min(rules.tokens.layout.page_gutter))
    expect(v.components.button.sizes.md.height).toBe(max(rules.components.button.sizes.md.height))
    expect(v.components.button.sizes.md.text.size).toBe(max(RULES.text.size))
    expect(v.components.card.base.shadow).toMatchObject({ x: 128, opacity: 1, elevation: 24 })
    expect(v.components.button.appearances.outline.border.width).toBe(0)
    // The ranges come from the limits.
    expect(RULES.text).toMatchObject({ size: [10, 72], line: [1, 2], letter_spacing: [-2, 4] })
  })

  it("a level's property to its scale's levels, as runtimeRules() widens it", () => {
    const iconGap = RULES.values.components.button.base.icon_gap
    expect(iconGap).toEqual([0, 96])
    expect(merge({ components: { button: { base: { icon_gap: 500 } } } }).components.button.base.icon_gap).toBe(96)
    expect(merge({ components: { button: { base: { icon_gap: 48 } } } }).components.button.base.icon_gap).toBe(48)
  })
})

describe('complete(): keys the schema lacks are dropped', () => {
  it('in every block', () => {
    const v = merge({
      extra: 1,
      tokens: { color: { accent: '#21BC9E' }, spacing: { md: 15 } },
      components: { user_menu: { base: {} }, button: { base: { foo: 1 }, variants: { accent: { solid: {} } } } },
      visibility: { 'nope.nope': false },
      images: { nope: 'https://cdn.test/a.png' },
      site: { foo: 'bar' },
      pages: { nope: { footer: true } },
      modes: [{ dark: {} }],
    })
    expect(v.extra).toBeUndefined()
    expect(v.tokens.color.accent).toBeUndefined()
    expect(v.tokens.spacing).toBeUndefined()
    expect(v.components.user_menu).toBeUndefined()
    expect(v.components.button.base.foo).toBeUndefined()
    expect(v.components.button.variants.accent).toBeUndefined()
    expect('nope.nope' in v.visibility).toBe(false)
    expect('nope' in v.images).toBe(false)
    expect(v.site.foo).toBeUndefined()
    expect(v.pages.nope).toBeUndefined()
    expect(v.modes).toEqual(D.modes)
  })

  it("a matrix component's variants are checked as their appearance", () => {
    const v = merge({ components: { button: { variants: { primary: { outline: { background: 'azul', color: '#112233', border: { width: 99999, color: '#112233', style: 'solid' } } } } } } })
    const outline = v.components.button.variants.primary.outline
    expect(outline.background).toBe(D.components.button.variants.primary.outline.background)
    expect(outline.color).toBe('#112233')
    expect(outline.border.width).toBe(9999)
  })

  it('of another version of the schema, or partial (a version 1 theme converted), the rest from the defaults', () => {
    const v = merge({ tokens: { color: { primary: '#123456', surface_raised: '#FFFFFF' } }, components: { button: { base: { radius: 4, cta_full_width: true } } } })
    expect(v.tokens.color).toEqual({ ...D.tokens.color, primary: '#123456' })
    expect(v.components.button.base).toEqual({ ...D.components.button.base, radius: 4 })
    expect(Object.keys(v)).toEqual(Object.keys(D))
    expect(merge([])).toEqual(D)
    expect(merge({})).toEqual(D)
  })
})

describe('complete(): the open blocks', () => {
  it("platforms: a property the platform's defaults lack, by the component's rules", () => {
    const v = merge({
      platforms: {
        ios: { components: { button: { base: { radius: 4 } } } },
        mobile_web: {
          components: {
            button: { base: { radius: 4, icon_gap: 'x', foo: 1 } },
            card: { base: { shadow: null, background: 'azul' } },
            product_section: { base: { columns: 4 } },
            home_hero: { base: { field_text: { size: 15 } } },
            nope: { base: { radius: 4 } },
            header: { slots: { cart: { size: 'sm' } } },
          },
        },
      },
    })
    expect(v.platforms.ios).toBeUndefined()
    const phone = v.platforms.mobile_web.components
    expect(phone.button.base).toEqual({ ...D.platforms.mobile_web.components.button?.base, radius: 4 })
    expect(phone.card.base).toEqual({ shadow: null })
    // The platform's own limit (1–2 columns on phones, 1–4 on the rest).
    expect(RULES.platform_values.mobile_web.components.product_section.base.columns).toEqual([1, 2])
    expect(phone.product_section.base.columns).toBe(2)
    expect(merge({ components: { product_section: { base: { columns: 4 } } } }).components.product_section.base.columns).toBe(4)
    // A text style over the platform's default (without a family it keeps none).
    const { family, ...fieldText } = D.platforms.mobile_web.components.home_hero.base.field_text
    expect(family).toBeTruthy()
    expect(phone.home_hero.base.field_text).toEqual({ ...fieldText, size: 15 })
    expect(phone.nope).toBeUndefined()
    expect(phone.header).toBeUndefined()
    // What the theme leaves keeps the platform's defaults.
    expect(phone.empty_state).toEqual(D.platforms.mobile_web.components.empty_state)
  })

  it('platforms: visibility keys of the schema and pages', () => {
    expect(D.platforms.mobile_web.visibility).toEqual({ footer: false })
    const v = merge({
      platforms: {
        mobile_web: {
          visibility: { 'business.header.logo': true, footer: true, 'nope.nope': true, 'home.hero.logo': 'no' },
          pages: { home: { footer: false, sections: [{ key: 'content', visible: false }, { key: 'nope' }] }, nope: { footer: true }, checkout: 'x' },
        },
      },
    })
    const phone = v.platforms.mobile_web
    expect(phone.visibility).toEqual({ footer: true, 'business.header.logo': true })
    expect(phone.pages).toEqual({ home: { footer: false, sections: [{ key: 'content', visible: false }] } })
  })

  it("pages: the theme's order with the page's own sections, once each", () => {
    const home = D.pages.home
    const v = merge({
      pages: {
        home: { footer: 'yes', sections: [{ key: 'content', visible: false }, { key: 'nope', visible: true }, { key: 'hero', visible: 'no' }, { key: 'content', visible: true }, 'x'] },
        checkout: { footer: false, sections: {} },
      },
    })
    expect(v.pages.home.footer).toBe(home.footer)
    expect(v.pages.home.sections).toEqual([{ key: 'content', visible: false }, { key: 'hero', visible: true }])
    expect(v.pages.checkout).toEqual({ ...D.pages.checkout, footer: false })
  })

  it('site: each setting by its rule', () => {
    const v = merge({
      site: {
        mode: 'shop',
        name: 'x'.repeat(61),
        description: 'Fresh food',
        tracking_only: 'true',
        franchise_id: -1,
        franchise_slug: 'my-franchise',
        home_html: 3,
        third_party_code: { head: '<script></script>', body: null },
        smart_banner: { apple_id: '12ab', android_id: 'com.example.app' },
      },
    })
    expect(v.site.mode).toBe(D.site.mode)
    expect(v.site.name).toBe(D.site.name)
    expect(v.site.description).toBe('Fresh food')
    expect(v.site.tracking_only).toBe(D.site.tracking_only)
    expect(v.site.franchise_id).toBe(D.site.franchise_id)
    expect(v.site.franchise_slug).toBe('my-franchise')
    expect(v.site.home_html).toBe(D.site.home_html)
    expect(v.site.third_party_code).toEqual({ head: '<script></script>', body: D.site.third_party_code.body })
    expect(v.site.smart_banner).toEqual({ apple_id: D.site.smart_banner.apple_id, android_id: 'com.example.app' })
    const ok = merge({ site: { mode: 'franchise', franchise_id: 12, tracking_only: true, name: 'x'.repeat(60), smart_banner: { apple_id: '123456789' } } }).site
    expect(ok).toMatchObject({ mode: 'franchise', franchise_id: 12, tracking_only: true, name: 'x'.repeat(60), smart_banner: { apple_id: '123456789' } })
  })

  it('slots: the axes the slot declares, by its component', () => {
    const slot = D.components.input.slots.trailing_action
    expect(slot.component).toBe('button')
    const v = merge({ components: { input: { slots: { trailing_action: { component: 'chip', size: 'xl', appearance: 'outline', shape: 'icon', color: 7, variant: 'primary' } }, nope: {} } } })
    // `variant` is a part of the button, but not an axis this slot declares.
    expect(v.components.input.slots).toEqual({ trailing_action: { ...slot, appearance: 'outline', shape: 'icon' } })
    expect(merge({ components: { input: { slots: { trailing_action: { shape: null, color: 'danger' } } } } }).components.input.slots.trailing_action)
      .toEqual({ ...slot, shape: null, color: 'danger' })
  })

  it('scope: the components the group may change, each as that component', () => {
    const v = merge({
      components: {
        header: { scope: { button: { base: { radius: 'x', icon_gap: 2 } }, input: { base: { radius: 2 } }, tabs: {} } },
        category_tabs: { scope: { tabs: { base: { icon_gap: 9999 } } } },
      },
    })
    expect(v.components.header.scope.button).toEqual({ ...D.components.button, base: { ...D.components.button.base, icon_gap: 2 } })
    expect(v.components.header.scope.input).toBeUndefined()
    expect(v.components.header.scope.tabs).toEqual({})
    // A partial one is completed from the theme's own component (the API serves it whole).
    expect(v.components.category_tabs.scope.tabs.base).toEqual({ ...D.components.tabs.base, icon_gap: max(RULES.values.components.tabs.base.icon_gap) })
    expect(v.components.category_tabs.scope.tabs.variants).toEqual(D.components.tabs.variants)
  })

  it("scope: what a scoped component lacks comes from the theme's global one, already checked", () => {
    const v = merge({
      components: {
        button: { base: { radius: 4, icon_gap: 'x' } },
        header: { scope: { button: { base: { icon_gap: 2 } } } },
      },
    })
    expect(v.components.button.base).toMatchObject({ radius: 4, icon_gap: D.components.button.base.icon_gap })
    expect(v.components.header.scope.button.base).toMatchObject({ radius: 4, icon_gap: 2 })
  })

  it('images: an http(s) URL of up to 2048 bytes, or none', () => {
    const [logo, mobile] = Object.keys(D.images)
    for (const url of ['/a.png', 'javascript:alert(1)', 'data:image/png;base64,AA', 3, null, `https://cdn.test/${'a'.repeat(2048)}`, 'https://cdn.test/a b.png']) {
      expect(merge({ images: { [logo!]: url } }).images[logo!], String(url).slice(0, 30)).toBe(D.images[logo!])
    }
    expect(merge({ images: { [logo!]: 'http://cdn.test/a.png', [mobile!]: 'https://cdn.test/i.png' } }).images)
      .toMatchObject({ [logo!]: 'http://cdn.test/a.png', [mobile!]: 'https://cdn.test/i.png' })
    expect(merge({ images: { [logo!]: '' } }).images[logo!]).toBe('')
  })

  it("modes: a mode's colors, of the schema's own colors", () => {
    const v = merge({ modes: { dark: { color: { primary: '#000000', background: 'azul', accent: '#FFFFFF' }, space: { md: 1 } }, sepia: { color: { primary: '#000000' } } } })
    expect(v.modes).toEqual({ dark: { color: { primary: '#000000' } } })
    expect(merge({ modes: { dark: { color: { nope: '#000000' } } } }).modes).toEqual({})
  })
})

describe('complete(): inputs', () => {
  it('leaves the compiled form and the theme untouched, sharing the defaults it does not change', () => {
    const before = JSON.stringify(C)
    const theme = { tokens: { typography: { body: { size: -4 } } }, platforms: { mobile_web: { components: { button: { base: { radius: 4 } } } } } }
    const copy = JSON.stringify(theme)
    const answer = merge(theme)
    expect(JSON.stringify(C)).toBe(before)
    expect(JSON.stringify(theme)).toBe(copy)
    expect(merge(D)).toEqual(D)
    // What the theme leaves out is the defaults, shared by every answer; each answer is an object of its own.
    expect(merge(null).tokens).toBe(merge(null).tokens)
    expect(answer.components).toBe(D.components)
    expect(merge(null)).not.toBe(merge(null))
    expect(merge(null)).toEqual(themeDefaults(nodes as NodeSchema, true))
  })

  it('never writes into the compiled form or what it shares', () => {
    const freeze = (value: unknown): unknown => {
      if (value !== null && typeof value === 'object') {
        Object.values(value).forEach(freeze)
        Object.freeze(value)
      }
      return value
    }
    const frozen = freeze(JSON.parse(JSON.stringify(C))) as RuntimeSchema
    const defaults = freeze(complete(frozen, null)) as Tree
    const served = [
      defaults,
      { tokens: { color: { primary: '#000000' }, typography: { body: { size: 999 } } } },
      { components: { button: { base: { radius: 4 }, slots: {} }, category_tabs: { scope: { tabs: { base: { icon_gap: 2 } } } } } },
      { platforms: { mobile_web: { components: { product_section: { base: { columns: 9 } } }, visibility: { footer: true }, pages: { home: { footer: null } } } } },
      { visibility: { footer: false }, pages: { home: { sections: [] } }, modes: { dark: { color: { primary: '#000000' } } }, images: { logo: 'https://x.test/a.png' } },
    ]
    for (const values of served) {
      const answer = complete(frozen, values) as Tree
      answer.images = {}
      expect(answer.images).toEqual({})
    }
  })
})
