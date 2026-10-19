import type { Json, JsonMap } from './php.js'

export type { Json, JsonMap }

/** A map as api-core serves it: PHP writes an empty one as `[]`. */
export type Collection = JsonMap | Json[]

/**
 * A theme schema in the node format (api-core's
 * docs/THEMES_V2_SCHEMA_FORMAT.md): every value a node shaped like a DTCG
 * token, `{ $type, $value, $extensions }`, grouped in `tokens`,
 * `components`, `visibility`, `images`, `pages` and `site`. The engine reads
 * `$extensions["co.ordering"]` and ignores `co.ordering.editor` and the
 * root's `editor`.
 */
export interface NodeSchema {
  $schema: string
  product_type: string
  version: string
  format?: number
  platforms?: string[]
  tokens?: JsonMap
  components?: JsonMap
  visibility?: JsonMap
  images?: JsonMap
  pages?: JsonMap
  site?: JsonMap
  [key: string]: unknown
}

/**
 * The schema as api-core's engine reads it (NodeCompiler's tuples): each
 * property `[kind, default, options?]`, plus `inherits`, `follows`,
 * `derive`, `component_derive` and `matrix` lifted from the nodes.
 */
export type TupleSchema = JsonMap

export interface SchemaError {
  path: string
  code: string
}

/**
 * What a version 2 theme stores (`site_themes.values`): only what it changes
 * from its schema's defaults. The engine assumes it is valid: api-core
 * validates a theme when it is saved and drops what no longer fits its
 * schema when it is served (ThemeResolver::valid()).
 */
export interface ThemeValues {
  tokens?: JsonMap
  components?: JsonMap
  visibility?: JsonMap
  images?: JsonMap
  pages?: JsonMap
  modes?: JsonMap
  platforms?: JsonMap
  [key: string]: unknown
}

/** A theme resolved: every key of its schema, every alias replaced. */
export interface ResolvedTheme {
  tokens: Collection
  components: Collection
  visibility: Collection
  images: Collection
  pages: Collection
  modes: Collection
  platforms: Collection
  [key: string]: Json
}

export interface ResolveOptions {
  /**
   * How an empty map comes out. `array` (the default) as api-core serves it
   * (`[]`, PHP cannot tell them apart), so the JSON is the API's; `object`
   * as `{}`.
   */
  emptyMaps?: 'array' | 'object'
}
