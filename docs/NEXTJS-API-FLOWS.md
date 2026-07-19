# Mamsa — Backend API Flow Map (master doc for the Next.js teams)

Every backend flow, end to end, with the endpoint chains that implement it. Generated from the live
route files on 2026-07-16. Use this to understand **how endpoints connect**; exact payloads live in
the specialized docs (linked per section) and in the Postman collection
(`backend/postman/Mamsa-API.postman_collection.json`), which covers 100% of these routes.

---

## 0. The three API surfaces — never mix them

| Surface | Base URL (prod) | Auth | Consumed by |
|---|---|---|---|
| **User-site API** | `https://api.mamsaa.com/api/v1` | **Bearer token** (Sanctum) | `www.mamsaa.com` (visitors, users, registration) |
| **Partner-dashboard API** | `https://api.mamsaa.com` (**root — no `/api/v1`**) | **httpOnly session cookie** | `partner.mamsaa.com` |
| **Admin API** | `https://api.mamsaa.com/api/v1/admin` | Bearer token (email+password login) | admin panel |

Staging equivalent for all three: `https://staging.mamsaa.com` (fixed OTP `111222`, `debug_otp`
echoed, Moyasar test keys). CORS: your origins are allowlisted per environment — `localhost:3000–3002`,
`5173/5174`, `mamsa-*.vercel.app` (pattern) on staging; the real domains on prod. Vercel **preview**
deploys must target staging (`SameSite` rules — `NEXTJS-DASHBOARD-PRODUCTION.md` §3).

**Envelopes differ by surface:**

- User-site + Admin: `{ success, message, data }`; validation failures are Laravel 422
  `{ message, errors: { field: [...] } }`.
- Dashboard: errors `{ error: { code, message, fields? } }`; lists `{ data, meta: { page, limit, total } }`.
  Branch on machine codes (`error.code`, or `code` on the user-site intent guard) — never on Arabic text.

---

## 1. Visitor flow (no auth) — browse & discover

```
GET /units                    ← search/filter listing (city, type, price, dates, rating…)
GET /units/popular            ← home-page rail
GET /units/categories | /cities | /budgets   ← filter facets
GET /units/{id}               ← detail; includes cancellation_policy_details {name, tiers[]} (FR-021)
GET /units/{id}/reviews
POST /units/{id}/availability ← { start_date, end_date } → { available, pricing }
                                 pricing = { nights, nightly_rate, subtotal, taxes,
                                 tax_percent, total } — subtotal + 15% VAT ONLY (fees
                                 abolished 2026-07-18); render verbatim, never compute in JS
GET /offers                   ← active offers
GET /testimonials
POST /contact                 ← public contact form (throttled 5/min)
```

Show the tiered cancellation policy from `cancellation_policy_details` — **ignore** the legacy
`cancellation_policy` enum string (stale; slated for removal).

## 2. User auth flow (passwordless OTP → Bearer pair)

Details: `NEXTJS-REGISTRATION.md`.

```
POST /auth/request-otp   { phone, intent: "login"|"register"|omit }
   ├─ intent guard fails fast: 422 code=PHONE_NOT_REGISTERED / PHONE_ALREADY_REGISTERED
POST /auth/verify-otp    { phone, code, device } → { access_token (1h), refresh_token, needs_profile, user }
   └─ needs_profile:true → POST /auth/complete-profile { name, email? }   (registration done)
POST /auth/resend-otp    (60s cooldown)
POST /auth/refresh       { refresh_token } → new pair (refresh is single-use — rotate!)
GET  /auth/me            ← roles, partner_status, email_verified (poll after anything that changes state)
POST /auth/logout
```

OTP policy everywhere: 6 digits, 5-min TTL, 3 attempts, 10 sends/phone/day.

## 3. Booking + payment flow (the money path)

```
POST /units/{id}/availability            ← re-check right before booking
POST /bookings                           ← creates PENDING booking + price freeze; response embeds
                                            the frozen `pricing` block: nightly_rate, nights,
                                            subtotal, taxes + tax_percent, total — re-render
                                            checkout from it (fee keys appear ONLY on historical
                                            fee-era bookings; treat them as optional)
GET  /payments/config                    ← publishable key, Apple Pay availability
POST /payments/initiate                  ← { booking_id, ... } → payment intent
POST /payments/pay                       ← charge (saved card / new card / Apple Pay token)
   ├─ 3-DS needed → browser goes to Moyasar → returns to:
   │    GET /payments/callback           ← BACKEND URL; verifies then 302s to your /payment/callback page
   ├─ Moyasar server webhook → POST /payments/callback (secret_token) — you never call this
POST /payments/verify                    ← { payment_id } → final status (poll from your callback page)
GET  /payments/{id}                      ← receipt/status
GET  /bookings/{id}                      ← includes policy_snapshot (frozen tiers, FR-036) once paid,
                                            + the frozen pricing block (percents show the rate applied
                                            at booking time, not the live setting) — wrapped: data.*
```

