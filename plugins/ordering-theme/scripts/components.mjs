#!/usr/bin/env node
// Inventory of a frontend's UI pieces, web or React Native: the routes or
// screens (React Navigation's <X.Screen name>), the components and styled
// elements it declares, grouped by role (button, card, modal, input…) so pieces
// with the same function show up together, the native elements it renders
// (button, a, img… or TouchableOpacity, Pressable, Image, TextInput…), how often
// each component is reused, and every image source (asset import or require(),
// url(), { uri }, remote).
//
//   node components.mjs <repo> [--src src,app] [--out <dir>] [--exclude <regex>]
//
// Writes <out>/components.json and <out>/components.md. Heuristic: names and
// tags point to candidates; the agent confirms each group by reading the code.

import fs from 'node:fs'
import path from 'node:path'
import { DEFAULT_EXCLUDE, parseArgs } from './inventory.mjs'

const CODE = ['.js', '.jsx', '.ts', '.tsx', '.mjs', '.vue', '.svelte', '.astro']

// Role → name pattern. Order matters: the first match wins.
const ROLES = [
  ['skeleton', /skeleton|shimmer|placeholder(?!image)/i],
  ['spinner', /spinner|loader|loading/i],
  ['icon_button', /icon.?button|close.?button|iconbtn/i],
  ['button', /button|btn|cta\b/i],
  ['link', /link|anchor/i],
  ['chip', /chip|pill|tag(?!line)|badge|label(?=s?$)|ribbon/i],
  ['tabs', /tabs?\b|tabbar|segment/i],
  ['input', /input|textfield|textarea|search.?bar|field/i],
  ['select', /select|dropdown|picker|combobox/i],
  ['toggle', /switch|toggle|checkbox|radio|check\b/i],
  ['stepper', /stepper|quantity|counter/i],
  ['modal', /modal|dialog|popup|sheet|drawer|lightbox|overlay/i],
  ['popover', /popover|tooltip|menu(?!item)|flyout/i],
  ['alert', /alert|notice|toast|snackbar|banner(?!image)|message|warning|error(?!page)/i],
  ['card', /card|tile|box|panel|container(?=card)/i],
  ['list_item', /item|row|entry|cell/i],
  ['avatar', /avatar|profile.?photo|user.?image/i],
  ['image', /image|img|photo|picture|logo|hero|cover|thumbnail|banner/i],
  ['rating', /rating|stars?\b|review.?score/i],
  ['header', /header|navbar|topbar|appbar/i],
  ['footer', /footer|bottom.?nav|tabbar/i],
  ['sidebar', /sidebar|sidemenu|aside/i],
  ['accordion', /accordion|collapse|expand|disclosure/i],
  ['carousel', /carousel|slider|swiper|gallery/i],
  ['empty_state', /empty|not.?found|no.?results|zero.?state/i],
  ['divider', /divider|separator|hr\b|line\b/i],
  ['title', /title|heading|headline|subtitle/i],
  ['price', /price|amount|total|currency/i],
  ['form', /form\b|form$/i],
  ['layout', /wrapper|container|layout|section|content|inner|grid|column|row/i]
]
const INTRINSIC = ['button', 'a', 'img', 'picture', 'svg', 'video', 'iframe', 'input', 'select', 'textarea', 'form', 'label', 'dialog', 'table', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'nav', 'header', 'footer', 'aside', 'main', 'section']

// React Native's own elements, counted like the web's native tags.
const RN_PRIMITIVES = ['View', 'Text', 'TouchableOpacity', 'TouchableHighlight', 'TouchableWithoutFeedback', 'Pressable', 'Image', 'ImageBackground', 'FastImage', 'TextInput', 'ScrollView', 'FlatList', 'SectionList', 'Modal', 'Switch', 'ActivityIndicator', 'SafeAreaView', 'KeyboardAvoidingView', 'RefreshControl']

