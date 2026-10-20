import { entries, get, has, isArray, isInt, strlen, type PhpArray } from './php.js'

/**
 * What every version 2 theme shares, whatever its product type: the names
 * the engine knows, the alias syntax and what a literal of each kind looks
 * like (api-core's ThemeFormat).
 *
 * PHP's `$` also matches before a final newline (preg_match without the D
 * modifier): the patterns that imitate one end in `\n?$`. A string's
 * `pattern` rule does not imitate it: PHP compiles that one with the D
 * modifier (src/pattern.ts).
 */

export const FONT_ROLES = ['heading', 'body'] as const

/** The typography styles, by the font role they use. */
export const TYPOGRAPHY = {
  heading: ['display', 'h1', 'h2', 'h3', 'h4'],
  body: ['body_lg', 'body', 'body_sm', 'label', 'caption', 'button', 'overline'],
} as const

/**
 * The token groups whose names each schema declares for itself, among the
 * catalog's (api-core's ThemeFormat::OWN_GROUPS); every other group is a
 * scale every schema has whole.
 */
export const OWN_GROUPS = ['color', 'typography', 'opacity'] as const

/** Every color token a schema version names, the ones a later version dropped included. */
export const COLORS = [
  'primary', 'on_primary', 'primary_subtle', 'primary_pressed', 'primary_text',
  'secondary', 'on_secondary', 'secondary_subtle', 'secondary_pressed',
  'background', 'surface', 'surface_alt', 'surface_raised', 'overlay', 'on_overlay', 'inverse_surface', 'on_inverse',
  'text_heading', 'text', 'text_muted', 'text_disabled', 'link',
  'border', 'border_strong', 'divider', 'focus',
  'success', 'on_success', 'success_subtle', 'success_text',
  'warning', 'on_warning', 'warning_subtle', 'warning_text',
  'danger', 'on_danger', 'danger_subtle', 'danger_text',
  'info', 'on_info', 'info_subtle',
  'disabled', 'rating', 'favorite',
] as const

/** The shadow levels, from none to the largest. */
export const SHADOW = ['none', 'sm', 'md', 'lg', 'xl'] as const

/** The border a theme sets to have none; the API serves it as null. */
export const NO_BORDER = 'none'

/** An alias: `{group.name}`. */
export const ALIAS = /^\{([a-z_]+)\.([a-z0-9_]+)\}\n?$/

/** A role of a component's color, as its appearances paint with it. */
export const ROLE = /^\{(main|on|ink|subtle|pressed)\}\n?$/

/** [group, name] of an alias, or null. */
export function alias(value: unknown): [string, string] | null {
  if (typeof value !== 'string') {
    return null
  }
  const match = ALIAS.exec(value)
  return match ? [match[1]!, match[2]!] : null
}

/** The font role of a typography style. */
export function typographyRole(style: string): 'heading' | 'body' {
  return (TYPOGRAPHY.heading as readonly string[]).includes(style) ? 'heading' : 'body'
}

/**
 * Each property kind as its node type and, for a dimension, the token group
 * its levels come from (api-core's NodeFormat::KINDS).
 */
export const KINDS: Record<string, [string, string | null]> = {
  color: ['color', null],
  role: ['role', null],
  radius: ['dimension', 'radius'],
  size: ['dimension', 'size'],
  icon: ['dimension', 'icon'],
  space: ['dimension', 'space'],
  motion: ['duration', null],
  opacity: ['opacity', null],
  number: ['number', null],
  text: ['typography', null],
  font_role: ['fontRole', null],
  border: ['border', null],
  shadow: ['shadow', null],
  image: ['image', null],
  bool: ['boolean', null],
  option: ['option', null],
  layout: ['layout', null],
  sections: ['sections', null],
  string: ['string', null],
  html: ['html', null],
  id: ['id', null],
}

/** The property kind of a node type and scale, or null. */
export function kindOf(type: string, scale: unknown): string | null {
  for (const [kind, [kindType, kindScale]] of Object.entries(KINDS)) {
    if (kindType === type && kindScale === scale) {
      return kind
    }
  }
  return null
}

export const VERSION = 2

export const RADIUS = ['none', 'xs', 'sm', 'md', 'lg', 'xl', 'xxl', 'full'] as const
export const SIZE = ['sm', 'md', 'lg', 'xl'] as const
export const ICON = ['sm', 'md', 'lg'] as const
export const SPACE = ['none', 'xxs', 'xs', 'sm', 'md', 'lg', 'xl', 'xxl', 'xxxl'] as const
export const LAYOUT = ['page_gutter', 'mode'] as const
export const LAYOUT_MODES = ['original', 'appointments'] as const
export const MOTION = ['fast', 'base', 'slow'] as const
export const EASINGS = ['linear', 'ease', 'ease_in', 'ease_out', 'ease_in_out'] as const
export const BORDER = ['thin', 'thick'] as const
export const OPACITY = ['disabled', 'pressed', 'scrim'] as const
export const BORDER_STYLES = ['solid', 'dashed', 'dotted'] as const

