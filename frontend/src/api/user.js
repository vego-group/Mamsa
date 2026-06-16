import http from './http'

/**
 * Regular user (renter) account endpoints.
 */
export const userApi = {
  getProfile: () => http.get('/user/profile'),
  updateProfile: (payload) => http.put('/user/profile', payload),
  bookings: () => http.get('/user/bookings'),
  cancelBooking: (id) => http.post(`/bookings/${id}/cancel`),
}
