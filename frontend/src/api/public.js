import http from './http'

/**
 * Public (guest-accessible) catalogue endpoints + authenticated booking.
 * Browsing requires no token; booking does (enforced server-side).
 */
export const publicApi = {
  listUnits: (params = {}) => http.get('/units', { params }),
  popularUnits: (params = {}) => http.get('/units/popular', { params }),
  categories: () => http.get('/units/categories'),
  cities: () => http.get('/units/cities'),
  budgets: () => http.get('/units/budgets'),
  offers: () => http.get('/offers'),
  testimonials: () => http.get('/testimonials'),
  getUnit: (id) => http.get(`/units/${id}`),
  checkAvailability: (id, start_date, end_date) =>
    http.post(`/units/${id}/availability`, { start_date, end_date }),
  // Public contact form — throttled 5/min server-side.
  // Nights already taken, for the guest date picker. `blocked` is a list of
  // { start, end } where BOTH ends are INCLUSIVE NIGHTS — `end` is the last
  // blocked night, not the checkout day, so a changeover day stays bookable.
  blockedDates: (id, params = {}) => http.get(`/units/${id}/blocked-dates`, { params }),
  // Public reviews for a unit. The detail page previously showed only the
  // aggregate, so a unit with 43 reviews rendered a number and nothing to read.
  unitReviews: (id) => http.get(`/units/${id}/reviews`),
  contact: (payload) => http.post('/contact', payload),
}

export const bookingApi = {
  create: (payload) => http.post('/bookings', payload),
  get: (id) => http.get(`/bookings/${id}`),
  // The review already left for this stay, if any — so the UI can show the
  // existing rating instead of offering to leave a second one.
  review: (id) => http.get(`/bookings/${id}/review`),
  // ZATCA tax invoice. Available only after payment completes; the API answers
  // 403 before that, which is a state to render, not an error to swallow.
  invoice: (id) => http.get(`/bookings/${id}/invoice`),
}

export const paymentApi = {
  // Gateway flags (publishable_key, test_mode) for pages that tokenise cards
  // outside checkout, e.g. the wallet's add-card form.
  config: () => http.get('/payments/config'),
  initiate: (booking_id, payment_method) =>
    http.post('/payments/initiate', { booking_id, payment_method }),
  pay: (payload) => http.post('/payments/pay', payload),
  // Verify a payment completed via the Moyasar hosted form.
  verify: (payment_id, moyasar_id) =>
    http.post('/payments/verify', { payment_id, moyasar_id }),
  // Poll a payment's own record — used when returning from the hosted form
  // without a usable callback payload.
  get: (id) => http.get(`/payments/${id}`),
}