/** The shadow styles and directions (api-core's ThemeShadows). */
export const SHADOW_STYLES = ['none', 'soft', 'normal', 'strong', 'solid'] as const
export const SHADOW_DIRECTIONS = ['down', 'down_right', 'down_left', 'around'] as const

/** The Identity choices a property may follow (ThemeOverall::GROUPS). */
export const OVERALL_GROUPS = ['shadow', 'radius', 'border'] as const

/** What a slot may choose of its component, by the section that holds the choices. */
export const SLOT_AXES: Record<string, string> = { size: 'sizes', variant: 'variants', appearance: 'appearances', color: 'colors', shape: 'shapes' }

/** Each token group and the kind of its values. */
export const TOKENS: Record<string, string> = {
  color: 'color',
  radius: 'number',
  size: 'number',
  icon: 'number',
  space: 'number',
  layout: 'number',
  motion: 'number',
  border: 'number',
  shadow: 'shadow',
  opacity: 'opacity',
}

/** The font catalog's ids. */
export const FONTS = ['archivo', 'inter', 'lato', 'montserrat', 'nunito', 'open_sans', 'playfair_display', 'poppins', 'raleway', 'roboto'] as const

export const WEIGHTS = [100, 200, 300, 400, 500, 600, 700, 800, 900] as const

/** The kinds a component property may have, and the token group an alias in it may name. */
export const PROPERTY_KINDS: Record<string, string | null> = {
  color: 'color',
  radius: 'radius',
  size: 'size',
  icon: 'icon',
  space: 'space',
  motion: 'motion',
  opacity: 'opacity',
  number: null,
  text: 'typography',
  font_role: null,
  border: null,
  shadow: 'shadow',
  image: null,
  bool: null,
  option: null,
  layout: null,
  string: null,
  html: null,
  id: null,
  role: 'color',
}

/** The roles of a component's color. */
export const ROLES = ['main', 'on', 'ink', 'subtle', 'pressed'] as const

/** The kinds whose value is one of the property's options. */
export const OPTION_KINDS = ['option', 'layout'] as const

/** The kinds that may narrow their levels with options. */
export const LEVEL_OPTION_KINDS = ['radius'] as const

/** The product types of the Builder's catalog (BuilderProductTypeEnum). */
export const PRODUCT_TYPES = [
  'marketplace_app', 'franchise_app', 'marketplace_website', 'franchise_website',
  'store_app', 'driver_app', 'kiosk_app', 'call_center', 'dashboard',
] as const

export const IMAGE_MAX_LENGTH = 2048
export const STRING_MAX_LENGTH = 1000
export const HTML_MAX_LENGTH = 200000
export const ID_MAX_LENGTH = 255

/** The names of a token group (empty for a group the format does not have). */
export function tokenNames(group: string): readonly string[] {
  switch (group) {
    case 'color': return COLORS
    case 'radius': return RADIUS
    case 'size': return SIZE
    case 'icon': return ICON
    case 'space': return SPACE
    case 'layout': return LAYOUT
    case 'motion': return MOTION
    case 'border': return BORDER
    case 'shadow': return SHADOW
    case 'opacity': return OPACITY
    case 'typography': return [...TYPOGRAPHY.heading, ...TYPOGRAPHY.body]
    case 'font': return FONT_ROLES
    case 'shadow_style': return ['style', 'color', 'direction', 'size']
    case 'border_style': return ['radius', 'width', 'style']
    case 'motion_style': return ['easing', 'enabled']
    case 'focus_ring': return ['width', 'offset', 'color']
    case 'scrollbar': return ['size', 'radius']
    default: return []
  }
}

/**
 * The node type of a token and its closed options, as the validator reads
 * it (api-core's NodeFormat::token()), or null for a token the format does
 * not have. The focus ring's color is one of $colors, the schema's own.
 */
