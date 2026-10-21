import { readFileSync, writeFileSync } from 'node:fs'
import { resolve as resolvePath } from 'node:path'
import { parseArgs, type ParseArgsConfig } from 'node:util'
import { compile, isNodes } from './compile.js'
import { defaults } from './resolver.js'
import { siteDefaults } from './schemas.js'
import { validateFull } from './validate.js'
import type { Changes, Json, NodeSchema, SchemaError, V1Map } from './types.js'

/**
 * The `ordering-theme` command (bin.ts runs it): what a product's
 * repository runs on its theme schema, so that none copies its own scripts.
 *
 * - `validate`: validateFull(), the meta-schema and what api-core checks;
 * - `defaults`: the empty theme resolved, the file a product paints with
 *   when no theme arrives, written or checked;
 * - `publish`: the schema to api-core's `POST {api}/system/theme_schemas`,
 *   after validating it here.
 *
 * Exit codes: 0 done, 1 a schema or an answer that fails, 2 a usage error.
 */

export const HELP = `Usage: ordering-theme <command> [options]

Commands:
  validate <schema.json>   Check a schema in nodes: the format's meta-schema and
                           what the Ordering.co API checks before it publishes one.
      --v1-map <file>      The version 1 map published with the schema.
      --previous <file>    The last version published of its base: also checks
                           the version's order and that nothing breaks.
      --previous-base <file>
                           The last version published of the previous base:
                           also checks the schema's upgrade block against it.

  defaults <schema.json>   The empty theme resolved (what the API serves for a
                           theme that sets nothing), as JSON.
      --out <file>         Write it to <file> instead of printing it.
      --check              Exit 1 when <file> (--out) is not up to date.
      --site               Add the defaults of the site's settings, under "site"
                           (left out when the schema has none).

  publish <schema.json>    Validate the schema, then publish it to the API.
      --v1-map <file>      The version 1 map to publish with it (the API keeps
                           the last one published when none is sent).
      --previous-base <file>
                           The last version published of the previous base, to
                           check the upgrade block here too (the API checks it
                           against its own registry either way).
      --dry-run            The API checks everything and saves nothing.
      --required           Fail when THEME_PUBLISH_API or THEME_PUBLISH_KEY is
                           missing, instead of skipping (or THEME_PUBLISH_REQUIRED
                           set to 1 or true).

Environment (publish):
  THEME_PUBLISH_API        The API with its version, e.g. https://apiv4.ordering.co/v400
  THEME_PUBLISH_KEY        The environment's theme publish key.

Options:
  -h, --help               Show this help.
  -v, --version            Show the package's version.

Exit codes: 0 done, 1 the schema or the API's answer fails, 2 a usage error.
`

/** Where the command reads and writes: the process's by default, a test's otherwise. */
export interface Io {
  env: Record<string, string | undefined>
  cwd: string
  stdout: (text: string) => void
  stderr: (text: string) => void
  fetch: typeof fetch
}

/** What api-core answered a publish: `ok` for `created`, `replaced`, `unchanged` and `valid`. */
export type PublishAnswer =
  | { ok: true, http: number, status: string, version: string | null, changes: Changes | null, errors: [] }
  | { ok: false, http: number | null, codes: string[], errors: Array<SchemaError | string> }

class UsageError extends Error {}

/** A file the command cannot use: missing, or not JSON. */
class FileError extends Error {}

const processIo = (): Io => ({
  env: process.env,
  cwd: process.cwd(),
  stdout: (text) => process.stdout.write(text),
  stderr: (text) => process.stderr.write(text),
  fetch: globalThis.fetch,
})

/** Runs `ordering-theme <args>` and answers its exit code. */
export async function run(args: string[], io: Io = processIo()): Promise<number> {
  const [command, ...rest] = args
  try {
    switch (command) {
      case 'validate':
        return validateCommand(rest, io)
      case 'defaults':
        return defaultsCommand(rest, io)
      case 'publish':
        return await publishCommand(rest, io)
      case undefined:
        io.stderr(HELP)
        return 2
      case '-h':
      case '--help':
      case 'help':
        io.stdout(HELP)
        return 0
      case '-v':
      case '--version':
        io.stdout(`${packageVersion()}\n`)
        return 0
      default:
        throw new UsageError(`unknown command ${command}`)
    }
  } catch (error) {
    if (error instanceof UsageError) {
      io.stderr(`ordering-theme: ${error.message}\nRun ordering-theme --help for the usage.\n`)
      return 2
    }
    if (error instanceof FileError) {
      io.stderr(`ordering-theme: ${error.message}\n`)
      return 1
    }
    throw error
  }
}

