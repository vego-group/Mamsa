# `description` — the form you asked for, plus one thing you didn't find

**From:** backend · **Date:** 2026-08-26 · **Re:** `BACKEND-REQUEST-description-formatting.md`
**Status:** ✅ live on **staging**, verified over the wire. **Not yet on production.**

Your form first, since that's what unblocks you. The detail is below it, and §3 is the part I'd
actually read — you were right to ask about `strip_tags`, and it was worse than you thought.

```text
1. تخزين \n كما هي:            نعم — على المسارات الثلاثة، مؤكَّد باختبارات
2. الحدّ الحالي على /admin/units:  كان 500  وعلى /units (الشريك): كان 500 — نفس القاعدة المشتركة
3. رُفع إلى 2000:                نعم — staging اليوم 2026-08-26، الإنتاج بانتظار موافقة المالك
4. العدّ بـ mb_strlen:            نعم — كان كذلك من البداية
5. الحدّ الأدنى ما زال 10:         نعم — لكنه شرط "تقديم" لا شرط "حفظ" (§5)
6. أي htmlspecialchars/strip_tags: htmlspecialchars لا — strip_tags نعم، وقد أُزيل (§3)
7. GET /units/{id} يُرجع النصّ كما خُزّن: نعم
8. مسح حقل اختياري:              null — و "" أيضاً تعمل (§5)
```

---

## 1. Newlines — they were already safe

Confirmed, and now pinned by tests rather than by my reading of the code. No `trim` beyond
Laravel's own edge-trimming on request input, no whitespace collapsing, no `\n` → `<br>`, no
sanitising middleware on the field.

`units.description` is a **`TEXT`** column, not `VARCHAR(500)` — so the 500 was only ever a
validation rule, never a storage constraint, and nothing has been truncated in the database.

Verified on staging over real HTTP, with your own example plus a `<=` thrown in:

```
GET https://staging.mamsaa.com/api/v1/units/2

## ما يميّز المكان
*مسبح خاص*

## المساحات
- **غرفة النوم:** سرير كينج.
المساحة <= 100 متر
> تسجيل الدخول بعد 3 عصراً.

newlines preserved : 6
note marker intact : True
<= survived        : True
no &gt; escaping   : True
```

Your edge-only `trim()` in `toCreateBody` is compatible — Laravel trims request strings at the
edges too, so you and the server agree on what a leading blank line means.

---

## 2. The limit — 2000, and you were right about where 500 came from

You were right, and the record backs you up: 500 was your guess in
`BACKEND-REQUEST-mamsa-owned-units.md` §8.4, you asked for confirmation, and **I never answered that
point.** It then got written into the shared writer as though it were a rule. That's on me.

Now:

```php
UnitWriter::MIN_DESCRIPTION = 10;      // submit-time gate
UnitWriter::MAX_DESCRIPTION = 2000;    // characters
```

**Both consoles were on the same 500** — admin and partner run through one shared `UnitWriter`, so
there was never a mismatch between them, and there isn't one now.

**Characters, not bytes — and it always was.** Laravel sizes strings with `mb_strlen`, so 2000 means
2000 characters. Your worry about `strlen()` giving ~666 Arabic characters was well-placed but
didn't apply here. Test: 2000 Arabic characters accepted, 2001 refused.

### A third surface you didn't know about

There's an older partner route, `POST|PUT /api/v1/partner/units`, which the Vue bench uses. Its
`description` rule was `nullable|string` — **no limit at all**. Left alone, a description written
there could be one the partner dashboard and admin console then refuse to save back, stranding the
unit on whichever screen wrote it. It's now on the same 2000.

---

## 3. `strip_tags` — this is the part worth reading

There was no `htmlspecialchars` and no `<br>` conversion; you were right to check, and both answers
are no. But `description` **did** pass through `strip_tags()` on save, on both consoles.

I assumed that was harmless for your markup, since none of your markers are `<`. That assumption was
wrong, and here is the actual behaviour:

```php
"شروط <= ثلاثة\n> ملاحظة مهمة"    →  "شروط  ملاحظة مهمة"
"المساحة <100 متر مربع"           →  "المساحة "
"أقل من <b متر\n> ملاحظة مهمة"    →  "أقل من  ملاحظة مهمة"
```

`strip_tags()` opens "tag mode" on a `<` followed by anything other than a space, and deletes
everything through to the next `>`. **Your note marker is `>`.** So a single comparison operator in
a description swallowed the line break *and* the note marker after it — merging two blocks into one
line and destroying the marker that made the second one a note.

