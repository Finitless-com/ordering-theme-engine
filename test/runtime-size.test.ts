import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
// @ts-expect-error: a script without types.
import { BUDGET, measureRuntime } from '../scripts/runtime-size.mjs'

/**
 * The `runtime` entry as a website bundles it (scripts/runtime-size.mjs,
 * which CI also runs over dist/): within its budget, minified and gzipped,
 * and without the rest of the engine or any dependency.
 */
describe('the runtime entry', () => {
  it('stays within its budget, with only the modules a served theme needs', async () => {
    const { gzipped, modules, problems } = await measureRuntime(fileURLToPath(new URL('../src/runtime.ts', import.meta.url)))
    expect(problems).toEqual([])
    expect(gzipped).toBeLessThanOrEqual(BUDGET)
    expect(modules).toEqual(expect.arrayContaining(['src/runtime.ts']))
    for (const module of ['validate', 'validator', 'valid', 'shape', 'shape-validator', 'cli', 'editor', 'check', 'diff', 'upgrade', 'compile', 'resolver', 'rules']) {
      expect(modules).not.toContain(`src/${module}.ts`)
    }
  })
})
