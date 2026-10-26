#!/usr/bin/env node
// Fails when the source has design literals the theme should give: colors,
// space, radius, borders, shadows, typography, motion and opacity written by
// hand. Recorded exceptions (with their reason) are allowed; with a baseline,
// only literals beyond each file's recorded count fail, so a codebase being
// converted can only get better.
//
//   node guard.mjs <repo> [--src src] [--exclude <regex>]
//                  [--allow theme-exceptions.json] [--baseline <file>] [--write-baseline]
//                  [--categories color,space,…] [--tailwind-mapped]
//
// --tailwind-mapped: tailwind.config maps its scales and colors to the theme's
// CSS variables, so a class like p-4 or bg-surface is themed; only arbitrary
// values (p-[13px], text-[#333]) and Tailwind's own palette (bg-gray-200) fail.
//
// theme-exceptions.json: [{ "file": "<regex>", "value"?: "<substring>",
//   "category"?: "color", "reason": "text over the hero photo" }]

import fs from 'node:fs'
import path from 'node:path'
import { scan, parseArgs } from './inventory.mjs'

const args = parseArgs(process.argv.slice(2))
const repo = path.resolve(args._[0] || '.')
const categories = new Set((args.categories || 'color,space,radius,border,shadow,shadow_part,font_size,font_weight,font_family,line_height,letter_spacing,motion,opacity').split(','))
const allow = args.allow ? JSON.parse(fs.readFileSync(path.resolve(repo, args.allow), 'utf8')) : []
for (const [i, a] of allow.entries()) if (!a.file || !a.reason) { console.error(`exception ${i}: needs "file" and "reason"`); process.exit(2) }
const rules = allow.map(a => ({ ...a, re: new RegExp(a.file) }))

const allowed = (e) => rules.some(r => r.re.test(e.file) && (!r.category || r.category === e.category) && (!r.value || e.value.includes(r.value)))
const entries = scan(repo, { src: args.src?.split(','), exclude: args.exclude })
  .filter(e => categories.has(e.category) && !e.fallback && !allowed(e))
  // A line height given as a ratio is what the theme stores, not a literal size.
  .filter(e => !(e.category === 'line_height' && e.ratio !== undefined))
  .filter(e => !(args['tailwind-mapped'] && e.tailwind && !e.arbitrary && !e.palette))

const perFile = {}
for (const e of entries) (perFile[e.file] = perFile[e.file] || []).push(e)

if (args['write-baseline']) {
  const file = path.resolve(repo, args.baseline || '.theme-audit/baseline.json')
  fs.mkdirSync(path.dirname(file), { recursive: true })
  fs.writeFileSync(file, JSON.stringify(Object.fromEntries(Object.entries(perFile).map(([f, l]) => [f, l.length]).sort()), null, 1) + '\n')
  console.log(`baseline: ${entries.length} literals in ${Object.keys(perFile).length} files → ${file}`)
  process.exit(0)
}

const baseline = args.baseline && fs.existsSync(path.resolve(repo, args.baseline)) ? JSON.parse(fs.readFileSync(path.resolve(repo, args.baseline), 'utf8')) : null
const failing = Object.entries(perFile).filter(([f, l]) => !baseline || l.length > (baseline[f] ?? 0))
const improved = baseline ? Object.entries(baseline).filter(([f, n]) => (perFile[f]?.length ?? 0) < n) : []

for (const [f, l] of failing) {
  console.log(`${f}: ${l.length}${baseline ? ` (baseline ${baseline[f] ?? 0})` : ''}`)
  for (const e of l.slice(0, 15)) console.log(`  ${e.line}: ${e.category} ${e.property ?? ''} ${e.value}`)
  if (l.length > 15) console.log(`  … ${l.length - 15} more`)
}
if (improved.length) console.log(`\n${improved.length} files below their baseline: lower it with --write-baseline.`)
if (failing.length) {
  console.log(`\n${failing.length} files with design literals: read the value from the theme (docs/THEMING.md) or record an exception with its reason.`)
  process.exit(1)
}
console.log(`ok: ${entries.length} literals${baseline ? ', none above the baseline' : ''}`)
