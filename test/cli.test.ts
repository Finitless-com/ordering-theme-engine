import { createServer, type IncomingHttpHeaders, type Server } from 'node:http'
import type { AddressInfo } from 'node:net'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { afterAll, afterEach, beforeAll, describe, expect, it } from 'vitest'
import { run, serializeDefaults, type Io } from '../src/cli.js'
import { compile, defaults, siteDefaults, type JsonMap, type NodeSchema } from '../src/index.js'
import { json } from './fixtures.js'

/**
 * The `ordering-theme` command, run in process over files in a temporary
 * directory: what it prints, what it writes and its exit code. `publish`
 * talks to a local HTTP server that answers as api-core's
 * ThemeSchemaController::publish() does.
 */
type Op = ['set', string[], unknown] | ['delete', string[]]

function apply<T>(document: T, ops: Op[]): T {
  const answer = structuredClone(document) as Record<string, unknown>
  for (const op of ops) {
    const segments = [...op[1]]
    const last = segments.pop()!
    let node = answer
    for (const segment of segments) {
      node = node[segment] as Record<string, unknown>
    }
    if (op[0] === 'set') {
      node[last] = op[2]
    } else {
      delete node[last]
    }
  }
  return answer as T
}

// The product's current schema made valid as the validate() cases make it (their `base`).
const original = json<NodeSchema>('schema-26.0.nodes.json')
const base = apply(original, json<{ base: Op[] }>('validate.json').base)
const map = json<JsonMap>('v1-map.json')

let directory: string

interface Run {
  code: number
  stdout: string
  stderr: string
}

async function cli(args: string[], env: Record<string, string> = {}): Promise<Run> {
  let stdout = ''
  let stderr = ''
  const io: Io = {
    env,
    cwd: directory,
    stdout: (text) => { stdout += text },
    stderr: (text) => { stderr += text },
    fetch: globalThis.fetch,
  }
  const code = await run(args, io)
  return { code, stdout, stderr }
}

const write = (file: string, content: unknown): void => writeFileSync(join(directory, file), typeof content === 'string' ? content : JSON.stringify(content))

beforeAll(() => {
  directory = mkdtempSync(join(tmpdir(), 'ordering-theme-cli-'))
  write('valid.json', base)
  // Its version is not semver and two defaults are out of their control's range.
  write('invalid.json', apply(base, [
    ['set', ['version'], '26.0'],
    ['set', ['components', 'signup_page', 'base', 'padding_y', '$value'], 120],
    ['set', ['components', 'signup_page', 'base', 'padding_x', '$value'], 130],
  ]))
  // Not even its shape: no product type.
  write('shapeless.json', apply(base, [['delete', ['product_type']]]))
  write('next.json', apply(base, [['set', ['version'], '26.1.0']]))
  write('v1-map.json', map)
  write('broken.json', '{ not json')
})

afterAll(() => {
  rmSync(directory, { recursive: true, force: true })
})

describe('ordering-theme', () => {
  it('prints its help, and refuses an unknown command', async () => {
    expect(await cli(['--help'])).toMatchObject({ code: 0, stdout: expect.stringContaining('Usage: ordering-theme <command>') })
    expect((await cli([])).code).toBe(2)
    const unknown = await cli(['compile', 'valid.json'])
    expect(unknown.code).toBe(2)
    expect(unknown.stderr).toContain('unknown command compile')
  })

  it('prints the package version', async () => {
    const manifest = JSON.parse(readFileSync(new URL('../package.json', import.meta.url), 'utf8')) as { version: string }
    expect(await cli(['--version'])).toMatchObject({ code: 0, stdout: `${manifest.version}\n` })
  })
})

