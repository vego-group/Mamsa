import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/api/auth', () => ({
  authApi: {
    verifyOtp: vi.fn(),
    adminLogin: vi.fn(),
    partnerRegister: vi.fn(),
    me: vi.fn(),
    logout: vi.fn(),
  },
}))

import { authApi } from '@/api/auth'
import { useAuthStore } from '@/stores/auth'

beforeEach(() => {
  localStorage.clear()
  setActivePinia(createPinia())
})

describe('auth store — session lifecycle', () => {
  it('starts unauthenticated with a clean localStorage', () => {
    const store = useAuthStore()
    expect(store.isAuthenticated).toBe(false)
    expect(store.user).toBeNull()
  })

  it('verify() persists tokens + user and reads needs_profile', async () => {
    authApi.verifyOtp.mockResolvedValue({
      data: {
        data: {
          access_token: 'A',
          refresh_token: 'R',
          user: { id: 1, name: 'نورة', roles: ['User'] },
          needs_profile: true,
        },
      },
    })

    const store = useAuthStore()
    await store.verify('0500000004', '1234')

    expect(store.accessToken).toBe('A')
    expect(store.refreshToken).toBe('R')
    expect(store.isAuthenticated).toBe(true)
    expect(store.needsProfile).toBe(true)
    expect(localStorage.getItem('access_token')).toBe('A')
    expect(JSON.parse(localStorage.getItem('user')).id).toBe(1)
  })

  it('adminLogin() authenticates a back-office user', async () => {
    authApi.adminLogin.mockResolvedValue({
      data: { data: { access_token: 'A', refresh_token: 'R', user: { id: 2, roles: ['Admin'] } } },
    })

    const store = useAuthStore()
    await store.adminLogin('admin@mamsaa.sa', 'Password1')

    expect(store.isAuthenticated).toBe(true)
    expect(store.isAdmin).toBe(true)
  })

  it('logout() clears tokens, user and storage even if the API call fails', async () => {
    authApi.logout.mockRejectedValue(new Error('network'))
    const store = useAuthStore()
    store.setTokens('A', 'R')
    store.setUser({ id: 1, roles: ['User'] })

    await store.logout()

    expect(store.accessToken).toBeNull()
    expect(store.user).toBeNull()
    expect(localStorage.getItem('access_token')).toBeNull()
    expect(localStorage.getItem('user')).toBeNull()
  })
})

describe('auth store — role gates', () => {
  it('classifies admin, partner and regular users correctly', () => {
    const store = useAuthStore()

    store.setUser({ roles: ['SuperAdmin'] })
    expect(store.isAdmin).toBe(true)
    expect(store.isPartner).toBe(false)

    store.setUser({ roles: ['Company'] })
    expect(store.isPartner).toBe(true)
    expect(store.isAdmin).toBe(false)

    store.setUser({ roles: ['User'] })
    expect(store.isAdmin).toBe(false)
    expect(store.isPartner).toBe(false)
  })

  it('homeRoute() routes each role to its landing page', () => {
    const store = useAuthStore()
    expect(store.homeRoute({ roles: ['Admin'] })).toEqual({ name: 'admin-dashboard' })
    expect(store.homeRoute({ roles: ['Individual'] })).toEqual({ name: 'partner-dashboard' })
    expect(store.homeRoute({ roles: ['User'] })).toEqual({ name: 'account' })
    expect(store.homeRoute({ roles: [] })).toEqual({ name: 'account' })
  })
})
