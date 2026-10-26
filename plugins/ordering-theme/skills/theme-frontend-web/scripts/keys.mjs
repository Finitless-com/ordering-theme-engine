#!/usr/bin/env node
// Lists the keys of a product schema that no code reads: a component, a
// component property, a visibility key, an image, a page section or a site
// setting the editor would offer but the frontend ignores ("a thick border on
// Card changed nothing"). Heuristic: it looks for each name as a string in the
// source; a hit is a candidate reader to confirm, a miss is almost always real.
//
//   node keys.mjs <repo> [--schema product.schema.json] [--src src] [--exclude <regex>]

import fs from 'node:fs'
import path from 'node:path'
import { DEFAULT_EXCLUDE, EXTENSIONS, parseArgs } from './inventory.mjs'

const args = parseArgs(process.argv.slice(2))
const repo = path.resolve(args._[0] || '.')
const schema = JSON.parse(fs.readFileSync(path.resolve(repo, args.schema || 'product.schema.json'), 'utf8'))
const exclude = args.exclude ? new RegExp(`${DEFAULT_EXCLUDE.source}|${args.exclude}`) : DEFAULT_EXCLUDE
const roots = (args.src ? args.src.split(',') : ['src']).map(d => path.resolve(repo, d))

const sources = []
const walk = (dir) => {
  let list; try { list = fs.readdirSync(dir, { withFileTypes: true }) } catch { return }
  for (const d of list) {
    const p = path.join(dir, d.name)
    if (exclude.test(p + (d.isDirectory() ? '/' : '')) || /\.compiled\.json$|schema\.json$/.test(p)) continue
    if (d.isDirectory()) walk(p)
    else if (EXTENSIONS.includes(path.extname(d.name))) sources.push({ file: path.relative(repo, p), text: fs.readFileSync(p, 'utf8') })
  }
}
roots.forEach(walk)

const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
// A name read as a string ('header.cart'), as an object key (promo_banners: () => …),
// or built in a template (`business.information.${part}`).
const matcher = (s) => {
  const forms = [`['"\`.]${esc(s)}['"\`.:\\s,)\\]]`, `(?:^|[\\s{,(])${esc(s)}\\s*:`]
  const dot = s.lastIndexOf('.')
  if (dot > 0) forms.push(`\`${esc(s.slice(0, dot + 1))}\\$\\{`)
  return new RegExp(forms.join('|'), 'm')
}
const filesWith = (s, within = sources) => { const re = matcher(s); return within.filter(f => re.test(f.text)) }
const isNode = (v) => v && typeof v === 'object' && '$value' in v
const leaves = (group, prefix = []) => Object.entries(group ?? {}).filter(([k]) => !k.startsWith('$')).flatMap(([k, v]) => isNode(v) ? [[...prefix, k]] : leaves(v, [...prefix, k]))

const missing = { components: [], properties: [], visibility: [], images: [], sections: [], site: [] }
for (const [name, component] of Object.entries(schema.components ?? {})) {
  const readers = filesWith(name)
  if (!readers.length) { missing.components.push(name); continue }
  for (const [part, group] of Object.entries(component)) {
    if (part.startsWith('$') || part === 'slots' || part === 'scope') continue
    // base.x, sizes.md.x, variants.v.states.hover.x → the property name is the leaf.
    for (const p of leaves(group)) {
      const prop = p.at(-1)
      if (!filesWith(prop, readers).length && !filesWith(`${part}.${p.join('.')}`).length) missing.properties.push(`${name}.${part}.${p.join('.')}`)
    }
  }
}
for (const key of Object.keys(schema.visibility ?? {}).filter(k => !k.startsWith('$'))) if (!filesWith(key).length) missing.visibility.push(key)
for (const key of Object.keys(schema.images ?? {}).filter(k => !k.startsWith('$'))) if (!filesWith(key).length) missing.images.push(key)
for (const [page, def] of Object.entries(schema.pages ?? {})) {
  if (page.startsWith('$')) continue
  const readers = filesWith(page)
  if (!readers.length) { missing.sections.push(`${page} (page)`); continue }
  for (const s of def.sections?.$value ?? []) if (!filesWith(s.key, readers).length && !filesWith(s.key).length) missing.sections.push(`${page}.${s.key}`)
}
for (const p of leaves(schema.site)) if (!filesWith(p.at(-1)).length) missing.site.push(p.join('.'))

let total = 0
for (const [kind, list] of Object.entries(missing)) {
  if (!list.length) continue
  total += list.length
  console.log(`\n${kind} without a reader (${list.length}):`)
  for (const k of list) console.log(`  ${k}`)
}
console.log(total ? `\n${total} keys without a reader: wire them or remove them from the schema.` : 'every key has a candidate reader')
process.exit(total ? 1 : 0)
