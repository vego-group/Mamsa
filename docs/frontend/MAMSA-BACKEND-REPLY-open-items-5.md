# Backend reply — §11.5–11.7, the last open questions

**From:** backend · **Date:** 2026-08-16
**Closes:** the final items from `BACKEND-REQUEST-open-items.md` §11
**Status:** all three answered · ⚠️ **two of them carry a labelling trap you should know about before
you write the caption** — §1.1 and §2.1

You said these were labels you were writing without knowing what they measure. Two of them measure
something other than what the tile above them shows, which is the case you were trying to avoid.

---

## 1. §11.5 `deltas` — month-over-month, **calendar** months

```php
$monthStart = now()->startOfMonth();                    // current, PARTIAL
$prevStart  = now()->subMonth()->startOfMonth();        // previous, COMPLETE
$prevEnd    = now()->subMonth()->endOfMonth();
```

Not the selected range — the dashboard has no range selector, and these are always **this calendar
month vs the previous calendar month**, counted on `created_at`.

Four keys only:

```jsonc
"deltas": { "totalUsers": …, "platformCommission": …, "totalBookings": …, "activePartners": … }
```

**`pendingRequests` and `avgBookingValue` have no delta.** If your UI reserves space for one on those
tiles, it will always be empty.

### 1.1 ⚠️ The trap: the current month is **incomplete**

`monthStart` → *now* is month-to-date; the comparison window is a **full** previous month. So on the
3rd of the month you are comparing three days against thirty-one, and **every delta reads
catastrophically negative** through the first half of every month, recovering as the month fills.

That is not a bug in the arithmetic — but a tile captioned *"vs last month"* showing **−80%** on the
3rd is telling the reader something false, and it will be read as a business emergency. If you render
these at all, the honest caption is **"this month so far vs all of last month"**, or suppress the
delta until the month is comparably advanced. Your call; I would rather you knew than discovered it
on the 2nd.

### 1.2 ⚠️ Two deltas measure a different quantity from the tile they sit under

| tile | what the KPI shows | what its delta measures |
|---|---|---|
| `totalUsers` | **all users ever** | users **created** this month vs last |
| `activePartners` | **currently active, approved partners** | partners **created** this month vs last |
| `totalBookings` | all bookings ever | bookings created this month vs last |
| `platformCommission` | commission all time | commission **earned** this month vs last |

So `+12%` beside `1,240 users` does **not** mean the user count grew 12%. It means 12% more people
signed up this month than last. Those are different claims and the second is the one being made.

`activePartners` is the sharper one: the KPI is a *stock* (how many are active right now) and the
delta is a *flow* (how many joined), so a month where ten partners were suspended and five joined
shows a **positive** delta beside a **falling** number. Caption them as **growth/new this month**, not
as a change in the figure above.

### 1.3 Zero handling

```php
if ($previous <= 0) return $current > 0 ? 100.0 : 0.0;
```

**`+100%` does not mean "doubled" — it means "from nothing".** A first-ever month, or any metric whose
previous month was zero, reports exactly `100.0`. Both-zero reports `0.0`. Never `null`, never
infinity, never a divide-by-zero. Values are signed, one decimal place.

---

## 2. §11.6 `monthlyGrowth`

**Gross revenue booked this calendar month vs the previous calendar month, as a signed percentage.**

```php
'monthlyGrowth' => $this->pctDelta($revThisMonth, $revPrevMonth)
// rev = SUM(total_amount) over confirmed + completed, filtered on created_at
```

- **Revenue, not bookings** — the name does not say which, so this is the answer: SAR, gross
  (VAT-inclusive `total_amount`), over `revenue()` = confirmed + completed.
- Same partial-month asymmetry as §1.1 — it is the same window.
- Same `+100%`-means-from-nothing rule as §1.3.

### 2.1 ⚠️ It counts by `created_at`, not by stay date

`monthlyGrowth` buckets a booking by **when it was made**. The partner dashboard's `/reports/summary`
filters on **`start_date`** — when the stay happens.

So a booking made in August for a December stay counts toward **August** here and **December** there.
The two screens are answering different questions and will not tie out; that is intended, but only if
the labels say so. "Revenue booked this month" is accurate for this field. "Revenue this month" is not.

---

## 3. §11.7 `latestPendingRequests` / `recentHostCancellations` — **capped at 5 server-side** ✅

```php
->orderByDesc('updated_at')->limit(5)     // latestPendingRequests
->orderByDesc('cancelled_at')->limit(5)   // recentHostCancellations
```

Your slice-to-5 matches the server exactly. **The payload can never get heavy** — it is five rows
each, permanently, however many pending units or cancellations exist.

Two details since you render them:

- **`latestPendingRequests` is ordered by `updated_at` descending**, i.e. *most recently touched
  first* — which is the **opposite** of the approvals queue's `submitted_at ASC`. The dashboard card
  shows the newest, the queue works the oldest. That is deliberate (a card is a "what just happened"
  surface) but do not caption the card as "next in the queue" — it is the reverse of it.
- **`recentHostCancellations` is host-only**: `cancelled_by != 'customer'` OR `cancelled_by IS NULL`.
  The null branch catches legacy rows where the canceller was never recorded, so a handful of old
  guest cancellations may appear in it. Nothing to do; worth knowing before someone reports it.

---

## 4. One more window mismatch, since you are captioning these

`revenueByCity` and `bookingStatusSlices` appear on **both** the dashboard and `/admin/reports/summary`,
with the **same field names and different windows**:

| | `/admin/dashboard/summary` | `/admin/reports/summary` |
|---|---|---|
| `revenueByCity` | **all time** | windowed by `range` |
| `bookingStatusSlices` | **all time** | windowed by `range` |
| `revenueSeries` | last **12 months**, fixed | `range`-dependent (6m / 1y / all) |
| `weeklyBookings` | last **7 days** incl. today | — |

The dashboard passes no `$since`, so those two are lifetime figures. If the same component renders
both payloads, it will silently show lifetime numbers on one screen and range-scoped ones on the
other under identical labels.

---

## 5. §11 — complete

| # | Field | Answer |
|---|---|---|
| 11.1 | `range` | `6m` / `1y` / `all`; unknown → `1y`. `all` = lifetime, min 12 months |
| 11.2 | `revenueSeries.label` | English `Jan`…`Dec`, oldest → newest |
| 11.3 | `weeklyBookings.label` | English `Sun`…`Sat`. ⚠️ `revenueByCity.label` is **Arabic** |
| 11.4 | occupancy | integers **0–100**, clamped |
| **11.5** | **`deltas`** | **calendar MoM on `created_at`; 4 keys; partial current month; stock-vs-flow (§1.2)** |
| **11.6** | **`monthlyGrowth`** | **gross revenue MoM, bucketed by `created_at` not stay date** |
| **11.7** | **the two lists** | **capped at 5 server-side; ordered newest-first** |
| 11.8 | timestamps | `+03:00` offset, Riyadh-local grouping |

**That is every question in `BACKEND-REQUEST-open-items.md` answered.** Nothing is outstanding from me
except the staging `/storage/*` 404 (production is correct) and the `cr_file` product decision, which
is not mine to make.

No code changed for this reply — these were all questions about existing behaviour, and I would rather
tell you what it does than quietly change it to match a caption.
