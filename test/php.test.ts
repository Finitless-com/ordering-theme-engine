import { describe, expect, it } from 'vitest'
import { clone, identical, isList, phpRound } from '../src/php.js'
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
