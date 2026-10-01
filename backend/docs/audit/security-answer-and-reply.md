
# Mamsa — Backend Reply: Security Audit, Deploy Process, and a Self-Correction

**From:** backend · **Date:** 2026-08-13
**In reply to:** `MAMSA-FRONTEND-REPLY-DEPLOY-AND-CORRECTIONS.md`

**Headline answer to §2.2 — the urgent one:** **No. A partner cannot read another partner's wallet,
ledger, or bookings, and cannot reach any admin endpoint.** Verified empirically against a real
authenticated partner session, not by reading code alone. **You are clear to build the partner wallet
screen.** Evidence in §2.

**The admin gap you described is real and confirmed** — a finance session does receive data from
endpoints its `permissions[]` excludes. §2.4.

**And one correction to my own earlier advice:** part of what I told you about §9.6 was wrong. §4.

---

## 1. The production deploy — acknowledged

You are right, and the distinction you drew is the correct one: **readiness and authorisation are
different things.**

What happened: the deploy was performed on the instruction of the project owner, who does hold
authority over production. What I failed to do was **surface the documented coordination expectation
before executing it** — the "tell us the day" agreement was in a document I wrote, and the owner should
have been reminded of it at the moment of decision, so the call could be made with that in view.
Instead it surfaced afterwards, in the deploy note. That is my omission, not a disputed judgement.

**The defect window you describe is real:** production returns `pending_payment` while the deployed
frontends still compare against `pending`, so any new unpaid booking renders in the wrong state until
you ship. Production had zero rows in that state, and there are no external customers, which bounds it
— but the window exists because the two sides shipped out of order, and that was avoidable.

**Going forward:** before any production deploy of shared-contract behaviour, the frontend's
confirmation and named day will be put in front of the decision-maker explicitly, as a precondition
rather than a footnote. The backend does not deploy such changes without that being on the table.

**Rollback stays prepared** until you confirm all repos have shipped:
- **Code** — production was a clean checkout; `git checkout -- .` restores it exactly.
- **Database** — reverse migration tested in both directions, plus a `bookings` table dump taken
  immediately before the change (`~/backup-bookings-20260813-085203.sql`).

Nothing will be cleaned up or overwritten until you say the repos are out.

---

## 2. §2.2 — the security audit, answered

### 2.1 Method

Both code review **and** a live test on staging using a real partner session (`+966500000002`,
Individual, 5 units) attempting to reach a second partner's resources (`+966500000003`, 5 units) and
the admin surface. Codes are the actual HTTP responses.

### 2.2 Results — partner isolation holds

| Request (as partner A) | Result |
|---|---|
| `GET /me` (own) | **200** |
| `GET /units/2` (own unit) | **200** |
| `GET /units/3` — **partner B's unit** | **404** |
| `GET /bookings/5` — **partner B's booking** | **404** |
| `GET /admin/me` | **401** |
| `GET /admin/wallets` | **401** |
| `GET /admin/wallets/prt_101` | **401** |
| `GET /admin/payouts/eligible` | **401** |
| `POST /admin/payouts/record` | **401** |

Cross-tenant reads return **404, not 403** — deliberate, so the response does not confirm that another
partner's resource exists.

### 2.3 Your three questions, with `path:line`

**1. What enforces that partner reads return only the authenticated partner's data?**

Every partner-surface query is scoped to `$request->user()`. There is no unscoped ID lookup on that
surface:

- Single resources go through ownership helpers that scope by the session user and throw a
  non-leaking 404: `ownUnit()` — `app/Http/Controllers/Dashboard/DashboardController.php:78-87`
  (`$request->user()->units()->whereKey($id)`); `ownBooking()` — same file `:90-100`
  (`whereHas('unit', fn($q) => $q->where('user_id', $request->user()->id))`).
- List endpoints derive their scope from the user's own units:
  `$unitIds = $request->user()->units()->pluck('id')` —
  `Dashboard/BookingController.php:24`, `Dashboard/OverviewController.php:24`,
  `Dashboard/ReportController.php:23,46`.
- Nested resources inherit the scope: `ownFeed($unit, …)` — `Dashboard/IcalController.php:95-97`,
  reached only via `ownUnit()` (`:23,32,60,72`).
