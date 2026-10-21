import { describe, expect, it } from 'vitest'
import {
  checkUpgrade, compile, cutDown, diff, themeErrors, upgrade, upgradeSettingsChain, v1MapErrors, valid, validSettings, validate,
  type JsonMap, type NodeSchema, type V1Map,
} from '../src/index.js'
import { applyMigrations } from '../src/migrations.js'
import { json } from './fixtures.js'

/**
 * What a change of a theme schema does to the themes and settings saved
 * with an earlier version, and what publishing it says, case by case, as
 * the PHP ThemeSchemaChangesTest: the regressions of a campaign of schema
 * changes over the product's schema.
 */
type Op = ['set', Array<string | number>, unknown] | ['delete', Array<string | number>]

const nodes = (version: string): NodeSchema => ({ ...structuredClone(json<NodeSchema>('schema-26.0.nodes.json')), version })
const tuples = (): JsonMap => compile(json<NodeSchema>('schema-26.0.nodes.json'), json<V1Map>('v1-map.json')).schema
const codes = (errors: Array<{ code: string }>): string[] => errors.map((error) => error.code)
const at = (schema: unknown, ...path: string[]): any => path.reduce((node: any, key) => node[key], schema)

/** The product's schema made valid as the golden cases make it (fixtures/validate.json's `base`). */
function validSchema(version: string): NodeSchema {
  const answer = structuredClone(json<NodeSchema>('schema-26.0.nodes.json')) as Record<string | number, any>
  for (const op of json<{ base: Op[] }>('validate.json').base) {
    const segments = [...op[1]]
    const last = segments.pop()!
    let node = answer
    for (const segment of segments) {
      if (typeof node[segment] !== 'object' || node[segment] === null) {
        node[segment] = {}
      }
      node = node[segment]
    }
    if (op[0] === 'set') {
      node[last] = structuredClone(op[2])
    } else {
      delete node[last]
    }
  }
  return { ...answer, version } as NodeSchema
}

