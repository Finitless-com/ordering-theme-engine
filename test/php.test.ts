import { describe, expect, it } from 'vitest'
import { clone, identical, isList, phpRound, phpString, versionCompare } from '../src/php.js'
import { json } from './fixtures.js'

describe('phpRound', () => {
  const { php, rounds } = json<{ php: string; rounds: Array<[number, number, number]> }>('php-round.json')

  it(`rounds as api-core's PHP (${php}) rounds`, () => {
    const wrong = rounds.filter(([value, places0, places2]) => phpRound(value) !== places0 || phpRound(value, 2) !== places2)
    expect(wrong).toEqual([])
  })

  it('differs from Math.round where PHP pre-rounds or rounds half away from zero', () => {
    expect(phpRound(127.49999999999997)).toBe(128)
    expect(Math.round(127.49999999999997)).toBe(127)
    expect(phpRound(-2.5)).toBe(-3)
    expect(phpRound(1.005, 2)).toBe(1.01)
  })
})

describe('PHP arrays', () => {
  it('reads [] and {} as the same empty list', () => {
    expect(isList([])).toBe(true)
    expect(isList({})).toBe(true)
    expect(isList({ a: 1 })).toBe(false)
    expect(clone([])).toEqual({})
  })

  it('compares arrays as PHP ===: same pairs, same order', () => {
    expect(identical({ a: 1, b: 2 }, { a: 1, b: 2 })).toBe(true)
    expect(identical({ a: 1, b: 2 }, { b: 2, a: 1 })).toBe(false)
    expect(identical('#FFF', '#fff')).toBe(false)
  })
})

describe('version_compare() and (string)', () => {
  const { php, version_compare: pairs, strings } = json<{
    php: string
    version_compare: Array<[string, string, number]>
    strings: Array<[number, string]>
  }>('php-strings.json')

  it(`compares versions as api-core's PHP (${php}) does`, () => {
    const wrong = pairs.filter(([a, b, answer]) => versionCompare(a, b) !== answer)
    expect(wrong).toEqual([])
  })

  // JSON.parse() reads `1.0e+14` and `-0.0` as the integers 1e14 and 0:
  // JavaScript cannot tell an integer-valued float from an int, so those
  // read as PHP's ints (README, "Donde PHP y JavaScript difieren").
  it('casts floats to strings as PHP does', () => {
    const floats = strings.filter(([value]) => !Number.isInteger(value))
    const wrong = floats.filter(([value, answer]) => phpString(value) !== answer)
    expect(floats.length).toBeGreaterThan(10)
    expect(wrong).toEqual([])
  })

  it('casts an integer-valued number as PHP casts an int', () => {
    expect(phpString(100000000000000)).toBe('100000000000000')
    expect(phpString(3)).toBe('3')
    expect(phpString(1e20)).toBe('1.0E+20')
  })
})