const LAYOUT_SUFFIX = /(Container|Wrapper|Content|Inner|Group|Section|Layout|Grid|Area|Holder|Block|Side|Column|Body)$/
const NATIVE_ROLE = {
  button: 'button', a: 'link', img: 'image', input: 'input', textarea: 'input', select: 'select', h1: 'title', h2: 'title', h3: 'title', h4: 'title', h5: 'title', h6: 'title', hr: 'divider', dialog: 'modal', label: 'title',
  TouchableOpacity: 'button', TouchableHighlight: 'button', TouchableWithoutFeedback: 'button', Pressable: 'button',
  Image: 'image', ImageBackground: 'image', FastImage: 'image', TextInput: 'input', Modal: 'modal', Switch: 'toggle', ActivityIndicator: 'spinner'
}
// A styled wrapper (ButtonContainer = div) is layout, not the role its name mentions;
// a styled native element (Foo = styled.button) takes that element's role.
const roleOf = (name, base, owner) => {
  if (base && NATIVE_ROLE[base]) return NATIVE_ROLE[base]
  if (name && LAYOUT_SUFFIX.test(name) && (!base || /^(div|section|span|ul|li|article|main|aside|nav|header|footer|form|View|ScrollView|SafeAreaView|KeyboardAvoidingView)$/.test(base))) return 'layout'
  for (const n of [name, base, owner]) for (const [r, re] of ROLES) if (n && re.test(n)) return r
  return null
}

function walk (dir, exclude, files = []) {
  let list
  try { list = fs.readdirSync(dir, { withFileTypes: true }) } catch { return files }
  for (const d of list) {
    const p = path.join(dir, d.name)
    if (exclude.test(p + (d.isDirectory() ? '/' : ''))) continue
    if (d.isDirectory()) walk(p, exclude, files)
    else if (CODE.includes(path.extname(d.name))) files.push(p)
  }
  return files
}

const args = parseArgs(process.argv.slice(2))
const repo = path.resolve(args._[0] || '.')
const out = path.resolve(args.out || path.join(repo, '.theme-audit'))
const roots = (args.src ? args.src.split(',') : ['src']).map(d => path.resolve(repo, d)).filter(d => fs.existsSync(d))
if (!roots.length) roots.push(repo)
const exclude = args.exclude ? new RegExp(`${DEFAULT_EXCLUDE.source}|${args.exclude}`) : DEFAULT_EXCLUDE

