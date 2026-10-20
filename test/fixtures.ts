import { readdirSync, readFileSync } from 'node:fs'
import { basename } from 'node:path'
import type { Json, NodeSchema, ThemeValues } from '../src/index.js'

/**
 * The fixtures the PHP engine produces (php/src, through
 * scripts/sync-fixtures.sh): the golden cases, the node schema they
 * resolve over, and the empty theme of the product's current schema.
 */
const directory = new URL('../fixtures/', import.meta.url)

export interface Case {
  description: string
  /** The renames and moves the schema declares for this case, if any. */
  migrate?: Json[]
  values: ThemeValues
  resolved: unknown
}

export function json<T>(path: string): T {
  return JSON.parse(readFileSync(new URL(path, directory), 'utf8')) as T
}

/** The golden cases, by name. */
export function cases(): Array<[string, Case]> {
  return readdirSync(new URL('cases/', directory))
    .filter((file) => file.endsWith('.json'))
    .sort()
    .map((file) => [basename(file, '.json'), json<Case>(`cases/${file}`)])
}

export const goldenSchema = (): NodeSchema => json<NodeSchema>('schema.nodes.json')

/**
 * $schema with the migrations a case declares (`co.ordering.migrate`, as
 * api-core's ThemeResolverGolden::schemaFor() adds them); $schema itself
 * when it declares none.
 */
export function schemaFor(schema: NodeSchema, golden: Case): NodeSchema {
  if (golden.migrate === undefined) {
    return schema
  }
  const extensions = (schema as Record<string, unknown>).$extensions
  return {
    ...schema,
    $extensions: { ...(typeof extensions === 'object' && extensions !== null ? extensions : {}), 'co.ordering.migrate': golden.migrate },
  } as NodeSchema
}

/**
 * Where two JSON values differ as PHP would see them: `[]` and `{}` are the
 * same empty array and key order does not count (JS objects put
 * integer-like keys first), but a number is compared exactly. Answers one
 * line per difference.
 */
export function differences(actual: unknown, expected: unknown, path = '$', out: string[] = []): string[] {
  const container = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null
  const empty = (value: unknown): boolean => container(value) && Object.keys(value).length === 0
  if (empty(actual) && empty(expected)) {
    return out
  }
  if (container(actual) && container(expected)) {
    if (Array.isArray(actual) !== Array.isArray(expected)) {
      out.push(`${path}: a ${Array.isArray(actual) ? 'list' : 'map'} where api-core has a ${Array.isArray(expected) ? 'list' : 'map'}`)
      return out
    }
    for (const key of new Set([...Object.keys(actual), ...Object.keys(expected)])) {
      if (!(key in actual)) {
        out.push(`${path}.${key}: missing (api-core: ${JSON.stringify(expected[key])})`)
      } else if (!(key in expected)) {
        out.push(`${path}.${key}: not in api-core's answer (${JSON.stringify(actual[key])})`)
      } else {
        differences(actual[key], expected[key], `${path}.${key}`, out)
      }
    }
    return out
  }
  if (actual !== expected) {
    out.push(`${path}: ${JSON.stringify(actual)} where api-core has ${JSON.stringify(expected)}`)
  }
  return out
}
