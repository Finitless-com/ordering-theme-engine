import { check } from './check.js'
import { EDITOR, compile, isNodes } from './compile.js'
import { diff } from './diff.js'
import { checkUpgrade } from './upgrade.js'
import { dig, entries, get, has, isArray, isNumeric, phpString, toFloat, versionCompare, type Json } from './php.js'
import { validateShape } from './shape.js'
import type { NodeSchema, SchemaError, ValidateOptions, ValidationResult } from './types.js'

const SEMVER = /^\d{1,4}\.\d{1,4}\.\d{1,4}\n?$/

/**
 * What api-core's ThemeSchemaPublisher::validate() answers for a schema in
 * nodes, so that a product's repository checks it before it publishes. The
 * first stage that fails answers its code:
 *
 * 1. `schema.format`: the schema is not in nodes;
 * 2. `schema.invalid`: the compiler's errors or, when there are none,
 *    check()'s (its editor checks included), outOfRange()'s and, given
 *    `previousBase`, checkUpgrade()'s, and in both cases a version that is
 *    not semver (`version.semver`);
 * 3. against `previous`, the last version published of its base:
 *    `schema.version_order` when the version is not greater, and
 *    `schema.incompatible` with SchemaDiff's errors (diff()).
 *
 * Valid, it answers the changes from `previous` (null without one). In
 * `development`, the third stage only answers the changes.
 *
 * It does not check the meta-schema, which api-core checks between 1 and
 * 2: validateFull() does both, or validateShape() alone. Nor does it know
 * the registry: api-core also refuses a version already published with
 * other contents (`schema.version_exists`).
 */
export function validate(schema: NodeSchema, options: ValidateOptions = {}): ValidationResult {
  if (!isNodes(schema)) {
    return { code: 'schema.format', errors: [{ path: '$schema', code: 'schema.format' }], changes: null }
  }
  const compiled = compile(schema, options.v1Map ?? {})
  const previousBase = options.previousBase ?? null
  const errors = compiled.errors.length > 0
    ? compiled.errors
    : [...check(compiled.schema, schema), ...outOfRange(schema), ...(previousBase === null ? [] : checkUpgrade(schema, previousBase))]
  if (!SEMVER.test(phpString(get(schema, 'version') ?? ''))) {
    errors.push({ path: 'version', code: 'version.semver' })
  }
  if (errors.length > 0) {
    return { code: 'schema.invalid', errors, changes: null }
  }
  const previous = options.previous ?? null
  if (previous === null) {
    return { code: null, errors: [], changes: null }
  }

  const development = options.development === true
  const version = phpString(get(schema, 'version'))
  const previousVersion = phpString(get(previous, 'version') ?? '')
  if (!development && version.split('.')[0] === previousVersion.split('.')[0] && versionCompare(full(version), full(previousVersion)) <= 0) {
    return { code: 'schema.version_order', errors: [{ path: 'version', code: 'schema.version_order' }], changes: null }
  }
  const compared = diff(previous, schema)
  if (!development && compared.errors.length > 0) {
    return { code: 'schema.incompatible', errors: compared.errors, changes: null }
  }

  return { code: null, errors: [], changes: compared.changes }
}

/**
 * validate() with the meta-schema in its place, between the format and the
 * compiler, as ThemeSchemaPublisher::validate() runs it: a schema in nodes
 * that fails validateShape() answers `schema.invalid` with its errors
 * (`<path>.schema`, plus `version.semver` when the version is not semver),
 * and one that passes, what validate() answers.
 */
export function validateFull(schema: NodeSchema, options: ValidateOptions = {}): ValidationResult {
  if (!isNodes(schema)) {
    return validate(schema, options)
  }
  const errors: SchemaError[] = validateShape(schema)
  if (errors.length === 0) {
    return validate(schema, options)
  }
  if (!SEMVER.test(phpString(get(schema, 'version') ?? ''))) {
    errors.push({ path: 'version', code: 'version.semver' })
  }
  return { code: 'schema.invalid', errors, changes: null }
}

/**
 * The defaults the editor's own control could not show: a number below its
 * `min` or above its `max` (ThemeSchemaPublisher::outOfRange()).
 */
export function outOfRange(schema: NodeSchema): SchemaError[] {
  const errors: SchemaError[] = []
  const walk = (group: Json, path: string): void => {
    if (has(group, '$value')) {
      const editor = dig(group, '$extensions', EDITOR) ?? []
      const value = get(group, '$value')
      const min = get(editor, 'min')
      const max = get(editor, 'max')
      if (typeof value === 'number' && isArray(editor)
        && ((isNumeric(min) && value < toFloat(min)) || (isNumeric(max) && value > toFloat(max)))) {
        errors.push({ path, code: `${path}.default_out_of_range` })
      }
      return
    }
    for (const [key, child] of entries(group)) {
      if (isArray(child) && !key.startsWith('$')) {
        walk(child, path === '' ? key : `${path}.${key}`)
      }
    }
  }
  for (const block of ['tokens', 'components', 'pages', 'site']) {
    const group = get(schema, block)
    if (isArray(group)) {
      walk(group, block)
    }
  }
  return errors
}

/** `26.0` compares as `26.0.0`. */
function full(version: string): string {
  const parts = version.split('.')
  while (parts.length < 3) {
    parts.push('0')
  }
  return parts.join('.')
}
