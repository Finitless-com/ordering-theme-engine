#!/usr/bin/env node
// Proposes how the literals of inventory.json (web or React Native) collapse
// into the theme's levels: for each scale (space, radius, border, font size,
// icon, control height, motion, shadow) how many fit the engine's catalog
// within tolerance, the value each level should take to keep today's look, and
// the outliers that need a decision; and the colors grouped into families by
// perceptual distance, with the roles they play (text, background, border,
// icon, shadow).
//
//   node scales.mjs <inventory.json> [--out <dir>] [--levels levels.json] [--delta 5]
//
// Writes scales.json and scales.md next to the inventory (or in --out).
// Suggestions only: the agent decides by purpose (each skill's
// references/unification.md).

import fs from 'node:fs'
import path from 'node:path'
import { parseArgs } from './inventory.mjs'

// The engine's web defaults (creating-a-schema.md §8): the starting point, not a rule.
const CATALOG = {
  space: { tolerance: 2, levels: { none: 0, xxs: 2, xs: 4, sm: 8, md: 12, lg: 16, xl: 24, xxl: 32, xxxl: 48 } },
  radius: { tolerance: 2, levels: { none: 0, xs: 4, sm: 8, md: 10, lg: 15, xl: 20, xxl: 30, full: 9999 }, full: 50 },
  border: { tolerance: 0, levels: { thin: 1, thick: 2 } },
  font_size: { tolerance: 1, levels: { caption: 12, body: 14, body_lg: 16, h4: 18, h3: 20, h2: 24, h1: 32, display: 36 } },
  icon: { tolerance: 2, levels: { sm: 16, md: 20, lg: 24 } },
  control_height: { tolerance: 2, levels: { sm: 32, md: 40, lg: 48, xl: 56 } },
  motion: { tolerance: 60, levels: { fast: 120, base: 200, slow: 300 } }
}
const SHADOWS = {
  sm: { x: 0, y: 1, blur: 4, spread: 0, alpha: 0.10 },
  md: { x: 0, y: 2, blur: 8, spread: 0, alpha: 0.10 },
  lg: { x: 0, y: 4, blur: 12, spread: 0, alpha: 0.15 },
  xl: { x: 0, y: 8, blur: 24, spread: 0, alpha: 0.20 }
}

const args = parseArgs(process.argv.slice(2))
const input = path.resolve(args._[0] || '.theme-audit/inventory.json')
const out = path.resolve(args.out || path.dirname(input))
const delta = Number(args.delta || 5)
if (args.levels) {
  for (const [scale, levels] of Object.entries(JSON.parse(fs.readFileSync(args.levels, 'utf8')))) CATALOG[scale].levels = levels
}
const entries = JSON.parse(fs.readFileSync(input, 'utf8')).entries.filter(e => !e.fallback)

// Values per scale -------------------------------------------------------------
const samples = { space: [], radius: [], border: [], font_size: [], icon: [], control_height: [], motion: [] }
for (const e of entries) {
  const where = { loc: e.loc, component: e.component }
  if (e.category === 'space') for (const px of e.px ?? []) samples.space.push({ v: Math.abs(px), ...where })
  if (e.category === 'radius') for (const px of e.px ?? []) samples.radius.push({ v: px, ...where })
  if (e.category === 'border') for (const px of (e.px ?? []).slice(0, 1)) samples.border.push({ v: px, ...where })
  if (e.category === 'font_size') for (const px of e.px ?? []) samples[e.icon ? 'icon' : 'font_size'].push({ v: px, ...where })
  if (e.category === 'size' && e.icon) for (const px of e.px ?? []) if (px <= 48) samples.icon.push({ v: px, ...where })
  if (e.category === 'size' && /height$/.test(e.property ?? '') && !e.icon) for (const px of e.px ?? []) if (px >= 28 && px <= 64) samples.control_height.push({ v: px, ...where })
  if (e.category === 'motion') for (const ms of e.ms ?? []) samples.motion.push({ v: ms, ...where })
}

