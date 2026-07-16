# Partner Dashboard — Reports & Overview (D-9 / D-3)

Implementation guide for the Next.js **partner dashboard** Reports screen and the Overview metrics,
regenerated 2026-07-16 directly from the live controllers (`OverviewController`, `ReportController`).
Read alongside `NEXTJS-DASHBOARD-DEVIATIONS.md` for the shared conventions (cookie-session auth, error
envelope, ID prefixes).

**Base URL** — root-mounted, **no `/api/v1`**: `https://api.mamsaa.com` (prod) ·
`https://staging.mamsaa.com` (staging). **Auth:** partner session cookie — send
`credentials: "include"` on every request. Example numbers below are illustrative.

---

## 1. Overview metrics — `GET /overview`

Powers the dashboard home cards + the 12-month spark charts. **No query params.**

```jsonc
{
  "unitsCount": 4,            // partner's units EXCLUDING drafts (approved+pending+rejected)
  "bookingsCount": 12,        // confirmed + completed (cancelled excluded)
  "totalRevenue": 84200.00,   // SAR, PARTNER SHARE (total − 2% commission)
  "bookingsByMonth": [ { "month": "2025-08", "count": 0 }, /* …exactly 12, oldest→newest */ ],
  "revenueByMonth":  [ { "month": "2025-08", "amount": 0 }, /* …exactly 12, PARTNER SHARE */ ],
  "thisMonthRevenue": 12400.00, // SAR partner share, current calendar month
  "occupancyRate": 62,        // integer 0–100: booked nights ÷ (approved units × days this month)
  "hasRejectedUnit": true     // show the "unit needs attention" banner
}
```

- **All Overview money is partner share** (after Mamsa's 2%), rounded to 2 decimals (SAR, never halalas).
- The two series are **always exactly 12 entries, oldest → newest, zero-filled**. Compute deltas,
  MoM %, and sparklines **on the client** — the API deliberately doesn't send them.
- `occupancyRate` counts booked nights (confirmed+completed) across **approved** units only, clamped
  to the current month, over `approvedUnits × daysInMonth`. `0` when the partner has no approved unit.

```ts
type MonthCount  = { month: string; count: number };   // month = "YYYY-MM"
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

`from` and `to` are **required**, format `YYYY-MM-DD`, and `to ≥ from` (else `400 VALIDATION` with
`error.fields.from` / `error.fields.to`). The date-shortcut buttons (this month / last 3 months / this
year) are computed on the client — just pass the resolved `from`/`to`. Scope is **all** the partner's
units automatically; there is no unit-filter param.

```jsonc
{
  "grossRevenue": 88500.00,  // SUM of booking totals (confirmed+completed) in range — the FULL total
  "bookingsCount": 14,
  "commission": 1770.00,     // Mamsa 2% (frozen commission_amount, or 2% of total for legacy rows)
  "netProfit": 86730.00,     // grossRevenue − commission (a real SAR amount, NOT a count)
  "revenueByMonth":  [ { "month": "2026-05", "amount": 24498.00 } ], // GROSS; only months WITH data, ascending
  "bookingsByMonth": [ { "month": "2026-05", "count": 6 } ],         // only months WITH data
  "perUnit": [
    { "unitId": "u_12", "unitName": "استوديو مرسى العليا", "bookings": 5, "revenue": 28800.00 } // revenue = GROSS
  ]
}
```

> **Two revenue bases — don't reconcile them 1:1:**
> - **Overview** `totalRevenue` / `revenueByMonth` = **partner share** (after the 2% commission).
> - **Reports** `grossRevenue` / `revenueByMonth` / `perUnit.revenue` = **full total** (gross). The
>   reports screen breaks commission out as its own line, so it starts from gross:
>   `netProfit = grossRevenue − commission`.

> **Series shape differs from Overview:** these series list **only months that have data** (ascending),
> not a fixed zero-filled 12. If your chart needs a continuous axis, fill the gaps client-side.

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

> ℹ️ **Commission is never blank on reports.** Legacy bookings created before the 2% feature shipped
> have no stored `commission_amount`, but the summary falls back to `2% × total` for them — so
> `commission` reflects the real 2% for every row and only reads `0` when `grossRevenue` itself is `0`.
> (This changed from an earlier version of this doc, which incorrectly warned commission could read 0
> on old data — the fallback makes that not happen.)

---

## 3. Reports export — `GET /reports/export?from=&to=&format=`

Same required `from`/`to`. `format` is `pdf` (default), `csv`, or `xlsx`.

| format | Response | Notes |
|---|---|---|
| `pdf` (default) | `application/pdf`, `Content-Disposition: attachment` | Server-rendered via **mpdf**, proper **Arabic shaping + RTL**, one A4 page for typical ranges. |
| `csv` | `text/csv; charset=utf-8`, UTF-8 BOM | Opens in Excel with Arabic intact. |
| `xlsx` | same as `csv` | `xlsx` is currently an **alias to CSV** (no binary dependency); you still get a `.csv` file. |

**Filename:** `mamsa-report-<from>_<to>.pdf` (or `.csv`).
**CSV columns (in order):** `Code, Unit, Guest, Check-in, Check-out, Nights, Total (SAR), Commission, Net, Status`.

This is an **authenticated file download**, not JSON — don't `fetch().json()` it or drop the URL in an
`<img>`. Two working patterns:

**A. In-app download via blob (recommended — keeps the credentialed fetch in one place):**

```ts
export async function downloadReport(from: string, to: string, format: "pdf" | "csv" | "xlsx" = "pdf") {
  const res = await fetch(`${API}/reports/export?from=${from}&to=${to}&format=${format}`, {
    credentials: "include",
  });
  if (!res.ok) throw await res.json();           // { error: { code, message } }
  const blob = await res.blob();
  const ext  = format === "pdf" ? "pdf" : "csv"; // xlsx returns csv
  const url  = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url; a.download = `mamsa-report-${from}_${to}.${ext}`;
  a.click();
  URL.revokeObjectURL(url);
}
```

**B. New-tab (simplest, PDF only):**
`window.open(\`${API}/reports/export?from=${from}&to=${to}&format=pdf\`)`.
Works on **production** because it's a top-level GET navigation to `api.mamsaa.com`, and the
`SameSite=Lax` session cookie is sent on top-level navigations (same-site). On **staging** the cookie
is `SameSite=None; Secure`, which also works. Prefer pattern A if you want error handling.

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
