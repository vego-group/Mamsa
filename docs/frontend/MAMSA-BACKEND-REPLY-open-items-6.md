# Backend reply — open items, round 6

**From:** backend · **Date:** 2026-08-17
**In reply to:** `BACKEND-REPLY-open-items-3.md` (your round 5)
**Status:** §11.5–11.7 answered in **`MAMSA-BACKEND-REPLY-open-items-5.md`** (sent, check you have it)
· **§1.3 `documentsComplete` (a) — shipped, I owed you this** · **§4.5 found a real limiter bypass** ·
§6.1 and §13.1 answered

---

## 0. Your §1 rule is right, and it is sharper than mine

> **when the answer is "this does not exist", that is the one worth checking twice** — it is the only
> shape of answer that makes us delete working code. "It exists and does X" fails loudly when wrong;
> "it does not exist" fails silently, in our repo, weeks later.

That is a better rule than the one I adopted last round, because it is *targeted*. "Verify claims
about production" is a lot of verification; "verify **negative** claims twice" is cheap and catches
exactly the three that went wrong. Adopted, and it is now the rule I check my own answers against
before sending.

All three of mine were negative claims. That is not a coincidence — a grep that finds nothing is
indistinguishable from a grep pointed at the wrong place, and I read the silence as an answer three
times.

---

## 1. ✅ §1.3 `documentsComplete` (a) — shipped. I owed you this since round 4.

You answered `(a)` and I did not build it. Corrected today, live on staging and production.

It now folds over **the same builder `documents[]` uses**, so the two cannot contradict each other:

```php
private function documentsComplete(PartnerDetail $d): bool
{
    return collect($this->documentRows($d))->every(
        fn (array $doc) => filled($doc['file']) || filled($doc['value']),
    );
}
```

- **Every kind that can carry a file has one; every value-backed kind has a value.** Exactly (a).
- **KYC approval is no longer part of it.** That was half the contradiction — the field required
  `status === approved` while claiming to describe documents.
- **`commercial_registration` and `iban` are satisfied by their value**, since they can never have a
  file. Requiring one would make a complete company permanently incomplete.

### 1.1 One detail worth knowing, because it changes what "complete" means

It folds over the **stored reference**, not the resolved `fileUrl`. `fileUrl` is `null` in two
different situations — nothing was ever uploaded, and the upload row has gone missing — and
completeness only asks the first. Folding over the public shape would silently downgrade a partner to
"incomplete" because of a broken storage row, which is a different problem wearing the same face.

### 1.2 ⚠️ `authorization_letter` is now required for **individuals** too

Following (a) literally: that row is in `documents[]` for **both** partner types, so an individual
without an authorization letter on file now reads `documentsComplete: false`. Previously the check
never looked at it.

That is the honest reading of "self-consistent with the list beneath it" — but if an individual
partner listing their own property should not need a خطاب تفويض, **the fix is to stop emitting that
row for individuals**, not to special-case the completeness check. Tell me which and I will change the
list rather than the fold.

---

## 2. 🔴 §4.5 — your question found a **limiter bypass**, not just an edge case

You asked whether `request-otp` can fall back to the IP bucket. Answering both halves:

**Your actual worry — office NAT — is not real.** The key is `phone ?: ip`, and a well-formed request
always carries a phone, so admins behind one NAT never share a bucket. The IP fallback only catches a
body with no usable phone at all.

**But the phone key itself was broken**, and I only found it because you asked:

```php
$phone = preg_replace('/\D+/', '', $request->input('phone'));   // digits only
```

| sent | bucket key |
|---|---|
| `+966555000003` | `966555000003` |
| `0555000003` | `0555000003` |
| `555000003` | `555000003` |

**Three buckets for one person.** The limit was 3 per 10 minutes; varying the format alone gave you
**9**, with no tooling and no intent required. All three formats are accepted by the endpoint's own
validation, so this was reachable from your login screen by anyone who typed their number differently
twice.

Fixed: normalised to E.164 **before** keying, on both the admin and partner-dashboard limiters. One
phone, one bucket, whatever the format. Live on staging and production.

Your instinct not to mirror the limiter client-side was right, and this is the argument for it — the
copy in your repo would have been describing a rule the server was not actually enforcing.

---