describe('ordering-theme validate', () => {
  it('passes a valid schema', async () => {
    expect(await cli(['validate', 'valid.json', '--v1-map', 'v1-map.json'])).toEqual({ code: 0, stdout: 'valid.json 26.0.0 is valid\n', stderr: '' })
  })

  it("fails with api-core's codes, one per line", async () => {
    const answer = await cli(['validate', 'invalid.json'])
    expect(answer.code).toBe(1)
    expect(answer.stdout).toBe('')
    expect(answer.stderr.split('\n')).toEqual([
      'components.signup_page.base.padding_y.default_out_of_range',
      'components.signup_page.base.padding_x.default_out_of_range',
      'version.semver',
      'invalid.json: schema.invalid (3 error(s))',
      '',
    ])
  })

  it('fails on the meta-schema before anything else, with what the validator says', async () => {
    const answer = await cli(['validate', 'shapeless.json'])
    expect(answer.code).toBe(1)
    expect(answer.stderr).toContain("schema (must have required property 'product_type'")
    expect(answer.stderr).toContain('shapeless.json: schema.invalid (1 error(s))')
  })

  it('checks the version and the compatibility against --previous', async () => {
    const older = await cli(['validate', 'valid.json', '--previous', 'next.json'])
    expect(older.code).toBe(1)
    expect(older.stderr).toBe('version: schema.version_order\nvalid.json: schema.version_order (1 error(s))\n')
    expect((await cli(['validate', 'next.json', '--previous', 'valid.json'])).code).toBe(0)
  })

  it('refuses a missing argument or option (2) and a file it cannot read (1)', async () => {
    expect((await cli(['validate'])).code).toBe(2)
    expect((await cli(['validate', 'valid.json', '--strict'])).code).toBe(2)
    expect((await cli(['validate', 'valid.json', 'next.json'])).code).toBe(2)
    const missing = await cli(['validate', 'nowhere.json'])
    expect(missing.code).toBe(1)
    expect(missing.stderr).toContain('cannot read nowhere.json')
    const broken = await cli(['validate', 'broken.json'])
    expect(broken.code).toBe(1)
    expect(broken.stderr).toContain('broken.json is not JSON')
  })
})

describe('ordering-theme defaults', () => {
  const expected = serializeDefaults(defaults(base))

  it('prints the empty theme resolved, as api-core serves it', async () => {
    expect(await cli(['defaults', 'valid.json'])).toEqual({ code: 0, stdout: expected, stderr: '' })
  })

  it('writes it, and --check says whether the file is up to date', async () => {
    expect(await cli(['defaults', 'valid.json', '--out', 'theme.defaults.json'])).toMatchObject({ code: 0, stdout: 'wrote theme.defaults.json\n' })
    expect(readFileSync(join(directory, 'theme.defaults.json'), 'utf8')).toBe(expected)
    expect(await cli(['defaults', 'valid.json', '--out', 'theme.defaults.json', '--check'])).toMatchObject({ code: 0, stdout: 'theme.defaults.json is up to date\n' })

    write('theme.defaults.json', '{}\n')
    const stale = await cli(['defaults', 'valid.json', '--out', 'theme.defaults.json', '--check'])
    expect(stale.code).toBe(1)
    expect(stale.stderr).toBe('theme.defaults.json is out of date: run ordering-theme defaults valid.json --out theme.defaults.json\n')
    expect(readFileSync(join(directory, 'theme.defaults.json'), 'utf8')).toBe('{}\n')
    expect((await cli(['defaults', 'valid.json', '--out', 'absent.json', '--check'])).code).toBe(1)
  })

  it('adds the site settings with --site', async () => {
    const answer = JSON.parse((await cli(['defaults', 'valid.json', '--site'])).stdout) as JsonMap
    expect(answer.site).toEqual(siteDefaults(compile(base).schema))
    expect(Object.keys(answer.site as JsonMap).length).toBeGreaterThan(0)
  })

  it('refuses --check without --out', async () => {
    const answer = await cli(['defaults', 'valid.json', '--check'])
    expect(answer.code).toBe(2)
    expect(answer.stderr).toContain('--check needs --out')
  })
})