Exactly the failure mode you described for `htmlspecialchars` in §3: **the damage was in the column,
not the render.** A description saved that way was already gone by the time anyone looked at it.

And it was buying nothing. `strip_tags` leaves the payload:

```php
"الوصف <script>alert(1)</script> تم"  →  "الوصف alert(1) تم"
```

It removed the part that made the string inert to *read* and kept the part that mattered. Real
safety is escaping at render, which every consumer already does — your three apps build text nodes,
the Vue bench uses `{{ }}`, and nothing in the backend renders `description` into HTML (no Blade
template touches it, and there is no unescaped output anywhere in the app; I checked).

**`strip_tags` is gone from `description`.** Stored verbatim, byte for byte.

### Same class of risk, still present, not in your scope

`name`, `district` and `address` still go through `strip_tags` on the same paths. Far less likely to
contain a `<`, and none of them carry markup — but it's the same silent-deletion behaviour, so say
the word if you want them cleared too and I'll do all three together.

---

## 4. Backwards compatibility

Confirmed — nothing to migrate. An old unformatted description parses as one paragraph and renders
as it always did. No new column, no `format` flag.

Existing descriptions **have not** been retroactively damaged by `strip_tags` in any way we can
detect: the two units on production have plain prose with no `<`. Anything that *was* damaged is
unrecoverable, but there is no evidence of it.

---

## 5. Clearing an optional field — `null`, and `""` works too

Both spellings clear `description`, `address` and `tourismLicenseNumber`:

```jsonc
{ "description": null }     // clears
{ "description": "" }       // also clears
```

`""` works because Laravel's `ConvertEmptyStringsToNull` runs before validation, so it arrives as
`null` either way. Send whichever your form produces — no special case needed. Both are now covered
by tests so this can't quietly change.

An **absent** key still means "unchanged", as before.

### Your sub-question: does the 10-character minimum make description mandatory forever?

**No.** The minimum is a **submit** gate, not a save rule:

- `PATCH` accepts `null` at any time — a draft can hold no description at all.
- `POST /units/{id}/submit` refuses a unit whose description is under 10 characters.

So an admin can clear it while editing; they just can't send it back for review empty. That's the
same gate that requires photos and a permit.

### On §5's other half — the wasted PATCH

Your fix to `toPatchBody` is the right one, and it matters more than it looks: on an **approved**
unit any successful PATCH returns it to `pending_review` and takes it off the public site. A
no-op save there costs a real review cycle. Good catch.

---

## 6. All three surfaces

Checked individually rather than assumed, since you specifically asked:

| surface | route | limit | `\n` kept | `strip_tags` |
|---|---|---|---|---|
| admin console | `POST`/`PATCH`/`GET /admin/units` | 2000 | ✅ | removed |
| partner dashboard | `POST`/`PATCH /units` | 2000 | ✅ | removed |
| partner (v1, Vue bench) | `POST`/`PUT /api/v1/partner/units` | 2000 *(was unlimited)* | ✅ | never had it |
| guest read | `GET /units`, `/units/{id}`, `/units/popular` | — | ✅ | — |

The admin and partner dashboards share one `UnitWriter`, which is why they could never drift apart.
The read paths (`UnitResource`, both `UnitPresenter`s) all pass `description` through untouched —
no truncation, no escaping, no transformation.

---

## 7. Verified

```
staging, over real HTTP — formatted description round-tripped identically
  118 characters / 192 bytes, 6 newlines, note marker and <= intact

backend suite: 330 passed, 1597 assertions
  21 new tests on description alone:
    byte-exact round trip on admin / partner / public read / admin read
    interior blank lines and leading indentation
    every marker character: # ## * ** - > » • – —
    five angle-bracket cases that used to delete text
    2000 Arabic characters accepted, 2001 refused, on both consoles
    null and "" both clear; absent key does not
    the 10-character minimum gates submit, not save
```

---

## 8. Summary against your table

| # | request | status |
|---|---|---|
| 1 | `\n` stored and returned unchanged, all paths | ✅ confirmed + tested |
| 2 | limit raised to 2000, `mb_strlen` | ✅ done (mb_strlen already correct) |
| 3 | no HTML conversion or escaping | ✅ `htmlspecialchars` never existed; **`strip_tags` found and removed** |
| 4 | public read returns stored bytes | ✅ confirmed |

**One thing to decide:** this is on staging, not production. It's a small, additive change — a
higher limit and one removed call — but it touches the live partner write path, so say when and
I'll deploy. Raising the limit is safe in isolation; the `strip_tags` removal is what makes
formatted descriptions actually survive, so they should go together.
