# New-booking notifications → routed to the unit **owner** (super admin for Mamsa units)

**Audience:** the Next.js frontend team — **admin panel** (`admin.mamsaa.com`) and **partner
dashboard** (`partner.mamsaa.com`).
**Status:** backend change is **already live on production** (PR #23, backend `c613119`, 2026-08-11).
**Frontend work:** small — render the **`booking`** notification category in the admin feed and
deep-link it. The partner dashboard already renders these; no change there.

**Rule in one line:** a confirmed booking now notifies **whoever owns the unit** — a partner
listing notifies that **partner only**; a **Mamsa-owned** listing (`mamsa_owned = true`) notifies
**all super admins**. (Previously every admin was emailed on every booking — that fan-out is gone.)

---

## 1. What changed on the backend (no action needed from you)

When a booking is paid & confirmed, the backend fires a `NewBooking` notification (in-app **and**
email) to:

| Unit | Recipient(s) |
|---|---|
| Partner listing (`mamsa_owned = false`) | the **partner** who owns the unit — only |
| **Mamsa-owned** listing (`mamsa_owned = true`) | **all super admins** |

The guest still gets their booking-confirmation (SMS) as before. The separate admin **alert feed**
(approvals / cancellations / refunds / partner applications) is unchanged.

---

## 2. What the frontend needs to do

### 2a. Admin panel (`admin.mamsaa.com`) — **the actionable part**

Super admins now receive an in-app **booking** notification when a **Mamsa-owned** unit is booked.
The admin notification BFF already emits it under `category: "booking"` with a booking deep-link —
you just need to render that category (the panel was originally built for
approval/cancellation/refund/partner only).

**Endpoints** (root base `https://api.mamsaa.com`, **no** `/api/v1`, cookie session):

| Method | Path | Purpose |
|---|---|---|
| GET | `/admin/notifications` | list (newest first) |
| GET | `/admin/notifications/unread-count` | badge count |
| POST | `/admin/notifications/read-all` | mark all read |
| POST | `/admin/notifications/{id}/read` | mark one read |

**`NotificationItem` shape** (a booking one):

```json
{
  "id": "9f1c…",
  "category": "booking",
  "title": "حجز جديد",
  "body": "تم تأكيد حجز جديد على وحدة \"…\"",
  "at": "2026-08-11T13:20:05+03:00",
  "read": false,
  "entity": { "type": "booking", "id": "482" }
}
```

**To implement:**
- Add a case for `category === "booking"` in the notification bell / list (icon + label, e.g. an
  “event” icon; the source event name is `event_available`).
- Deep-link it to the booking detail using `entity` → `/admin/bookings/${entity.id}` (use your
  existing booking-detail route).
- The unread badge (`unread-count`) already includes these — no extra work.

### 2b. Partner dashboard (`partner.mamsaa.com`) — already works, FYI only

Partners already receive `new_booking` for **their own** unit bookings (this is unchanged — the
change only *narrowed* who gets it). Endpoints (root base, cookie session):
`GET /notifications`, `GET /notifications/unread-count`, `POST /notifications/read-all`,
`POST /notifications/{id}/read`.

Partner feed item shape:

```json
{
  "id": "9f1c…",
  "type": "new_booking",
  "title": "حجز جديد",
  "body": "تم تأكيد حجز جديد على وحدة \"…\"",
  "read": false,
  "createdAt": "2026-08-11T10:20:05Z",
  "href": "/…booking-detail-path…"
}
```

Nothing to change; just don't be surprised that admins no longer appear as recipients of partner
bookings.

---

## 3. Email (backend-handled — no frontend work, but read this)

The email is sent by the backend via Resend. Two things to know so expectations are right:

- The email only reaches super admins **who have an email address on their account**. The
  **test super-admin login** (`+966555000003`) currently has **no email**, so it receives the
  **in-app** notification only. `superadmin@mamsaa.sa` (the primary super admin) has an email.
- If you want a super admin to actually *receive the email*, that account must have an `email` set
  (backend/admin concern, not frontend).

---

## 4. How to test on production

A ready-made **Mamsa-owned** unit exists for this:

- **Unit:** `MAMWYAO7` (id **30**), **10 SAR**, `mamsa_owned = true`, approved + available.
- It's the only 10 SAR listing, so filter the marketplace by price = 10 to find it.

**Flow:** book `MAMWYAO7` as any guest → pay → on confirmation, open the **admin panel** and check
the notification bell: a `category: "booking"` item should appear for the super admin, deep-linking
to that booking. (Production is in live mode, so booking uses a real card; use this cheap unit to
keep the amount trivial.)

> This unit is temporary test data — it will be removed after testing. Do not rely on it long-term.

---

## 5. Notes / edge cases

- **`mamsa_owned` is a backend routing signal**, not something the frontend sends or sees on the
  booking. You don't need to branch on it — just render whatever notifications the feed returns.
- A Mamsa-owned unit's `cancellation_policy` **display string** may read `no_cancel` (a legacy
  field); the authoritative refund tiers are in `cancellation_policy_details` on the unit — same as
  any other unit. Not related to notifications.
- Category mapping is keyword-based on the backend; `new_booking` → `booking`. If a future booking
  event needs its own category, coordinate with backend.
