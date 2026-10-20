import validator from './shape-validator.js'
import type { SchemaError } from './types.js'

/** A meta-schema error: api-core's `{ path, code }`, plus what the validator says. */
export interface ShapeError extends SchemaError {
  /** The validator's explanation, one per keyword that fails there; api-core does not send it. */
  message: string
}

/**
 * What a schema in nodes gets wrong against the format's meta-schema
 * (meta/theme-schema-1.json), as api-core's ThemeSchemas::meta() reports it:
 * one error per path of a node or group that fails, with the code
 * `<path>.schema` (`schema` at the root), in the order they are found; []
 * when it passes. The meta-schema is compiled into the package at build
 * time, so it needs no JSON Schema validator.
 *
 * api-core runs another validator (opis/json-schema), so when a schema
 * fails, the paths it names can differ: under an `anyOf`, for instance, one
 * reports the branches and the other only the node. Whether a schema passes
 * is the same.
 */
export function validateShape(schema: unknown): ShapeError[] {
  if (validator(schema)) {
    return []
  }
  const byPath = new Map<string, string[]>()
  for (const error of validator.errors ?? []) {
    const path = error.instancePath
      .split('/')
      .filter((segment) => segment !== '')
      .map((segment) => segment.replace(/~1/g, '/').replace(/~0/g, '~'))
      .join('.')
    const params = Object.keys(error.params).length > 0 ? ` ${JSON.stringify(error.params)}` : ''
    const message = `${error.message ?? error.keyword}${params}`
    const messages = byPath.get(path) ?? []
    if (!messages.includes(message)) {
      messages.push(message)
    }
    byPath.set(path, messages)
  }
  return [...byPath].map(([path, messages]) => ({
    path,
    code: path === '' ? 'schema' : `${path}.schema`,
    message: messages.join('; '),
  }))
}
