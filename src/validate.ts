import { check } from './check.js'
import { EDITOR, compile, isNodes } from './compile.js'
import { canonical, diff } from './diff.js'
import { checkUpgrade } from './upgrade.js'
import { defaultSections } from './pages.js'
import { dig, entries, get, has, isArray, isNumeric, phpString, toFloat, versionCompare, type Json, type JsonMap } from './php.js'
import { kindAt } from './schemas.js'
import { validateShape } from './shape.js'
import type { Changes, NodeSchema, SchemaError, TupleSchema, V1Map, ValidateOptions, ValidationResult } from './types.js'

const SEMVER = /^\d{1,4}\.\d{1,4}\.\d{1,4}\n?$/

/**
 * What api-core's ThemeSchemaPublisher::validate() answers for a schema in
 * nodes, so that a product's repository checks it before it publishes. The
 * first stage that fails answers its code:
 *
 * 1. `schema.format`: the schema is not in nodes;
 * 2. `schema.invalid`: the compiler's errors or, when there are none,
 *    check()'s (its editor checks included), outOfRange()'s, given a
 *    `v1Map` v1MapErrors()'s and, given `previousBase`, checkUpgrade()'s,
 *    and in both cases a version that is not semver (`version.semver`);
 * 3. against `previous`, the last version published of its base:
 *    `schema.version_exists` when it is the same version with another
 *    content, `schema.version_order` when the version is lower, and
 *    `schema.incompatible` with SchemaDiff's errors (diff()).
 *
 * Valid, it answers the changes from `previous` (null without one); the
 * same version with the same content is valid and `unchanged`, as api-core
 * answers a deploy that publishes it again. In `development`, the third
 * stage refuses nothing: what production would refuse is answered in
 * `warnings`, with the changes.
 *
 * It does not check the meta-schema, which api-core checks between 1 and
 * 2: validateFull() does both, or validateShape() alone. Nor does it know
 * the registry: the CLI's `validate --against` reads the versions from the
 * API.
 */
export function validate(schema: NodeSchema, options: ValidateOptions = {}): ValidationResult {
  if (!isNodes(schema)) {
    return answer('schema.format', [{ path: '$schema', code: 'schema.format' }])
  }
  const v1Map = options.v1Map ?? null
  const compiled = compile(schema, v1Map ?? {})
  const previousBase = options.previousBase ?? null
  const errors = compiled.errors.length > 0
    ? compiled.errors
    : [
        ...check(compiled.schema, schema),
        ...outOfRange(schema),
        ...(v1Map === null ? [] : v1MapErrors(compiled.schema, v1Map)),
        ...(previousBase === null ? [] : checkUpgrade(schema, previousBase)),
      ]
  if (!SEMVER.test(phpString(get(schema, 'version') ?? ''))) {
    errors.push({ path: 'version', code: 'version.semver' })
  }
  if (errors.length > 0) {
    return answer('schema.invalid', errors)
  }
  const previous = options.previous ?? null
  if (previous === null) {
    return answer(null)
  }

  const development = options.development === true
  const version = phpString(get(schema, 'version'))
  const previousVersion = phpString(get(previous, 'version') ?? '')
  const warnings: SchemaError[] = []
  if (version.split('.')[0] === previousVersion.split('.')[0]) {
    const order = versionCompare(full(version), full(previousVersion))
    if (order === 0 && canonical(schema as unknown as Json) === canonical(previous as unknown as Json)) {
      return answer(null, [], [], null, true)
    }
    const refusal = order === 0 ? 'schema.version_exists' : order < 0 ? 'schema.version_order' : null
    if (refusal !== null) {
      if (!development) {
        return answer(refusal, [{ path: 'version', code: refusal }])
      }
      warnings.push({ path: 'version', code: refusal })
    }
  }
  const compared = diff(previous, schema)
  if (compared.errors.length > 0) {
    if (!development) {
      return answer('schema.incompatible', compared.errors)
    }
    warnings.push(...compared.errors)
  }

  return answer(null, [], warnings, compared.changes)
}

function answer(code: string | null, errors: SchemaError[] = [], warnings: SchemaError[] = [], changes: Changes | null = null, unchanged = false): ValidationResult {
  return { code, errors, warnings, changes, unchanged }
}

/**
 * The paths of a version 1 map the schema (compiled) does not have
 * (ThemeSchemaPublisher::v1MapErrors()), each
 * `v1_map.<section>.<path>.unknown`: a version 2 path of `v1` that is no
 * token, component property or setting of it, a visibility key or an image
 * it has not, a page or a page's section it has not
 * (`v1_map.pages.<page>.<section>`) and a setting it has not. Its other
 * keys (`description`, `source`) say nothing.
 */
export function v1MapErrors(schema: TupleSchema, v1Map: V1Map): SchemaError[] {
  const errors: SchemaError[] = []
  const unknown = (path: string): void => {
    errors.push({ path: `v1_map.${path}`, code: `v1_map.${path}.unknown` })
  }
  const block = (key: string): JsonMap => {
    const value = get(v1Map, key)
    return (isArray(value) ? value : {}) as JsonMap
  }
  for (const [path] of entries(block('v1'))) {
    if (!['tokens', 'components', 'site'].includes(path.split('.')[0]!) || kindAt(schema, path) === null) {
      unknown(`v1.${path}`)
    }
  }
  for (const section of ['visibility', 'images']) {
    for (const [key] of entries(block(section))) {
      if (!isArray(dig(schema, section, key))) {
        unknown(`${section}.${key}`)
      }
    }
  }
  for (const [page, sections] of entries(block('pages'))) {
    const spec = dig(schema, 'pages', page)
    if (!isArray(spec)) {
      unknown(`pages.${page}`)
      continue
    }
    const keys = defaultSections(spec).map((section) => section.key)
    for (const [key] of entries(isArray(sections) ? sections : {})) {
      if (!keys.includes(key)) {
        unknown(`pages.${page}.${key}`)
      }
    }
  }
  for (const [key] of entries(block('site'))) {
    if (kindAt(schema, `site.${key}`) === null) {
      unknown(`site.${key}`)
    }
  }
  return errors
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
  return answer('schema.invalid', errors)
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