Booking lifecycle: `pending → confirmed (paid) → completed (after checkout, cron)`, or `cancelled`.
Apple Pay merchant validation: `POST /payments/apple-pay/validate-merchant`.

### Guest cancellation (refund by frozen policy)

```
GET  /bookings/{id}/cancellation-preview  ← which tier applies NOW + refund amount (show before confirm)
POST /bookings/{id}/cancel                ← executes; refund → gateway + wallet ledger entry
```

Always render refund math from `policy_snapshot` / the preview — never from the unit's current policy
(it may have changed since booking). Policies are 3 fixed presets chosen per unit by the partner —
flexible 100/75/50, moderate 100/50/25 (default), strict 75/25/0 (% by 7+/3–7/<3 days before
check-in; locked after check-in). Refunds execute as Moyasar PARTIAL refunds server-side
(`NEXTJS-CANCELLATION-PRESETS.md`).

## 4. User account area (Bearer)

```
GET/PUT  /user/profile
GET      /user/bookings                         ← list; rows include policy_snapshot + frozen pricing
                                                  (incl. tax_percent; fee keys only on fee-era rows)
POST     /user/change-phone → POST /user/change-phone/verify   (OTP on the NEW number)
POST     /user/email → /user/email/verify → /user/email/resend  ← verified email channel
                                                  (NEXTJS-EMAIL-VERIFICATION.md — machine codes
                                                  EMAIL_*/OTP_*/RATE_LIMITED; 300s OTP, 60s cooldown;
                                                  booking gate 422 EMAIL_VERIFICATION_REQUIRED on
                                                  POST /bookings — ON staging / OFF prod for now)
DELETE   /user/account
GET      /user/favorites | POST/DELETE /user/favorites/{unitId}
GET      /user/cards | POST /user/cards/from-token | DELETE /user/cards/{id} | POST /user/cards/{id}/default
GET      /user/transactions                     ← wallet ledger (refunds land here)
POST     /reviews                               ← after a completed stay
```

## 5. Partner lifecycle — the flow that spans BOTH apps

Details: `NEXTJS-REGISTRATION.md` §4, `NEXTJS-DASHBOARD-LOGIN-APPROVAL.md`.

```
 on www.mamsaa.com (user-site API, Bearer)
 ────────────────────────────────────────
 POST /auth/request-otp        { phone }            ← NO intent (allows user→partner upgrade)
 POST /auth/partner/register   { type: individual|company, name, phone, code, email,
                                 national_id | cr_number }        → 201, partner_status: "pending"
 POST /auth/email/request-otp → POST /auth/email/verify           ← FR-005 email verification
        │
        ▼  admin reviews (flow 7) …status → approved (applicant notified in-app + email)
        │
 on partner.mamsaa.com (dashboard API, cookie session)
 ─────────────────────────────────────────────────────
 POST /auth/otp/request { phone } → POST /auth/otp/verify { phone, code }
   ├─ 200 → session cookie set → dashboard
   ├─ 403 ACCOUNT_PENDING   ← pending OR rejected: show "تحت المراجعة" + retry-login button
   ├─ 403 ACCOUNT_SUSPENDED / 404 PARTNER_NOT_FOUND
 GET /me → GET /overview → …
```

**There is no status-poll endpoint** — retrying the dashboard login IS the approval check.

## 6. Partner dashboard flows (cookie session, root-mounted)

Reference docs: `NEXTJS-DASHBOARD-PRODUCTION.md` (wiring), `NEXTJS-DASHBOARD-DEVIATIONS.md`
(conventions), `NEXTJS-DASHBOARD-ENUMS.md` (city/type/amenity values — `makkah`, not `mecca`),
`NEXTJS-DASHBOARD-REPORTS.md`, `NEXTJS-DASHBOARD-CALENDAR-ICAL.md`,
`NEXTJS-DASHBOARD-PROPERTY-WIZARD-ANSWERS.md`.

### 6.1 Add-property wizard

```
POST /uploads/presign { kind: unit_photo|license_pdf|company_doc, fileName, mimeType, size }
   → { fileId, uploadUrl }
PUT  {uploadUrl}                          ← raw bytes (signed URL; magic-byte validated, ≤10MB)
POST /units                               ← partial DRAFT ok (only drafts skip required fields);
                                            accepts cancellationPolicy: flexible|moderate|strict
                                            (echoed on every unit; unset ⇒ moderate)
PATCH /units/{id}                         ← photoFileIds[] (ordered, authoritative replace),
                                            coverFileId, tourismLicenseFileId — photos echo fileId back
POST /units/{id}/submit                   ← full validation here → status pending → admin review (flow 7)
```

Company partners must first complete `PUT /me/company-docs` (cr, iban + 3 `company_doc` fileIds) or
submit fails `COMPANY_DOCS_INCOMPLETE`.

