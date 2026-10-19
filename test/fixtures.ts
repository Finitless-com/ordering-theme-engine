import { readdirSync, readFileSync } from 'node:fs'
import { basename } from 'node:path'
import type { NodeSchema, ThemeValues } from '../src/index.js'

/**
 * The fixtures api-core produces (scripts/sync-fixtures.sh): its golden
 * cases, the node schema they resolve over, and the empty theme of the
 * product's current schema.
 */
const directory = new URL('../fixtures/', import.meta.url)

export interface Case {
  description: string
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
