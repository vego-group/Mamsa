import http from './http'

/**
 * Regular user (renter) account endpoints.
 */
export const userApi = {
  getProfile: () => http.get('/user/profile'),
  updateProfile: (payload) => http.put('/user/profile', payload),
  bookings: () => http.get('/user/bookings'),
  getBooking: (id) => http.get(`/bookings/${id}`),
  // FR-036: exact refund quote NOW — render in the confirm dialog, never
  // compute percentages client-side.
  cancellationPreview: (id) => http.get(`/bookings/${id}/cancellation-preview`),
  cancelBooking: (id, reason = null) => http.post(`/bookings/${id}/cancel`, { reason }),
  // Verified email channel — machine-coded errors (EMAIL_*/OTP_*/RATE_LIMITED).
  addEmail: (email) => http.post('/user/email', { email }),
  verifyEmailOtp: (code) => http.post('/user/email/verify', { code }),
  resendEmailOtp: () => http.post('/user/email/resend'),
  submitReview: (payload) => http.post('/reviews', payload),
  // Saved cards — metadata only; gateway tokens never leave the backend.
  cards: () => http.get('/user/cards'),
  // Manual save: pass { token } (Moyasar token id) in live mode, or the card
  // metadata { brand, last4, exp_month, exp_year } in simulate mode (no keys).
  saveCardFromToken: (payload) => http.post('/user/cards/from-token', payload),
  deleteCard: (id) => http.delete(`/user/cards/${id}`),
  setDefaultCard: (id) => http.post(`/user/cards/${id}/default`),
  // Favorites — toggle endpoints are idempotent server-side.
  favorites: () => http.get('/user/favorites'),
  addFavorite: (unitId) => http.post(`/user/favorites/${unitId}`),
  removeFavorite: (unitId) => http.delete(`/user/favorites/${unitId}`),
  // Wallet — read-only transaction ledger.
  transactions: () => http.get('/user/transactions'),
}