export function tokenType(group: string, name: string, colors: readonly string[] = COLORS): [string, readonly string[] | null] | null {
  if (!tokenNames(group).includes(name)) {
    return null
  }
  switch (group) {
    case 'font': return ['font', FONTS]
    case 'typography': return ['typography', null]
    case 'color': return ['color', null]
    case 'radius': case 'size': case 'icon': case 'space': case 'border': return ['dimension', null]
    case 'layout': return name === 'mode' ? ['option', LAYOUT_MODES] : ['dimension', null]
    case 'motion': return ['duration', null]
    case 'shadow': return ['shadow', null]
    case 'opacity': return ['opacity', null]
    case 'shadow_style':
      return name === 'style' ? ['option', SHADOW_STYLES] : name === 'direction' ? ['option', SHADOW_DIRECTIONS] : name === 'size' ? ['option', SHADOW] : ['color', null]
    case 'border_style':
      return name === 'radius' ? ['option', RADIUS] : name === 'width' ? ['option', [NO_BORDER, ...BORDER]] : ['option', BORDER_STYLES]
    case 'motion_style': return name === 'easing' ? ['option', EASINGS] : ['boolean', null]
    case 'focus_ring': return name === 'width' ? ['option', BORDER] : name === 'offset' ? ['option', SPACE] : ['option', colors]
    case 'scrollbar': return ['dimension', null]
    default: return null
  }
}

/**
 * The tokens whose options a schema may narrow to some of the ones
 * tokenType() gives (NodeFormat::NARROWABLE): a theme then takes only those.
 */
export const NARROWABLE = ['focus_ring.color', 'focus_ring.offset'] as const

/** The type a group's tokens had before, which a schema published with it may still declare (NodeFormat::FORMER_TYPES). */
export const FORMER_TYPES: Readonly<Record<string, string>> = { scrollbar: 'number' }

// PCRE's \s without the u modifier: ASCII whitespace only.
const COLOR_HEX = /^#([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})\n?$/
const COLOR_RGB = /^rgba?\([ \t\n\v\f\r]*\d{1,3}[ \t\n\v\f\r]*,[ \t\n\v\f\r]*\d{1,3}[ \t\n\v\f\r]*,[ \t\n\v\f\r]*\d{1,3}[ \t\n\v\f\r]*(,[ \t\n\v\f\r]*(0|1|0?\.\d+)[ \t\n\v\f\r]*)?\)\n?$/
const IMAGE_URL = /^https?:\/\/[^ \t\n\v\f\r]+\n?$/

/** A hex color, rgb() / rgba(), or `transparent`. */
export function isColor(value: unknown): boolean {
  return typeof value === 'string' && (value === 'transparent' || COLOR_HEX.test(value) || COLOR_RGB.test(value))
}

/** None ('') or an http(s) URL of at most IMAGE_MAX_LENGTH bytes. */
export function isImage(value: unknown): boolean {
  return value === '' || (typeof value === 'string' && strlen(value) <= IMAGE_MAX_LENGTH && IMAGE_URL.test(value))
}

export function isNumber(value: unknown): value is number {
  return typeof value === 'number' && value >= 0 && value <= 9999
}

export function isOpacity(value: unknown): value is number {
  return typeof value === 'number' && value >= 0 && value <= 1
}

const hasKeys = (value: PhpArray, allowed: readonly string[]): boolean => entries(value).every(([key]) => allowed.includes(key))

/** null for none, or a shadow's offsets, blur, color and opacity (and elevation). */
export function isShadow(value: unknown): boolean {
  if (value === null) {
    return true
  }
  if (!isArray(value) || !hasKeys(value, ['x', 'y', 'blur', 'spread', 'color', 'opacity', 'elevation'])) {
    return false
  }
  if (!['x', 'y', 'blur', 'color', 'opacity'].every((key) => has(value, key))) {
    return false
  }
  for (const offset of ['x', 'y', 'spread']) {
    if (has(value, offset)) {
      const amount = get(value, offset)
      if (!(typeof amount === 'number' && Math.abs(amount) <= 128)) {
        return false
      }
    }
  }
  const elevation = get(value, 'elevation')
  return isNumber(get(value, 'blur'))
    && isColor(get(value, 'color'))
    && isOpacity(get(value, 'opacity'))
    && (!has(value, 'elevation') || (isNumber(elevation) && elevation <= 24))
}

/** A typography style. */
/** A typography's line height, a ratio of its size (ThemeFormat::LINE_MIN and LINE_MAX). */
export const LINE_MIN = 0.8
export const LINE_MAX = 3

export function isTypography(value: unknown): boolean {
  if (!isArray(value) || !hasKeys(value, ['size', 'line', 'weight', 'letter_spacing', 'transform'])) {
    return false
  }
  const size = get(value, 'size')
  const line = get(value, 'line')
  const weight = get(value, 'weight')
  const spacing = get(value, 'letter_spacing')
  // isset(): null counts as missing.
  return size !== null && line !== null && weight !== null
    && isNumber(size) && size <= 200
    && typeof line === 'number' && line >= LINE_MIN && line <= LINE_MAX
    && isInt(weight) && (WEIGHTS as readonly number[]).includes(weight)
    && (!has(value, 'letter_spacing') || (typeof spacing === 'number' && Math.abs(spacing) <= 20))
    && (!has(value, 'transform') || get(value, 'transform') === 'none' || get(value, 'transform') === 'uppercase')
}
