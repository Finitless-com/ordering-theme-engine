import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
// @ts-expect-error: a script without types.
import { ENTRIES, measureEntry } from '../scripts/runtime-size.mjs'

/**
 * The browser entries as a website bundles them (scripts/runtime-size.mjs,
 * which CI also runs over dist/): each within its budget, minified and
 * gzipped, and without the rest of the engine or any dependency.
 */
const ENGINE = ['validate', 'validator', 'valid', 'shape', 'shape-validator', 'cli', 'editor', 'check', 'diff', 'upgrade', 'compile', 'resolver', 'rules']

describe('the browser entries', () => {
  it('runtime stays within its budget, with only the modules a served theme needs', async () => {
    const { gzipped, modules, problems } = await measureEntry('runtime', fileURLToPath(new URL('../src/runtime.ts', import.meta.url)))
    expect(problems).toEqual([])
    expect(gzipped).toBeLessThanOrEqual(ENTRIES.runtime.budget)
    expect(modules).toEqual(expect.arrayContaining(['src/runtime.ts']))
    for (const module of [...ENGINE, 'css']) {
      expect(modules).not.toContain(`src/${module}.ts`)
    }
  })

  it('css stays within its budget, without the runtime or the rest of the engine', async () => {
    const { gzipped, modules, problems } = await measureEntry('css', fileURLToPath(new URL('../src/css.ts', import.meta.url)))
    expect(problems).toEqual([])
    expect(gzipped).toBeLessThanOrEqual(ENTRIES.css.budget)
    expect(modules).toEqual(expect.arrayContaining(['src/css.ts']))
    for (const module of [...ENGINE, 'runtime', 'limits', 'pattern']) {
      expect(modules).not.toContain(`src/${module}.ts`)
    }
  })
})