function validateCommand(args: string[], io: Io): number {
  const { values, file } = parse(args, {
    'v1-map': { type: 'string' },
    previous: { type: 'string' },
    'previous-base': { type: 'string' },
  })
  if (values.help) {
    io.stdout(HELP)
    return 0
  }
  const schema = readJson(io, file) as NodeSchema
  const result = validateFull(schema, {
    v1Map: optionalJson(io, values['v1-map']) as V1Map | null,
    previous: optionalJson(io, values.previous) as NodeSchema | null,
    previousBase: optionalJson(io, values['previous-base']) as NodeSchema | null,
  })
  if (result.code !== null) {
    result.errors.forEach((error) => io.stderr(`${describeError(error)}\n`))
    io.stderr(`${file}: ${result.code} (${result.errors.length} error(s))\n`)
    return 1
  }
  io.stdout(`${file} ${versionOf(schema)} is valid\n`)
  describeChanges(result.changes).forEach((line) => io.stdout(`${line}\n`))
  return 0
}

function defaultsCommand(args: string[], io: Io): number {
  const { values, file } = parse(args, {
    out: { type: 'string' },
    check: { type: 'boolean' },
    site: { type: 'boolean' },
  })
  if (values.help) {
    io.stdout(HELP)
    return 0
  }
  const out = values.out as string | undefined
  if (values.check && out === undefined) {
    throw new UsageError('--check needs --out <file>, the file to compare')
  }
  const schema = readJson(io, file) as NodeSchema
  if (!isNodes(schema)) {
    io.stderr(`${file}: not a schema in nodes ($schema is not urn:ordering:theme-schema:1)\n`)
    return 1
  }
  const compiled = compile(schema)
  if (compiled.errors.length > 0) {
    compiled.errors.forEach((error) => io.stderr(`${describeError(error)}\n`))
    io.stderr(`${file}: does not compile (${compiled.errors.length} error(s)), run ordering-theme validate ${file}\n`)
    return 1
  }
  const output = serializeDefaults(themeDefaults(schema, values.site === true))
  if (out === undefined) {
    io.stdout(output)
    return 0
  }
  const target = resolvePath(io.cwd, out)
  if (values.check) {
    let current: string | null = null
    try {
      current = readFileSync(target, 'utf8')
    } catch {
      // A missing file is out of date.
    }
    if (current !== output) {
      io.stderr(`${out} is out of date: run ordering-theme defaults ${file} --out ${out}${values.site ? ' --site' : ''}\n`)
      return 1
    }
    io.stdout(`${out} is up to date\n`)
    return 0
  }
  writeFileSync(target, output)
  io.stdout(`wrote ${out}\n`)
  return 0
}

async function publishCommand(args: string[], io: Io): Promise<number> {
  const { values, file } = parse(args, {
    'v1-map': { type: 'string' },
    'previous-base': { type: 'string' },
    'dry-run': { type: 'boolean' },
    required: { type: 'boolean' },
  })
  if (values.help) {
    io.stdout(HELP)
    return 0
  }
  const dryRun = values['dry-run'] === true
  const schema = readJson(io, file) as NodeSchema
  const v1Map = optionalJson(io, values['v1-map']) as V1Map | null
  const version = versionOf(schema)
  const checked = validateFull(schema, { v1Map, previousBase: optionalJson(io, values['previous-base']) as NodeSchema | null })
  if (checked.code !== null) {
    checked.errors.forEach((error) => io.stderr(`${describeError(error)}\n`))
    io.stderr(`${file} ${version}: not published, ${checked.code} (${checked.errors.length} error(s))\n`)
    return 1
  }

  const { api, key, required } = publishConfig(io.env)
  if (api === '' || key === '') {
    const missing = [api === '' && 'THEME_PUBLISH_API', key === '' && 'THEME_PUBLISH_KEY'].filter(Boolean).join(' and ')
    if (required || values.required) {
      io.stderr(`${missing} missing: ${file} ${version} cannot be published\n`)
      return 1
    }
    io.stdout(`${missing} not set: ${file} ${version} is valid, publishing skipped\n`)
    return 0
  }

  const answer = await publishSchema({ api, key, schema, v1Map, dryRun, fetchImpl: io.fetch })
  if (!answer.ok) {
    answer.errors.forEach((error) => io.stderr(`${describeError(error)}\n`))
    const why = [answer.http, ...answer.codes].filter((part) => part !== null)
    io.stderr(`${file} ${version}: rejected by ${api} (${why.length > 0 ? why.join(', ') : 'no answer'})\n`)
    return 1
  }
  io.stdout(`${file} ${answer.version ?? version}: ${answer.status}${dryRun ? ' (dry run)' : ''} on ${api}\n`)
  describeChanges(answer.changes).forEach((line) => io.stdout(`${line}\n`))
  return 0
}

/** The empty theme resolved, with the site's settings' defaults under `site` when asked. */
export function themeDefaults(schema: NodeSchema, site = false): Record<string, unknown> {
  const theme = defaults(schema) as Record<string, unknown>
  if (!site) {
    return theme
  }
  const settings = siteDefaults(compile(schema).schema)
  return Object.keys(settings).length > 0 ? { ...theme, site: settings } : theme
}

/** The defaults as the file a product versions: indented JSON with a final newline. */
export const serializeDefaults = (values: unknown): string => `${JSON.stringify(values, null, 2)}\n`

