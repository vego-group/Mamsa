import http from './http'

/**
 * Partner (Individual / Company) API.
 * Backend response shapes are normalised at the call site in the views
 * (some endpoints wrap in { data }, paginated ones add { meta }).
 */
export const partnerApi = {
  // Dashboard KPIs — raw object: { units, bookings, revenue }
  dashboard: () => http.get('/partner/dashboard'),

  // Units
  listUnits: (page = 1) => http.get('/partner/units', { params: { page } }),
  getUnit: (id) => http.get(`/partner/units/${id}`),
  createUnit: (payload) => http.post('/partner/units', payload),
  updateUnit: (id, payload) => http.put(`/partner/units/${id}`, payload),
  deleteUnit: (id) => http.delete(`/partner/units/${id}`),
  submitUnit: (id) => http.post(`/partner/units/${id}/submit`),

  // Bookings
  listBookings: (page = 1) => http.get('/partner/bookings', { params: { page } }),

  // Profile
  getProfile: () => http.get('/partner/profile'),
  updateProfile: (payload) => http.put('/partner/profile', payload),
}