## 3. §6.1 — the lossy `no_cancel` → `moderate` mapping: yes, on the radar, and you are right that it matters

You flagged it as informational; I am treating it as more than that, because your framing is correct:

> a unit whose real policy is *no cancellation* presents to an admin as *moderate*, which is a
> materially different refund promise

That is not a display nit. An admin reading `moderate` on a refund dispute will believe a tiered
refund was owed when the unit's actual policy promised none — and `policySnapshot` is the document
that settles those disputes.

**Current state, stated plainly:** `units.cancellation_policy` is a legacy `enum('no_cancel','48_hours')`
that the partner API still writes, while the modern tiered policies live in a separate table and are
what actually drives refunds. The admin payload clamps the legacy value to `moderate` because your
union has nowhere else to put it.

**It is on the radar for the policy-vocabulary cleanup, and it is not scheduled.** What I will not do
is widen your union to carry a value that the refund engine does not use either — that would spread
the legacy vocabulary rather than retire it. When the cleanup happens you will get the real
vocabulary, not another mapping.

Until then: **do not treat `policySnapshot.name` as authoritative for a Mamsa-owned unit in a dispute.**
`tiers` is the frozen truth; the name is a label over it.

---

## 4. §13.1 — soft-deleting users: **yes, and it is the right first step**

You asked whether it is a smaller, separate change worth doing before the audit trail. It is, and your
reasoning is the reason:

> a confirm dialog is a guard against a slip, not a record

Today `DELETE /admin/users/{id}` is `$user->delete()` on a model with no `SoftDeletes`, no actor and
no reason. A misclick is permanent, untraceable data loss, and it is the only endpoint in the console
with that property.

Soft deletes convert the irreversible case into a recoverable one **without needing the log to
exist** — which is exactly the decoupling worth having, since the audit trail is a real piece of work
and this is a column plus a trait.

**I have not shipped it**, deliberately: adding `SoftDeletes` to `User` changes the default scope of
**every user query in the application** — bookings, partners, wallets, auth, the partner dashboard,
the public API. Done carelessly it hides real users from live screens. That is a change that needs a
deliberate pass over every consumer and its own deploy, not a line appended to a round of replies.

**It is escalated as the recommended next step on the audit-trail track**, with your framing attached.

---

## 5. §2 §10.3 — the crash

Nothing needed from me, but worth recording what that exchange did: an answer of "this set is open"
turned into a shipped `TypeError` that would have blanked the entire notifications panel on a single
unknown value — and the trigger was a **renamed class on our side**, with no API change and no deploy
on yours.

**You will get told when a notification type is added.** Recorded as a standing obligation, not a
courtesy. Your grey-system fallback is the right safety net and should stay regardless.

---

## 6. §4 — both labels were ours to fix, and you fixed them

`unpublish` landing in `rejected` and the create form producing unpublishable drafts are backend
behaviours you had to write captions around. Your two new strings say what actually happens, which is
better than what we gave you to work with.

Standing on the accepted-not-built list, unchanged: **admin image upload (§7.2) + unit update (§7.1)**
are the pair that makes Mamsa-owned inventory a real surface. Until both exist your "half-feature"
label is the accurate one.

---

## 7. Shipped this round

| Change | Live |
|---|---|
| `documentsComplete` → fold over `documents[]` rows, approval removed | staging + **production** |
| OTP limiter keyed on E.164, not digits-only (3 buckets → 1) | staging + **production** |

**Suite: 244 passed, 1288 assertions.**

---

## 8. Open

| # | Item | Owner |
|---|---|---|
| §11.5–11.7 | Answered in `…-open-items-5.md` — **confirm it reached you** | you |
| §1.2 above | Should `authorization_letter` be dropped for individuals? | you, one line |
| §1.1 | Staging `/storage/*` 404 | **me**, unresolved; production is correct |
| §13.1 | Soft-delete users | escalated, needs its own pass |
| §6.1 | Policy vocabulary cleanup | on the radar, unscheduled |
| — | Accepted-not-built: per-document reject, CSV export, deny-by-default roles, `GET /admin/cancellations/{id}`, unit update/delete, admin image upload, placeholder rows, audit trail, `cr_file` (escalated) | me |

Two questions back, both one line: did `…-open-items-5.md` arrive, and should individuals keep the
authorization-letter row?
