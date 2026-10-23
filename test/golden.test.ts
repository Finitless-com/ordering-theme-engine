import { describe, expect, it } from 'vitest'
import { defaults, resolve } from '../src/index.js'
import { cases, differences, goldenSchema, json, schemaFor, type Case, reference } from './fixtures.js'

/**
 * The golden cases (fixtures/cases): each theme resolves to exactly what
 * the PHP ThemeResolver::resolve() answers for it, the one api-core runs,
 * over the node conversion of the frozen Web Marketplace 26 schema.
 */
describe('the golden cases', () => {
  const schema = goldenSchema()
  const all = cases()

  it('has the cases the PHP engine pins, the empty theme among them', () => {
    expect(all.length).toBeGreaterThanOrEqual(26)
    expect(all.map(([name]) => name)).toContain('empty')
  })

  it.each(all)('%s resolves as api-core does', (_name, golden: Case) => {
    expect(differences(resolve(schemaFor(schema, golden), golden.values), golden.resolved)).toEqual([])
  })

  it('has a case that a migration of its schema moves', () => {
    expect(all.some(([, golden]) => golden.migrate !== undefined)).toBe(true)
  })
})

/**
 * The reference schema (meta/reference/reference-1.0.0.json): what a site
 * whose theme sets nothing serves.
 */
describe('the reference schema', () => {
  it('resolves the empty theme as api-core does', () => {
    const schema = reference()
    const golden = json<Case>('reference.empty.json')
    expect(differences(defaults(schema), golden.resolved)).toEqual([])
  })
})
