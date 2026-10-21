import { describe, expect, it } from 'vitest'
import { deriveColors } from '../src/colors.js'
import { insets, matrices, shades } from '../src/derivations.js'
import { applyMigrations } from '../src/migrations.js'
import { applyOverall, follows } from '../src/overall.js'
import { kindAt, schemaDefaults } from '../src/schemas.js'
import { check, compile, defaults, resolve, siteDefaults, type JsonMap, type NodeSchema, type SchemaError, type V1Map } from '../src/index.js'
import { cases, differences, goldenSchema, json, schemaFor } from './fixtures.js'

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

  // Prior behavior: empty maps came out as `[]` unless asked, while api-core serves `{}`.
  it('writes every empty map as an object, as api-core serves it, and sections as lists', () => {
    const resolved = resolve(schema, {}) as JsonMap
    const lists: string[] = []
    const walk = (value: unknown, path: string): void => {
      if (Array.isArray(value)) {
        lists.push(path)
      }
      if (typeof value === 'object' && value !== null) {
        Object.entries(value).forEach(([key, child]) => walk(child, `${path}.${key}`))
      }
    }
    walk(resolved, '')
    expect(resolved.modes).toStrictEqual({})
    expect(lists.filter((path) => !/^\.pages\.[a-z_]+\.sections$/.test(path))).toEqual([])
    expect(lists.length).toBeGreaterThan(0)
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
    expect(differences(resolve(schemaFor(undeclared, golden), golden.values), golden.resolved)).toEqual([])
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

  // What api-core's NodeCompiler::compile() answers for the current schema (scripts/compile-schema.php).
  it('answers the tuples api-core compiles for the current schema, its translucent colors included', () => {
    const golden = json<{ alpha: Record<string, boolean>; schema: JsonMap; errors: SchemaError[] }>('schema-26.0.compiled.json')
    const nodes = json<NodeSchema>('schema-26.0.nodes.json')
    // The editor's `alpha` on the nodes the script marks.
    for (const [path, mark] of Object.entries(golden.alpha)) {
      let node = nodes as unknown as Record<string, JsonMap>
      for (const segment of path.split('.')) {
        node = node[segment] as unknown as Record<string, JsonMap>
      }
      const extensions = (node.$extensions ??= {})
      extensions['co.ordering.editor'] = { ...(extensions['co.ordering.editor'] as JsonMap | undefined), alpha: mark }
    }
    const { schema, errors } = compile(nodes, json<V1Map>('v1-map.json'))
    expect(errors).toEqual(golden.errors)
    expect(differences(schema, golden.schema)).toEqual([])
    expect([...schema.alpha as string[]].sort()).toEqual(Object.keys(golden.alpha).filter((path) => golden.alpha[path]).sort())
  })
})

describe('siteDefaults', () => {
  const nodes = json<NodeSchema>('schema-26.0.nodes.json')
  const compiled = compile(nodes, json<V1Map>('v1-map.json'))

  it('reads the compiled schema', () => {
    expect(compiled.errors).toEqual([])
    expect(Object.keys(siteDefaults(compiled.schema)).length).toBeGreaterThan(0)
  })

  // Either would give the raw node tree or {} without a word.
  it('refuses the nodes and what compile() answers', () => {
    expect(() => siteDefaults(nodes as unknown as JsonMap)).toThrow(new TypeError('siteDefaults() reads a compiled schema, not one in nodes: pass compile(schema).schema.'))
    expect(() => siteDefaults(compiled as unknown as JsonMap)).toThrow(new TypeError("siteDefaults() reads a compiled schema, not compile()'s answer: pass compile(schema).schema."))
  })
})

