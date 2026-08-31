import { describe, it, expect, beforeEach, vi } from 'vitest'

// One shared mock http so every module-under-test records against the same spies.
vi.mock('./http', () => {
  const ok = () => Promise.resolve({ data: {} })
  return { default: { get: vi.fn(ok), post: vi.fn(ok), put: vi.fn(ok), delete: vi.fn(ok) } }
})

import http from './http'
import { publicApi, bookingApi, paymentApi } from './public'
import { userApi } from './user'
import { authApi } from './auth'
import { partnerApi } from './partner'

beforeEach(() => {
  http.get.mockClear()
  http.post.mockClear()
  http.put.mockClear()
  http.delete.mockClear()
})

describe('publicApi — catalogue endpoints', () => {
  it('lists units with query params', () => {
    publicApi.listUnits({ q: 'الرياض', min_price: 500 })
    expect(http.get).toHaveBeenCalledWith('/units', { params: { q: 'الرياض', min_price: 500 } })
  })

  it('maps the remaining catalogue getters to their routes', () => {
    publicApi.popularUnits({ limit: 8 })
    expect(http.get).toHaveBeenCalledWith('/units/popular', { params: { limit: 8 } })

    publicApi.categories()
    expect(http.get).toHaveBeenCalledWith('/units/categories')

    publicApi.cities()
    expect(http.get).toHaveBeenCalledWith('/units/cities')

    publicApi.budgets()
    expect(http.get).toHaveBeenCalledWith('/units/budgets')

    publicApi.offers()
    expect(http.get).toHaveBeenCalledWith('/offers')

    publicApi.testimonials()
    expect(http.get).toHaveBeenCalledWith('/testimonials')

    publicApi.getUnit(42)
    expect(http.get).toHaveBeenCalledWith('/units/42')
  })

  it('checks availability with a date range', () => {
    publicApi.checkAvailability(42, '2026-07-10', '2026-07-12')
    expect(http.post).toHaveBeenCalledWith('/units/42/availability', {
      start_date: '2026-07-10',
      end_date: '2026-07-12',
    })
  })
})

describe('bookingApi + paymentApi', () => {
  it('creates and fetches bookings', () => {
    const payload = { unit_id: 1, start_date: '2026-07-10', end_date: '2026-07-12', guests: 2 }
    bookingApi.create(payload)
    expect(http.post).toHaveBeenCalledWith('/bookings', payload)

    bookingApi.get(7)
    expect(http.get).toHaveBeenCalledWith('/bookings/7')
  })

  it('drives the payment flow', () => {
    paymentApi.initiate(7, 'creditcard')
    expect(http.post).toHaveBeenCalledWith('/payments/initiate', {
      booking_id: 7,
      payment_method: 'creditcard',
    })

    paymentApi.verify('p1', 'moy1')
    expect(http.post).toHaveBeenCalledWith('/payments/verify', {
      payment_id: 'p1',
      moyasar_id: 'moy1',
    })
  })
})

describe('userApi — account + bookings', () => {
  it('maps profile, bookings, cancel and review', () => {
    userApi.getProfile()
    expect(http.get).toHaveBeenCalledWith('/user/profile')

    userApi.updateProfile({ name: 'نورة' })
    expect(http.put).toHaveBeenCalledWith('/user/profile', { name: 'نورة' })

    userApi.bookings()
    expect(http.get).toHaveBeenCalledWith('/user/bookings')

    userApi.cancelBooking(7, 'تغيير الخطة')
    expect(http.post).toHaveBeenCalledWith('/bookings/7/cancel', { reason: 'تغيير الخطة' })

    userApi.cancelBooking(7)
    expect(http.post).toHaveBeenCalledWith('/bookings/7/cancel', { reason: null })

    const review = { unit_id: 1, booking_id: 7, rating: 5, comment: 'ممتاز' }
    userApi.submitReview(review)
    expect(http.post).toHaveBeenCalledWith('/reviews', review)
  })
})

