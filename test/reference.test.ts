import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { describe, expect, it } from 'vitest'
import { compile, validateFull, type NodeSchema, type V1Map } from '../src/index.js'
import { KINDS, SLOT_AXES, tokenNames } from '../src/format.js'

/**
 * The reference schema (meta/reference/): no product's, it uses every shape
 * of the format so that a consumer (api-core, an editor) tests against the
 * format instead of copying a product's schema. 1.0.0 with its version 1
 * map, 1.1.0 a minor of it and 2.0.0 a new base from 1.1.0.
 */
const Ajv2020 = createRequire(import.meta.url)('ajv/dist/2020').default

type Json = null | boolean | number | string | Json[] | { [key: string]: Json }
type Meta = { [key: string]: Json }

const read = <T>(path: string): T => JSON.parse(readFileSync(new URL(`../meta/${path}`, import.meta.url), 'utf8')) as T
const reference = (version: string): NodeSchema => read<NodeSchema>(`reference/reference-${version}.json`)
const v1Map = read<V1Map>('reference/reference-1.0.0.v1-map.json')
const meta = read<Meta>('theme-schema-1.json')

const isMap = (value: unknown): value is { [key: string]: Json } => typeof value === 'object' && value !== null && !Array.isArray(value)
const canonical = (node: Json): string => JSON.stringify(node, (_, value: Json) => isMap(value) ? Object.fromEntries(Object.entries(value).sort(([a], [b]) => a < b ? -1 : 1)) : value)
const escape = (key: string): string => key.replace(/~/g, '~0').replace(/\//g, '~1')
const at = (pointer: string): Json => pointer.split('/').slice(1).reduce<Json>((node, key) => (node as Meta)[key.replace(/~1/g, '/').replace(/~0/g, '~')] ?? null, meta)

const ajv = new Ajv2020({ strict: false })
const validators = new Map<string, (value: unknown) => boolean>()
/** Whether $value satisfies the meta-schema's subschema at $pointer. */
function fits(pointer: string, value: unknown): boolean {
  let validate = validators.get(pointer)
  if (validate === undefined) {
    validate = ajv.compile({ ...(at(pointer) as Meta), $defs: meta.$defs }) as (value: unknown) => boolean
    validators.set(pointer, validate)
  }
  return validate(value)
}

/**
 * A property of the meta-schema, or an enum value, by what it is rather
 * than where: the meta-schema repeats some lists and shapes (the editor's
 * `control` on a node, a field and a card), and using one of them once is
 * enough.
 */
const propertyKey = (schema: Meta, key: string): string => `${canonical(schema)}.${key}`
const enumKey = (options: Json[], value: Json): string => `${canonical(options)}#${JSON.stringify(value)}`

/**
 * What of the meta-schema $value uses, walked as a validator walks it: each
 * property it has and each enum value it takes. A condition (`if`) and a
 * choice (`oneOf`, `anyOf`) are followed only where $value satisfies them.
 */
function used(pointer: string, value: unknown, out: Set<string>): Set<string> {
  const schema = at(pointer)
  if (!isMap(schema)) {
    return out
  }
  if (typeof schema.$ref === 'string') {
    used(schema.$ref.slice(1), value, out)
  }
  if (Array.isArray(schema.enum) && schema.enum.some((option) => JSON.stringify(option) === JSON.stringify(value))) {
    out.add(enumKey(schema.enum, value as Json))
  }
  ;(Array.isArray(schema.allOf) ? schema.allOf : []).forEach((_, index) => used(`${pointer}/allOf/${index}`, value, out))
  for (const choice of ['oneOf', 'anyOf']) {
    const branches = schema[choice]
    ;(Array.isArray(branches) ? branches : []).forEach((_, index) => {
      if (fits(`${pointer}/${choice}/${index}`, value)) {
        used(`${pointer}/${choice}/${index}`, value, out)
      }
    })
  }
  if (schema.if !== undefined) {
    const branch = fits(`${pointer}/if`, value) ? 'then' : 'else'
    if (schema[branch] !== undefined) {
      used(`${pointer}/${branch}`, value, out)
    }
  }
  if (isMap(value)) {
    const properties = isMap(schema.properties) ? schema.properties : {}
    const patterns = Object.keys(isMap(schema.patternProperties) ? schema.patternProperties : {})
    for (const [key, child] of Object.entries(value)) {
      if (key in properties) {
        out.add(propertyKey(schema, key))
        used(`${pointer}/properties/${escape(key)}`, child, out)
        continue
      }
      const pattern = patterns.find((candidate) => new RegExp(candidate, 'u').test(key))
      if (pattern !== undefined) {
        used(`${pointer}/patternProperties/${escape(pattern)}`, child, out)
      } else if (isMap(schema.additionalProperties)) {
        used(`${pointer}/additionalProperties`, child, out)
      }
    }
  }
  if (Array.isArray(value) && isMap(schema.items)) {
    value.forEach((item) => used(`${pointer}/items`, item, out))
  }
  return out
}

/**
 * Every property and enum value the meta-schema defines, conditions (`if`)
 * aside, each with the first place it is defined at.
 */
function defined(pointer: string, node: Json, out: Map<string, string>): Map<string, string> {
  if (Array.isArray(node)) {
    node.forEach((child, index) => defined(`${pointer}/${index}`, child, out))
  } else if (isMap(node)) {
    for (const key of Object.keys(isMap(node.properties) ? node.properties : {})) {
      const found = propertyKey(node, key)
      out.set(found, out.get(found) ?? `${pointer}/properties/${escape(key)}`)
    }
    for (const option of Array.isArray(node.enum) ? node.enum : []) {
      const found = enumKey(node.enum as Json[], option)
      out.set(found, out.get(found) ?? `${pointer}#${JSON.stringify(option)}`)
    }
    for (const [key, child] of Object.entries(node)) {
      if (key !== 'if' && key !== 'enum' && key !== 'const') {
        defined(`${pointer}/${escape(key)}`, child, out)
      }
    }
  }
  return out
}

/**
 * What the reference leaves out on purpose, each with why. Anything else
 * the meta-schema defines and the reference does not use fails the test:
 * add it to a reference version (a new base, if 1.x cannot take it).
 */
const LEFT_OUT: Record<string, string> = {
  '/$defs/editorLayout/properties/labels/properties/page_section_groups': 'no editor reads it; the meta-schema keeps it only so that an earlier schema stays valid',
}

describe('the reference schema', () => {
  const versions = { '1.0.0': reference('1.0.0'), '1.1.0': reference('1.1.0'), '2.0.0': reference('2.0.0') }

  it('is valid as publishing checks it, each version against the one before', () => {
    const answers = [
      validateFull(versions['1.0.0'], { v1Map }),
      validateFull(versions['1.1.0'], { v1Map, previous: versions['1.0.0'] }),
      validateFull(versions['2.0.0'], { v1Map, previousBase: versions['1.1.0'] }),
    ]
    expect(answers.map((answer) => [answer.code, answer.errors, answer.warnings])).toEqual([[null, [], []], [null, [], []], [null, [], []]])
    // The minor changes something of every kind the comparison reports.
    expect(Object.entries(answers[1]!.changes ?? {}).filter(([, paths]) => paths.length === 0).map(([kind]) => kind)).toEqual([])
  })

  it('uses every property and every enum value of the meta-schema', () => {
    const all = defined('', meta, new Map())
    const seen = new Set<string>()
    for (const schema of Object.values(versions)) {
      used('', schema as Json, seen)
    }
    const missing = [...all].filter(([key]) => !seen.has(key)).map(([, pointer]) => pointer)
    for (const pointer of Object.keys(LEFT_OUT)) {
      expect([...all.values()].includes(pointer), `${pointer} is no longer in the meta-schema: drop it from LEFT_OUT`).toBe(true)
      expect(missing.includes(pointer), `${pointer} is used now: drop it from LEFT_OUT`).toBe(true)
    }
    expect(missing.filter((pointer) => !(pointer in LEFT_OUT))).toEqual([])
  })

  it('has every token group of the catalog, each with every name', () => {
    const tokens = versions['1.0.0'].tokens as Record<string, Record<string, unknown>>
    const groups = ['font', 'typography', 'color', 'opacity', 'radius', 'size', 'icon', 'space', 'layout', 'motion', 'border', 'shadow', 'shadow_style', 'border_style', 'motion_style', 'focus_ring', 'scrollbar']
    expect(Object.keys(tokens).sort()).toEqual([...groups].sort())
    // color and typography have names of their own, some of the catalog's; the rest whole.
    for (const group of groups.filter((name) => !['color', 'typography'].includes(name))) {
      expect(Object.keys(tokens[group] ?? {}).filter((name) => !name.startsWith('$')).sort(), group).toEqual([...tokenNames(group)].sort())
    }
  })

  it('has every part of a component, slot axis and kind of property', () => {
    const components = versions['1.0.0'].components as Record<string, Record<string, unknown>>
    const parts = new Set(Object.values(components).flatMap((component) => Object.keys(component).filter((part) => !part.startsWith('$'))))
    expect([...parts].sort()).toEqual(['appearances', 'base', 'colors', 'platforms', 'scope', 'shapes', 'sizes', 'slots', 'variants'])

    const axes = new Set(Object.values(components).flatMap((component) => Object.values((component.slots ?? {}) as Record<string, { $value?: Record<string, unknown> }>))
      .flatMap((slot) => Object.entries(slot.$value ?? {}).filter(([axis, value]) => axis !== 'component' && value !== null).map(([axis]) => axis)))
    expect([...axes].sort()).toEqual(Object.keys(SLOT_AXES).sort())

    // Each compiled property is a tuple [kind, default, options|null, rules?].
    const kinds = new Set<string>()
    const walk = (node: unknown): void => {
      if (Array.isArray(node) && typeof node[0] === 'string' && node[0] in KINDS && node.length >= 2) {
        kinds.add(node[0])
      } else if (Array.isArray(node) || isMap(node)) {
        Object.values(node).forEach(walk)
      }
    }
    const compiled = compile(versions['1.0.0'], v1Map).schema
    walk([compiled.components, compiled.pages, compiled.site])
    expect([...kinds].sort()).toEqual(Object.keys(KINDS).sort())
  })
})