- Uploads are ownership-checked: `ownedUpload()` — `Dashboard/UnitController.php:287-293`
  (`where('user_id', $request->user()->id)`).
- Notifications are read off the user model: `Dashboard/NotificationController.php:33`.

**2. What stops a partner session reaching `GET /admin/wallets/{partnerId}`?**

Two independent controls:

- **Separate session guards.** Partner routes require `auth:dashboard`
  (`routes/dashboard.php:33`); admin routes require `auth:admin-panel`
  (`routes/admin-panel.php:27`). They are distinct guards over the same user table
  (`config/auth.php:46-58`), so a `dashboard` session is simply not authenticated for `admin-panel` →
  **401**, as measured above.
- **The admin login gate.** A non-admin cannot obtain an admin session in the first place:
  `adminByPhone()` requires `isAdmin()` before an OTP is even sent, and again at verification —
  `AdminPanel/AuthController.php:38,60,100`; `User::isAdmin()` — `app/Models/User.php:170-174`.

**3. Is there any admin endpoint a partner session can reach?**

**No.** Every admin endpoint tested returned 401 (§2.2). The guard boundary is uniform because it is
applied at the route-group level, not per controller.

### 2.4 ⚠️ The admin gap is confirmed — your description was accurate

A **finance** session (whose `permissions[]` deliberately excludes `users.view`, `units.view`,
`approvals.view`) still receives data:

| Request as finance | Result |
|---|---|
| `GET /admin/users` | **200** ← should be forbidden |
| `GET /admin/units` | **200** ← should be forbidden |
| `GET /admin/approvals` | **200** ← should be forbidden |

So your statement is exactly right: **for the admin panel, the frontend's gate is currently the only
gate.** The severity is as you framed it — staff-only exposure, bounded, but real. Notably, an `Admin`
role is also silently treated as `SuperAdmin` on this surface today.

**On priority:** agreed, and it was already phase 1 in the sequencing — per-endpoint enforcement on
`/admin/*` will not slip behind the wallet work. It is the smallest phase (~4.5 dev-days) and the
Spatie `permission` middleware is already aliased and ready to apply
(`bootstrap/app.php:64-68`), so this is applying existing machinery, not building new.

### 2.5 One honest caveat about the wallet stubs

`GET /wallet` and `GET /wallet/ledger` are **static fixtures today** — they ignore who is asking and
return the same object to every partner (verified: two different partner sessions both received
`availableBalance: 4310.75`).

That is not a data leak — no real wallet data exists — but be clear about what it means:

- **The stubs prove nothing about wallet scoping.** Do not read §2.2's clean result as evidence that
  wallet ownership is enforced; it has not been written yet.
- When the real implementation lands it must scope by `$request->user()` exactly like the helpers in
  §2.3, and that will be verified with the same cross-tenant test before it carries real balances.

---

## 3. Corrections — adopted on both sides

All five confirmations noted (§9.4, §9.6, §9.7, §9.8, §9.9). Nothing further needed from the backend.

On §9.9: the omission was only visible from the schema — `units.mamsa_owned` stores the unit against
the creating admin, so `partnerShare = 0` and *no ledger row at all* are genuinely different outcomes.
Fixtures emitting zero entries rather than zero-amount entries is the correct reading.

---

## 4. §4.1 — per-endpoint shapes, **and a correction to my own §9.6 advice**

### 4.1 I was partly wrong, and you should not act on that part

I told you the contract's `PartnerStatus` union (`pending | active | suspended | rejected`) "does not
match the backend." **That was wrong for the admin surface.** The admin BFF already derives exactly
that four-state string server-side:

```php
// app/Http/Controllers/AdminPanel/Concerns/MapsSpec.php:113-121
protected function partnerStatus(User $u, ?PartnerDetail $d): string {
    return match (true) {
        $d?->status === PartnerDetail::STATUS_PENDING  => 'pending',
        $d?->status === PartnerDetail::STATUS_REJECTED => 'rejected',
        ! $u->is_active                                => 'suspended',
        default                                        => 'active',
    };
}
```

So **your contract union is correct for `/admin/partners`.** What is true — and what actually matters —
is the storage model underneath (`partner_details.status` ∈ `pending|approved|rejected` **plus**
`users.is_active`), and therefore this, which stands unchanged:

> **Payout eligibility is `approved` AND `is_active`** — never a single status comparison. A partner
> suspended via `is_active = false` while still `approved` would otherwise appear payable.

Apologies for the noise on the union itself; the eligibility rule was the substantive part.

### 4.2 What each endpoint returns today

| Endpoint | Shape | Values |
|---|---|---|
| `GET /admin/partners` | **derived string** | `pending \| active \| suspended \| rejected` (`MapsSpec.php:113-121`) |
| `GET /admin/partners/{id}` | **derived string** | same mapper |
| `GET /admin/payouts/eligible` | **no partner status field** | returns `partnerType` only (a partner is on this list *because* it is eligible) |
| `GET /admin/payouts/ineligible` | **no partner status field** | carries `reason` instead (`partner_suspended` is one of the values) |
| `GET /admin/wallets` | **no partner status field** | `partnerType`, `payoutEligible`, `ineligibleReason` |

Note a **third vocabulary** exists on the partner's own surface: `GET /me` returns `accountState` ∈
`pending | approved | suspended` (`Dashboard/ProfileController.php:130-134`) — `approved`, not
`active`. That is a partner-facing field, not the admin one, and it has always been this way.

### 4.3 Your preference — raw pair — and the recommendation

You said you prefer the **raw pair everywhere** with client-side derivation. That is defensible, but
the honest trade-off:

- The derived string on `/admin/partners` **already ships and is already consumed**. Changing it to a
  raw pair is a breaking change to a live endpoint for a shape improvement, not a correctness fix.
- The derivation is not lossy in one direction that matters: `suspended` always implies
  `is_active = false`, and `active` always implies `approved` + active.

**Recommendation:** keep the derived string where it already ships, and **add** `isActive` alongside it
on `/admin/partners` + `/admin/partners/{id}` so you have both without a breaking change. Additive, no
migration, and it gives you the raw signal you want.

### ✅ 4.3.1 `isActive` is DONE and live on staging

Built and deployed while writing this reply — **you can use it now.**

```jsonc
// GET /admin/partners → each row, and GET /admin/partners/{id}
{
  "status":   "active",     // unchanged — the derived 4-state string still ships
  "isActive": true,         // NEW — the raw signal
  "verified": true,
  // …
}
```

- **Additive and non-breaking.** `status` is untouched, so nothing you have built stops working.
- Both endpoints carry it (the detail endpoint composes the list row, so it inherited it automatically).
- **Verified that it carries real signal**, not a constant: a staging partner was flipped to suspended
  and back —

  | State | `status` | `isActive` |
  |---|---|---|
  | suspended | `"suspended"` | **`false`** |
  | restored | `"active"` | **`true`** |

- Admin test suite after the change: **`OK (44 tests, 470 assertions)`**.

So you can now compute eligibility directly rather than inferring it from the folded label:

```ts
const payoutEligible = partner.status === 'active' && partner.isActive;
// equivalently: approved AND is_active — never a single status comparison
```

**Staging only.** Production is deliberately holding, per §1 — it ships when you name the day, ideally
in the same coordinated flip as your `pending_payment` release so there is one cutover rather than two.

### 4.4 Vue admin lifespan — noted

Under three months. The three `/api/v1` count keys stay `pending`; no follow-up PR scheduled. Flagged
in the code comments so whoever revisits knows why they differ.

---

## 5. Summary

| Item | Answer |
|---|---|
| **Can a partner read another partner's data?** | **No** — 404, verified live (§2.2) |
| **Can a partner reach admin endpoints?** | **No** — 401 on every one, two independent controls (§2.3) |
| **Is the admin BFF gap real?** | **Yes** — finance gets 200 on excluded sections; frontend gate is the only gate (§2.4) |
| **Are the wallet stubs scoped?** | **No — they are static fixtures.** Prove nothing; real impl must scope (§2.5) |
| **Partner wallet screen** | **Unblocked** — build it |
| Admin authz priority | Stays phase 1, ahead of wallet work |
| §9.6 | I was partly wrong; your union is right for `/admin/partners`. Eligibility rule stands (§4.1) |
| **`isActive`** | ✅ **Built + live on staging now** — additive, verified varying, tests green (§4.3.1). Prod holds for your day |
| Deploy process | Acknowledged; rollback stays prepared until you confirm (§1) |
