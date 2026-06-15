import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authApi } from '@/api/auth'

export const useAuthStore = defineStore('auth', () => {
  const user         = ref(JSON.parse(localStorage.getItem('user') || 'null'))
  const accessToken  = ref(localStorage.getItem('access_token') || null)
  const refreshToken = ref(localStorage.getItem('refresh_token') || null)
  const needsProfile = ref(false)

  const isAuthenticated = computed(() => !!accessToken.value)
  const isAdmin = computed(() =>
    user.value?.roles?.some((r) => r === 'Admin' || r === 'SuperAdmin'),
  )

  const isPartner = computed(() =>
    user.value?.roles?.some((r) => r === 'Individual' || r === 'Company'),
  )

  function setTokens(access, refresh) {
    accessToken.value  = access
    refreshToken.value = refresh
    localStorage.setItem('access_token', access)
    localStorage.setItem('refresh_token', refresh)
  }

  function setUser(u) {
    user.value = u
    localStorage.setItem('user', JSON.stringify(u))
  }

  async function verify(phone, code) {
    const { data } = await authApi.verifyOtp(phone, code)
    setTokens(data.data.access_token, data.data.refresh_token)
    setUser(data.data.user)
    needsProfile.value = data.data.needs_profile ?? false
    return data.data
  }

  async function fetchMe() {
    const { data } = await authApi.me()
    setUser(data.data)
    return data.data
  }

  async function logout() {
    try { await authApi.logout() } catch {}
    accessToken.value  = null
    refreshToken.value = null
    user.value         = null
    localStorage.removeItem('access_token')
    localStorage.removeItem('refresh_token')
    localStorage.removeItem('user')
  }

  return {
    user, accessToken, refreshToken, needsProfile,
    isAuthenticated, isAdmin, isPartner,
    setTokens, setUser, verify, fetchMe, logout,
  }
})