/**
 * Where and how to publish, from the environment: `{ api, key, required }`.
 * `THEME_PUBLISH_REQUIRED` requires it when `1` or `true` (any case); no
 * other variable does (a CI's own flag, such as WORKERS_CI, is not read).
 */
export function publishConfig(env: Record<string, string | undefined>): { api: string, key: string, required: boolean } {
  return {
    api: String(env.THEME_PUBLISH_API ?? '').trim().replace(/\/+$/, ''),
    key: String(env.THEME_PUBLISH_KEY ?? '').trim(),
    required: /^(1|true)$/i.test(String(env.THEME_PUBLISH_REQUIRED ?? '').trim()),
  }
}

/**
 * Sends the schema to api-core (ThemeSchemaController::publish): `POST
 * {api}/system/theme_schemas` with `{ schema, v1_map?, dry_run? }` and the
 * environment's key in `X-Theme-Publish-Key-X`. api-core answers 201
 * `created`, or 200 `replaced` (a product in development), `unchanged` (the
 * same version with the same content, so a deploy can be repeated) or
 * `valid` (a dry run); a refusal carries its `error_codes` and `errors`
 * (`[{ path, code }]`), 422 for the schema and 409 for the version. Never
 * rejects: a network failure is an answer without `http`.
 */
export async function publishSchema({ api, key, schema, v1Map = null, dryRun = false, fetchImpl = fetch }: {
  api: string
  key: string
  schema: NodeSchema
  v1Map?: V1Map | null
  dryRun?: boolean
  fetchImpl?: typeof fetch
}): Promise<PublishAnswer> {
  let response: Response
  try {
    response = await fetchImpl(`${api}/system/theme_schemas`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Theme-Publish-Key-X': key },
      body: JSON.stringify({ schema, ...(v1Map !== null && { v1_map: v1Map }), ...(dryRun && { dry_run: true }) }),
    })
  } catch (error) {
    return { ok: false, http: null, codes: [], errors: [String((error as Error)?.message ?? error)] }
  }
  let body: Record<string, any> | null = null
  try {
    body = await response.json() as Record<string, any>
  } catch {
    // Not JSON: answered by its status.
  }
  const result = body?.result
  if (response.ok && body !== null && !body.error && typeof result?.status === 'string') {
    return { ok: true, http: response.status, status: result.status, version: result.version ?? null, changes: result.changes ?? null, errors: [] }
  }
  const codes: string[] = body?.error_codes ?? result?.error_codes ?? []
  const errors: SchemaError[] = body?.errors ?? result?.errors ?? []
  const messages: string[] = Array.isArray(result) ? result : []
  return {
    ok: false,
    http: response.status,
    codes,
    errors: errors.length > 0 ? errors : messages.length > 0 ? messages : [`HTTP ${response.status}`],
  }
}

/** One error per line: its code (with its path when the code does not say it) and the validator's message. */
function describeError(error: SchemaError | string): string {
  if (typeof error === 'string') {
    return error
  }
  const code = error.code.startsWith(error.path) ? error.code : `${error.path}: ${error.code}`
  const message = (error as { message?: unknown }).message
  return typeof message === 'string' ? `${code} (${message})` : code
}

/** `  added: a, b`, one line per kind of change that has any. */
function describeChanges(changes: Changes | null | undefined): string[] {
  return Object.entries(changes ?? {})
    .filter(([, paths]) => Array.isArray(paths) && paths.length > 0)
    .map(([kind, paths]) => `  ${kind}: ${(paths as string[]).join(', ')}`)
}

/** A command's options and its one argument, the schema file. */
function parse(args: string[], options: NonNullable<ParseArgsConfig['options']>): { values: Record<string, string | boolean | undefined>, file: string } {
  let parsed
  try {
    parsed = parseArgs({ args, allowPositionals: true, strict: true, options: { ...options, help: { type: 'boolean', short: 'h' } } })
  } catch (error) {
    throw new UsageError((error as Error).message)
  }
  const values = parsed.values as Record<string, string | boolean | undefined>
  if (values.help) {
    return { values, file: '' }
  }
  if (parsed.positionals.length !== 1) {
    throw new UsageError(parsed.positionals.length === 0 ? 'missing <schema.json>' : `one schema at a time, got ${parsed.positionals.join(' ')}`)
  }
  return { values, file: parsed.positionals[0]! }
}

function readJson(io: Io, file: string): Json {
  let text: string
  try {
    text = readFileSync(resolvePath(io.cwd, file), 'utf8')
  } catch (error) {
    throw new FileError(`cannot read ${file}: ${(error as Error).message}`)
  }
  try {
    return JSON.parse(text) as Json
  } catch (error) {
    throw new FileError(`${file} is not JSON: ${(error as Error).message}`)
  }
}

const optionalJson = (io: Io, file: string | boolean | undefined): Json | null => (typeof file === 'string' ? readJson(io, file) : null)

const versionOf = (schema: unknown): string => String((schema as { version?: unknown } | null)?.version ?? '(no version)')

function packageVersion(): string {
  const manifest = JSON.parse(readFileSync(new URL('../package.json', import.meta.url), 'utf8')) as { version: string }
  return manifest.version
}
