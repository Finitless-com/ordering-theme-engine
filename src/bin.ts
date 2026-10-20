#!/usr/bin/env node
/** The `ordering-theme` command (cli.ts). */
import { run } from './cli.js'

// A reader that stops early (`| head`) is not an error.
process.stdout.on('error', (error: NodeJS.ErrnoException) => {
  if (error.code !== 'EPIPE') {
    throw error
  }
  process.exit(0)
})

process.exitCode = await run(process.argv.slice(2))