### 6.2 Units, calendar, iCal

```
GET /units | GET /units/{id} | DELETE /units/{id} (drafts only, else 409)
PATCH on an APPROVED unit → reverts to pending (re-review)

GET  /units/{id}/calendar?month=YYYY-MM        ← day grid: available|booked|blocked|external
POST /units/{id}/calendar/block | /unblock     ← manual closures (booked/external days refuse: 409)

GET/POST /units/{id}/ical                      ← import feeds (Airbnb/Booking); validated then synced
POST /units/{id}/ical/{feedId}/sync | DELETE /units/{id}/ical/{feedId}
GET  /units/{id}/ical/export                   ← { url } = public tokenised .ics for other platforms
```

### 6.3 Bookings, reports, notifications, profile

```
GET  /bookings | GET /bookings/{id}   ← each booking: `financials` (partner cut) + guest-facing
                                        `pricing` block (camelCase: nightlyRate, nights, subtotal,
                                        taxes, taxPercent, total — frozen at booking time; build
                                        invoice screens from it; serviceFee/cleaningFee keys appear
                                        only on historical fee-era bookings)
POST /bookings/{id}/host-cancel   ← body { reason }, header Idempotency-Key (retry-safe);
                                     100% refund to guest, dates auto-blocked, counts toward flagging
GET  /overview                                    ← KPIs + 12-month series (partner-share revenue)
GET  /reports/summary?from&to                     ← gross/commission/netProfit + per-unit (gross)
GET  /reports/export?from&to&format=pdf|csv|xlsx  ← file download (blob), Arabic-RTL PDF
GET  /notifications | /notifications/unread-count | POST …/read-all | POST /notifications/{id}/read
GET/PATCH /me · GET/PUT /me/company-docs · POST /me/phone/request → /me/phone/verify · POST /auth/logout
```

## 7. Admin flows (Bearer via email+password)

```
POST /auth/admin/login { email, password } → Bearer pair
GET  /admin/dashboard                         ← KPIs

Partner applications (what unblocks dashboard login):
GET  /admin/partners?status=pending&search=
POST /admin/partners/{userId}/approve         ← sets partnerDetail.status=approved + notifies
POST /admin/partners/{userId}/reject { reason }   ← notified; applicant may re-register → pending
   ⚠ NOT the same as PATCH /admin/users/{id}/status (is_active) — that suspends/activates the
     ACCOUNT and does NOT clear ACCOUNT_PENDING.

Unit review:      GET /admin/requests → GET /admin/requests/{unitId}
                  POST /admin/requests/{unitId}/approve | /reject { reason }
Users:            GET/POST /admin/users · PATCH /admin/users/{id}/status · DELETE /admin/users/{id}
Read-only:        GET /admin/units · GET /admin/bookings · GET /admin/reports
Pricing:          GET /admin/platform-settings → { tax_percent: 15 }   ← read-only since the
                  2026-07-18 fee revert; the PATCH endpoint was removed (405) — nothing is editable
Notifications:    same shape as partner block, under /admin/notifications
```

## 8. Public machine endpoints (no user in the loop)

```
GET  /api/v1/calendar/{token}.ics    ← iCal export consumed by Airbnb/Booking (token = credential)
POST /api/v1/payments/callback       ← Moyasar webhook (secret_token)
POST (dashboard) /webhooks/moyasar   ← dashboard-side Moyasar webhook
```

---

## 9. Cross-cutting rules

- **Roles:** `User` (guest) · `Individual`/`Company` (partner) · `Admin`/`SuperAdmin`. Route access is
  role-gated; the dashboard adds the `approved` gate on top.
- **Unit lifecycle:** `draft → (submit) pending → approved | rejected(reason)`; editing an approved
  unit re-enters `pending`. Only `approved` units are public/bookable.
- **Money:** guest total = subtotal + 15% VAT — nothing else (cleaning/service fees abolished
  2026-07-18, `NEXTJS-REVERT-FEES.md`); amounts + `tax_percent` frozen per booking at
  `POST /bookings`; historical fee-era bookings keep their frozen fee lines (optional keys).
  Commission = 2% of rental subtotal, frozen per booking; Overview revenue = partner share,
  Reports revenue = gross (see `NEXTJS-DASHBOARD-REPORTS.md` — don't reconcile 1:1). SAR, 2 decimals.
- **IDs:** dashboard uses prefixed ids (`u_12`, `b_45`, `p_9`, `file_…`) — echo them back as received;
  user-site/admin use bare numeric ids.
- **Dates:** `YYYY-MM-DD`; all ranges checkout-exclusive (end date = departure day, not booked).
- **Throttles** worth handling in UI: OTP request 5/min + 10/phone/day; payments 20/min; dashboard
  session 120/min. On 429 honor `Retry-After`.
