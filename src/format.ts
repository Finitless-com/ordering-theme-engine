/**
 * What every version 2 theme shares, whatever its product type: the names
 * the engine knows and the alias syntax (api-core's ThemeFormat; only what
 * resolving reads).
 */

export const FONT_ROLES = ['heading', 'body'] as const

/** The typography styles, by the font role they use. */
export const TYPOGRAPHY = {
  heading: ['display', 'h1', 'h2', 'h3', 'h4'],
  body: ['body_lg', 'body', 'body_sm', 'label', 'caption', 'button', 'overline'],
} as const

export const COLORS = [
  'primary', 'on_primary', 'primary_subtle', 'primary_pressed', 'primary_text',
  'secondary', 'on_secondary', 'secondary_subtle', 'secondary_pressed',
  'background', 'surface', 'surface_alt', 'surface_raised', 'overlay', 'on_overlay', 'inverse_surface', 'on_inverse',
  'text_heading', 'text', 'text_muted', 'text_disabled', 'link',
  'border', 'border_strong', 'divider', 'focus',
  'success', 'on_success', 'success_subtle',
  'warning', 'on_warning', 'warning_subtle',
  'danger', 'on_danger', 'danger_subtle',
  'info', 'on_info', 'info_subtle',
  'disabled', 'rating', 'favorite',
] as const

/** The shadow levels, from none to the largest. */
export const SHADOW = ['none', 'sm', 'md', 'lg', 'xl'] as const

/** The border a theme sets to have none; the API serves it as null. */
export const NO_BORDER = 'none'

/** An alias: `{group.name}`. */
export const ALIAS = /^\{([a-z_]+)\.([a-z0-9_]+)\}$/

/** A role of a component's color, as its appearances paint with it. */
export const ROLE = /^\{(main|on|ink|subtle|pressed)\}$/

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
