# Mamsa — Four answers before you wire the VAT conversion

**From:** backend · **Date:** 2026-08-15
**All four verified against the live servers just now, not from memory.**

**Short answers:**
1. **Production too** — it is live on **both** environments. My two documents contradicted each other and I should have said so at the time (§1).
2. **Flagged, not decided — but no real partner is affected.** The unit belongs to a **test account** (§2).
3. VAT number / CR / address are **yours to supply**; the QR itself is **~1 day** once they arrive — and my recommendation is to keep the invoice page closed rather than ship a placeholder (§3).
4. **Backup confirmed**, hash re-verified, and **proven to contain the deleted rows** (§4).

---

## 1. Where the VAT conversion is deployed

**Both staging and production.** Verified by running the pricing engine on each server:

| Environment | Code | 450 × 2 nights |
|---|---|---|
| `staging.mamsaa.com` | VAT-inclusive present | gross **900** · net 782.61 · vat 117.39 |
| `api.mamsaa.com` (**production**) | VAT-inclusive present | gross **900** · net 782.61 · vat 117.39 |

**So: the second scenario applies — your change affects real guests immediately, and you should ship the
same day.**

### Why you received two contradictory documents

Both were accurate when written, and neither said so:

- The first was written **before** the deploy, when the answer was genuinely "committed, not deployed
  anywhere".
- The second was written **after** the owner said *"go stag and prod"* and the deploy completed.

They were hours apart on the same day, and I did not date-stamp the state or flag that the earlier
document had been superseded. **That is on me** — a status claim without a timestamp is a trap, and this
is the second time a stale premise has cost you a round trip. Going forward every status claim in these
documents carries the environment and the time it was verified.

---

## 2. The 450 SAR unit — flagged, undecided, but harmless

**Honest sequence:** I raised it **twice before deploying** and asked for a decision. No answer came,
the instruction to deploy did, and the deploy proceeded. So it was **not overlooked — but it was not
consciously decided either.** It became a decision by default, which is exactly the outcome you are
right to be wary of.

**However, no real partner loses income.** The unit belongs to a test account:

```
unit #1  شقة مودرن بإطلالة على الواجهة  price=450
owner:   #15  +966555000002  (no email)  roles=Individual
```

`+966555000002` is one of the three demo accounts — not a real person, no email, created for testing.
So **there is nobody to notify**, and your concern about informing the partner does not apply here.

**It still matters as a precedent.** The arithmetic:

| | Old model | New model |
|---|---|---|
| Guest pays | 517.50 | **450.00** |
| Partner share | 441.00 | **383.47** (−13%) |

**For real partners this must never happen silently.** When real listings exist, either they set gross
prices from the start, or every existing price is converted (× 1.15) and the partner is told before the
change takes effect. That is precisely why contract §10.1 insists on locking the model pre-launch.

**Still open:** leave this test unit at 450, or reprice to 517.50? Either is defensible; it is a test
unit, so §10.1 says leave it. Say the word and it changes in one command.

---

## 3. The tax invoice

### 3.1 (a) What is needed from you — exact fields

None of these exist in the codebase; all three are company records:

| Field | Used for | Format |
|---|---|---|
| **VAT registration number** | QR + invoice header | 15 digits, starts and ends with `3` |
| **Commercial Registration (CR)** | invoice header | 10 digits |
| **Company address** | invoice header (ZATCA requires the seller address) | free text, Arabic |

Also worth confirming: the **legal seller name** exactly as registered (the invoice is issued in Mamsa's
name as supplier of record, §1.5), and whether the VAT registration is active as of the first invoice
date.

Once supplied they go into config, not code — no deploy coupling.

### 3.2 (b) QR estimate — ~1 developer-day after the data arrives

The QR itself is small and well-specified: TLV-encode five fields (seller name, VAT number, timestamp,
total incl. VAT, VAT amount), base64 the result, return the string. The frontend renders it as an image
and does nothing else — as you described.

| Piece | Estimate |
|---|---|
| TLV encoder + base64 + unit tests | 0.5 d |
| Invoice endpoint + numbering + bilingual fields | 1.0 d |
| Credit-note variant (refunds) | 0.5 d |
| **Total** | **~2 days**, of which the QR is ~0.5 |

**The estimate is not the constraint — the data is.** The encoder cannot be tested meaningfully against
a fake VAT number, because the whole point of the QR is that a ZATCA reader validates those exact
values.

### 3.3 My recommendation on your question: keep the page closed

You asked whether to open the invoice page with a placeholder QR or keep it locked until the QR ships.

**Keep it closed.** A tax invoice is a legal document; one carrying a placeholder QR is not a valid tax
invoice, and a guest cannot tell the difference. If a customer downloads it, files it, or submits it for
a VAT reclaim, the problem surfaces later and is worse than a missing feature.

**A middle path if you need something now:** show a **booking receipt** — clearly labelled as a receipt
and not a tax invoice — with the gross/net/VAT breakdown that is already live. It is honest, useful, and
does not pretend to a compliance status it lacks. Then swap in the real invoice when the data arrives.

---

## 4. Backup confirmation — verified, not asserted

### 4.1 The file

```
file    : backup-preclean-20260814-143720.sql
created : 2026-08-14 14:37:20   (the purge transaction ran immediately after)
size    : 220K
sha256  : 9441b12de50f27719b97664708989b40e0d331a43b1095765660ee09832940ad
```

The hash above was **re-computed just now** and matches the value reported yesterday, so the file has
not been altered since it was written.

### 4.2 Proof it actually contains the deleted data

A backup that exists is not the same as a backup that is *good*, so rather than trusting the filename I
searched the dump for records that no longer exist in the live database:

| Record | In the backup | Live now |
|---|---|---|
| `superadmin@mamsaa.sa` | ✅ present | 0 |
| `admin@mamsaa.sa` | ✅ present | 0 |
| `individual@mamsaa.sa` | ✅ present | 0 |
| `aboashraf777283@gmail.com` | ✅ present | 0 |
| `m.almuhanna@vego.sa` | ✅ present | 0 |

Every one of those accounts was removed by the purge and every one is recoverable from the dump. That
is the property that matters.

### 4.3 One point of precision

The backup was **taken before any row was deleted** — the file timestamp and the transaction order
confirm it. But to be exact about what was promised: **the hash was computed after the fact, not
before.** The integrity check above (same hash now, contents verified) gives the same assurance
retroactively, but it is not the same as having verified it in the moment, and I would rather say so
than imply otherwise.

Two further backups exist: `backup-prevat-20260814-195442.sql` (`ca4614c9…`, taken immediately before
the VAT deploy) and `backup-preclean2-20260814-194314.sql` (`935fb5c2…`).

---

## 5. Summary

| # | Question | Answer |
|---|---|---|
| 1 | Where is VAT deployed | **Staging AND production** — ship same day. My two docs contradicted; that was my error |
| 2 | The 450 unit | Flagged twice, undecided, deployed by default — but the owner is a **test account**, so nobody to notify. Decision still open |
| 3a | Invoice data | **VAT number (15 digits), CR (10 digits), company address, legal seller name** — all yours to supply |
| 3b | QR estimate | **~0.5 d for the QR, ~2 d for the whole invoice**, starting when the data arrives |
| 3b | Page open or closed | **Closed.** Ship a clearly-labelled *receipt* instead if you need something now |
| 4 | Backup | **Confirmed** — taken before deletion, hash re-verified `9441b12d…`, and proven to contain the deleted rows |