const histogram = (list) => {
  const h = new Map()
  for (const s of list) h.set(s.v, (h.get(s.v) || 0) + 1)
  return [...h.entries()].sort((a, b) => b[1] - a[1])
}

function fit (scale, list) {
  const { tolerance, levels, full } = CATALOG[scale]
  const names = Object.keys(levels)
  const result = { total: list.length, fits: 0, levels: {}, outliers: [] }
  for (const n of names) result.levels[n] = { catalog: levels[n], values: new Map() }
  const outliers = new Map()
  for (const s of list) {
    let best = null; let dist = Infinity
    if (full && s.v >= full) best = 'full'
    else for (const n of names) { const d = Math.abs(levels[n] - s.v); if (d < dist && n !== 'full') { dist = d; best = n } }
    if (best === 'full' || dist <= tolerance) {
      result.fits++
      const vals = result.levels[best].values
      vals.set(s.v, (vals.get(s.v) || 0) + 1)
    } else {
      const o = outliers.get(s.v) ?? { value: s.v, count: 0, nearest: best, components: new Set(), sample: s.loc }
      o.count++; if (s.component) o.components.add(s.component)
      outliers.set(s.v, o)
    }
  }
  for (const n of names) {
    const vals = [...result.levels[n].values.entries()].sort((a, b) => b[1] - a[1])
    result.levels[n] = { catalog: levels[n], used: vals.reduce((a, [, c]) => a + c, 0), mode: vals[0]?.[0] ?? null, values: Object.fromEntries(vals) }
  }
  result.outliers = [...outliers.values()].sort((a, b) => b.count - a.count).map(o => ({ ...o, components: o.components.size }))
  result.coverage = list.length ? Math.round(result.fits / list.length * 100) : 100
  result.fitted = refit(scale, list)
  return result
}

// The same number of levels, moved to where the code's values are: a 1-D
// weighted k-means seeded with the catalog, levels kept in order, `none` and
// `full` fixed. It answers "which values would these levels need to absorb
// what the code uses today", so near values collapse into one level instead
// of each becoming its own.
function refit (scale, list) {
  const { tolerance, levels, full } = CATALOG[scale]
  const fixed = new Set(['none', 'full'])
  const names = Object.keys(levels).filter(n => !fixed.has(n))
  const values = list.map(s => s.v).filter(v => !(full && v >= full) && v > 0)
  if (!values.length || !names.length) return null
  let centers = names.map(n => levels[n])
  for (let it = 0; it < 50; it++) {
    const sums = centers.map(() => [0, 0])
    for (const v of values) {
      let k = 0; for (let j = 1; j < centers.length; j++) if (Math.abs(centers[j] - v) < Math.abs(centers[k] - v)) k = j
      sums[k][0] += v; sums[k][1]++
    }
    const next = centers.map((c, k) => sums[k][1] ? sums[k][0] / sums[k][1] : c)
    if (next.every((c, k) => Math.abs(c - centers[k]) < 0.01)) break
    centers = next
  }
  // Snap each level to the most used value it absorbs (a real value, not a mean).
  const snapped = centers.map((c, k) => {
    const lo = k ? (centers[k - 1] + c) / 2 : -Infinity; const hi = k < centers.length - 1 ? (c + centers[k + 1]) / 2 : Infinity
    const h = new Map(); for (const v of values) if (v >= lo && v < hi) h.set(v, (h.get(v) || 0) + 1)
    return [...h.entries()].sort((a, b) => b[1] - a[1])[0]?.[0] ?? Math.round(c)
  })
  const fitted = Object.fromEntries(names.map((n, k) => [n, snapped[k]]))
  const fits = list.filter(s => (full && s.v >= full) || s.v === 0 || snapped.some(c => Math.abs(c - s.v) <= tolerance)).length
  return { levels: fitted, coverage: list.length ? Math.round(fits / list.length * 100) : 100 }
}

const scales = {}
for (const [scale, list] of Object.entries(samples)) scales[scale] = { ...fit(scale, list), histogram: histogram(list).slice(0, 30) }

