# Implementation notes carried into Phase A and the VAT phase

Recorded 2026-08-13 from frontend-raised items. None are urgent; all are traps that are cheap now and
expensive after the fact.

---

## 1. ⚠️ Completeness must become TYPE-AWARE in Phase A

**Raised by the frontend, and correct.**

Individual partners' IBANs now land in **`partner_details.iban`** — the same column the company-docs
completeness check reads (`ProfileController::docs()`, the `iban` element). That happens because
`PUT /me/company-docs` has no partner-type gate and the dashboard writes individual IBANs through it as
the interim path (there is no other store until `bank_details` ships).

**No conflict today**, because the completeness gate only applies to companies —
`UnitController.php:249-252` checks `type === 'company'`.

**The trap, if completeness is extended to individuals as-is:**

| Case | Wrong outcome |
|---|---|
| Individual saved only an IBAN | Reads as **"complete"** — the four company-only fields (`cr`, three doc files) are legitimately null for them, so a naive "all five non-empty" check either fails them or, if relaxed, passes them on the IBAN alone |
| Individual with no IBAN | **Blocked from submitting a unit** with a `409 COMPANY_DOCS_INCOMPLETE` that names company documents — no visible cause for an individual |

**Required in Phase A:**

- Completeness becomes **type-aware**, not one flat five-field check:
  - **Company** → CR + the three document files + a verified bank account.
  - **Individual** → a bank account only, and only if payout eligibility actually requires one.
- The bank-account element reads **`bank_details`**, not `partner_details.iban`.
- The error code/message for an individual must name the *bank account*, not company documents.
- **Migration**: when backfilling `partner_details.iban` → `bank_details`, individual rows must migrate
  too — they are indistinguishable from company rows in that column today.

A warning to this effect is in the code at `ProfileController::docs()` so it is seen at the point of
change, not only here.

---

## 2. `/reports/summary` — two fields owed with the VAT phase

Both surfaces currently return neither field.

**Partner — `GET /reports/summary`** (`Dashboard/ReportController::summary`) returns today:
`grossRevenue`, `bookingsCount`, `commission`, `netProfit`, `revenueByMonth`, `bookingsByMonth`,
`perUnit`.

**Admin — `GET /admin/reports/summary`** (`AdminPanel/ReportsController::summary`) returns today:
`totalRevenue`, `totalCommission`, `totalBookings`, `avgMonthlyRevenue`, `revenueSeries`,
`revenueByCity`, `bookingStatusSlices`, `bookingVolume`, `occupancySeries`, `occupancyAverage`,
`topPartners`.

**To add with the VAT-inclusive refactor:**

| Surface | Field | Meaning |
|---|---|---|
| Partner | `netRevenue` | revenue net of VAT |
| Partner | `vat` | VAT collected |
| Admin | `netRevenue` | revenue net of VAT |
| Admin | `vatCollected` | VAT collected |

⚠️ **Naming diverges between surfaces in the contract**: partner §6.1 specifies `vat`, admin §5.4
specifies `vatCollected`. The frontend requested `netRevenue` + `vat`. **Confirm which surface they meant
before implementing** — if the admin page is the one showing `undefined`, the contract name there is
`vatCollected`, and either the contract or the frontend expectation needs correcting rather than
silently shipping a third variant.

Also per contract: admin revenue KPIs must be stated **net of VAT**, with VAT shown separately and never
summed into a revenue tile.

---

## 3. `bank_details` — accepted at ~4 dev-days

Estimate accepted by the frontend. Their work is complete and behind a feature flag, waiting on Phase A.

**Obligation: notify the frontend the moment Phase A reaches production**, at which point their write
switches from `PUT /me/company-docs` to `PUT /me/bank-details` in one line.

Phase split as estimated:
- **Phase A (~2.5 d)** — table/model/migration, real `GET`/`PUT` in **all** environments, mod-97,
  completeness re-pointed **type-aware** (§1), backfill.
- **Phase B (~1 d)** — server-derived `bankName`; blocked on sourcing an authoritative SAMA bank-code
  table (will not be reconstructed from memory).
- **+0.5 d** — admin verify/reject, required before any partner is payout-eligible.
