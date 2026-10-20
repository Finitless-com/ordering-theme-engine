/**
 * The format's meta-schema compiled into a standalone validator
 * (scripts/build-shape-validator.mjs writes shape-validator.js, which is not
 * versioned). Like an Ajv validate function: true when the document passes,
 * and otherwise its errors in `errors`.
 */
export interface ShapeValidatorError {
  instancePath: string
  schemaPath: string
  keyword: string
  params: Record<string, unknown>
  message?: string
}

declare const validate: {
  (document: unknown): boolean
  errors?: ShapeValidatorError[] | null
}

export default validate
