import { describe, it, expect } from 'vitest'
import router from './index.js'

/**
 * The booking confirmation email links to /my-reservations/{id}, because that
 * is the path the production storefront serves. This bench has always called
 * the same screen /bookings/{id}, so a guest arriving from a real email hit a
 * path the router does not define — a blank page, with no 404 and no clue.
 */
describe('reservation routes', () => {
  it('resolves the path the confirmation email actually links to', () => {
    const r = router.resolve('/my-reservations/83')

    expect(r.matched.length).toBeGreaterThan(0)
    expect(r.name).toBe('booking-detail')
    expect(r.params.id).toBe('83')
  })

  it('keeps the original path working', () => {
    const r = router.resolve('/bookings/83')

    expect(r.name).toBe('booking-detail')
    expect(r.params.id).toBe('83')
  })

  // Both are the same screen, so the auth guard must cover both. An alias that
  // dropped the meta would be an unauthenticated hole into someone's booking.
  it('guards both paths behind auth', () => {
    for (const p of ['/bookings/83', '/my-reservations/83']) {
      expect(router.resolve(p).meta.requiresAuth).toBe(true)
    }
  })
})
