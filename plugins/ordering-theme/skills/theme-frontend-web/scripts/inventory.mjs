#!/usr/bin/env node
// Inventory of the literal design values in a web frontend's source: colors,
// lengths (space, radius, border widths, sizes), typography, shadows, motion,
// opacity, z-index and breakpoints, each with file:line, component, selector
// and CSS property. Reads CSS, SCSS, Less, styled-components / emotion
// templates, JS style objects, JSX attributes and Tailwind classes.
//
//   node inventory.mjs <repo> [--src src,app] [--out <dir>] [--exclude <regex>]
//
// Writes <out>/inventory.json and <out>/inventory.csv (out defaults to
// <repo>/.theme-audit). No dependencies. Also imported by guard.mjs.

import fs from 'node:fs'
import path from 'node:path'
import { pathToFileURL } from 'node:url'

export const EXTENSIONS = ['.js', '.jsx', '.ts', '.tsx', '.mjs', '.cjs', '.css', '.scss', '.sass', '.less', '.vue', '.svelte', '.astro', '.html']
export const DEFAULT_EXCLUDE = /(\/node_modules\/|\/dist\/|\/build\/|\/\.next\/|\/\.nuxt\/|\/coverage\/|\/__tests__\/|\/__mocks__\/|\.test\.|\.spec\.|\.stories\.|\.d\.ts$|\/\.theme-audit\/)/

// CSS property (kebab-case) → category.
const PROPS = {
  color: 'color', background: 'color', 'background-color': 'color', 'border-color': 'color',
  'border-top-color': 'color', 'border-bottom-color': 'color', 'border-left-color': 'color', 'border-right-color': 'color',
  'outline-color': 'color', fill: 'color', stroke: 'color', 'caret-color': 'color', 'accent-color': 'color',
  'text-decoration-color': 'color', 'stop-color': 'color', 'background-image': 'color',
  margin: 'space', 'margin-top': 'space', 'margin-bottom': 'space', 'margin-left': 'space', 'margin-right': 'space',
  'margin-inline': 'space', 'margin-block': 'space', 'margin-inline-start': 'space', 'margin-inline-end': 'space',
  padding: 'space', 'padding-top': 'space', 'padding-bottom': 'space', 'padding-left': 'space', 'padding-right': 'space',
  'padding-inline': 'space', 'padding-block': 'space', 'padding-inline-start': 'space', 'padding-inline-end': 'space',
  gap: 'space', 'row-gap': 'space', 'column-gap': 'space', 'grid-gap': 'space',
  'border-radius': 'radius', 'border-top-left-radius': 'radius', 'border-top-right-radius': 'radius',
  'border-bottom-left-radius': 'radius', 'border-bottom-right-radius': 'radius',
  border: 'border', 'border-top': 'border', 'border-bottom': 'border', 'border-left': 'border', 'border-right': 'border',
  'border-width': 'border', outline: 'border', 'outline-width': 'border',
  'box-shadow': 'shadow', 'text-shadow': 'shadow', filter: 'shadow',
  'font-size': 'font_size', 'line-height': 'line_height', 'font-weight': 'font_weight', 'font-family': 'font_family',
  'letter-spacing': 'letter_spacing', 'text-transform': 'text_transform', font: 'font_size',
  width: 'size', height: 'size', 'min-width': 'size', 'min-height': 'size', 'max-width': 'size', 'max-height': 'size',
  transition: 'motion', 'transition-duration': 'motion', animation: 'motion', 'animation-duration': 'motion',
  'transition-timing-function': 'motion',
  opacity: 'opacity', 'z-index': 'z_index'
}