describe('authApi — auth endpoints', () => {
  it('sends OTP verify with the default web device', () => {
    authApi.verifyOtp('0500000004', '1234')
    expect(http.post).toHaveBeenCalledWith('/auth/verify-otp', {
      phone: '0500000004',
      code: '1234',
      device: 'web',
    })
  })

  it('maps admin login and refresh', () => {
    authApi.adminLogin('admin@mamsaa.sa', 'Password1')
    expect(http.post).toHaveBeenCalledWith('/auth/admin/login', {
      email: 'admin@mamsaa.sa',
      password: 'Password1',
      device: 'admin-web',
    })

    authApi.refresh('r1')
    expect(http.post).toHaveBeenCalledWith('/auth/refresh', { refresh_token: 'r1' })
  })
})

describe('partnerApi — unit gallery', () => {
  it('uploads images as multipart with the JSON content-type disabled', () => {
    const files = [new File(['x'], 'a.png', { type: 'image/png' })]
    partnerApi.uploadUnitImages(5, files)

    const [url, body, config] = http.post.mock.calls.at(-1)
    expect(url).toBe('/partner/units/5/images')
    expect(body).toBeInstanceOf(FormData)
    expect(body.getAll('images[]')).toHaveLength(1)
    // Unset so the browser adds the multipart boundary itself.
    expect(config.headers['Content-Type']).toBeUndefined()
  })

  it('maps delete + set-main to their routes', () => {
    partnerApi.deleteUnitImage(5, 9)
    expect(http.delete).toHaveBeenCalledWith('/partner/units/5/images/9')

    partnerApi.setMainImage(5, 9)
    expect(http.post).toHaveBeenCalledWith('/partner/units/5/images/9/main')
  })
})

describe('endpoints that existed on the backend but were never called', () => {
  it('sets the apartment count on a unit', () => {
    partnerApi.setApartmentCount(42, 5)
    expect(http.post).toHaveBeenCalledWith('/partner/units/42/apartments', { count: 5 })
  })

  it('reads a unit\'s blocked nights and public reviews', () => {
    publicApi.blockedDates(42, { from: '2026-09-01', to: '2026-10-01' })
    expect(http.get).toHaveBeenCalledWith('/units/42/blocked-dates', {
      params: { from: '2026-09-01', to: '2026-10-01' },
    })

    publicApi.unitReviews(42)
    expect(http.get).toHaveBeenCalledWith('/units/42/reviews')
  })

  it('fetches the ZATCA tax invoice for a booking', () => {
    bookingApi.invoice(7)
    expect(http.get).toHaveBeenCalledWith('/bookings/7/invoice')
  })

  it('polls a payment record by id', () => {
    paymentApi.get('pay_123')
    expect(http.get).toHaveBeenCalledWith('/payments/pay_123')
  })

  it('changes the phone in two steps, sending the new number BOTH times', () => {
    userApi.changePhone('0512345678')
    expect(http.post).toHaveBeenCalledWith('/user/change-phone', { new_phone: '0512345678' })

    // The server does not carry the number between the two calls, so verify
    // must send it again alongside the code. Sending only the code fails.
    userApi.verifyChangePhone('0512345678', '111111')
    expect(http.post).toHaveBeenCalledWith('/user/change-phone/verify', {
      new_phone: '0512345678',
      code: '111111',
    })
  })

  it('deletes the account', () => {
    userApi.deleteAccount()
    expect(http.delete).toHaveBeenCalledWith('/user/account')
  })
})

describe('unit documents — licence and ownership proof', () => {
  it('uploads a document as multipart with the type in the body', () => {
    const file = new File(['x'], 'deed.pdf', { type: 'application/pdf' })
    partnerApi.uploadUnitDocument(9, 'ownership_doc', file)

    const [url, body, opts] = http.post.mock.calls.at(-1)
    expect(url).toBe('/partner/units/9/documents')
    expect(body).toBeInstanceOf(FormData)
    expect(body.get('type')).toBe('ownership_doc')
    expect(body.get('file')).toBe(file)
    // Unset, not set: the browser must add its own multipart boundary, and the
    // client's default JSON header would break the upload.
    expect(opts.headers['Content-Type']).toBeUndefined()
  })

  it('deletes a document by type', () => {
    partnerApi.deleteUnitDocument(9, 'tourism_permit')
    expect(http.delete).toHaveBeenCalledWith('/partner/units/9/documents/tourism_permit')
  })
})