// Shadows ----------------------------------------------------------------------
const shadow = { total: 0, fits: 0, levels: { sm: 0, md: 0, lg: 0, xl: 0 }, fixed: { inset: 0, colored: 0, spread_only: 0, unfit: 0 }, unfit: [] }
for (const e of entries.filter(e => e.category === 'shadow' && e.property === 'box-shadow')) {
  shadow.total++
  const main = [...e.layers].sort((a, b) => (b.blur + Math.abs(b.y)) - (a.blur + Math.abs(a.y)))[0]
  if (main.inset) { shadow.fixed.inset++; continue }
  if (!main.blur && main.spread) { shadow.fixed.spread_only++; continue }
  const rgb = main.color?.slice(1, 7); const alpha = main.color?.length === 9 ? parseInt(main.color.slice(7), 16) / 255 : 1
  const gray = rgb && Math.max(...[0, 2, 4].map(i => parseInt(rgb.slice(i, i + 2), 16))) - Math.min(...[0, 2, 4].map(i => parseInt(rgb.slice(i, i + 2), 16))) < 16
  if (rgb && !gray) { shadow.fixed.colored++; continue }
  let best = null; let d = Infinity
  for (const [n, l] of Object.entries(SHADOWS)) {
    const dist = 2 * Math.abs(l.y - main.y) + Math.abs(l.blur - main.blur) + Math.abs(l.spread - main.spread) + 40 * Math.abs(l.alpha - alpha)
    if (dist < d) { d = dist; best = n }
  }
  if (d <= 6) { shadow.fits++; shadow.levels[best]++ } else { shadow.fixed.unfit++; if (shadow.unfit.length < 25) shadow.unfit.push({ loc: e.loc, value: e.value, nearest: best, distance: Math.round(d) }) }
}

// Colors -----------------------------------------------------------------------
const lab = (hex) => {
  const c = [1, 3, 5].map(i => parseInt(hex.slice(i, i + 2), 16) / 255).map(v => v > 0.04045 ? ((v + 0.055) / 1.055) ** 2.4 : v / 12.92)
  const xyz = [
    (c[0] * 0.4124 + c[1] * 0.3576 + c[2] * 0.1805) / 0.95047,
    (c[0] * 0.2126 + c[1] * 0.7152 + c[2] * 0.0722),
    (c[0] * 0.0193 + c[1] * 0.1192 + c[2] * 0.9505) / 1.08883
  ].map(v => v > 0.008856 ? Math.cbrt(v) : 7.787 * v + 16 / 116)
  return [116 * xyz[1] - 16, 500 * (xyz[0] - xyz[1]), 200 * (xyz[1] - xyz[2])]
}
const dE = (a, b) => Math.hypot(a[0] - b[0], a[1] - b[1], a[2] - b[2])
const role = (e) => {
  const p = e.property ?? ''
  if (e.category === 'shadow') return 'shadow'
  if (e.category === 'border' || /border|outline|ring/.test(p)) return 'border'
  if (/background|^tw:bg|gradient|from|to|via/.test(p)) return 'background'
  if (/fill|stroke/.test(p) || e.icon) return 'icon'
  if (/color|^tw:text/.test(p)) return 'text'
  return 'other'
}

const colorUse = new Map()
for (const e of entries) {
  for (const c of e.colors ?? []) {
    const key = c.slice(0, 7); const alpha = c.length === 9 ? Math.round(parseInt(c.slice(7), 16) / 2.55) / 100 : 1
    const u = colorUse.get(key) ?? { hex: key, count: 0, roles: {}, alphas: {}, files: new Set(), sample: e.loc }
    u.count++; u.files.add(e.file)
    const r = role(e); u.roles[r] = (u.roles[r] || 0) + 1
    u.alphas[alpha] = (u.alphas[alpha] || 0) + 1
    colorUse.set(key, u)
  }
}
const families = []
for (const u of [...colorUse.values()].sort((a, b) => b.count - a.count)) {
  const l = lab(u.hex)
  const family = families.find(f => dE(f.lab, l) < delta)
  if (family) family.members.push(u)
  else families.push({ lab: l, lead: u.hex, members: [u] })
}

