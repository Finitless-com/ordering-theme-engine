import { describe, expect, it } from 'vitest'
import { settingsErrors, themeErrors, valid, type Json, type JsonMap, type SchemaError } from '../src/index.js'
import { differences, json } from './fixtures.js'

/**
 * What the PHP ThemeValidator::validate() and ThemeResolver::valid() answer
 * for each theme, and ThemeValidator::settings() for each site's settings,
 * over the product's current schema as NodeCompiler compiles it
 * (scripts/valid-cases.php): the same errors in the same order, and the
 * same theme kept.
 */
interface ValidCase {
  name: string
  description: string
  values?: Json
  settings?: Json
  errors: SchemaError[]
  valid?: Json
}

describe("valid() answers what api-core's valid() answers", () => {
  const fixture = json<{ cases: ValidCase[] }>('valid.json')
  const schema = json<{ schema: JsonMap }>('schema-26.0.compiled.json').schema
  const themes = fixture.cases.filter((golden) => golden.values !== undefined)
  const settings = fixture.cases.filter((golden) => golden.settings !== undefined)

  it('has cases of every section and of the settings, with every rule', () => {
    expect(themes.length).toBeGreaterThanOrEqual(8)
    expect(settings.length).toBeGreaterThanOrEqual(3)
    const rules = new Set(fixture.cases.flatMap((golden) => golden.errors.map((error) => error.code.split('.').pop())))
    expect([...rules].sort()).toEqual(['alias', 'alpha', 'duplicate', 'invalid', 'unknown'])
  })

  it.each(themes.map((golden) => [golden.name, golden] as const))('%s', (_name, golden) => {
    expect(themeErrors(golden.values!, schema)).toEqual(golden.errors)
    const before = JSON.stringify(golden.values)
    expect(differences(valid(golden.values as JsonMap, schema), golden.valid)).toEqual([])
    expect(JSON.stringify(golden.values)).toBe(before)
  })

  it.each(settings.map((golden) => [golden.name, golden] as const))('%s', (_name, golden) => {
    expect(settingsErrors(golden.settings!, schema)).toEqual(golden.errors)
  })
})
