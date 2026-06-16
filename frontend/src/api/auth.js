import http from './http'

export const authApi = {
  // Back-office (Admin / SuperAdmin) email + password login
  adminLogin: (email, password, device = 'admin-web') =>
    http.post('/auth/admin/login', { email, password, device }),

  requestOtp: (phone) =>
    http.post('/auth/request-otp', { phone }),

  verifyOtp: (phone, code, device = 'web') =>
    http.post('/auth/verify-otp', { phone, code, device }),

  resendOtp: (phone) =>
    http.post('/auth/resend-otp', { phone }),

  refresh: (refresh_token) =>
    http.post('/auth/refresh', { refresh_token }),

  me: () =>
    http.get('/auth/me'),

  logout: () =>
    http.post('/auth/logout'),

  completeProfile: (data) =>
    http.post('/auth/complete-profile', data),

  // Self-service partner onboarding (OTP-verified)
  partnerRegister: (payload) =>
    http.post('/auth/partner/register', payload),
}
