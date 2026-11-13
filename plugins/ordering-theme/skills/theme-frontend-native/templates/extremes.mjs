#!/usr/bin/env node
// Template for scripts/theme/extremes.mjs in a themed React Native app (run it in CI as
// `theme:extremes`). Replace the paths the checks read with ones of the app's schema.
//
// Extreme themes as the API serves them: each one is resolved with the schema
// (`resolve`, what api-core does), completed with the compiled file (`complete`, what
// the app does) and converted (`toNative`). It fails when a theme does not reach what
// the app paints: a property that stopped following Identity, a font without its
// files, a color the matrix no longer derives. It does not replace looking at the
// extreme themes on a device (references/verification.md §3).
import fs from 'node:fs'
import { resolve } from '@orderingco/ordering-theme-engine'
import { complete } from '@orderingco/ordering-theme-engine/runtime'
import { toNative } from '@orderingco/ordering-theme-engine/native'

const schema = JSON.parse(fs.readFileSync('product.schema.json', 'utf8'))
const compiled = JSON.parse(fs.readFileSync('src/theme.compiled.json', 'utf8'))
const paint = (theme) => {
  const resolved = resolve(schema, theme)
  const values = complete(compiled, resolved.values ?? resolved)
  return { values, native: toNative(compiled, values) }
}

const fonts = schema.tokens.font.body.$extensions['co.ordering.editor'].options.map((o) => o.value)
const defaultFont = schema.tokens.font.body.$value
const defaultFile = paint({}).native.fonts.body.files['400']

// name: [the theme as an author saves it, what the app must then paint]
const checks = {
  square: [{ tokens: { border_style: { radius: 'none' } } }, (t) => t.native.components.button.base.radius === 0],
  pill: [{ tokens: { border_style: { radius: 'full' } } }, (t) => t.native.components.button.base.radius > 100],
  light_primary: [{ tokens: { color: { primary: '#FFE66D', on_primary: '#000000' } } }, (t) => t.native.components.button.variants.primary.solid.color === '#000000'],
  dark_surfaces: [{ tokens: { color: { background: '#0B0B0F', surface: '#16161D', text: '#F5F5F7' } } }, (t) => t.native.components.card.base.background === '#16161D'],
  no_shadows: [{ tokens: { shadow_style: { size: 'none' } } }, (t) => t.native.components.card.base.shadow === null || t.native.components.card.base.shadow.shadowOpacity === 0],
  larger_text: [{ tokens: { typography: { body: { size: 18, line: 1.5, weight: 400 } } } }, (t) => t.native.tokens.typography.body.fontSize === 18 && t.native.tokens.typography.body.lineHeight === 27],
  // Every catalog font paints with its own files: the bold file on headings, the
  // regular one on body, never the default family's.
  ...Object.fromEntries(fonts.map((font) => [`font_${font}`, [{ tokens: { font: { heading: font, body: font } } }, (t) => {
    const heading = t.native.tokens.typography.h1.fontFamily
    const body = t.native.tokens.typography.body.fontFamily
    return heading === t.native.fonts.heading.files['700'] && body === t.native.fonts.body.files['400'] &&
      (body === defaultFile) === (font === defaultFont)
  }]])),
}

let failed = 0
for (const [name, [theme, ok]] of Object.entries(checks)) {
  let pass = false
  let error = ''
  try { pass = !!ok(paint(theme)) } catch (e) { error = ` (${e.message})` }
  if (!pass) failed++
  console.log(`${pass ? 'ok  ' : 'FAIL'} ${name}${error}`)
}
process.exit(failed ? 1 : 0)
