import { describe, expect, it } from 'vitest'
import {
  checkUpgrade, compile, cutDown, diff, themeErrors, upgrade, upgradeSettingsChain, v1MapErrors, valid, validSettings, validate,
  type JsonMap, type NodeSchema, type V1Map,
} from '../src/index.js'
import { applyMigrations } from '../src/migrations.js'
import { reference, referenceV1Map } from './fixtures.js'

/**
 * What a change of a theme schema does to the themes and settings saved
 * with an earlier version, and what publishing it says, case by case, as
 * the PHP ThemeSchemaChangesTest: the regressions of a campaign of schema
 * changes, over the reference schema.
 */
const nodes = (version: string): NodeSchema => ({ ...structuredClone(reference()), version })
const tuples = (): JsonMap => compile(reference(), referenceV1Map()).schema
const codes = (errors: Array<{ code: string }>): string[] => errors.map((error) => error.code)
const at = (schema: unknown, ...path: string[]): any => path.reduce((node: any, key) => node[key], schema)

describe('a change of the schema', () => {
  // Prior behavior: leaving out a section that was not the last one turned the list into a map.
  it("keeps a page's list a list when a section is left out", () => {
    const schema = tuples()
    const values = { pages: { home: { sections: [{ key: 'nope', visible: false }, { key: 'featured', visible: false }] } } }
    const kept = valid(values, schema)
    expect(at(kept, 'pages', 'home', 'sections')).toEqual([{ key: 'featured', visible: false }])
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
    const editor = (schema: NodeSchema, ...path: string[]): JsonMap => {
      const node = at(schema, ...path)
      node.$extensions ??= {}
      return (node.$extensions['co.ordering.editor'] ??= {})
    }
    const old = nodes('1.1.0')
    editor(old, 'components', 'card', 'base', 'background').alpha = true

    const patch = nodes('1.1.1')
    editor(patch, 'components', 'card', 'base', 'background').alpha = true
    editor(patch, 'components', 'button', 'base', 'icon_gap').max = 64
    expect(codes(diff(old, patch).errors)).toEqual(['schema.patch_changes_values'])
    Object.assign(editor(patch, 'components', 'button', 'base', 'icon_gap'), { max: 32, step: 4, unit: 'px' })
    at(patch, 'components', 'button', 'base', 'icon_gap').$description = 'The gap.'
    expect(diff(old, patch).errors).toEqual([])

    const minor = nodes('1.2.0')
    editor(minor, 'components', 'button', 'base', 'icon_gap').max = 24
    delete editor(minor, 'components', 'input', 'base', 'text').custom
    const compared = diff(old, minor)
    expect(codes(compared.errors)).toEqual(['components.input.base.text.custom_removed', 'components.card.base.background.alpha_removed'])
    expect(compared.changes.rules_changed).toEqual(['components.button.base.icon_gap', 'components.input.base.text', 'components.card.base.background'])
  })

  // Prior behavior: a platform's default was `.removed` in a minor and `.unaccounted` in a new base.
  it("takes a platform's default with its property", () => {
    const old = nodes('1.1.0')
    const moved = (schema: NodeSchema): NodeSchema => {
      const component = at(schema, 'components', 'banner')
      component.base.per_row = component.base.columns
      component.platforms.mobile_web.per_row = component.platforms.mobile_web.columns
      delete component.base.columns
      delete component.platforms.mobile_web.columns
      return schema
    }
    const minor = moved(nodes('1.2.0'))
    minor.$extensions = { 'co.ordering.migrate': [{ from: 'components.banner.base.columns', to: 'components.banner.base.per_row' }] }
    const compared = diff(old, minor)
    expect(compared.errors).toEqual([])
    expect(compared.changes.migrated).toEqual(['components.banner.base.columns', 'components.banner.platforms.mobile_web.columns'])

    const base = moved(nodes('2.0.0'))
    base.$extensions = { 'co.ordering.upgrade': { from: 1, moves: [{ from: 'components.banner.base.columns', to: 'components.banner.base.per_row' }], drop: [] } }
    expect(checkUpgrade(base, old)).toEqual([])
    expect(upgrade(
      { components: { banner: { base: { columns: 4 } } }, platforms: { mobile_web: { components: { banner: { base: { columns: 2 } } } } } },
      base,
      old,
    )).toEqual({ components: { banner: { base: { per_row: 4 } } }, platforms: { mobile_web: { components: { banner: { base: { per_row: 2 } } } } } })
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
    const older = nodes('1.1.0')
    const newer = nodes('1.2.0')
    const visibility = newer.visibility as JsonMap
    visibility['header.badge'] = visibility['header.search']!
    delete visibility['header.search']
    newer.$extensions = { 'co.ordering.migrate': [{ from: 'visibility.header.search', to: 'visibility.header.badge' }] }
    expect(cutDown(
      { visibility: { 'header.badge': false }, platforms: { mobile_web: { visibility: { 'header.badge': true } } } },
      [newer, older],
    )).toEqual({ visibility: { 'header.search': false }, platforms: { mobile_web: { visibility: { 'header.search': true } } } })
  })

  // Prior behavior: the editor's limits and an image's formats were the editor's and the upload's
  // only; then a stored number out of range was read as its default, losing the closest value.
  it('refuses a number out of its limits and an image of another format, and reads the number clamped', () => {
    const schema = tuples()
    const values = {
      tokens: { size: { lg: 90 }, typography: { h1: { size: 30, line: 2.5, weight: 600 } } },
      components: { header: { base: { logo_width: 400, icon_size: '{icon.sm}' } } },
      images: { favicon: 'https://cdn.example.com/favicon.svg', logotype: 'https://cdn.example.com/logo.svg' },
    }
    expect(codes(themeErrors(values, schema))).toEqual([
      'images.favicon.format',
      'tokens.size.lg.out_of_range',
      'tokens.typography.h1.line.out_of_range',
      'components.header.base.logo_width.out_of_range',
    ])
    const kept = valid(values, schema)
    expect(kept.tokens).toEqual({ size: { lg: 64 }, typography: { h1: { size: 30, line: 1.6, weight: 600 } } })
    expect(kept.components).toEqual({ header: { base: { logo_width: 320, icon_size: '{icon.sm}' } } })
    expect(kept.images).toEqual({ logotype: 'https://cdn.example.com/logo.svg' })
  })

  // Prior behavior: a page's sections were one value for SchemaDiff.
  it("takes each section of a page as a key", () => {
    const old = nodes('1.1.0')
    const next = nodes('1.2.0')
    const sections = at(old, 'pages', 'checkout', 'sections', '$value')
    at(next, 'pages', 'checkout', 'sections').$value = [{ ...sections[1], hideable: false }, sections[0]]
    const compared = diff(old, next)
    expect(codes(compared.errors)).toEqual(['pages.checkout.sections.notes.removed'])
    expect(compared.changes.sections_changed).toEqual(['pages.checkout.sections.tips', 'pages.checkout.sections'])
  })

  // Prior behavior: a version 1 map could name what the schema does not have.
  it('takes a version 1 map that names only what the schema has', () => {
    expect(v1MapErrors(tuples(), {
      description: 'Not a path.',
      v1: { 'tokens.color.primary': 'a.b', 'tokens.color.nope': 'a.c' },
      visibility: { footer: 'footer.hidden' },
      images: { logotype: null, nope: null },
      pages: { home: { featured: { v1: 'x.hidden' }, nope: { v1: 'y.hidden' } } },
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
    const base = nodes('1.0.0')
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
