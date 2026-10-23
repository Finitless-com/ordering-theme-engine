import { describe, expect, it } from 'vitest'
import {
  check, checkUpgrade, compile, cutDown, cutDownSettings, upgradeChain, upgradeSettingsChain, validate, validateShape,
  type Json, type JsonMap, type NodeSchema, type Rewrite,
} from '../src/index.js'
import { differences, json, reference } from './fixtures.js'

/**
 * What the PHP ThemeUpgrades answers for each theme or site's settings
 * between three bases made from the product's current schema
 * (scripts/upgrade-cases.php): the same values, step by step.
 */
type Op = ['set', string[], unknown] | ['delete', string[]]

interface UpgradeCase {
  name: string
  description: string
  kind: 'upgrade' | 'settings' | 'cutDown' | 'cutDownSettings'
  bases: string[]
  rewrite: boolean
  values: Json
  expected: Json
}

/** The operations applied in order, as the PHP script applies them, to a copy of $document. */
function apply<T>(document: T, ops: Op[]): T {
  const answer = structuredClone(document) as Record<string, unknown>
  for (const op of ops) {
    const segments = [...op[1]]
    const last = segments.pop()!
    let node = answer
    for (const segment of segments) {
      if (typeof node[segment] !== 'object' || node[segment] === null) {
        node[segment] = {}
      }
      node = node[segment] as Record<string, unknown>
    }
    if (op[0] === 'set') {
      node[last] = structuredClone(op[2])
    } else {
      delete node[last]
    }
  }
  return answer as T
}

/** The script's rewrite `test.bolder`: `small` becomes `label` unless it is set, and every weight goes a step up. */
const bolder: Rewrite = (id, values, _previous, next) => {
  const declared = (next.upgrade as JsonMap | undefined)?.rewrite as JsonMap | undefined
  if (id !== 'test.bolder' || declared?.id !== id) {
    throw new Error(`Unexpected rewrite ${id}.`)
  }
  const styles = (values.tokens as JsonMap | undefined)?.typography
  if (typeof styles !== 'object' || styles === null || Array.isArray(styles)) {
    return values
  }
  if (typeof styles.small === 'object' && styles.small !== null) {
    styles.label ??= styles.small
    delete styles.small
  }
  for (const typography of Object.values(styles)) {
    const style = typography as JsonMap
    if (Number.isInteger(style.weight) && (style.weight as number) < 900) {
      style.weight = (style.weight as number) + 100
    }
  }
  return values
}

describe("the upgrade between bases answers what api-core's ThemeUpgrades answers", () => {
  const fixture = json<{ schemas: Record<string, Op[]>; cases: UpgradeCase[] }>('upgrade.json')
  const original = reference()
  const bases = Object.fromEntries(Object.entries(fixture.schemas).map(([base, ops]) => [base, apply(original, ops)]))

  it('has cases of every kind: identity, moves, map, token aliases, type change, drop, rewrite, chain, settings and cut-down', () => {
    expect(new Set(fixture.cases.map((golden) => golden.kind))).toEqual(new Set(['upgrade', 'settings', 'cutDown', 'cutDownSettings']))
    expect(fixture.cases.map((golden) => golden.name)).toEqual(expect.arrayContaining(['identity', 'moves', 'map', 'token-aliases', 'type-changed', 'drop', 'rewrite', 'chain']))
  })

  it.each(fixture.cases.map((golden) => [golden.name, golden] as const))('%s', (_name, golden) => {
    const chain = golden.bases.map((base) => bases[base]!)
    const values = golden.values as JsonMap
    const before = JSON.stringify(values)
    const rewrite = golden.rewrite ? bolder : undefined
    const answer = golden.kind === 'upgrade' ? upgradeChain(values, chain, rewrite)
      : golden.kind === 'settings' ? upgradeSettingsChain(values, chain, rewrite)
        : golden.kind === 'cutDown' ? cutDown(values, chain)
          : cutDownSettings(values, chain)
    expect(differences(answer, golden.expected)).toEqual([])
    expect(JSON.stringify(values)).toBe(before)
  })
})

describe('the upgrade block, as the PHP ThemeUpgradesTest', () => {
  const schema = (version: string): NodeSchema => ({ ...reference(), version })

  it('passes the meta-schema with empty lists and a map by index, and not in another shape', () => {
    const next = schema('2.0.0')
    next.$extensions = {
      'co.ordering.migrate': [],
      'co.ordering.upgrade': {
        from: 1,
        moves: [{ from: 'components.card.base.columns', to: 'components.card.base.columns', map: { 0: 'none', 1: 'two' } }],
        drop: [],
        rewrite: { id: 'reference.2', paths: [] },
      },
    }
    expect(validateShape(next)).toEqual([])
    next.$extensions = { 'co.ordering.upgrade': { from: '1', moves: [{ from: 'components', to: 'x.y', extra: 1 }] } }
    expect(validateShape(next)).not.toEqual([])
  })

  it('goes to the tuples with its shape checked', () => {
    const block = { from: 1, moves: [], drop: ['visibility.footer'] }
    const next = { ...schema('2.0.0'), $extensions: { 'co.ordering.upgrade': block } }
    const compiled = compile(next).schema
    expect(differences(compiled.upgrade, block)).toEqual([])
    expect(check(compiled).map((error) => error.code)).not.toContain('upgrade.invalid')
    for (const invalid of [{}, { from: '1', moves: [], drop: [] }, { from: 1, moves: { a: 1 }, drop: [] }, { from: 1, moves: [] }]) {
      compiled.upgrade = invalid as JsonMap
      expect(check(compiled).map((error) => error.code)).toContain('upgrade.invalid')
    }
  })

  it('names no previous base without one', () => {
    const next = schema('2.0.0')
    expect(checkUpgrade(next, null)).toEqual([])
    next.$extensions = { 'co.ordering.upgrade': { from: 1, moves: [], drop: [] } }
    expect(checkUpgrade(next, null)).toEqual([{ path: 'upgrade.from', code: 'upgrade.from.invalid' }])
    expect(validate(next).errors.map((error) => error.code)).not.toContain('upgrade.from.invalid')
  })
})