describe('applyMigrations, as the PHP ThemeSchemaPublicationTest::testAThemeTakesWhatTheSchemaMovedWhereItWent', () => {
  it('moves each value unless its new place is set, and drops the maps it leaves empty', () => {
    const migrations = [
      { from: 'components.card.base.bg', to: 'components.card.base.background' },
      { from: 'visibility.business.info', to: 'visibility.business.header.info' },
      { from: 'components.chip.base.bg', to: 'components.chip.base.background' },
    ]
    const values = applyMigrations({
      components: { card: { base: { bg: '#FFF8E1' } }, chip: { base: { bg: '#000000', background: '#FDE68A' } } },
      visibility: { 'business.info': false },
    }, migrations)

    expect(values).toEqual({
      components: { chip: { base: { background: '#FDE68A' } }, card: { base: { background: '#FFF8E1' } } },
      visibility: { 'business.header.info': false },
    })
    // A map left empty goes; one recreated comes last.
    expect(Object.keys(values.components as JsonMap)).toEqual(['chip', 'card'])
  })
})

describe('migrations of visibility, images and pages, as the PHP ThemeSchemaPublicationTest', () => {
  const schema = (): JsonMap => compile(json<NodeSchema>('schema-26.0.nodes.json'), json<V1Map>('v1-map.json')).schema
  const codes = (errors: SchemaError[]): string[] => errors.map((error) => error.code)

  // Prior behavior: kindAt() knew no visibility, image or page path.
  it('gives visibility, images and pages the kind of the schema\'s own keys', () => {
    const compiled = schema()
    const kinds = (paths: string[]): Array<string | null> => paths.map((path) => kindAt(compiled, path))
    expect(kinds(['visibility.footer', 'visibility.business.header.info', 'images.logo', 'images.empty.not_found', 'pages.home', 'pages.home.footer', 'pages.home.sections', 'pages.home.sections.hero']))
      .toEqual(['bool', 'bool', 'image', 'image', 'page', 'bool', 'sections', 'section'])
    expect(kinds(['visibility', 'visibility.business', 'images.nope', 'pages', 'pages.nope', 'pages.home.extra', 'pages.home.sections.nope', 'pages.home.sections.hero.visible', 'pages.cards.sections.hero']))
      .toEqual(Array(9).fill(null))

    // A version 1 path is a token's, a component's or a site setting's: the others carry theirs.
    compiled.v1 = { 'visibility.footer': 'some.hidden', 'images.logo': 'some.image', 'pages.home.footer': 'some.footer' }
    expect(codes(check(compiled))).toEqual(['v1.visibility.footer.invalid', 'v1.images.logo.invalid', 'v1.pages.home.footer.invalid'])
  })

  it('keeps the kind of what a migration moves', () => {
    const compiled = schema()
    compiled.migrate = [
      { from: 'visibility.header.brand', to: 'visibility.header.logo' },
      { from: 'images.brand_logo', to: 'images.logo' },
      { from: 'pages.shop', to: 'pages.business_listing' },
      { from: 'pages.shop.footer', to: 'pages.home.footer' },
      { from: 'pages.shop.sections', to: 'pages.home.sections' },
      { from: 'pages.home.sections.banner', to: 'pages.home.sections.promo_banners' },
      // Invalid from here: a key that is no key, across sections, and kinds that differ.
      { from: 'visibility.Header-Brand', to: 'visibility.header.logo' },
      { from: 'images.', to: 'images.logo' },
      { from: 'visibility.home.banner', to: 'pages.home.sections.promo_banners' },
      { from: 'pages.shop.footer', to: 'pages.home.sections.hero' },
      { from: 'pages.shop', to: 'pages.home.footer' },
      { from: 'pages.shop.sections.hero', to: 'pages.home' },
      { from: 'pages.shop.sections.Hero', to: 'pages.home.sections.hero' },
      { from: 'pages.home.sections.hero.visible', to: 'pages.home.sections.hero' },
    ]
    expect(codes(check(compiled))).toEqual([6, 7, 8, 9, 10, 11, 12, 13].map((index) => `migrate.${index}.invalid`))
  })

  // Prior behavior: a section's path named no value of the theme (its
  // sections are a list), and a platform's override was left where it was.
  it('moves a section as an item of its list, and a platform follows', () => {
    const section = (key: string, visible: boolean): JsonMap => ({ key, visible })
    const apply = (values: JsonMap, from: string, to: string): JsonMap => applyMigrations(structuredClone(values), [{ from, to }])
    const home: JsonMap = { pages: { home: { footer: false, sections: [section('hero', true), section('banner', false), section('content', true)] } } }

    // Within the page: renamed in its place, with its `visible`.
    expect(apply(home, 'pages.home.sections.banner', 'pages.home.sections.promo'))
      .toEqual({ pages: { home: { footer: false, sections: [section('hero', true), section('promo', false), section('content', true)] } } })
    // The new key set already: the old one is dropped.
    expect(apply(home, 'pages.home.sections.banner', 'pages.home.sections.content'))
      .toEqual({ pages: { home: { footer: false, sections: [section('hero', true), section('content', true)] } } })
    // To another page: at the end of its list, created when it has none.
    expect(apply(home, 'pages.home.sections.banner', 'pages.business.sections.offers')).toEqual({ pages: {
      home: { footer: false, sections: [section('hero', true), section('content', true)] },
      business: { sections: [section('offers', false)] },
    } })
    // A list left empty goes, with each map it leaves empty.
    expect(apply({ pages: { home: { sections: [section('hero', true)] }, business: { sections: [section('menu', true)] } } }, 'pages.home.sections.hero', 'pages.business.sections.hero'))
      .toEqual({ pages: { business: { sections: [section('menu', true), section('hero', true)] } } })
    // Every item of the old key goes; the first one moves.
    expect(apply({ pages: { home: { sections: [section('banner', false), section('hero', true), section('banner', true)] } } }, 'pages.home.sections.banner', 'pages.home.sections.promo'))
      .toEqual({ pages: { home: { sections: [section('promo', false), section('hero', true)] } } })
    // Onto itself, or between a section and anything else, nothing moves.
    expect(apply(home, 'pages.home.sections.banner', 'pages.home.sections.banner')).toEqual(home)
    expect(apply(home, 'pages.home.sections.banner', 'pages.home.footer')).toEqual(home)
    expect(apply(home, 'pages.home.footer', 'pages.home.sections.banner')).toEqual(home)

    // A platform's override moves by the same path: a visibility key, a page, a component property.
    // Each map left empty goes, and comes back last.
    const moved = applyMigrations({
      visibility: { 'header.brand': false },
      platforms: { mobile_web: {
        components: { card: { base: { corner: '{radius.sm}' } } },
        visibility: { 'header.brand': true },
        pages: { shop: { footer: true } },
      } },
    }, [
      { from: 'visibility.header.brand', to: 'visibility.header.logo' },
      { from: 'pages.shop', to: 'pages.business_listing' },
      { from: 'components.card.base.corner', to: 'components.card.base.radius' },
    ])
    // In the same order as PHP's.
    expect(JSON.stringify(moved)).toBe(JSON.stringify({
      platforms: { mobile_web: {
        visibility: { 'header.logo': true },
        pages: { business_listing: { footer: true } },
        components: { card: { base: { radius: '{radius.sm}' } } },
      } },
      visibility: { 'header.logo': false },
    }))
  })
})

