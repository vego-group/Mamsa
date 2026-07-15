# Partner Dashboard — Reports & Overview (D-9 / D-3)

Implementation guide for the Next.js **partner dashboard** Reports screen and the Overview
metrics, matched to the live backend. Read alongside `NEXTJS-DASHBOARD-DEVIATIONS.md` for the
shared conventions (cookie-session auth, error envelope, ID prefixes). All endpoints are
**root-mounted** on `https://api.mamsaa.com` (staging: `https://staging.mamsaa.com`) and require an
authenticated partner **session cookie** — send `credentials: "include"` on every request.

---

## 1. Overview metrics — `GET /overview`

Powers the dashboard home cards + the 12-month spark charts. No query params.

```jsonc
{
  "unitsCount": 4,            // partner's units EXCLUDING drafts
  "bookingsCount": 12,        // confirmed + completed (not cancelled)
  "totalRevenue": 84200,      // SAR, partner share (total − 2% commission)
  "bookingsByMonth": [ { "month": "2025-08", "count": 0 }, /* …12, oldest→newest */ ],
  "revenueByMonth":  [ { "month": "2025-08", "amount": 0 }, /* …12, SAR partner share */ ],
  "thisMonthRevenue": 12400,  // SAR partner share, current calendar month
  "occupancyRate": 62,        // 0–100 integer: booked nights / available nights, approved units, this month
  "hasRejectedUnit": true     // show the "unit needs attention" banner
}
```

- **All money is partner share** (after Mamsa's 2%). Whole/decimal SAR — never halalas.
- The series are always **exactly 12 entries, oldest → newest, zero-filled**. Compute deltas,
  month-over-month %, and sparklines **on the client** — the API deliberately doesn't send them.

```ts
type MonthCount = { month: string; count: number };   // month = "YYYY-MM"
type MonthAmount = { month: string; amount: number };
type Overview = {
  unitsCount: number; bookingsCount: number; totalRevenue: number;
  bookingsByMonth: MonthCount[]; revenueByMonth: MonthAmount[];
  thisMonthRevenue: number; occupancyRate: number; hasRejectedUnit: boolean;
};

export const getOverview = () =>
  fetch(`${API}/overview`, { credentials: "include" }).then(r => r.json()) as Promise<Overview>;
```

---

## 2. Reports summary — `GET /reports/summary?from=&to=`

Both `from` and `to` are **required**, format `YYYY-MM-DD`, and `to` must be ≥ `from` (else
`400 VALIDATION` with `error.fields.to`). The date shortcut buttons (this month / last 3 months /
this year) are computed on the client — just pass the resolved from/to.

```jsonc
{
  "grossRevenue": 88500,     // SUM of booking totals (non-cancelled) in range — the FULL total, not partner share
  "bookingsCount": 14,
  "commission": 1770,        // Mamsa 2%
  "netProfit": 86730,        // grossRevenue − commission (a real SAR amount, NOT a count)
  "revenueByMonth":  [ { "month": "2026-05", "amount": 24498 }, /* only months with data, ascending */ ],
  "bookingsByMonth": [ { "month": "2026-05", "count": 6 } ],
  "perUnit": [
    { "unitId": "u_1", "unitName": "استوديو مرسى العليا", "bookings": 5, "revenue": 28800 }
  ]
}
```

> **Note on revenue basis:** Overview `totalRevenue` is the **partner share** (after commission),
> but Reports `grossRevenue` is the **full total** and `netProfit = grossRevenue − commission`.
> That's intentional — the reports screen breaks the commission out as its own line, so it starts
> from gross. Don't try to reconcile the two figures 1:1.

```ts
type ReportSummary = {
  grossRevenue: number; bookingsCount: number; commission: number; netProfit: number;
  revenueByMonth: MonthAmount[]; bookingsByMonth: MonthCount[];
  perUnit: { unitId: string; unitName: string; bookings: number; revenue: number }[];
};

export const getReportSummary = (from: string, to: string) =>
  fetch(`${API}/reports/summary?from=${from}&to=${to}`, { credentials: "include" })
    .then(r => r.json()) as Promise<ReportSummary>;
```

> ⚠️ **Historical commission may read 0** for bookings created before the 2% commission feature
> shipped — those rows genuinely had no commission. New bookings always carry it. Don't treat a
> `commission: 0` on old data as a bug.

---

## 3. Reports export — `GET /reports/export?from=&to=&format=`

Same required `from`/`to`. `format` is `pdf` (default), `csv`, or `xlsx`.

| format | Response | Notes |
|---|---|---|
| `pdf` | `application/pdf` file (`Content-Disposition: attachment`) | Server-rendered, **Arabic RTL**, one A4 page for typical ranges. |
| `csv` / `xlsx` | `text/csv` file, UTF-8 BOM | Opens natively in Excel with Arabic intact. `xlsx` currently returns the same CSV file. |

This is an **authenticated file download**, not JSON — so you can't just drop the URL in an
`<img>`/`fetch().json()`. Two working patterns:

**A. In-app download via blob (recommended — keeps the session header logic in one place):**

```ts
export async function downloadReport(from: string, to: string, format: "pdf" | "csv") {
  const res = await fetch(`${API}/reports/export?from=${from}&to=${to}&format=${format}`, {
    credentials: "include",
  });
  if (!res.ok) throw await res.json();           // { error: { code, message } }
  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = `mamsa-report-${from}_${to}.${format === "pdf" ? "pdf" : "csv"}`;
  a.click();
  URL.revokeObjectURL(url);
}
```

**B. New-tab (simplest):** `window.open(\`${API}/reports/export?from=${from}&to=${to}&format=pdf\`)`.
This works because it's a top-level GET navigation to `api.mamsaa.com` — the SameSite=Lax session
cookie **is** sent on top-level navigations. (It is NOT sent on a cross-site `fetch` without
`credentials:"include"`, which is why pattern A passes it explicitly.)

---

## 4. Error handling

Standard dashboard envelope on every failure:

```jsonc
{ "error": { "code": "VALIDATION", "message": "بيانات غير صالحة", "fields": { "to": "…" } } }
```

- `400 VALIDATION` — missing/invalid `from`/`to` (check `error.fields`).
- `401 UNAUTHENTICATED` — session expired → bounce to the OTP login.
- `429 RATE_LIMITED` — honor `Retry-After`.

Render `error.message` (Arabic, user-facing); branch logic on `error.code` (stable).
