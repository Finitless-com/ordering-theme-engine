/**
 * A string property's `pattern` rule: a regular expression in the subset
 * that PCRE (the Ordering.co API) and ECMAScript with the `u` flag (here
 * and in the editor) read the same way, so a value passes in both or in
 * neither (api-core's ThemePattern, scanned with the same logic).
 *
 * The subset (anything else and patternSource() answers null):
 *
 * - literal characters, except the metacharacters `\ ^ $ . | ? * + ( ) [ ] { }`
 *   (a lone `]`, `{` or `}` included) and the control characters
 *   (U+0000 to U+001F and U+007F: `\n`, `\r` and `\t` write those);
 * - the escapes `\d \D \w \W \n \r \t` and an escaped metacharacter or `/`
 *   (`\-` only inside a class, where ECMAScript accepts it);
 * - `.`;
 * - the anchors `^` and `$`, which never repeat;
 * - `( … )`, `(?: … )` and the lookaheads `(?= … )` and `(?! … )`, which
 *   never repeat; parentheses balance;
 * - `|`;
 * - the quantifiers `? * +`, `{n}`, `{n,}` and `{n,m}` (digits, n ≤ m ≤
 *   65535, PCRE's largest), optionally lazy (`?`), each after something
 *   that repeats and never after another (no possessive `++`);
 * - the classes `[ … ]` and `[^ … ]`, not empty, of literals other than
 *   `\`, `[` and `]`, the escapes above but `\D` and `\W`, and ranges
 *   whose ends are single characters (a literal or an escaped one), the
 *   first not after the last; `-` is literal first, last or escaped.
 *
 * Where the engines would still differ, patternSource() writes the pattern
 * as ECMAScript reads it, which PCRE then reads the same way:
 *
 * - PHP's `u` modifier also makes `\d` and `\w` Unicode (PCRE2_UCP), while
 *   ECMAScript's are ASCII: they are written `[0-9]` and `[A-Za-z0-9_]`
 *   (`\D` and `\W` their negations), which is also why neither negation
 *   goes in a class, and why `\b` and `\B`, word boundaries by that `\w`,
 *   are outside the subset;
 * - PCRE's `.` leaves out only `\n`, ECMAScript's also `\r`, U+2028 and
 *   U+2029: it is written as that class;
 * - PCRE's `$` also matches before a final newline unless the `D` modifier
 *   is set, which PHP sets: `$` matches only at the very end.
 */

/** What `.` matches in ECMAScript. */
const DOT = '[^\\n\\r  ]'

/** What `\d` and `\w` match in ECMAScript, as a class's members. */
const DIGIT = '0-9'
const WORD = 'A-Za-z0-9_'

const META = '\\^$.|?*+()[]{}'

/** The largest repetition PCRE compiles. */
const MAX_REPEAT = 65535

/**
 * The pattern as both engines read it alike (`.`, `\d`, `\D`, `\w` and `\W`
 * written as the classes ECMAScript reads), or null when it is outside the
 * subset (ThemePattern::source()).
 */
export function patternSource(pattern: string): string | null {
  const chars = Array.from(pattern)
  // PHP reads only UTF-8, which has no lone surrogate.
  if (chars.some((char) => isSurrogate(char))) {
    return null
  }
  let source = ''
  // What the last token was: `none` (the start, after `(` or `|`), `atom` (it repeats), `fixed` (it does not) or `quantifier`.
  let last = 'none'
  // Each open group, true when it is a lookahead.
  const groups: boolean[] = []

  for (let i = 0; i < chars.length;) {
    const char = chars[i] as string
    if (char === '\\') {
      const escaped = chars[i + 1]
      if (escaped === undefined) {
        return null
      }
      const written = escaped === 'd' ? `[${DIGIT}]`
        : escaped === 'D' ? `[^${DIGIT}]`
          : escaped === 'w' ? `[${WORD}]`
            : escaped === 'W' ? `[^${WORD}]`
              : 'nrt/'.includes(escaped) || META.includes(escaped) ? char + escaped
                : null
      if (written === null) {
        return null
      }
      last = 'atom'
      source += written
      i += 2
    } else if (char === '^' || char === '$') {
      last = 'fixed'
      source += char
      i++
    } else if (char === '.') {
      last = 'atom'
      source += DOT
      i++
    } else if (char === '|') {
      last = 'none'
      source += char
      i++
    } else if (char === '(') {
      let open = '('
      let lookahead = false
      if (chars[i + 1] === '?') {
        const kind = chars[i + 2]
        if (kind !== ':' && kind !== '=' && kind !== '!') {
          return null
        }
        open += '?' + kind
        lookahead = kind !== ':'
      }
      groups.push(lookahead)
      last = 'none'
      source += open
      i += open.length
    } else if (char === ')') {
      if (groups.length === 0) {
        return null
      }
      last = groups.pop() ? 'fixed' : 'atom'
      source += char
      i++
    } else if (char === '?' || char === '*' || char === '+' || char === '{') {
      let length = char === '{' ? bounds(chars, i) : 1
      if (length === null || last !== 'atom') {
        return null
      }
      if (chars[i + length] === '?') {
        length++
      }
      last = 'quantifier'
      source += chars.slice(i, i + length).join('')
      i += length
    } else if (char === '[') {
      const at = { i }
      const written = characterClass(chars, at)
      if (written === null) {
        return null
      }
      last = 'atom'
      source += written
      i = at.i
    } else if (char === ']' || char === '}' || isControl(char)) {
      return null
    } else {
      last = 'atom'
      source += char
      i++
    }
  }

  return groups.length === 0 ? source : null
}

