import axios from 'axios'

// API base URL.
// - Dev: leave VITE_API_BASE_URL unset → '/api/v1' is proxied by Vite to the
//   local backend (see vite.config.js).
// - Prod: set VITE_API_BASE_URL to the deployed API, e.g.
//   https://api.mamsa.com/api/v1
export const API_BASE = import.meta.env.VITE_API_BASE_URL || '/api/v1'

const http = axios.create({
  baseURL: API_BASE,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
})

http.interceptors.request.use((config) => {
  const token = localStorage.getItem('access_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

let isRefreshing = false
let failedQueue = []

const processQueue = (error, token = null) => {
  failedQueue.forEach((p) => (error ? p.reject(error) : p.resolve(token)))
  failedQueue = []
}

http.interceptors.response.use(
  (res) => res,
  async (err) => {
    const original = err.config

    if (err.response?.status === 401 && !original._retry) {
      if (isRefreshing) {
        return new Promise((resolve, reject) => {
          failedQueue.push({
            resolve: (token) => {
              original.headers.Authorization = `Bearer ${token}`
              resolve(http(original))
            },
            reject,
          })
        })
      }

      original._retry = true
      isRefreshing = true

      const refreshToken = localStorage.getItem('refresh_token')
      if (!refreshToken) {
        isRefreshing = false
        clearAuth()
        return Promise.reject(err)
      }

      try {
        const { data } = await axios.post(`${API_BASE}/auth/refresh`, {
          refresh_token: refreshToken,
        })
        const newAccess = data.data.access_token
        const newRefresh = data.data.refresh_token
        localStorage.setItem('access_token', newAccess)
        localStorage.setItem('refresh_token', newRefresh)
        http.defaults.headers.common.Authorization = `Bearer ${newAccess}`
        processQueue(null, newAccess)
        original.headers.Authorization = `Bearer ${newAccess}`
        return http(original)
      } catch (refreshErr) {
        processQueue(refreshErr, null)
        clearAuth()
        return Promise.reject(refreshErr)
      } finally {
        isRefreshing = false
      }
    }

    return Promise.reject(err)
  },
)

function clearAuth() {
  localStorage.removeItem('access_token')
  localStorage.removeItem('refresh_token')
  window.location.href = '/login'
}

export default http
