import { describe, expect, it } from 'vitest'
import { validate, type JsonMap, type NodeSchema, type ValidationResult } from '../src/index.js'
import { json } from './fixtures.js'

/**
 * What api-core's ThemeSchemaPublisher::validate() answers for each
 * mutation of the product's current schema (scripts/validate-cases.php):
 * validate() answers the same code, the same errors in the same order and
 * the same changes.
 */
type Op = ['set', Array<string | number>, unknown] | ['delete', Array<string | number>]

interface Mutation {
  from: string
  ops: Op[]
}

interface ValidateCase {
  name: string
  description: string
  schema: Mutation
  previous?: Mutation
  v1Map?: Mutation
  development?: boolean
  expected: ValidationResult
}

interface ValidateFixture {
  php: string
  base: Op[]
  cases: ValidateCase[]
}

/** The operations applied in order, as the PHP script applies them, to a copy of $document. */
function apply<T>(document: T, ops: Op[]): T {
  const answer = structuredClone(document) as Record<string | number, unknown>
  for (const op of ops) {
    const segments = [...op[1]]
    const last = segments.pop()!
    let node = answer
    for (const segment of segments) {
      const next = node[segment]
      if (typeof next !== 'object' || next === null) {
        node[segment] = {}
      }
      node = node[segment] as Record<string | number, unknown>
    }
    if (op[0] === 'set') {
      node[last] = structuredClone(op[2])
    } else {
      delete node[last]
    }
  }
  return answer as T
}

describe("validate() answers what api-core's validate() answers", () => {
  const fixture = json<ValidateFixture>('validate.json')
  const original = json<NodeSchema>('schema-26.0.nodes.json')
  const map = json<JsonMap>('v1-map.json')
  const base = apply(original, fixture.base)
  const start = (mutation: Mutation): NodeSchema => apply(mutation.from === 'original' ? original : base, mutation.ops)

  it('has the cases of every stage', () => {
    const codes = new Set(fixture.cases.map((golden) => golden.expected.code))
    expect([...codes].sort()).toEqual([null, 'schema.format', 'schema.incompatible', 'schema.invalid', 'schema.version_order'].sort())
    expect(fixture.cases.length).toBeGreaterThanOrEqual(50)
  })

  it.each(fixture.cases.map((golden) => [golden.name, golden] as const))('%s', (_name, golden) => {
    const answer = validate(start(golden.schema), {
      ...(golden.previous ? { previous: start(golden.previous) } : {}),
      ...(golden.v1Map ? { v1Map: apply(map, golden.v1Map.ops) } : {}),
      ...(golden.development ? { development: true } : {}),
    })
    expect(answer).toEqual(golden.expected)
  })

  it('changes neither the schema nor the previous version', () => {
    const schema = start({ from: 'base', ops: [['set', ['version'], '26.1.0']] })
    const before = JSON.stringify(schema)
    const previous = JSON.stringify(base)
    validate(schema, { previous: base, v1Map: map })
    expect(JSON.stringify(schema)).toBe(before)
    expect(JSON.stringify(base)).toBe(previous)
  })
})
