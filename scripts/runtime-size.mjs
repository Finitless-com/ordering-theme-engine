#!/usr/bin/env node
/**
 * The size of the `runtime` entry as a website bundles it: esbuild bundles
 * it for the browser, minified, and the result is gzipped. It fails when
 * that is over BUDGET, when the bundle takes a module outside ALLOWED (the
 * validator, the meta-schema, the CLI, the editor's checks, publish, diff
 * or upgrade would come in through one) or when it imports anything (a
 * `node:` module, a package): the entry has no dependencies.
 *
 *   node scripts/runtime-size.mjs            measures dist/runtime.js (after pnpm build)
 *   node scripts/runtime-size.mjs <entry>    measures another entry (src/runtime.ts)
 *
 * test/runtime-size.test.ts runs it over src/runtime.ts.
 */
import { dirname, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { gzipSync } from 'node:zlib'
import { build } from 'esbuild'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')

/** The most the entry may weigh, minified and gzipped, in bytes. */
export const BUDGET = 6 * 1024

/** The modules the entry may take, by name without extension. */
export const ALLOWED = ['runtime', 'format', 'limits', 'pattern', 'php']

/** `{ minified, gzipped, modules, problems }` of the entry bundled for the browser. */
export async function measureRuntime(entry = resolve(ROOT, 'dist/runtime.js')) {
  const result = await build({
    entryPoints: [entry],
    bundle: true,
    format: 'esm',
    platform: 'browser',
    target: 'es2020',
    minify: true,
    write: false,
    metafile: true,
    logLevel: 'silent',
  })
  const code = result.outputFiles[0].contents
  const inputs = Object.keys(result.metafile.inputs)
  const modules = inputs.map((input) => relative(ROOT, resolve(ROOT, input)))
  const problems = []
  for (const module of modules) {
    const name = module.replace(/^(src|dist)\//, '').replace(/\.(ts|js)$/, '')
    if (!/^(src|dist)\//.test(module) || !ALLOWED.includes(name)) {
      problems.push(`${module} is not one of the modules the runtime entry may take (${ALLOWED.join(', ')})`)
    }
  }
  for (const input of Object.values(result.metafile.inputs)) {
    for (const imported of input.imports) {
      if (imported.external) {
        problems.push(`imports ${imported.path}: the runtime entry has no dependencies`)
      }
    }
  }
  const gzipped = gzipSync(code, { level: 9 }).length
  if (gzipped > BUDGET) {
    problems.push(`${gzipped} bytes gzipped, over the budget of ${BUDGET}`)
  }
  return { minified: code.length, gzipped, modules, problems }
}

const isMain = process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)
if (isMain) {
  const entry = process.argv[2] ? resolve(process.argv[2]) : undefined
  const { minified, gzipped, modules, problems } = await measureRuntime(entry)
  console.log(`runtime: ${minified} bytes minified, ${gzipped} bytes gzipped (budget ${BUDGET}), modules: ${modules.join(', ')}`)
  problems.forEach((problem) => console.error(`runtime: ${problem}`))
  process.exit(problems.length > 0 ? 1 : 0)
}