describe('ordering-theme publish', () => {
  interface Received {
    method: string | undefined
    url: string | undefined
    headers: IncomingHttpHeaders
    body: JsonMap
  }

  let server: Server
  let api: string
  let received: Received[] = []
  let reply: { status: number, body: unknown } = { status: 500, body: null }

  beforeAll(async () => {
    server = createServer((request, response) => {
      let text = ''
      request.on('data', (chunk: Buffer) => { text += chunk.toString() })
      request.on('end', () => {
        received.push({ method: request.method, url: request.url, headers: request.headers, body: JSON.parse(text) as JsonMap })
        response.writeHead(reply.status, { 'Content-Type': 'application/json' })
        response.end(JSON.stringify(reply.body))
      })
    })
    await new Promise<void>((done) => server.listen(0, '127.0.0.1', done))
    api = `http://127.0.0.1:${(server.address() as AddressInfo).port}/v400`
  })

  afterAll(async () => {
    await new Promise((done) => server.close(done))
  })

  afterEach(() => {
    received = []
  })

  const env = (): Record<string, string> => ({ THEME_PUBLISH_API: `${api}/`, THEME_PUBLISH_KEY: 'secret' })
  const answer = (status: string, extra: JsonMap = {}): JsonMap => ({
    error: false,
    result: { status, product_type: base.product_type, version: '26.0.0', sha256: 'abc', changes: null, ...extra },
  })

  it('validates and skips publishing without THEME_PUBLISH_API and THEME_PUBLISH_KEY', async () => {
    expect(await cli(['publish', 'valid.json', '--dry-run'])).toEqual({
      code: 0,
      stdout: 'THEME_PUBLISH_API and THEME_PUBLISH_KEY not set: valid.json 26.0.0 is valid, publishing skipped\n',
      stderr: '',
    })
  })

  it('fails without them when required', async () => {
    const flag = await cli(['publish', 'valid.json', '--required'], { THEME_PUBLISH_API: api })
    expect(flag.code).toBe(1)
    expect(flag.stderr).toBe('THEME_PUBLISH_KEY missing: valid.json 26.0.0 cannot be published\n')
    expect((await cli(['publish', 'valid.json'], { THEME_PUBLISH_REQUIRED: '1' })).code).toBe(1)
  })

  it('sends nothing when the schema is not valid here', async () => {
    const refused = await cli(['publish', 'invalid.json'], env())
    expect(refused.code).toBe(1)
    expect(refused.stderr).toContain('version.semver')
    expect(refused.stderr).toContain('invalid.json 26.0: not published, schema.invalid (3 error(s))')
    expect(received).toEqual([])
  })

  it('--dry-run asks api-core to check everything and save nothing', async () => {
    reply = { status: 200, body: answer('valid', { changes: { added: ['tokens.color.brand'], removed: [], migrated: [], deprecated: [] } }) }
    const dry = await cli(['publish', 'valid.json', '--dry-run', '--v1-map', 'v1-map.json'], env())
    expect(dry).toEqual({ code: 0, stdout: `valid.json 26.0.0: valid (dry run) on ${api}\n  added: tokens.color.brand\n`, stderr: '' })
    expect(received).toHaveLength(1)
    const [request] = received
    expect(request!.method).toBe('POST')
    expect(request!.url).toBe('/v400/system/theme_schemas')
    expect(request!.headers['x-theme-publish-key-x']).toBe('secret')
    expect(request!.headers['content-type']).toBe('application/json')
    expect(request!.body).toEqual({ schema: base, v1_map: map, dry_run: true })
  })

  it('publishes, and sends no v1_map when none is given (api-core keeps the last one)', async () => {
    reply = { status: 201, body: answer('created') }
    expect(await cli(['publish', 'valid.json'], env())).toMatchObject({ code: 0, stdout: `valid.json 26.0.0: created on ${api}\n` })
    expect(received[0]!.body).toEqual({ schema: base })
  })

  it('takes a version already published with the same content as done', async () => {
    reply = { status: 200, body: answer('unchanged') }
    expect(await cli(['publish', 'valid.json'], env())).toEqual({ code: 0, stdout: `valid.json 26.0.0: unchanged on ${api}\n`, stderr: '' })
  })

  it('fails with the codes and errors of a refusal', async () => {
    reply = {
      status: 422,
      body: {
        error: true,
        result: ['The schema would break the themes made with the last version of its base.'],
        error_codes: ['schema.incompatible'],
        errors: [{ path: 'components.button.base.size', code: 'components.button.base.size.option_removed' }],
      },
    }
    expect(await cli(['publish', 'valid.json'], env())).toEqual({
      code: 1,
      stdout: '',
      stderr: `components.button.base.size.option_removed\nvalid.json 26.0.0: rejected by ${api} (422, schema.incompatible)\n`,
    })
  })

  it("falls back to api-core's message when a refusal has no errors", async () => {
    reply = { status: 409, body: { error: true, result: ['This version is already published with another content.'], error_codes: ['schema.version_exists'], errors: [] } }
    expect((await cli(['publish', 'valid.json'], env())).stderr).toBe(
      `This version is already published with another content.\nvalid.json 26.0.0: rejected by ${api} (409, schema.version_exists)\n`,
    )
    reply = { status: 401, body: { error: true, result: ["You don't have permission"] } }
    expect(await cli(['publish', 'valid.json'], env())).toMatchObject({ code: 1, stderr: `You don't have permission\nvalid.json 26.0.0: rejected by ${api} (401)\n` })
  })

  it('fails when api-core cannot be reached', async () => {
    const closed = createServer()
    await new Promise<void>((done) => closed.listen(0, '127.0.0.1', done))
    const port = (closed.address() as AddressInfo).port
    await new Promise((done) => closed.close(done))
    const down = await cli(['publish', 'valid.json'], { THEME_PUBLISH_API: `http://127.0.0.1:${port}`, THEME_PUBLISH_KEY: 'secret' })
    expect(down.code).toBe(1)
    expect(down.stderr).toContain(`valid.json 26.0.0: rejected by http://127.0.0.1:${port} (no answer)`)
  })
})