describe('tokens in migrations, as the PHP ThemeSchemaPublicationTest', () => {
  const schema = (): JsonMap => {
    const compiled = compile(json<NodeSchema>('schema-26.0.nodes.json'), json<V1Map>('v1-map.json')).schema
    delete ((compiled.tokens as JsonMap).typography as JsonMap).overline
    return compiled
  }
  const codes = (errors: SchemaError[]): string[] => errors.map((error) => error.code)

  // Prior behavior: only a color, a font role and the layout's mode had a kind.
  it('gives every token the kind of its group, by the schema\'s own names', () => {
    const compiled = schema()
    const kinds = (paths: string[]): Array<string | null> => paths.map((path) => kindAt(compiled, path))
    expect(kinds(['tokens.color.primary', 'tokens.font.body', 'tokens.layout.mode', 'tokens.layout.page_gutter', 'tokens.typography.h1', 'tokens.opacity.scrim', 'tokens.radius.md', 'tokens.space.xxxl', 'tokens.shadow_style.color', 'tokens.scrollbar.size']))
      .toEqual(['color', 'font', 'layout_mode', 'layout', 'typography', 'opacity', 'radius', 'space', 'shadow_style', 'scrollbar'])
    expect(kinds(['tokens.typography.overline', 'tokens.typography.small', 'tokens.typography', 'tokens.nope.sm', 'tokens.radius.md.more']))
      .toEqual(Array(5).fill(null))
  })

  // Prior behavior: a token other than a color could not be migrated, and a
  // `from` the schema still has as a group passed.
  it('migrates a token by its group, and takes no group the schema still has as a from', () => {
    const compiled = schema()
    compiled.migrate = [
      { from: 'tokens.typography.overline', to: 'tokens.typography.caption' },
      { from: 'tokens.opacity.dim', to: 'tokens.opacity.scrim' },
      { from: 'tokens.color.brand', to: 'tokens.color.primary' },
      { from: 'tokens.color', to: 'tokens.color.primary' },
      { from: 'components.button.base', to: 'components.button.base.radius' },
      { from: 'components.card.variants.default', to: 'components.card.base.radius' },
    ]
    expect(codes(check(compiled))).toEqual(['migrate.3.invalid', 'migrate.4.invalid', 'migrate.5.invalid'])
  })

  // Prior behavior: a color's value in each mode stayed, and its aliases
  // named a color the schema no longer has.
  it('moves a token with its modes and every alias to it', () => {
    const moved = applyMigrations({
      tokens: { color: { brand: '#E4002B', accent: '#00AA55', secondary: '#123456' }, typography: { overline: { size: 11 } } },
      modes: { dark: { color: { brand: '#FF5A5F', accent: '#33CC77' } } },
      components: {
        button: { colors: { primary: { main: '{color.brand}', on: '{color.on_primary}' } } },
        card: { base: { border: { width: 1, color: '{color.accent}' } } },
        header: { base: { title: '{typography.overline}' } },
      },
      platforms: { mobile_web: { components: { card: { base: { background: '{color.brand}' } } } } },
    }, [
      { from: 'tokens.color.brand', to: 'tokens.color.primary' },
      { from: 'tokens.color.accent', to: 'tokens.color.secondary' },
      { from: 'tokens.typography.overline', to: 'tokens.typography.caption' },
    ])
    // In the same order as PHP's.
    expect(JSON.stringify(moved)).toBe(JSON.stringify({
      tokens: { color: { secondary: '#123456', primary: '#E4002B' }, typography: { caption: { size: 11 } } },
      modes: { dark: { color: { primary: '#FF5A5F', secondary: '#33CC77' } } },
      components: {
        button: { colors: { primary: { main: '{color.primary}', on: '{color.on_primary}' } } },
        card: { base: { border: { width: 1, color: '{color.secondary}' } } },
        header: { base: { title: '{typography.caption}' } },
      },
      platforms: { mobile_web: { components: { card: { base: { background: '{color.primary}' } } } } },
    }))
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

describe('Identity, as the PHP ThemeOverallTest::testAFollowedBorderOfNoneAndAPathThatIsNotThere', () => {
  it('gives a followed border of none a border, and creates no path the defaults do not have', () => {
    const schema = compile(goldenSchema()).schema
    const card = (schema.components as JsonMap).card as JsonMap
    ;(card.base as JsonMap).border = ['border', 'none']
    const declared = schema.follows as JsonMap
    declared['components.card.base.border'] = 'border'
    declared['components.card.variants.ghost.shadow'] = 'shadow'

    const applied = applyOverall(schemaDefaults(schema), schema, { border_width: 'thin', shadow: 'lg' })
    const components = applied.components as JsonMap
    expect(((components.card as JsonMap).base as JsonMap).border).toEqual({ width: '{border.thin}', color: '{color.border}', style: 'solid' })
    expect((components.card as JsonMap).variants).not.toHaveProperty('ghost')
  })
})

describe('compile, as the PHP ThemeSchemaNodesTest::testWhatAGroupOrTheEngineSaysForANodeThatDoesNot', () => {
  const nodes = goldenSchema()
  const declared = compile(nodes).schema

  it('gives a node without a `$type` its group\'s', () => {
    const typed = structuredClone(nodes)
    const primary = (((typed.components as JsonMap).button as JsonMap).colors as JsonMap).primary as JsonMap
    primary.$type = 'color'
    for (const [role, node] of Object.entries(primary)) {
      if (!role.startsWith('$')) {
        delete (node as JsonMap).$type
      }
    }
    const colors = (schema: JsonMap) => (((schema.components as JsonMap).button as JsonMap).colors as JsonMap).primary
    expect(colors(compile(typed).schema)).toEqual(colors(declared))
  })

  it('leaves what follows Identity to the engine\'s rule when no node declares it', () => {
    const undeclared = without(nodes, ['follows'])
    const compiled = compile(undeclared).schema
    expect(compiled).not.toHaveProperty('follows')
    expect(follows(compiled)).toEqual(declared.follows)
  })

  it.each(cases())('%s resolves the same by the engine\'s rule', (_name, golden) => {
    expect(differences(resolve(schemaFor(without(nodes, ['follows']), golden), golden.values), golden.resolved)).toEqual([])
  })
})

describe('deriveColors, as the PHP ThemeColorsTest::testADerivedColorChangesWhatDerivesFromIt', () => {
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

describe('matrices, as the PHP ThemeDerivationsTest::testAMatrixRoleIsLegibleOverAColor', () => {
  it('derives a role legible over a color, and keeps one the color has with `when: missing`', () => {
    const button = {
      colors: { warning: { main: '#F5C400' }, primary: { main: '#1E72E3', ink: '#000000' } },
      appearances: { ghost: { color: '{ink}' } },
    }
    const matrix = { button: { into: 'variants', roles: { ink: { op: 'legible', from: 'main', over: '{color.background}', contrast: 5.5, step: 0.01, when: 'missing' } } } }
    const variants = ((matrices({ button }, { background: '#FFFFFF' }, {}, matrix).button as JsonMap).variants) as JsonMap

    expect(((variants.warning as JsonMap).ghost as JsonMap).color).toBe('#7B6200')
    expect(((variants.primary as JsonMap).ghost as JsonMap).color).toBe('#000000')
  })
})

describe('compile, as the PHP ThemeSchemaNodesTest::testAVisibilityKeyMayShowOtherwiseOnAPlatform', () => {
  it('serves a visibility key\'s platform default with the platform\'s', () => {
    const nodes = goldenSchema() as JsonMap
    const logo = ((nodes.visibility as JsonMap)['business.header.logo'] as JsonMap)
    logo.$extensions = { ...(logo.$extensions as JsonMap ?? {}), 'co.ordering': { platforms: { mobile_web: false } } }
    const { schema, errors } = compile(nodes as NodeSchema)
    expect(errors).toEqual([])
    expect(((schema.visibility as JsonMap)['business.header.logo'] as unknown[])[2]).toEqual({ mobile_web: false })
    expect(check(schema)).toEqual([])
    const resolved = schemaDefaults(schema)
    expect((resolved.visibility as JsonMap)['business.header.logo']).toBe(true)
    expect(((resolved.platforms as JsonMap).mobile_web as JsonMap).visibility).toEqual({ 'business.header.logo': false })

    ;((schema.visibility as JsonMap)['business.header.logo'] as unknown[])[2] = { tv: false }
    expect(check(schema).map((error) => error.code)).toEqual(['visibility.business.header.logo.platforms.invalid'])
  })
})
