import http from './http'

/**
 * Regular user (renter) account endpoints.
 */
export const userApi = {
  getProfile: () => http.get('/user/profile'),
  updateProfile: (payload) => http.put('/user/profile', payload),
  bookings: () => http.get('/user/bookings'),
  getBooking: (id) => http.get(`/bookings/${id}`),
  cancelBooking: (id, reason = null) => http.post(`/bookings/${id}/cancel`, { reason }),
  submitReview: (payload) => http.post('/reviews', payload),
}
