# Partner Dashboard API — Backend Notes & Deviations

Backend implementation status against `mamsa-backend-api-requirements.md` v1.2.
**All 37 endpoints in §12 are implemented and mounted at the documented root paths**
(`https://api.mamsaa.com/auth/otp/request`, `/me`, `/units`, …). Read this for the
handful of places where reality differs slightly from the contract, plus the auth wiring.

---

## 1. Auth & sessions — how to wire the client

- Auth is a **cookie session** (`dashboard` guard), httpOnly + Secure + SameSite=Lax.
  Send `credentials: "include"` on every request. There is **no bearer token**.
- CSRF: we do **not** use the Laravel XSRF-token double-submit. Mutations are protected by
  (a) SameSite=Lax cookies and (b) an **Origin allowlist** — any non-GET request must carry an
  `Origin`/`Referer` on `*.mamsaa.com` or a configured CORS origin, else `403 FORBIDDEN_ORIGIN`.
  Browsers send Origin automatically, so you don't do anything; just don't strip it.
- For this to work cross-origin, the server env sets `CORS_SUPPORTS_CREDENTIALS=true` and lists
  `https://www.mamsaa.com` (and the Vercel preview pattern) explicitly — credentialed CORS cannot
  use `*`. If you add a new frontend origin, tell us to add it.

## 2. Login gate — verify response codes (§1.2)

`POST /auth/otp/verify` returns, in this order:
- Bad/expired/locked code → `401 OTP_WRONG` / `401 OTP_EXPIRED` / `429 OTP_LOCKED` (checked **first**,
  so we never reveal whether a phone is a partner to someone without the code).
- Not a partner → `404 PARTNER_NOT_FOUND`.
- Suspended (`is_active=false`) → `403 ACCOUNT_SUSPENDED`.
- Pending **or rejected** application → `403 ACCOUNT_PENDING`. (We fold "rejected" into pending —
  a rejected applicant re-applies through the public partner-registration flow, not the dashboard.)
- Success → `200 { ok: true }` and the session cookie is set.

## 3. OTP policy (§1 / §0.6)

- Code is **6 digits, 5-minute TTL, 3 attempts** — the contract says 60s TTL; our platform-wide OTP
  is 5 minutes and we kept it consistent across the user site and dashboard. Adjust your countdown
  copy accordingly (or tell us if 60s is a hard requirement).
- Rate limit: **3 sends per phone per 10 min** + a 60s resend cooldown + daily caps (10/phone, 30/IP).
- **No debug/static code in production.** Staging only: `OTP_FIXED_CODE=111222`. The OTP is never in
  any response body on any environment.

## 4. IDs are prefixed strings (§0.3 "opaque strings")

We return `id: "u_1"` (units), `"b_1"` (bookings), `"p_1"` (partner), `"ph1"` (photos), `"file_…"`
(uploads). Path params accept **either** the prefixed or the raw numeric form, so `GET /units/u_1`
and `GET /units/1` both work. Just echo back whatever we send.

## 5. Money & dates

- All money is **whole/decimal SAR** (numbers, not halalas). `commission + partnerShare === total`
  holds on every booking (2% commission, frozen per booking).
- Dates in JSON are **ISO-8601 UTC** (`…Z`). Booking `checkIn`/`checkOut` combine the stay date with
  the unit's check-in/out time (default 15:00 / 12:00).

## 6. Cities & amenities are enums mapped server-side

Send the contract slugs (`city: "riyadh"`, `amenities: ["wifi","ac",…]`). We store the Arabic value
for the public site and map back to slugs in responses. The **city enum** we accept is in
`config`/`Maps::CITIES` — currently 20 Saudi cities. If you need one that's missing, we add it (a unit
`submit` with an unlisted city fails validation with `city` field error). Amenity keys are exactly the
8 in the contract §4.

## 7. iCal feeds are stored as named multi-feeds (§5.4) — matches contract

`GET/POST/DELETE /units/:id/ical` + `/sync` all work as specified, returning
`{ id, source, url, status, lastSync }`. `status` is `synced` | `error` (a brand-new feed reports
`synced` until its first sync result). Background auto-sync runs every 15 min via the server cron;
a feed that errors flips to `status:"error"` and fires a `sync_failed` notification **once per error
transition** (not every cycle).

## 8. Host-cancel (§6.1) — as specified

`POST /bookings/:id/host-cancel` is atomic + idempotent. **Send an `Idempotency-Key` header** — a
repeat with the same key returns the already-cancelled booking without a second refund. Refund is
**100% of `total`** (guest made whole; partner + Mamsa both forfeit their shares), auto via Moyasar,
no admin step. On success the freed dates are auto-blocked on the unit calendar, the guest gets an
apology SMS, and the cancellation counts toward `hostCancellationsLast12m`.
`refundStatus` starts `processing` and flips to `completed` on the Moyasar webhook.

## 9. Uploads — signed PUT, not S3 presigned (§9.1)

We're on shared hosting (no S3), so `POST /uploads/presign` returns a **short-lived signed URL to our
own PUT endpoint** instead of an S3 URL — the two-step client flow is identical: presign → `PUT` the
raw bytes to `uploadUrl` → reference `fileId`. Server validates **type by magic bytes** (PNG/JPG for
`unit_photo`, PDF for `license_pdf`/`company_doc`) and **size ≤ 10MB** on receipt; client MIME is
never trusted. The signed URL is valid for 30 minutes and single-use.

## 10. Reports export (§7.2) — done

`GET /reports/summary` returns the full JSON (grossRevenue, commission, netProfit, series, perUnit).
`GET /reports/export`:
- `format=pdf` → a real **server-generated PDF** (`application/pdf` download) rendered with **mpdf**,
  which does proper Arabic letter-shaping + RTL (dompdf can't shape Arabic, so we used mpdf instead).
- `format=xlsx` (or `csv`) → a real **CSV file download** (UTF-8 BOM, opens in Excel).

## 11. Everything else

Overview (§3, incl. `thisMonthRevenue` + `occupancyRate`), profile + phone-change + company-docs
(§2/§9.2, with server-computed `complete`), units CRUD + lifecycle + submit validation (§4, incl.
Saudi-bounds lat/lng and the company-docs gate), calendar month grid + block/unblock (§5.1–5.3),
bookings list/detail with all filters (§6), notifications (§8, the 5 types + `sync_failed` and
`host_cancellation` now firing) — all implemented to the documented shapes.

Ownership is enforced on every `:id` route (foreign resource → `404 *_NOT_FOUND`, never leaks). The
error envelope `{ error: { code, message, fields? } }` and `{ data, meta:{page,limit,total} }`
pagination are global.