const declared = []; const usage = new Map(); const tags = {}; const images = []; const routes = []; const headings = {}
for (const root of roots) {
  for (const file of walk(root, exclude)) {
    const rel = path.relative(repo, file)
    const text = fs.readFileSync(file, 'utf8')
    const lines = text.split('\n')
    const dirName = path.basename(path.dirname(file)); const baseName = path.basename(file, path.extname(file))
    const owner = /^(index|styles?|style)$/i.test(baseName) ? dirName : baseName
    lines.forEach((line, i) => {
      const loc = `${rel}:${i + 1}`
      // styled.button`…`, styled(Button)`…`, styled('div'), emotion's styled too.
      const st = line.match(/(?:const|let)\s+([A-Z]\w*)\s*=\s*styled(?:\.(\w+)|\(\s*['"]?([\w.]+)['"]?\s*\))/)
      if (st) declared.push({ name: st[1], kind: 'styled', base: st[2] || st[3], file: rel, loc, owner, role: roleOf(st[1], st[2] || st[3], owner) })
      else {
        const c = line.match(/^\s*(?:export\s+)?(?:default\s+)?(?:const|function|class)\s+([A-Z]\w*)\s*(?:=\s*(?:\([^)]*\)|\w+|React\.memo|memo|forwardRef)|\(|extends)/)
        if (c && /\.(jsx|tsx|vue|svelte)$|\.js$|\.ts$/.test(file) && !/styles?\./.test(path.basename(file))) declared.push({ name: c[1], kind: 'component', file: rel, loc, owner, role: roleOf(c[1], null, owner) })
      }
      for (const m of line.matchAll(/<([A-Za-z][\w.]*)(?=[\s/>])/g)) {
        const tag = m[1]
        if (/^[a-z]/.test(tag)) {
          if (INTRINSIC.includes(tag)) { tags[tag] = tags[tag] || { count: 0, files: new Set() }; tags[tag].count++; tags[tag].files.add(rel) }
          if (/^h[1-6]$/.test(tag)) (headings[rel] = headings[rel] || []).push(tag)
        } else {
          if (RN_PRIMITIVES.includes(tag)) { tags[tag] = tags[tag] || { count: 0, files: new Set() }; tags[tag].count++; tags[tag].files.add(rel) }
          const u = usage.get(tag) ?? { count: 0, files: new Set() }
          u.count++; u.files.add(rel); usage.set(tag, u)
        }
      }
      // Images: <img src>, url(), imported assets, remote URLs.
      for (const m of line.matchAll(/<img\b[^>]*?\bsrc\s*=\s*(\{[^}]*\}|"[^"]*"|'[^']*')/g)) images.push({ loc, kind: 'img', source: m[1].slice(0, 120) })
      for (const m of line.matchAll(/url\(\s*(['"]?)([^)'"]+)\1\s*\)/g)) if (!/^\$\{|^data:image\/svg/.test(m[2])) images.push({ loc, kind: 'css-url', source: m[2].slice(0, 120) })
      for (const m of line.matchAll(/(?:import\s+\w+\s+from|require\()\s*['"]([^'"]+\.(?:png|jpe?g|gif|webp|avif|svg|ico))['"]/g)) images.push({ loc, kind: 'asset', source: m[1] })
      for (const m of line.matchAll(/['"`](https?:\/\/[^'"`\s]+\.(?:png|jpe?g|gif|webp|avif|svg))['"`]/g)) images.push({ loc, kind: 'remote', source: m[1] })
      for (const m of line.matchAll(/\bsource\s*=\s*\{\s*\{\s*uri\s*:\s*([^}]+)\}/g)) images.push({ loc, kind: 'uri', source: m[1].trim().slice(0, 120) })
      // Routes: react-router / vue-router / config objects.
      for (const m of line.matchAll(/\bpath\s*[:=]\s*\{?\s*['"`](\/[^'"`]*)['"`]/g)) routes.push({ path: m[1], loc })
    })
    // React Navigation: <Stack.Screen name="Business" component={BusinessPage} />, over several lines.
    for (const m of text.matchAll(/<(\w+)\.Screen\b([^>]*?)\/?>/g)) {
      const name = m[2].match(/\bname\s*=\s*\{?\s*['"`]([^'"`]+)['"`]/)
      if (!name) continue
      const component = m[2].match(/\bcomponent\s*=\s*\{\s*([\w.]+)/)?.[1]
      routes.push({ path: name[1], loc: `${rel}:${text.slice(0, m.index).split('\n').length}`, navigator: m[1], component, screen: true })
    }
  }
}
// File-system routes (Next.js, Nuxt, SvelteKit, Astro, Remix).
for (const dir of ['pages', 'app', 'src/pages', 'src/app', 'src/routes', 'app/routes']) {
  const abs = path.join(repo, dir)
  if (!fs.existsSync(abs)) continue
  for (const f of walk(abs, exclude)) {
    const r = path.relative(abs, f)
    if (/(^|\/)(page|\+page|index)\.\w+$|^[^_][^/]*\.(jsx|tsx|vue|astro|svelte)$/.test(r) && !/(^|\/)_|components\//.test(r)) routes.push({ path: '/' + r.replace(/(^|\/)(page|\+page|index)?\.\w+$/, '').replace(/\.\w+$/, ''), loc: path.relative(repo, f), fileRoute: true })
  }
}

for (const d of declared) d.uses = usage.get(d.name)?.files.size ?? 0
const byRole = {}
for (const d of declared) if (d.role && d.role !== 'layout') (byRole[d.role] = byRole[d.role] || []).push(d)

const result = {
  repo,
  // A screen name may repeat across navigators: keep one per navigator.
  routes: [...new Map(routes.map(r => [`${r.navigator ?? ''}:${r.path}`, r])).values()].sort((a, b) => a.path.localeCompare(b.path)),
  roles: Object.fromEntries(Object.entries(byRole).map(([r, list]) => [r, list.map(({ name, kind, base, file, loc, uses }) => ({ name, kind, base, file, loc, uses }))])),
  intrinsic: Object.fromEntries(Object.entries(tags).map(([t, v]) => [t, { count: v.count, files: v.files.size }])),
  reused: [...usage.entries()].map(([name, u]) => ({ name, files: u.files.size, count: u.count })).sort((a, b) => b.files - a.files).slice(0, 80),
  images,
  headings
}
fs.mkdirSync(out, { recursive: true })
fs.writeFileSync(path.join(out, 'components.json'), JSON.stringify(result, null, 1))

const md = ['# Component inventory', '', `Source: \`${repo}\`. A heuristic on names and tags: confirm each group by reading the code.`, '']
md.push(`## Routes and screens (${result.routes.length})`, '', ...result.routes.map(r => `- \`${r.path}\`${r.screen ? ` (${r.navigator}.Screen${r.component ? ` → ${r.component}` : ''})` : ''} — \`${r.loc}\``), '')
md.push('## Native elements', '', '| tag | uses | files |', '| --- | --- | --- |', ...Object.entries(result.intrinsic).sort((a, b) => b[1].count - a[1].count).map(([t, v]) => `| \`<${t}>\` | ${v.count} | ${v.files} |`), '')
md.push('## By function (candidates to group into one theme component)', '')
for (const [r, list] of Object.entries(byRole).sort((a, b) => b[1].length - a[1].length)) {
  const styled = list.filter(d => d.kind === 'styled'); const comps = list.filter(d => d.kind === 'component')
  md.push(`### ${r} — ${comps.length} components, ${styled.length} own styles`, '')
  if (comps.length) md.push('Components: ' + comps.sort((a, b) => b.uses - a.uses).map(d => `\`${d.name}\` (${d.uses} files, \`${d.loc}\`)`).join(', '), '')
  const bases = {}; for (const d of styled) if (d.base) bases[d.base] = (bases[d.base] || 0) + 1
  if (styled.length) md.push('Bases of the own styles: ' + Object.entries(bases).sort((x, y) => y[1] - x[1]).map(([b, n]) => `${b} ${n}`).join(', '), '')
  if (styled.length) md.push('Own styles: ' + styled.slice(0, 40).map(d => `\`${d.name}\`${d.base ? `=${d.base}` : ''} (\`${d.loc}\`)`).join(', ') + (styled.length > 40 ? `… and ${styled.length - 40} more` : ''), '')
}
md.push('## Most reused (likely primitives)', '', ...result.reused.slice(0, 40).map(u => `- \`${u.name}\`: ${u.files} files, ${u.count} uses`), '')
md.push(`## Images (${images.length})`, '')
const byKind = {}; for (const im of images) (byKind[im.kind] = byKind[im.kind] || []).push(im)
for (const [k, list] of Object.entries(byKind)) md.push(`### ${k} (${list.length})`, '', ...list.slice(0, 60).map(im => `- \`${im.loc}\` ${im.source}`), '')
md.push('## Headings by file', '', ...Object.entries(headings).map(([f, h]) => `- \`${f}\`: ${h.join(' ')}`), '')
fs.writeFileSync(path.join(out, 'components.md'), md.join('\n') + '\n')

console.log(`components → ${path.join(out, 'components.md')}`)
console.log(`  routes ${result.routes.length}, declared ${declared.length}, images ${images.length}`)
for (const [r, list] of Object.entries(byRole).sort((a, b) => b[1].length - a[1].length).slice(0, 12)) console.log(`  ${r.padEnd(12)} ${list.length}`)
