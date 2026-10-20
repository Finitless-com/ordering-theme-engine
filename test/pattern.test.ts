import { describe, expect, it } from 'vitest'
import { matchesPattern, patternSource } from '../src/pattern.js'
import { validRules, valueError } from '../src/validator.js'

/**
 * A string's `pattern` rule is a regular expression in the subset PCRE and
 * ECMAScript read alike, so the API and the editor agree on every value.
 * The same cases as php/tests/ThemePatternTest.php.
 */

const ACCEPTED = [
  '^\\d{6,12}$',
  '^[a-zA-Z][\\w]*(\\.[a-zA-Z][\\w]*)+$',
  '^(?:https?)://\\w+',
  'a|b|',
  '()',
  '^(?=.*\\d)(?!.*x).{8,}$',
  '[-a-z]',
  '[a-z-]',
  '[^-]',
  '[\\-\\]\\\\]',
  '[\\--/]',
  'a{2,}?',
  'x*?y+?z??',
  'a{0,65535}',
  '\\/path',
  '[\\n\\r\\t]',
  '[\\t-\\r]',
  '[!-~]',
  '[{}()|.*+?$^]',
  '\\[\\]\\{\\}\\(\\)\\|\\^\\$\\.\\?\\*\\+\\\\',
    '\\D\\W[\\d\\w]',
  'ñ+ü',
  '^a$b^',
]

const REJECTED = [
  // \s is whitespace by a different list in each (U+0085 only in PHP, U+FEFF only in ECMAScript).
  '\\s', '\\S', '[\\s]',
  // Word boundaries, by a \w that is Unicode in PHP and ASCII in ECMAScript.
  '\\b', '\\B', 'a\\bb',
  // PCRE's own escapes.
  '\\A', '\\z', '\\Z', '\\G', '\\K', '\\Qa\\E', '\\h', '\\v', '\\R', '\\X', '\\e', '\\0',
  // Properties and character codes.
  '\\p{L}', '\\P{L}', '[\\p{L}]', '\\x41', '\\x{41}', '\\u0041', '\\u{41}', '\\cA',
  // Backreferences.
  '(a)\\1', '(?<n>a)\\k<n>',
  // `\-` outside a class, which ECMAScript rejects.
  'a\\-b',
  // A lone `]`, `{` or `}`, which PCRE takes as a literal.
  'a]', 'a{', 'a}', '{2}', 'a{,3}', 'a{x}', 'a{1,2', 'a{1, 2}', 'a{3,2}', 'a{65536}', 'a{99999999999999999999}',
  // Groups other than capturing, non-capturing and lookahead.
  '(?<=a)b', '(?<!a)b', '(?<n>a)', '(?P<n>a)', '(?i)a', '(?i:a)', '(?>a)', '(?(1)a|b)', '(?#c)', '(?|a)', '(?',
  // Unbalanced parentheses.
  '(a', 'a)', '((a)', '(a))',
  // Quantifiers: possessive, repeated, with nothing to repeat or over an assertion.
  'a++', 'a*+', 'a?+', 'a{2}+', 'a**', 'a{2}{3}', '*a', 'a|*b', '(+a)', '^*', '$?', '(?=a)*', '(?!a){2}',
  // Classes.
  '[]', '[^]', '[]a]', '[a', '[[:alpha:]]', '[a[]', '[\\b]', '[\\B]', '[\\D]', '[^\\W]', '[a-\\d]', '[\\d-z]', '[\\w-a]', '[z-a]',
  '[a-z-0]', '[a-c-e]', '[a--]', '[\\x41]', '[\\q]',
  // A trailing backslash and control characters.
  'a\\', 'a\x01b', 'a\0b', 'a\nb', '[\t]', 'a\x7f',
]

describe('patternSource', () => {
  it.each(ACCEPTED)('accepts %j, a valid rule that compiles', (pattern) => {
    const source = patternSource(pattern)
    expect(source).not.toBeNull()
    expect(() => new RegExp(source as string, 'u')).not.toThrow()
    expect(validRules('string', { pattern })).toBe(true)
  })

  it.each(REJECTED)('rejects %j: no valid rule, and no value matches it', (pattern) => {
    expect(patternSource(pattern)).toBeNull()
    expect(validRules('string', { pattern })).toBe(false)
    expect(matchesPattern(pattern, 'a')).toBe(false)
  })

  it('rejects a lone surrogate, which no UTF-8 string has', () => {
    expect(patternSource('a\ud800b')).toBeNull()
    expect(patternSource('a\u{1F600}b')).toBe('a\u{1F600}b')
  })
})

describe('matchesPattern', () => {
  it('matches `$` only at the very end', () => {
    expect(matchesPattern('^\\d{6,12}$', '123456')).toBe(true)
    expect(matchesPattern('^\\d{6,12}$', '123456\n')).toBe(false)
    expect(matchesPattern('^\\d{6,12}$', '123456\n7')).toBe(false)
    expect(valueError('string', '123456\n', null, { pattern: '^\\d{6,12}$' })).toBe('invalid')
    expect(valueError('string', '123456', null, { pattern: '^\\d{6,12}$' })).toBeNull()
  })

  it('matches `.` as ECMAScript does, which is how PHP reads it too', () => {
    expect(patternSource('a.b')).toBe('a[^\\n\\r  ]b')
    expect(patternSource('a[.]b\\.')).toBe('a[.]b\\.')
    expect(matchesPattern('^.$', 'a')).toBe(true)
    expect(matchesPattern('^.$', 'ñ')).toBe(true)
    expect(matchesPattern('^.$', '\t')).toBe(true)
    for (const lineBreak of ['\n', '\r', ' ', ' ']) {
      expect(matchesPattern('^.$', lineBreak)).toBe(false)
    }
  })

  it('matches classes and quantifiers as written', () => {
    expect(matchesPattern('^[^-]+$', 'abc')).toBe(true)
    expect(matchesPattern('^[^-]+$', 'a-c')).toBe(false)
    expect(matchesPattern('^[\\--/]$', '.')).toBe(true)
    expect(matchesPattern('^a{2,}?$', 'aaa')).toBe(true)
    expect(matchesPattern('^a{2}$', 'aaa')).toBe(false)
    expect(matchesPattern('^[a-zA-Z][\\w]*(\\.[a-zA-Z][\\w]*)+$', 'com.ordering.app')).toBe(true)
    expect(matchesPattern('^[a-zA-Z][\\w]*(\\.[a-zA-Z][\\w]*)+$', 'ordering')).toBe(false)
  })

  it('matches `\\d` and `\\w` as ASCII, which is how PHP reads them too', () => {
    expect(patternSource('\\d\\D\\w\\W')).toBe('[0-9][^0-9][A-Za-z0-9_][^A-Za-z0-9_]')
    expect(patternSource('[\\d][^\\w-]')).toBe('[0-9][^A-Za-z0-9_-]')
    expect(matchesPattern('^\\w$', 'ñ')).toBe(false)
    expect(matchesPattern('^\\W$', 'ñ')).toBe(true)
    expect(matchesPattern('^\\d$', '٣')).toBe(false)
    expect(matchesPattern('^[\\d]$', '٣')).toBe(false)
    expect(matchesPattern('^\\d{6,12}$', '٣٣٣٣٣٣')).toBe(false)
  })
})