describe('a change of the schema', () => {
  // Prior behavior: leaving out a section that was not the last one turned the list into a map.
  it("keeps a page's list a list when a section is left out", () => {
    const schema = tuples()
    const values = { pages: { home: { sections: [{ key: 'nope', visible: false }, { key: 'content', visible: false }] } } }
    const kept = valid(values, schema)
    expect(at(kept, 'pages', 'home', 'sections')).toEqual([{ key: 'content', visible: false }])
    expect(Array.isArray(at(kept, 'pages', 'home', 'sections'))).toBe(true)
    expect(themeErrors(kept, schema)).toEqual([])
    expect(cutDown(values, [schema])).toEqual(kept)
  })

  // Prior behavior: a migration of a setting within a base was reported `migrated` and never applied.
  it('moves a setting a migration within the base renames', () => {
    const schema = tuples()
    const site = schema.site as JsonMap
    site.analytics_only = site.tracking_only!
    delete site.tracking_only
    schema.migrate = [{ from: 'site.tracking_only', to: 'site.analytics_only' }]
    expect(validSettings({ tracking_only: true, name: 'Shop' }, schema)).toEqual({ name: 'Shop', analytics_only: true })
    expect(upgradeSettingsChain({ tracking_only: true }, [schema])).toEqual({ analytics_only: true })
  })

  // Prior behavior: SchemaDiff ignored every key of the editor.
  it('takes what the editor validates as a rule', () => {
    const editor = (schema: NodeSchema, ...path: string[]): JsonMap => at(schema, ...path, '$extensions', 'co.ordering.editor')
    const old = nodes('26.1.0')
    editor(old, 'components', 'card', 'base', 'background').alpha = true
    editor(old, 'components', 'link', 'sizes', 'sm', 'text').custom = true

    const patch = nodes('26.1.1')
    editor(patch, 'components', 'card', 'base', 'background').alpha = true
    editor(patch, 'components', 'link', 'sizes', 'sm', 'text').custom = true
    editor(patch, 'components', 'button', 'base', 'icon_gap').max = 64
    expect(codes(diff(old, patch).errors)).toEqual(['schema.patch_changes_values'])
    Object.assign(editor(patch, 'components', 'button', 'base', 'icon_gap'), { max: 96, step: 4, unit: 'px' })
    at(patch, 'components', 'button', 'base', 'icon_gap').$description = 'The gap.'
    expect(diff(old, patch).errors).toEqual([])

    const minor = nodes('26.2.0')
    editor(minor, 'components', 'button', 'base', 'icon_gap').max = 64
    const compared = diff(old, minor)
    expect(codes(compared.errors)).toEqual(['components.link.sizes.sm.text.custom_removed', 'components.card.base.background.alpha_removed'])
    expect(compared.changes.rules_changed).toEqual(['components.button.base.icon_gap', 'components.link.sizes.sm.text', 'components.card.base.background'])
  })

  // Prior behavior: a platform's default was `.removed` in a minor and `.unaccounted` in a new base.
  it("takes a platform's default with its property", () => {
    const old = nodes('26.1.0')
    const moved = (schema: NodeSchema): NodeSchema => {
      const component = at(schema, 'components', 'empty_state')
      component.base.padding_top = component.base.padding_y
      component.platforms.mobile_web.padding_top = component.platforms.mobile_web.padding_y
      delete component.base.padding_y
      delete component.platforms.mobile_web.padding_y
      return schema
    }
    const minor = moved(nodes('26.2.0'))
    minor.$extensions = { 'co.ordering.migrate': [{ from: 'components.empty_state.base.padding_y', to: 'components.empty_state.base.padding_top' }] }
    const compared = diff(old, minor)
    expect(compared.errors).toEqual([])
    expect(compared.changes.migrated).toEqual(['components.empty_state.base.padding_y', 'components.empty_state.platforms.mobile_web.padding_y'])

    const base = moved(nodes('27.0.0'))
    base.$extensions = { 'co.ordering.upgrade': { from: 26, moves: [{ from: 'components.empty_state.base.padding_y', to: 'components.empty_state.base.padding_top' }], drop: [] } }
    expect(checkUpgrade(base, old)).toEqual([])
    expect(upgrade(
      { components: { empty_state: { base: { padding_y: 40 } } }, platforms: { mobile_web: { components: { empty_state: { base: { padding_y: 24 } } } } } },
      base,
      old,
    )).toEqual({ components: { empty_state: { base: { padding_top: 40 } } }, platforms: { mobile_web: { components: { empty_state: { base: { padding_top: 24 } } } } } })
  })

  // Prior behavior: a map over a boolean dropped every value.
  it('translates a boolean by `true` and `false`', () => {
    expect(applyMigrations({ site: { kiosk_only: true } }, [{ from: 'site.kiosk_only', to: 'site.kiosk', map: { true: 'on', false: 'off' } }]))
      .toEqual({ site: { kiosk: 'on' } })
    expect(applyMigrations({ visibility: { hide_prices: false } }, [{ from: 'visibility.hide_prices', to: 'visibility.prices', map: { true: false, false: true } }]))
      .toEqual({ visibility: { prices: true } })
  })

  // Prior behavior: a mode's colors were checked against the catalog.
  it("takes a mode's colors from the schema's own", () => {
    const schema = tuples()
    const values = { modes: { dark: { color: { primary: '#111111', danger_text: '#B02A3E' } } } }
    expect(codes(themeErrors(values, schema))).toEqual(['modes.dark.color.danger_text.unknown'])
    expect(valid(values, schema)).toEqual({ modes: { dark: { color: { primary: '#111111' } } } })
  })

  // Prior behavior: a client of an older version got the default of every renamed key.
  it('undoes, for an older client, what a newer version renamed', () => {
    const older = nodes('26.1.0')
    const newer = nodes('26.2.0')
    const visibility = newer.visibility as JsonMap
    visibility['header.badge'] = visibility['header.logo']!
    delete visibility['header.logo']
    newer.$extensions = { 'co.ordering.migrate': [{ from: 'visibility.header.logo', to: 'visibility.header.badge' }] }
    expect(cutDown(
      { visibility: { 'header.badge': false }, platforms: { mobile_web: { visibility: { 'header.badge': true } } } },
      [newer, older],
    )).toEqual({ visibility: { 'header.logo': false }, platforms: { mobile_web: { visibility: { 'header.logo': true } } } })
  })

  // Prior behavior: the editor's limits and an image's formats were the editor's and the upload's only.
  it('refuses a number out of its limits and an image of another format', () => {
    const schema = tuples()
    const values = {
      tokens: { size: { sm: 90 }, typography: { h1: { size: 30, line: 2.5, weight: 600 } } },
      components: { button: { sizes: { xs: { height: 40, icon_size: '{icon.sm}' } } } },
      images: { favicon: 'https://cdn.example.com/favicon.svg', logo: 'https://cdn.example.com/logo.svg' },
    }
    expect(codes(themeErrors(values, schema))).toEqual([
      'images.favicon.format',
      'tokens.size.sm.out_of_range',
      'tokens.typography.h1.line.out_of_range',
      'components.button.sizes.xs.height.out_of_range',
    ])
    const kept = valid(values, schema)
    expect(kept.components).toEqual({ button: { sizes: { xs: { icon_size: '{icon.sm}' } } } })
    expect(kept.images).toEqual({ logo: 'https://cdn.example.com/logo.svg' })
  })

  // Prior behavior: a page's sections were one value for SchemaDiff.
  it("takes each section of a page as a key", () => {
    const old = nodes('26.1.0')
    const next = nodes('26.2.0')
    const sections = at(old, 'pages', 'home', 'sections', '$value')
    at(next, 'pages', 'home', 'sections').$value = [sections[0], { ...sections[1], hideable: false }, sections[3], sections[2]]
    const compared = diff(old, next)
    expect(codes(compared.errors)).toEqual(['pages.home.sections.promo_banners.removed'])
    expect(compared.changes.sections_changed).toEqual(['pages.home.sections.login_account', 'pages.home.sections'])
  })

  // Prior behavior: a version 1 map could name what the schema does not have.
  it('takes a version 1 map that names only what the schema has', () => {
    expect(v1MapErrors(tuples(), {
      description: 'Not a path.',
      v1: { 'tokens.color.primary': 'a.b', 'tokens.color.nope': 'a.c' },
      visibility: { footer: 'footer.hidden' },
      images: { logo: null, nope: null },
      pages: { home: { content: { v1: 'x.hidden' }, nope: { v1: 'y.hidden' } } },
      site: { name: 'a.d', nope: 'a.e' },
    })).toEqual([
      { path: 'v1_map.v1.tokens.color.nope', code: 'v1_map.v1.tokens.color.nope.unknown' },
      { path: 'v1_map.images.nope', code: 'v1_map.images.nope.unknown' },
      { path: 'v1_map.pages.home.nope', code: 'v1_map.pages.home.nope.unknown' },
      { path: 'v1_map.site.nope', code: 'v1_map.site.nope.unknown' },
    ])
  })

  // Prior behavior: development said nothing, and the same content was `schema.version_order`.
  it('warns in development of what production refuses, and takes the same content as unchanged', () => {
    const base = validSchema('26.0.0')
    const changed = structuredClone(base)
    at(changed, 'tokens', 'color', 'primary').$value = '#123456'
    expect(validate(base, { previous: base })).toEqual({ code: null, errors: [], warnings: [], changes: null, unchanged: true })
    expect(validate(changed, { previous: base }).code).toBe('schema.version_exists')
    const development = validate(changed, { previous: base, development: true })
    expect(development.code).toBeNull()
    expect(codes(development.warnings)).toEqual(['schema.version_exists', 'schema.patch_changes_values'])
    expect(development.changes?.defaults_changed).toEqual(['tokens.color.primary'])
  })
})
