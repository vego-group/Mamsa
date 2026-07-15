# Partner Dashboard — Calendar & iCal Availability (D-6)

Implementation guide for the Next.js **partner dashboard** availability calendar + iCal
import/export, matched to the live backend. Read alongside `NEXTJS-DASHBOARD-DEVIATIONS.md` for the
shared conventions. All endpoints are **root-mounted** on `https://api.mamsaa.com`
(staging: `https://staging.mamsaa.com`), require the partner **session cookie**
(`credentials: "include"`), and are ownership-enforced — another partner's unit returns
`404 UNIT_NOT_FOUND` (never leaks existence).

`:id` accepts either the prefixed `u_1` or the raw `1` — pass back whatever the unit object gave you.

---

## 1. Month grid — `GET /units/:id/calendar?month=YYYY-MM`

Returns a **bare JSON array** (not enveloped), one entry per day of that month. `month` defaults to
the current month; a bad format returns `400 VALIDATION` (`error.fields.month`).

```jsonc
[
  { "date": "2026-07-01", "status": "available" },
  { "date": "2026-07-03", "status": "booked",   "bookingCode": "BK-2401", "bookingId": "b_1" },
  { "date": "2026-07-08", "status": "blocked",  "reason": "صيانة" },
  { "date": "2026-07-17", "status": "external", "source": "Booking.com" }
]
```

Status values and their extra fields:

| status | extra fields | meaning | user action |
|---|---|---|---|
| `available` | — | free to book | click to select → block |
| `booked` | `bookingCode`, `bookingId` | a Mamsa booking | read-only; link to `/bookings/{bookingId}` |
| `blocked` | `reason` (nullable) | partner closed it manually | click to **unblock** |
| `external` | `source` | imported from an iCal feed (`source` = feed name) | read-only; manage via the feed |

**Priority when a day overlaps multiple states:** `booked` > `external` > `blocked` > `available`.
The backend already resolves this — each day has exactly one `status`.

```ts
type CalendarDay =
  | { date: string; status: "available" }
  | { date: string; status: "booked"; bookingCode: string; bookingId: string }
  | { date: string; status: "blocked"; reason: string | null }
  | { date: string; status: "external"; source: string };

export const getCalendar = (unitId: string, month: string /* "YYYY-MM" */) =>
  fetch(`${API}/units/${unitId}/calendar?month=${month}`, { credentials: "include" })
    .then(r => r.json()) as Promise<CalendarDay[]>;
```

> **Date semantics:** each day is a full calendar day in the unit's local time. A booking that
> checks out on the 25th frees the 25th (checkout-exclusive) — the backend already reflects that in
> the grid, so just render the `status` you're given.

---

## 2. Block dates — `POST /units/:id/calendar/block`

Close one or more days manually (maintenance, off-platform booking…).

```jsonc
// body
{ "dates": ["2026-07-08", "2026-07-09"], "reason": "صيانة" }   // reason optional, ≤255 chars
```

- Response `200 { "ok": true }`.
- Each date must currently be **available**. If any day is already `booked` or `external`, the whole
  request is rejected with `409 DATE_UNAVAILABLE` and a message naming the offending date — validate
  the selection against the grid first for a nicer UX, but always handle the 409.
- Already-manually-blocked days in the list are silently skipped (idempotent).

```ts
export const blockDates = (unitId: string, dates: string[], reason?: string) =>
  fetch(`${API}/units/${unitId}/calendar/block`, {
    method: "POST", credentials: "include",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ dates, reason }),
  }).then(async r => { if (!r.ok) throw await r.json(); return r.json(); });
```

## 3. Unblock dates — `POST /units/:id/calendar/unblock`

```jsonc
{ "dates": ["2026-07-08"] }
```

- Response `200 { "ok": true }`.
- **Only manually-blocked days can be unblocked.** `external` days can't be reopened here — they
  mirror the source feed and would just reappear on the next sync; the partner must remove them at
  the origin platform (or delete the feed). `booked` days obviously can't be freed here.

---

## 4. iCal import feeds (§5.4)

A unit can have **multiple named feeds** (Airbnb, Booking.com, …). Each is fetched by a background
job every ~15 minutes; a feed that fails to fetch flips to `status: "error"` and fires a
`sync_failed` notification (once per error transition, not every cycle).

