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

  // Unit gallery (multipart). Content-Type is unset so the browser adds the
  // multipart boundary; the default JSON header would break the upload.
  uploadUnitImages: (id, files) => {
    const fd = new FormData()
    for (const f of files) fd.append('images[]', f)
    return http.post(`/partner/units/${id}/images`, fd, { headers: { 'Content-Type': undefined } })
  },
  deleteUnitImage: (id, imageId) => http.delete(`/partner/units/${id}/images/${imageId}`),
  setMainImage: (id, imageId) => http.post(`/partner/units/${id}/images/${imageId}/main`),

  // Availability calendar (anti double-booking): manual closures + iCal sync.
  getCalendar: (id) => http.get(`/partner/units/${id}/calendar`),
  saveCalendarSettings: (id, ical_import_url) => http.put(`/partner/units/${id}/calendar`, { ical_import_url }),
  addBlockedDates: (id, payload) => http.post(`/partner/units/${id}/blocked-dates`, payload),
  removeBlockedDates: (id, blockId) => http.delete(`/partner/units/${id}/blocked-dates/${blockId}`),

  // Bookings
  listBookings: (page = 1) => http.get('/partner/bookings', { params: { page } }),

  // Profile
  getProfile: () => http.get('/partner/profile'),
  updateProfile: (payload) => http.put('/partner/profile', payload),
}