function suggest (f) {
  const [L, a, b] = f.lab; const chroma = Math.hypot(a, b); const hue = (Math.atan2(b, a) * 180 / Math.PI + 360) % 360
  const roles = {}; for (const m of f.members) for (const [r, c] of Object.entries(m.roles)) roles[r] = (roles[r] || 0) + c
  const main = Object.entries(roles).filter(([r]) => r !== 'other').sort((x, y) => y[1] - x[1])[0]?.[0] ?? 'other'
  const translucent = f.members.some(m => Object.keys(m.alphas).some(x => Number(x) < 1))
  if (main === 'shadow') return 'shadow.* (not a color token)'
  if (chroma < 12) {
    if (L > 97) return main === 'text' || main === 'icon' ? 'on_primary / on_* / on_overlay (review: text on a fill or photo)' : 'background | surface'
    if (L > 90) return main === 'border' ? 'border' : 'surface_alt'
    if (L > 75) return main === 'border' ? 'border | border_strong' : main === 'text' ? 'text_disabled' : 'surface_alt | disabled'
    if (L > 45) return main === 'border' ? 'border_strong' : 'text_muted'
    if (L > 20) return main === 'background' ? 'inverse_surface' : 'text'
    return main === 'background' ? (translucent ? 'overlay' : 'inverse_surface') : 'text'
  }
  // CIELAB hue angles: red ≈ 40, yellow ≈ 100, green ≈ 135, blue ≈ 300.
  if (hue < 50 || hue >= 345) return 'danger | primary'
  if (hue < 105) return L > 75 ? 'rating | warning' : 'warning | secondary'
  if (hue < 200) return 'success | primary'
  if (hue < 310) return 'primary | info (decide by purpose)'
  return 'primary | secondary'
}

const colorFamilies = families.map(f => {
  const count = f.members.reduce((a, m) => a + m.count, 0)
  const roles = {}; for (const m of f.members) for (const [r, c] of Object.entries(m.roles)) roles[r] = (roles[r] || 0) + c
  return { lead: f.lead, count, distinct: f.members.length, files: new Set(f.members.flatMap(m => [...m.files])).size, roles, suggestion: suggest(f), members: f.members.map(m => ({ hex: m.hex, count: m.count, alphas: m.alphas, sample: m.sample })) }
}).sort((a, b) => b.count - a.count)

// Other histograms -------------------------------------------------------------
const plain = (category, key) => histogram(entries.filter(e => e.category === category).flatMap(e => [].concat(e[key] ?? []).map(v => ({ v }))))
const other = {
  font_weight: plain('font_weight', 'weight'),
  font_family: histogram(entries.filter(e => e.category === 'font_family').map(e => ({ v: e.value }))),
  line_height_px: plain('line_height', 'px'),
  line_height_ratio: plain('line_height', 'ratio'),
  letter_spacing: plain('letter_spacing', 'px'),
  opacity: plain('opacity', 'n'),
  z_index: plain('z_index', 'n'),
  breakpoint: plain('breakpoint', 'px'),
  // React Native splits a shadow into props: Android's elevation, iOS's radius and opacity.
  elevation: histogram(entries.filter(e => e.property === 'elevation').map(e => ({ v: e.n }))),
  shadow_radius: histogram(entries.filter(e => e.property === 'shadow-radius').map(e => ({ v: e.n }))),
  shadow_opacity: histogram(entries.filter(e => e.property === 'shadow-opacity').map(e => ({ v: e.n }))),
  // Tailwind color classes carry no hex: Tailwind's palette (bg-gray-200) is a literal,
  // a semantic class mapped to a CSS variable (text-muted-foreground) is already a token.
  tailwind_palette: histogram(entries.filter(e => e.tailwind && e.palette).map(e => ({ v: e.value.split(':').pop() }))),
  tailwind_semantic: histogram(entries.filter(e => e.tailwind && e.category === 'color' && !e.palette && !e.colors?.length).map(e => ({ v: e.value.split(':').pop() })))
}

