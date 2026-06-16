import http from './http'

/**
 * Public (guest-accessible) catalogue endpoints + authenticated booking.
 * Browsing requires no token; booking does (enforced server-side).
 */
export const publicApi = {
  listUnits: (params = {}) => http.get('/units', { params }),
  getUnit: (id) => http.get(`/units/${id}`),
  checkAvailability: (id, start_date, end_date) =>
    http.post(`/units/${id}/availability`, { start_date, end_date }),
}

export const bookingApi = {
  create: (payload) => http.post('/bookings', payload),
  get: (id) => http.get(`/bookings/${id}`),
}

export const paymentApi = {
  initiate: (booking_id, payment_method) =>
    http.post('/payments/initiate', { booking_id, payment_method }),
  pay: (payload) => http.post('/payments/pay', payload),
  // Verify a payment completed via the Moyasar hosted form.
  verify: (payment_id, moyasar_id) =>
    http.post('/payments/verify', { payment_id, moyasar_id }),
}
