/**
 * api-core's theme engine (version 2 themes) in TypeScript: the same node
 * schema, the same rules and, case by case, the same answer
 * (fixtures/cases, the golden cases of api-core's ThemeResolverGoldenTest;
 * fixtures/validate.json, what ThemeSchemaPublisher::validate() answers).
 */
export { resolve, defaults } from './resolver.js'
export { compile } from './compile.js'
export { siteDefaults } from './schemas.js'
export { validate, validateFull, outOfRange, v1MapErrors } from './validate.js'
export { validateShape, type ShapeError } from './shape.js'
export { valid, validSettings, themeErrors, settingsErrors } from './valid.js'
export { checkUpgrade, cutDown, cutDownSettings, upgrade, upgradeChain, upgradeSettings, upgradeSettingsChain, type Rewrite } from './upgrade.js'
export { runtimeRules, serializeRules } from './rules.js'
export { check } from './check.js'
export { checkEditor } from './editor.js'
export { diff } from './diff.js'
export type {
  Changes,
  Collection,
  Json,
  JsonMap,
  NodeSchema,
  ResolvedTheme,
  SchemaError,
  ThemeValues,
  TupleSchema,
  V1Map,
  ValidateOptions,
  ValidationResult,
} from './types.js'