/**
 * Whether a value matches a pattern of the subset (never one outside it;
 * ThemePattern::matches()).
 */
export function matchesPattern(pattern: string, value: string): boolean {
  const source = patternSource(pattern)
  return source !== null && new RegExp(source, 'u').test(value)
}

/** The length of the quantifier `{n}`, `{n,}` or `{n,m}` at start, or null when the `{` starts none. */
function bounds(chars: string[], start: number): number | null {
  const at = { i: start + 1 }
  const min = number(chars, at)
  if (min === null) {
    return null
  }
  let max: number | null = min
  if (chars[at.i] === ',') {
    at.i++
    max = chars[at.i] === '}' ? MAX_REPEAT : number(chars, at)
  }
  if (max === null || min > max || chars[at.i] !== '}') {
    return null
  }
  return at.i + 1 - start
}

/** The decimal number at `at.i`, which moves past it, or null when there is none or it is above MAX_REPEAT. */
function number(chars: string[], at: { i: number }): number | null {
  const start = at.i
  let value = 0
  for (let digit = chars[at.i]; digit !== undefined && '0123456789'.includes(digit); digit = chars[at.i]) {
    value = value * 10 + Number(digit)
    if (value > MAX_REPEAT) {
      return null
    }
    at.i++
  }
  return at.i > start ? value : null
}

/** The class at `at.i` as it is written in the source, `at.i` moved past it, or null when it is outside the subset. */
function characterClass(chars: string[], at: { i: number }): string | null {
  const out = { written: '[' }
  at.i++
  if (chars[at.i] === '^') {
    out.written += '^'
    at.i++
  }
  let members = 0
  for (;;) {
    const char = chars[at.i]
    if (char === undefined) {
      return null
    }
    if (char === ']') {
      at.i++
      return members === 0 ? null : `${out.written}]`
    }
    if (char === '-') {
      // A literal `-` only first or last.
      if (members > 0 && chars[at.i + 1] !== ']') {
        return null
      }
      out.written += char
      at.i++
      members++
      continue
    }
    const from = classMember(chars, at, out)
    if (from === false) {
      return null
    }
    if (chars[at.i] === '-' && chars[at.i + 1] !== ']') {
      out.written += '-'
      at.i++
      const to = chars[at.i] === '-' ? false : classMember(chars, at, out)
      if (from === null || to === null || to === false || from > to) {
        return null
      }
    }
    members++
  }
}

/**
 * The code point of the class member at `at.i`, `at.i` moved past it and
 * the member added to `out.written`: null for `\d` and `\w`, which are no
 * single character, and false when it is outside the subset (`\`, `[`, a
 * control character or another escape).
 */
function classMember(chars: string[], at: { i: number }, out: { written: string }): number | false | null {
  const char = chars[at.i]
  if (char === undefined || char === '[' || isControl(char)) {
    return false
  }
  if (char !== '\\') {
    out.written += char
    at.i++
    return char.codePointAt(0) as number
  }
  const escaped = chars[at.i + 1]
  at.i += 2
  if (escaped === 'd' || escaped === 'w') {
    out.written += escaped === 'd' ? DIGIT : WORD
    return null
  }
  const code = escaped === undefined ? false
    : escaped === 'n' ? 10
      : escaped === 'r' ? 13
        : escaped === 't' ? 9
          : escaped === '/' || escaped === '-' || META.includes(escaped) ? escaped.codePointAt(0) as number
            : false
  if (code !== false) {
    out.written += char + escaped
  }
  return code
}

function isControl(char: string): boolean {
  const code = char.codePointAt(0) as number
  return code < 0x20 || code === 0x7f
}

function isSurrogate(char: string): boolean {
  const code = char.codePointAt(0) as number
  return code >= 0xd800 && code <= 0xdfff
}