const HEX = /#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{4}|[0-9a-fA-F]{3})\b/g
const FUNC_COLOR = /\b(?:rgba?|hsla?)\([^()]*\)/g
const NAMED = { white: '#FFFFFF', black: '#000000', red: '#FF0000', green: '#008000', blue: '#0000FF', gray: '#808080', grey: '#808080', yellow: '#FFFF00', orange: '#FFA500', purple: '#800080', pink: '#FFC0CB', silver: '#C0C0C0', lightgray: '#D3D3D3', lightgrey: '#D3D3D3', darkgray: '#A9A9A9', darkgrey: '#A9A9A9', whitesmoke: '#F5F5F5', gainsboro: '#DCDCDC', navy: '#000080', teal: '#008080', crimson: '#DC143C', gold: '#FFD700' }
const NAMED_RE = new RegExp(`(?<![\\w.#-])(${Object.keys(NAMED).join('|')})(?![\\w-])`, 'gi')
const NUMBER = /(-?\d*\.?\d+)(px|rem|em|%|vh|vw|vmin|vmax|pt|ms|s)?(?![\w.])/g
const DECL = /(?:^|[\s{;(,])(-{0,2}[a-zA-Z][a-zA-Z-]*)\s*:\s*([^;{}\n]+)/g
const ATTR = /\b(fill|stroke|color|width|height|size)\s*=\s*\{?\s*['"]([^'"]+)['"]/g
const CLASS_ATTR = /\b(?:className|class)\s*=\s*(?:\{\s*)?[`'"]([^`'"]+)[`'"]/g
const TW = /^(-?)(p|px|py|pt|pb|pl|pr|ps|pe|m|mx|my|mt|mb|ml|mr|ms|me|gap|gap-x|gap-y|space-x|space-y|rounded|rounded-[trblse]{1,2}|text|bg|border|border-[trblxy]|ring|outline|shadow|font|leading|tracking|w|h|min-w|min-h|max-w|max-h|size|opacity|duration|z|from|to|via|fill|stroke)-(.+)$/
// Tailwind's own palette: a color the theme does not give (bg-gray-200, text-white).
const TW_PALETTE = /(^|-)(slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d{2,3}(\/\d+)?$|^(white|black)(\/\d+)?$/
const TW_CATEGORY = { p: 'space', m: 'space', gap: 'space', space: 'space', rounded: 'radius', text: 'text', bg: 'color', border: 'border', ring: 'border', outline: 'border', shadow: 'shadow', font: 'font', leading: 'line_height', tracking: 'letter_spacing', w: 'size', h: 'size', min: 'size', max: 'size', size: 'size', opacity: 'opacity', duration: 'motion', z: 'z_index', from: 'color', to: 'color', via: 'color', fill: 'color', stroke: 'color' }
const COMPONENT = /^\s*(?:export\s+)?(?:default\s+)?(?:const|let|function|class)\s+([A-Za-z_$][\w$]*)/
const SELECTOR = /^\s*([^:;{}]*[^\s:;{}][^;{}]*)\{\s*$/
const MEDIA = /@media[^{]*\(\s*(?:max|min)-width\s*:\s*(\d+(?:\.\d+)?)(px|em|rem)?/g
const FALLBACK = /(\|\||\?\?)\s*['"`]?\s*$/

const kebab = (s) => s.replace(/([a-z])([A-Z])/g, '$1-$2').replace(/^(webkit|moz|ms)-/i, '-$1-').toLowerCase()

export function normalizeColor (raw) {
  const s = raw.trim().toLowerCase()
  if (NAMED[s]) return NAMED[s]
  if (s.startsWith('#')) {
    let h = s.slice(1)
    if (h.length <= 4) h = [...h].map(c => c + c).join('')
    return '#' + h.toUpperCase()
  }
  const m = s.match(/^(rgba?|hsla?)\(([^)]*)\)$/)
  if (!m) return null
  const parts = m[2].split(/[\s,/]+/).filter(Boolean)
  const num = (p, scale = 1) => p.endsWith('%') ? parseFloat(p) / 100 * scale : parseFloat(p)
  let r, g, b
  const a = parts[3] !== undefined ? num(parts[3], 1) : 1
  if (m[1].startsWith('rgb')) {
    [r, g, b] = parts.slice(0, 3).map(p => num(p, 255))
  } else {
    const hh = parseFloat(parts[0]) / 360; const ss = num(parts[1], 1); const ll = num(parts[2], 1)
    const q = ll < 0.5 ? ll * (1 + ss) : ll + ss - ll * ss; const p = 2 * ll - q
    const t = (x) => { x = (x + 1) % 1; return x < 1 / 6 ? p + (q - p) * 6 * x : x < 1 / 2 ? q : x < 2 / 3 ? p + (q - p) * (2 / 3 - x) * 6 : p }
    ;[r, g, b] = [t(hh + 1 / 3), t(hh), t(hh - 1 / 3)].map(x => x * 255)
  }
  if ([r, g, b, a].some(Number.isNaN)) return null
  const hex = [r, g, b].map(x => Math.round(Math.max(0, Math.min(255, x))).toString(16).padStart(2, '0')).join('').toUpperCase()
  return '#' + hex + (a < 1 ? Math.round(a * 255).toString(16).padStart(2, '0').toUpperCase() : '')
}

// Removes ${...} interpolations (nesting aware) so only literal text is read.
export function stripInterpolations (s) {
  let out = ''; let depth = 0
  for (let i = 0; i < s.length; i++) {
    if (depth === 0 && s[i] === '$' && s[i + 1] === '{') { depth = 1; i++; out += ' '; continue }
    if (depth > 0) { if (s[i] === '{') depth++; else if (s[i] === '}') depth--; continue }
    out += s[i]
  }
  return out
}

// Named colors count only inside a CSS declaration: elsewhere `'green'` is
// usually a variant name (<Button color='green'>), not a color.
function colorsIn (text, named = true) {
  const found = []
  for (const m of text.matchAll(HEX)) found.push(m[0])
  for (const m of text.matchAll(FUNC_COLOR)) if (!/var\(|calc\(/.test(m[0])) found.push(m[0])
  if (named) for (const m of text.matchAll(NAMED_RE)) found.push(m[1])
  return found
}

function lengthsIn (text, bareNumbersArePx) {
  const out = []
  const clean = text.replace(HEX, ' ').replace(FUNC_COLOR, ' ')
  for (const m of clean.matchAll(NUMBER)) {
    const n = parseFloat(m[1]); const unit = m[2] || ''
    if (unit === 'px' || (unit === '' && bareNumbersArePx)) out.push({ px: n, unit: 'px' })
    else if (unit === 'rem') out.push({ px: n * 16, unit: 'rem' })
    else if (unit === 'pt') out.push({ px: n * 4 / 3, unit: 'pt' })
    else if (unit) out.push({ px: null, unit, n })
  }
  return out
}

function shadowLayers (text) {
  return text.split(/,(?![^(]*\))/).map(layer => {
    const color = colorsIn(layer)[0]
    const nums = lengthsIn(layer.replace(/\binset\b/, ''), true).filter(x => x.px !== null).map(x => x.px)
    return { inset: /\binset\b/.test(layer), x: nums[0] ?? 0, y: nums[1] ?? 0, blur: nums[2] ?? 0, spread: nums[3] ?? 0, color: color ? normalizeColor(color) : null }
  }).filter(l => l.color || l.blur || l.y || l.x)
}

function durationsIn (text) {
  const out = []
  for (const m of text.matchAll(/(\d*\.?\d+)(ms|s)\b/g)) out.push(m[2] === 's' ? parseFloat(m[1]) * 1000 : parseFloat(m[1]))
  return out
}

// Tailwind's default scale (a project with its own `theme` in tailwind.config
// maps differently: read the config before trusting these numbers).
const TW_RADIUS = { none: 0, sm: 2, DEFAULT: 4, md: 6, lg: 8, xl: 12, '2xl': 16, '3xl': 24, full: 9999 }
const TW_TEXT = { xs: 12, sm: 14, base: 16, lg: 18, xl: 20, '2xl': 24, '3xl': 30, '4xl': 36, '5xl': 48, '6xl': 60 }
const TW_WEIGHT = { thin: 100, extralight: 200, light: 300, normal: 400, medium: 500, semibold: 600, bold: 700, extrabold: 800, black: 900 }
function tailwindValue (category, arg) {
  const bracket = arg.match(/^\[(.+)\]$/)
  if (bracket) {
    const px = lengthsIn(bracket[1].replace(/_/g, ' '), false).filter(x => x.px !== null).map(x => x.px)
    if (category === 'motion') return { ms: durationsIn(bracket[1]) }
    return px.length ? { px } : {}
  }
  const step = (v) => v === 'px' ? 1 : /^\d+(\.\d+)?$/.test(v) ? parseFloat(v) * 4 : null
  if (category === 'space' || category === 'size') { const px = step(arg.replace(/^[xytblrse]-/, '')); return px === null ? {} : { px: [px] } }
  if (category === 'radius') { const k = arg.replace(/^[trblse]{1,2}(-|$)/, ''); return k in TW_RADIUS ? { px: [TW_RADIUS[k]] } : {} }
  if (category === 'font_size') return TW_TEXT[arg] ? { px: [TW_TEXT[arg]] } : {}
  if (category === 'font_weight') return TW_WEIGHT[arg] ? { weight: TW_WEIGHT[arg] } : {}
  if (category === 'border') { const n = arg.replace(/^[trblxy](-|$)/, ''); return n === 'DEFAULT' ? { px: [1] } : /^\d+$/.test(n) ? { px: [Number(n)] } : {} }
  if (category === 'motion') return /^\d+$/.test(arg) ? { ms: [Number(arg)] } : {}
  if (category === 'opacity' || category === 'z_index') return /^\d+$/.test(arg) ? { n: category === 'opacity' ? Number(arg) / 100 : Number(arg) } : {}
  return {}
}

function walk (dir, exclude, files = []) {
  let list
  try { list = fs.readdirSync(dir, { withFileTypes: true }) } catch { return files }
  for (const d of list) {
    const p = path.join(dir, d.name)
    if (exclude.test(p + (d.isDirectory() ? '/' : ''))) continue
    if (d.isDirectory()) walk(p, exclude, files)
    else if (EXTENSIONS.includes(path.extname(d.name))) files.push(p)
  }
  return files
}

function record (entries, base, category, property, raw, extra = {}) {
  if (!raw.trim()) return
  const e = { ...base, category, property, value: raw.trim().slice(0, 200), ...extra }
  // Icon sizes and colors are not text: the sweeps must not map them to typography.
  if (/svg|icon|\bi\b/i.test(`${base.selector ?? ''} ${base.component ?? ''}`)) e.icon = true
  if (category === 'color') {
    e.colors = colorsIn(raw, !property.startsWith('attr:')).map(normalizeColor).filter(Boolean)
    if (!e.colors.length) return
  } else if (category === 'shadow') {
    if (/^\s*none\s*$/.test(raw)) return
    e.layers = shadowLayers(raw)
    e.colors = e.layers.map(l => l.color).filter(Boolean)
    if (!e.layers.length) return
  } else if (category === 'motion') {
    e.ms = durationsIn(raw)
    if (!e.ms.length) return
  } else if (category === 'font_weight') {
    const w = raw.match(/\b([1-9]00|bold|normal|lighter|bolder)\b/)
    if (!w) return
    e.weight = { bold: 700, normal: 400 }[w[1]] ?? (Number(w[1]) || w[1])
  } else if (category === 'font_family') {
    if (/inherit|var\(/.test(raw)) return
  } else if (category === 'text_transform') {
    if (!/uppercase|capitalize|lowercase/.test(raw)) return
  } else if (category === 'opacity' || category === 'z_index') {
    const n = raw.match(/-?\d*\.?\d+/)
    if (!n) return
    e.n = parseFloat(n[0])
  } else {
    const nums = lengthsIn(raw, extra.inlineObject)
    const px = nums.filter(x => x.px !== null && x.px !== 0)
    if (category === 'border') {
      const c = colorsIn(raw).map(normalizeColor).filter(Boolean)
      if (c.length) e.colors = c
      if (!px.length && !c.length) return
    } else if (!px.length && !(category === 'line_height' && nums.some(x => x.unit === '' || x.px))) {
      if (category === 'line_height' && /^\s*\d*\.?\d+\s*$/.test(raw)) e.ratio = parseFloat(raw)
      else return
    }
    if (category === 'line_height' && /^\s*\d*\.?\d+\s*$/.test(raw) && !extra.inlineObject) e.ratio = parseFloat(raw)
    else e.px = px.map(x => Math.round(x.px * 100) / 100)
    if (category === 'font_size') e.px = e.px?.slice(0, 1)
  }
  entries.push(e)
}

export function scanFile (file, rel) {
  const entries = []
  const text = fs.readFileSync(file, 'utf8')
  const ext = path.extname(file)
  const isStyleFile = ['.css', '.scss', '.sass', '.less'].includes(ext)
  let component = null; const selectors = []; let inBlockComment = false
  text.split('\n').forEach((original, i) => {
    let line = original
    if (inBlockComment) { const end = line.indexOf('*/'); if (end < 0) return; line = line.slice(end + 2); inBlockComment = false }
    line = line.replace(/\/\*.*?\*\//g, ' ')
    const open = line.indexOf('/*'); if (open >= 0) { line = line.slice(0, open); inBlockComment = true }
    if (/^\s*\/\//.test(line)) return
    line = line.replace(/(^|[^:'"`])\/\/.*$/, '$1')
    const c = !isStyleFile && line.match(COMPONENT); if (c) { component = c[1]; selectors.length = 0 }
    const s = line.match(SELECTOR)
    if (s && !/^\s*(if|for|while|switch|function|else|return|try|catch)\b|=>\s*\{$|\)\s*\{$/.test(line)) selectors.push(s[1].trim())
    else if (/^\s*\}/.test(line) && selectors.length) selectors.pop()
    const base = { loc: `${rel}:${i + 1}`, file: rel, line: i + 1, component, selector: selectors.at(-1) ?? null }
    const literal = stripInterpolations(line)
    const fallback = (raw) => { const at = line.indexOf(raw); return at > 0 && FALLBACK.test(line.slice(0, at)) }
    const seen = new Set()
    for (const m of literal.matchAll(MEDIA)) {
      entries.push({ ...base, category: 'breakpoint', property: '@media', value: m[0], px: [m[2] === 'em' || m[2] === 'rem' ? parseFloat(m[1]) * 16 : parseFloat(m[1])] })
      seen.add('media')
    }
    if (seen.has('media')) return
    for (const m of literal.matchAll(DECL)) {
      const prop = kebab(m[1]); const category = PROPS[prop]
      if (!category) continue
      let raw = m[2].replace(/\s*(?:!important)?\s*[,`'"]*\s*$/, '').replace(/^\s*['"`]/, '')
      // A JS style object (style={{ marginTop: 8 }}): camelCase key, or a bare number with no `;`.
      const ends = literal[m.index + m[0].length]
      const inlineObject = !isStyleFile && ends !== ';' && (/[a-z][A-Z]/.test(m[1]) || /^\s*-?\d*\.?\d+\s*,\s*$/.test(m[2]))
      if (/^\s*(var\(|inherit|initial|unset|currentColor|auto|none\s*$)/i.test(raw) && category !== 'border') continue
      raw = raw.replace(/\s*\}\s*$/, '')
      record(entries, { ...base, fallback: fallback(m[2].trim()) }, category, prop, raw, { inlineObject })
      seen.add(m[2])
    }
    for (const m of literal.matchAll(ATTR)) {
      const category = { fill: 'color', stroke: 'color', color: 'color', width: 'size', height: 'size', size: 'size' }[m[1]]
      record(entries, base, category, `attr:${m[1]}`, m[2], { inlineObject: true })
      seen.add(m[2])
    }
    for (const m of line.matchAll(CLASS_ATTR)) {
      for (const cls of m[1].split(/\s+/)) {
        // `rounded`, `border-t` and `shadow` alone are their default step.
        const bare = cls.split(':').pop().replace(/^(-?(?:rounded|border|shadow))((?:-[trblsexy]{1,2})?)$/, '$1$2-DEFAULT')
        const t = bare.match(TW)
        if (!t) continue
        const key = t[2].split('-')[0].replace(/^(p[xytblrse]?|m[xytblrse]?)$/, x => x[0])
        let category = TW_CATEGORY[key] ?? 'other'
        const arg = t[3]
        // Alignment, wrapping and `*-none` are not design values.
        if (key === 'text' && /^(left|right|center|justify|start|end|ellipsis|clip|wrap|nowrap|balance|pretty)$/.test(arg)) continue
        if (['border', 'outline', 'ring', 'shadow'].includes(key) && /^(none|hidden|collapse|separate|solid|dashed|dotted|double|inset|0)$/.test(arg)) continue
        if (category === 'text') category = /^(xs|sm|base|lg|[2-9]?xl)$|\[\d/.test(arg) ? 'font_size' : 'color'
        if (category === 'font') category = /^(thin|extralight|light|normal|medium|semibold|bold|extrabold|black)$/.test(arg) ? 'font_weight' : 'font_family'
        if (category === 'border' && !/^(\d|\[|DEFAULT$)/.test(arg.replace(/^[trblxy]-/, ''))) category = 'color'
        seen.add(cls)
        entries.push({ ...base, category, property: `tw:${t[2]}`, value: cls, tailwind: true, arbitrary: /\[.+\]/.test(arg), palette: TW_PALETTE.test(arg), colors: colorsIn(arg).map(normalizeColor).filter(Boolean), ...tailwindValue(category, arg) })
      }
    }
    // Colors outside a recognised property (JSX props, objects, SVG markup).
    for (const raw of colorsIn(literal, false)) {
      if ([...seen].some(v => typeof v === 'string' && v.includes(raw))) continue
      const color = normalizeColor(raw)
      if (color) entries.push({ ...base, category: 'color', property: null, value: raw, colors: [color], fallback: fallback(raw), loose: true })
    }
  })
  return entries
}

export function scan (repo, { src, exclude } = {}) {
  const roots = (src?.length ? src : ['src']).map(d => path.resolve(repo, d)).filter(d => fs.existsSync(d))
  if (!roots.length) roots.push(path.resolve(repo))
  const ex = exclude ? new RegExp(`${DEFAULT_EXCLUDE.source}|${exclude}`) : DEFAULT_EXCLUDE
  const entries = []
  for (const root of roots) {
    for (const file of walk(root, ex)) entries.push(...scanFile(file, path.relative(repo, file)))
  }
  return entries
}

function csv (entries) {
  const cols = ['loc', 'component', 'selector', 'category', 'property', 'value', 'norm', 'fallback']
  const q = (v) => v === null || v === undefined ? '' : /[",\n]/.test(String(v)) ? `"${String(v).replace(/"/g, '""')}"` : String(v)
  const norm = (e) => e.colors?.join(' ') || e.px?.join(' ') || e.ms?.join(' ') || e.weight || e.n || e.ratio || ''
  return [cols.join(','), ...entries.map(e => cols.map(c => q(c === 'norm' ? norm(e) : e[c])).join(','))].join('\n') + '\n'
}

export function parseArgs (argv) {
  const args = { _: [] }
  for (let i = 0; i < argv.length; i++) {
    if (argv[i].startsWith('--')) { const k = argv[i].slice(2); args[k] = argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[++i] : true } else args._.push(argv[i])
  }
  return args
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
  const args = parseArgs(process.argv.slice(2))
  const repo = path.resolve(args._[0] || '.')
  const out = path.resolve(args.out || path.join(repo, '.theme-audit'))
  const entries = scan(repo, { src: args.src?.split(','), exclude: args.exclude })
  fs.mkdirSync(out, { recursive: true })
  fs.writeFileSync(path.join(out, 'inventory.json'), JSON.stringify({ repo, generated: new Date().toISOString(), entries }, null, 1))
  fs.writeFileSync(path.join(out, 'inventory.csv'), csv(entries))
  const by = {}
  for (const e of entries) by[e.category] = (by[e.category] || 0) + 1
  console.log(`${entries.length} literals in ${new Set(entries.map(e => e.file)).size} files → ${out}`)
  for (const [k, v] of Object.entries(by).sort((a, b) => b[1] - a[1])) console.log(`  ${k.padEnd(15)} ${v}`)
}
