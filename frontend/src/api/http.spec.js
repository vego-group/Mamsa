import { describe, it, expect, beforeEach, vi } from 'vitest'

// Shared mock axios instance/handle — declared via vi.hoisted so the (hoisted)
// vi.mock factory can safely reference it.
const h = vi.hoisted(() => {
  const instance = vi.fn() // callable: http(original) is used to retry requests
  instance.interceptors = { request: { use: vi.fn() }, response: { use: vi.fn() } }
  instance.defaults = { headers: { common: {} } }
  return { instance, axiosPost: vi.fn() }
})

vi.mock('axios', () => ({
  default: {
    create: vi.fn(() => h.instance),
    post: (...args) => h.axiosPost(...args),
  },
}))

let reqHandler, onSuccess, onError

beforeEach(async () => {
  localStorage.clear()
  h.axiosPost.mockReset()
  h.instance.mockReset()
  h.instance.interceptors.request.use.mockReset()
  h.instance.interceptors.response.use.mockReset()

  // Re-import http.js fresh so the interceptors register against clean spies.
  vi.resetModules()
  await import('./http')

  reqHandler = h.instance.interceptors.request.use.mock.calls[0][0]
  onSuccess = h.instance.interceptors.response.use.mock.calls[0][0]
  onError = h.instance.interceptors.response.use.mock.calls[0][1]
})

describe('http request interceptor', () => {
  it('attaches the bearer token when present', () => {
    localStorage.setItem('access_token', 'abc123')
    const cfg = reqHandler({ headers: {} })
    expect(cfg.headers.Authorization).toBe('Bearer abc123')
  })

  it('sends no Authorization header when unauthenticated', () => {
    const cfg = reqHandler({ headers: {} })
    expect(cfg.headers.Authorization).toBeUndefined()
  })
})

describe('http response interceptor', () => {
  it('passes successful responses through untouched', () => {
    const res = { data: { ok: true } }
    expect(onSuccess(res)).toBe(res)
  })

  it('rejects non-401 errors without attempting a refresh', async () => {
    const err = { config: { headers: {} }, response: { status: 500 } }
    await expect(onError(err)).rejects.toBe(err)
    expect(h.axiosPost).not.toHaveBeenCalled()
  })

  it('on 401 refreshes the token and retries the original request', async () => {
    localStorage.setItem('access_token', 'old')
    localStorage.setItem('refresh_token', 'r1')
    h.axiosPost.mockResolvedValue({
      data: { data: { access_token: 'A2', refresh_token: 'R2' } },
    })
    h.instance.mockResolvedValue({ data: 'retried' })

    const err = { config: { headers: {} }, response: { status: 401 } }
    const out = await onError(err)

    expect(h.axiosPost).toHaveBeenCalledWith(
      expect.stringContaining('/auth/refresh'),
      { refresh_token: 'r1' },
    )
    expect(localStorage.getItem('access_token')).toBe('A2')
    expect(localStorage.getItem('refresh_token')).toBe('R2')
    expect(h.instance).toHaveBeenCalledTimes(1) // request retried
    expect(out).toEqual({ data: 'retried' })
  })

  it('does not retry a request that was already retried (_retry flag)', async () => {
    const err = { config: { headers: {}, _retry: true }, response: { status: 401 } }
    await expect(onError(err)).rejects.toBe(err)
    expect(h.axiosPost).not.toHaveBeenCalled()
  })

  it('clears auth and redirects to /login when no refresh token exists', async () => {
    // Stub navigation — jsdom throws on real location assignment.
    Object.defineProperty(window, 'location', { writable: true, value: { href: '' } })
    localStorage.setItem('access_token', 'stale')

    const err = { config: { headers: {} }, response: { status: 401 } }
    await expect(onError(err)).rejects.toBe(err)

    expect(localStorage.getItem('access_token')).toBeNull()
    expect(localStorage.getItem('refresh_token')).toBeNull()
    expect(window.location.href).toBe('/login')
  })

  it('clears auth when the refresh call itself fails', async () => {
    Object.defineProperty(window, 'location', { writable: true, value: { href: '' } })
    localStorage.setItem('access_token', 'old')
    localStorage.setItem('refresh_token', 'r1')
    h.axiosPost.mockRejectedValue(new Error('refresh failed'))

    const err = { config: { headers: {} }, response: { status: 401 } }
    await expect(onError(err)).rejects.toThrow('refresh failed')

    expect(localStorage.getItem('access_token')).toBeNull()
    expect(window.location.href).toBe('/login')
  })
})