const report = { input, delta, scales, shadow, colors: { distinct: colorUse.size, families: colorFamilies }, other }
fs.mkdirSync(out, { recursive: true })
fs.writeFileSync(path.join(out, 'scales.json'), JSON.stringify(report, null, 1))

// Markdown ---------------------------------------------------------------------
const md = []
const row = (cells) => `| ${cells.join(' | ')} |`
md.push('# Proposed scales', '', `Source: \`${input}\` (fallbacks left out). Suggestions only: each value is decided by its purpose.`, '')
for (const [scale, r] of Object.entries(scales)) {
  md.push(`## ${scale}`, '', `${r.total} uses; ${r.fits} (${r.coverage}%) fit a level within ±${CATALOG[scale].tolerance}.`, '')
  if (r.fitted && r.fitted.coverage > r.coverage) md.push(`With the levels moved to the values the code uses (column "fitted"), ${r.fitted.coverage}% would fit: the default theme would reproduce today's look with fewer visible changes.`, '')
  md.push(row(['level', 'catalog', 'fitted', 'most used', 'uses', 'values it absorbs']), row(['---', '---', '---', '---', '---', '---']))
  for (const [n, l] of Object.entries(r.levels)) md.push(row([n, l.catalog, r.fitted?.levels[n] ?? l.catalog, l.mode ?? '—', l.used, Object.entries(l.values).map(([v, c]) => `${v}×${c}`).join(' ')]))
  if (r.outliers.length) {
    md.push('', 'Off the scale (decide: the nearest level with a visible change, move the level\'s value, or a recorded exception):', '')
    md.push(row(['value', 'uses', 'components', 'nearest level', 'example']), row(['---', '---', '---', '---', '---']))
    for (const o of r.outliers.slice(0, 20)) md.push(row([o.value, o.count, o.components, o.nearest, `\`${o.sample}\``]))
  }
  md.push('')
}
md.push('## shadow', '', `${shadow.total} box-shadow; ${shadow.fits} fit (d ≤ 6): ${Object.entries(shadow.levels).map(([k, v]) => `${k} ${v}`).join(', ')}. Fixed: ${Object.entries(shadow.fixed).map(([k, v]) => `${k} ${v}`).join(', ')}.`, '')
for (const u of shadow.unfit.slice(0, 10)) md.push(`- \`${u.loc}\` \`${u.value}\` → ${u.nearest} (d ${u.distance})`)
md.push('', '## Colors', '', `${colorUse.size} distinct colors in ${colorFamilies.length} families (ΔE76 < ${delta}). The token is chosen by role (property and context), not by closeness: the suggestion is only a starting point.`, '')
md.push(row(['family', 'uses', 'distinct', 'files', 'roles', 'suggestion', 'members']), row(['---', '---', '---', '---', '---', '---', '---']))
for (const f of colorFamilies.slice(0, 60)) md.push(row([f.lead, f.count, f.distinct, f.files, Object.entries(f.roles).map(([r, c]) => `${r} ${c}`).join(', '), f.suggestion, f.members.slice(0, 8).map(m => `${m.hex}×${m.count}`).join(' ')]))
md.push('', '## Other', '')
for (const [k, h] of Object.entries(other)) md.push(`- **${k}**: ${h.slice(0, 20).map(([v, c]) => `${v}×${c}`).join(', ') || '—'}`)
fs.writeFileSync(path.join(out, 'scales.md'), md.join('\n') + '\n')

console.log(`scales → ${path.join(out, 'scales.md')}`)
for (const [scale, r] of Object.entries(scales)) console.log(`  ${scale.padEnd(15)} ${String(r.coverage).padStart(3)}% of ${r.total} fit the catalog, ${r.fitted?.coverage ?? '-'}% fitted levels, ${r.outliers.length} outlier values`)
console.log(`  shadow          ${shadow.fits}/${shadow.total}`)
console.log(`  colors          ${colorUse.size} distinct → ${colorFamilies.length} families`)
