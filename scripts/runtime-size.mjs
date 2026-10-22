#!/usr/bin/env node
/**
 * The size of each browser entry (ENTRIES: `runtime`, `css`) as a website
 * bundles it: esbuild bundles it for the browser, minified, and the result
 * is gzipped. It fails when that is over the entry's budget, when the
 * bundle takes a module outside the entry's allowed ones (the validator,
 * the meta-schema, the CLI, the editor's checks, publish, diff or upgrade
 * would come in through one) or when it imports anything (a `node:`
 * module, a package): the entries have no dependencies.
 *
 *   node scripts/runtime-size.mjs                  measures every entry in dist/ (after pnpm build)
 *   node scripts/runtime-size.mjs <name> <file>    measures one entry from another file (src/css.ts)
 *
 * test/runtime-size.test.ts runs it over src/.
 */
import { dirname, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { gzipSync } from 'node:zlib'
import { build } from 'esbuild'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')

/** Each entry's budget (the most it may weigh, minified and gzipped, in bytes) and the modules it may take, by name without extension. */
export const ENTRIES = {
  runtime: { budget: 6 * 1024, allowed: ['runtime', 'format', 'limits', 'pattern', 'php'] },
  css: { budget: 5 * 1024, allowed: ['css', 'format', 'php'] },
}

/** `{ minified, gzipped, modules, problems }` of an entry bundled for the browser. */
export async function measureEntry(name, entry = resolve(ROOT, `dist/${name}.js`)) {
  const { budget, allowed } = ENTRIES[name]
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
    const own = module.replace(/^(src|dist)\//, '').replace(/\.(ts|js)$/, '')
    if (!/^(src|dist)\//.test(module) || !allowed.includes(own)) {
      problems.push(`${module} is not one of the modules the ${name} entry may take (${allowed.join(', ')})`)
    }
  }
  for (const input of Object.values(result.metafile.inputs)) {
    for (const imported of input.imports) {
      if (imported.external) {
        problems.push(`imports ${imported.path}: the ${name} entry has no dependencies`)
      }
    }
  }
  const gzipped = gzipSync(code, { level: 9 }).length
  if (gzipped > budget) {
    problems.push(`${gzipped} bytes gzipped, over the budget of ${budget}`)
  }
  return { minified: code.length, gzipped, budget, modules, problems }
}

const isMain = process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)
if (isMain) {
  const [only, file] = process.argv.slice(2)
  let failed = false
  for (const name of only ? [only] : Object.keys(ENTRIES)) {
    const { minified, gzipped, budget, modules, problems } = await measureEntry(name, file ? resolve(file) : undefined)
    console.log(`${name}: ${minified} bytes minified, ${gzipped} bytes gzipped (budget ${budget}), modules: ${modules.join(', ')}`)
    problems.forEach((problem) => console.error(`${name}: ${problem}`))
    failed ||= problems.length > 0
  }
  process.exit(failed ? 1 : 0)
}
