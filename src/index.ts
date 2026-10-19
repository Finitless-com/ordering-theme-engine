/**
 * api-core's theme engine (version 2 themes) in TypeScript: the same node
 * schema, the same rules and, case by case, the same answer
 * (fixtures/cases, the golden cases of api-core's ThemeResolverGoldenTest).
 */
export { resolve, defaults } from './resolver.js'
export { compile } from './compile.js'
export { siteDefaults } from './schemas.js'
export type {
  Collection,
  Json,
  JsonMap,
  NodeSchema,
  ResolveOptions,
  ResolvedTheme,
  SchemaError,
  ThemeValues,
  TupleSchema,
} from './types.js'
