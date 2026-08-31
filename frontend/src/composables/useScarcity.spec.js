import { describe, it, expect } from 'vitest'
import { useScarcity, SCARCE_AT } from './useScarcity'

const building = (count) => ({ listing_id: '01ABCDEF', available_count: count })
const standalone = (id = 7) => ({ listing_id: `u${id}`, id, available_count: 1 })

describe('useScarcity', () => {
  it('says nothing about a standalone listing', () => {
    // Every ordinary unit reports 1. A badge here would appear on the entire
    // catalogue and mean nothing.
    const { scarce } = useScarcity(() => standalone())
    expect(scarce.value).toBe(false)
  })

  it('stays silent while a building has plenty left', () => {
    const { scarce } = useScarcity(() => building(SCARCE_AT + 1))
    expect(scarce.value).toBe(false)
  })

  it('speaks up at the threshold', () => {
    const { scarce, scarceLabel } = useScarcity(() => building(SCARCE_AT))
    expect(scarce.value).toBe(true)
    expect(scarceLabel.value).toContain(String(SCARCE_AT))
  })

  it('uses the singular for the last one', () => {
    const { scarce, scarceLabel } = useScarcity(() => building(1))
    expect(scarce.value).toBe(true)
    expect(scarceLabel.value).toBe('وحدة واحدة متبقية')
  })

  it('says nothing when none are left', () => {
    // Zero is not scarcity, it is absence — and such a card should not have
    // been rendered for those dates at all.
    const { scarce } = useScarcity(() => building(0))
    expect(scarce.value).toBe(false)
  })

  it('tolerates a unit from an API that predates the field', () => {
    const { scarce } = useScarcity(() => ({ id: 3 }))
    expect(scarce.value).toBe(false)
  })
})