### Feed object

```ts
type IcalFeed = {
  id: string;          // feed id
  source: string;      // "Airbnb"
  url: string;         // the .ics URL
  status: "synced" | "error";
  lastSync: string | null;   // ISO-8601 UTC, null until first sync
};
```

### Endpoints

| Method & path | Purpose | Response |
|---|---|---|
| `GET /units/:id/ical` | List feeds | **bare array** of `IcalFeed` |
| `POST /units/:id/ical` | Add a feed | `201` `IcalFeed` |
| `DELETE /units/:id/ical/:feedId` | Remove a feed (and its imported blocks) | `200 { ok: true }` |
| `POST /units/:id/ical/:feedId/sync` | "مزامنة الآن" — sync now | `200` updated `IcalFeed` |

**Add a feed:**

```jsonc
// POST body
{ "source": "Airbnb", "url": "https://www.airbnb.com/calendar/ical/….ics" }
```

- `source` required (≤50 chars), `url` required (must start `http(s)://`, ≤2048 chars).
- The backend **fetches and validates the URL is real iCal before saving** — a bad link returns
  `400 INVALID_ICAL` ("تعذّر قراءة الرابط — تأكد أنه رابط iCal (.ics) صالح").
- On success the feed is created and **synced immediately** (no need to wait for the cron); the
  returned object already reflects the first sync's `status`/`lastSync`.
- An unknown `feedId` on delete/sync → `404 FEED_NOT_FOUND`.

```ts
export const listFeeds = (unitId: string) =>
  fetch(`${API}/units/${unitId}/ical`, { credentials: "include" }).then(r => r.json()) as Promise<IcalFeed[]>;

export const addFeed = (unitId: string, source: string, url: string) =>
  fetch(`${API}/units/${unitId}/ical`, {
    method: "POST", credentials: "include",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ source, url }),
  }).then(async r => { if (!r.ok) throw await r.json(); return r.json() as Promise<IcalFeed>; });

export const syncFeed = (unitId: string, feedId: string) =>
  fetch(`${API}/units/${unitId}/ical/${feedId}/sync`, { method: "POST", credentials: "include" })
    .then(r => r.json()) as Promise<IcalFeed>;

export const deleteFeed = (unitId: string, feedId: string) =>
  fetch(`${API}/units/${unitId}/ical/${feedId}`, { method: "DELETE", credentials: "include" })
    .then(r => r.json());
```

**UX notes:**
- Show `status`: `synced` = green with relative `lastSync`; `error` = red "تعذّرت المزامنة" with a
  "مزامنة الآن" retry that calls the sync endpoint.
- After a manual sync, re-fetch the month grid — newly imported dates show as `external`.
- Imported days appear on the grid as `external` with `source` = the feed's `source` name.

---

## 5. iCal export (§5.5) — `GET /units/:id/ical/export`

Gives the partner a **read-only public .ics URL** to paste into other platforms' "Import calendar"
box, so Mamsa bookings + manual blocks close those dates there automatically.

```jsonc
// response
{ "url": "https://api.mamsaa.com/api/v1/calendar/9f3a…c1.ics" }
```

- The URL carries an **unguessable per-unit token** — that token is the only credential, so the
  `.ics` itself needs no auth (external platforms fetch it anonymously).
- Just display it with a copy button. Don't build the URL yourself — always read it from this
  endpoint (the token is minted server-side and is revocable).

---

## 6. Error codes (this feature)

| HTTP | `error.code` | When |
|---|---|---|
| 404 | `UNIT_NOT_FOUND` | unit isn't the partner's |
| 400 | `VALIDATION` | bad `month`, bad `dates`, missing `source`/`url` (see `error.fields`) |
| 409 | `DATE_UNAVAILABLE` | blocking a day that's already booked/external |
| 400 | `INVALID_ICAL` | the import URL isn't a reachable/valid .ics |
| 404 | `FEED_NOT_FOUND` | unknown `feedId` on sync/delete |
| 401 | `UNAUTHENTICATED` | session expired |

Every failure uses the envelope `{ "error": { "code", "message", "fields"? } }` — render `message`
(Arabic), branch on `code`.
