import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

/**
 * Writes src/shape-validator.js, the meta-schema compiled at build time,
 * which is not versioned: the tests read src/, as a build reads it.
 */
export default function setup(): void {
  execFileSync(process.execPath, [fileURLToPath(new URL('../scripts/build-shape-validator.mjs', import.meta.url))], { stdio: 'ignore' })
}
