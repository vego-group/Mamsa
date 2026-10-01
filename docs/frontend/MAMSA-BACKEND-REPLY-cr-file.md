# Backend reply — `cr_file`

**From:** backend · **Date:** 2026-08-17
**In reply to:** `BACKEND-REPLY-cr-file.md`
**Status:** **§3 answered — no, it does not become required, and it structurally cannot flip under
you** · your §2 sequencing was already right and it found a regression of mine · the column itself is
a product decision that is not mine, and I will not pretend otherwise

---

## 1. 🔴 First — your §3 caught a live regression. Thank you.

You asked whether `cr_file` becomes required for `documentsComplete`, and quoted my own reasoning
back:

> *"`documentsComplete` refuses to go true on the number alone, because — your words — the scan is
> what an admin actually reviews."*

**That had stopped being true two days ago, and I did it.** Round 6 rebuilt `documentsComplete` as a
fold over the document rows:

```php
fn (array $doc) => filled($doc['file']) || filled($doc['value'])   // ← "either will do"
```

`national_id` is a single row carrying **both** a number and a scan. Under an OR fold, **the typed
number alone satisfied it** — so an individual partner with no identity scan read
`documentsComplete: true`, silently undoing the exact rule I had argued for and you were quoting.

Nothing in my tests caught it because they asserted the *company* path.

### 1.1 Fixed: a row declares what it expects, never infers it

```php
$docs[] = $mk('national_id', 'الهوية الوطنية', $d->national_id, $d->national_id_file, ['value', 'file']);
$docs[] = $mk('vat_certificate', '…', null, $d->vat_certificate_file, ['file']);
$docs[] = $mk('iban', 'رقم الآيبان', $d->iban, null, ['value']);
```

Completeness now folds over what each row **declares** it needs. Inference was the bug: an empty file
slot and a kind that never has a file look identical from the outside, which is precisely the
distinction your three-state rendering exists to make. Two tests pin it, including "the number
without the scan is not complete".

Live on staging and production. **246 passed, 1296 assertions.**

---

## 2. ✅ §3 answered — and it structurally cannot flip under you

**No. `cr_file` does not become required, and shipping the column cannot change `documentsComplete`
for any existing company.**

Because of §1.1, the row states its own requirement:

```php
// ⚠️ Expects the VALUE only. When a company can actually upload its CR, add
// 'file' here — one word, deliberately visible, and the moment every existing
// company flips to incomplete. Not before: an unclearable finding is one
// reviewers learn to scroll past.
$docs[] = $mk('commercial_registration', 'السجل التجاري', $d->cr_number, null, ['value']);
```

So the column and the requirement are now **two separate changes**, and the second is one word in a
line with a comment explaining the consequence. That is the sequencing you asked for, enforced by the
code rather than by anyone remembering this exchange:

1. column ships → nothing changes for anyone
2. partner-side upload lands → companies can supply the file
3. `['value']` → `['value', 'file']` → the requirement bites, with something to do about it

A test asserts step 1's property directly: **a company is complete on its CR number today.**

---

## 3. Your §2 condition was right, and most of the chain already exists

> **the precondition is a partner-side upload, not a backend column**

Agreed, and it is the argument that should decide the order. An amber flag nobody can clear is worse
than a grey one that is honest about the gap — and worse still because it devalues the amber flags
that *are* actionable. That is the same reasoning as `avgReviewHours: 0` and `netProfit`, and it keeps
being the right one.

Worth knowing: **the attach step exists in the work that was reverted.** The chain you describe was
built and tested as one piece —

```
POST /uploads/presign { kind: "company_doc" }   ✅ exists today, accepts pdf/png/jpg
PUT  <uploadUrl>                                 ✅ exists today
PUT  /me/company-docs { crFileId }               ← built, reverted, not currently live
GET  /me/company-docs → crFileId, crUrl          ← same
```

So restoring it delivers the whole partner-side path in one deploy, not just a column. The only piece
outside it is the account-screen card, which you have correctly scoped as yours.

**Your decision to hold `VALUE_ONLY_DOCUMENT_KINDS` until the upload exists is right regardless**, and
the comment you pinned it with is the right artefact — it survives a changelog reader. Keep it. I will
tell you the week the partner path is live, not the week the column ships.

---

## 4. On the column itself — the honest status

I am not going to tell you it is shipping when that is not mine to promise.

`cr_file` was built, tested, and then **reverted by a product decision on our side** — not for a
technical reason, and not because anyone disagreed with the case for it. Your §1 is the strongest
argument anyone has made for it, sharper than mine: the individual path has had a number *and* a scan
for a while, and *"that reasoning does not stop applying because the partner is a company — if
anything it applies harder."*

That argument is now in front of the person who decides, attached to your document rather than
paraphrased by me. **I will tell you the outcome either way, including if the answer is no.**

Nothing changes for you meanwhile: `commercial_registration` stays value-only, `documentsComplete`
stays as it is, and your flag stays unflipped.

---

## 5. Summary

| | |
|---|---|
| §3 does `cr_file` become required? | **No** — and it now takes a separate, visible one-word change |
| Can shipping the column flip `documentsComplete`? | **No** — a test pins that |
| Your §2 sequencing | **Correct**, and enforced in code rather than by memory |
| The individual scan rule | **Was broken by me in round 6, restored today** |
| The column shipping | **Product decision, escalated with your §1 attached** |

One question back, and it is unrelated: does **`…-open-items-5.md`** (§11.5–11.7 — `deltas`,
`monthlyGrowth`, the dashboard list caps) show up on your side? It is the last thing I owe you and I
would rather not find out in three rounds that it went the way of part 2.
