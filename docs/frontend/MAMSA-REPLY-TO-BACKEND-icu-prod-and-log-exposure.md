# تنبيه ICU على الإنتاج ✅ · اللوج مش قابل للجلب من المتصفح ✅ · الخصوصية والروابط اتكتبت ✅ · `uid` لسه ⏳

**التاريخ:** 01/10/2026
**لمين:** فريق Next.js — لوحة الشريك ولوحة الأدمن
**المرفقات: ١** — `docs/ops/access-log.md` بعد التحديث، **ملصوق كامل في آخر الملف ده**.

---

## ١. تنبيه ICU — ✅ **على الإنتاج** (تاج `prod-2026-10-01-collation`)

بنفس الخطوات بالظبط:

| الخطوة | النتيجة |
|---|---|
| الاختبارات على فرع الإنتاج نفسه | **840 ناجح**، 1 متخطّي (اختبار الترتيب العربي، لأن Docker عندنا محلياً مافيهوش بيانات `ar`) |
| مقارنة الملفات **قبل** | الإنتاج = التاج اللي قبله (`prod-2026-10-01-accesslog`)، **٣٧٩ ملف، صفر فرق** |
| نسخة احتياطية | قبل ما أي ملف يتغيّر |
| العقد قبل وبعد | **مافيش أي تغيير**: ٢٥٠ مسار، ونفس أشكال الردود العامة |
| مقارنة الملفات **بعد** | الإنتاج = التاج الجديد، **٣٨١ ملف، صفر فرق** |

**ونتيجة `php artisan ops:check-collation` على الإنتاج، زي ما طلعت:**

```
Collator('ar') loads ar (ICU 64.2).
exit=0
```

**ومتسجّل في الجدول:**
```
10   3  * * *  php artisan ops:check-collation --alert  Next Due: 10 hours from now
```

---

## ٢. هل اللوج قابل للجلب من المتصفح؟ — ✅ **لأ، على البيئتين** (نداء حقيقي)

| المسار | api.mamsaa.com | staging.mamsaa.com | فيه محتوى لوج؟ |
|---|---|---|---|
| `/storage/logs/access-2026-10-01.log` | **404** | **403** | لأ |
| `/storage/logs/access.log` | 404 | 403 | لأ |
| `/app_core/storage/logs/access-2026-10-01.log` | 404 | 404 | لأ |
| `/../app_core/storage/logs/access-2026-10-01.log` | 400 | 400 | لأ |
| `/logs/access-2026-10-01.log` | 404 | 404 | لأ |
| `/access-2026-10-01.log` | 404 | 404 | لأ |
| `/storage/logs/laravel.log` | 403 | 403 | لأ |
| `/.env` · `/app_core/.env` | 403 | 403 | لأ |

اتأكدنا من محتوى كل رد إنه مافيهوش ولا سطر لوج، مش بس من الكود اللي رجع.

**وما وقفناش عند الأكواد**، لأن الـ403 مكانش متوقع. **بصّينا على السيرفر نفسه:**
- **الرابط الوحيد** في الـdocroot على البيئتين هو `storage → app_core/storage/app/public`، وده
  الرابط القياسي بتاع Laravel، **ومافيهوش فولدر `logs`**. اللوج في `app_core/storage/logs`، **برّه الـdocroot**.
- لما مشينا ورا كل الروابط تحت `public_html`، لقينا **صفر** ملف `access-*.log` يتوصل له.
- **للتأكد إن الطريق شغّال أصلاً:** طلبنا ملف موجود فعلاً من نفس المسار
  (`/storage/defaults/unit-default.avif`) ورجّع **200** على البيئتين. يعني المسار بيوصل، والملف مش هناك.
- **الـ403 مصدرها فلتر الاستضافة** (`x-rasp-block: 1`) على أي مسار آخره `.log` أو `.env`. **مش
  معتمدين عليه:** الملف ماينفعش يتوصل له حتى لو الفلتر ده اتشال.

**فمكانش فيه حاجة محتاجة تتقفل.** والنتيجة دي مكتوبة في `access-log.md` (قسم الخصوصية).

---

## ٣. الخصوصية — ✅ **اتكتبت** في `docs/ops/access-log.md`

في قسم جديد اسمه «Privacy»:
- الـIP والـuser id **بيانات شخصية**، **وغرضها تشخيص الأعطال بس**، ومافيش أي استخدام تاني.
- **١٤ يوم**، والمسح بيعمله اللوج نفسه، من غير خطوة يدوية حد ممكن ينساها.
- اللي ما بيتسجّلش أصلاً، وإن الملف مش قابل للجلب من الويب (بنتيجة البند ٢)، ومين يقدر يقراه، وإزاي يتقفل في سطر واحد.

---

## ٤. `uid` بقيمة — ⏳ **لسه ما ظهرش**

لحد آخر مرة بصّينا: **٦٦ سطر** على البيئتين، **ومافيش ولا واحد فيهم بمستخدم داخل**. يعني مافيش حاجة
نبعتها لسه، ومش هنقول إنه شغّال قبل ما نشوفه. **أول سطر يظهر هنبعته لكم بالـIP محجوب.**

(جرّبنا ندخل بحساب الشريك الاختباري على staging عشان نولّد سطر بنفسنا، والدخول رجّع 401 بالكود الثابت.
ماكمّلناش في الموضوع ده لأنه خارج المهمة، فالحقل لسه مثبّت باختبار بس.)

---

## ٥. جدول «إزاي تقرا الغياب» — ✅ **مربوط من كل مكان بيتعامل مع بلاغات الأعطال**

| المكان | اللي اتضاف |
|---|---|
| `docs/ops/HOST-NOTES-hostinger-shared.md` | قسم «Web access logs» كان بيقول إن مافيش لوج — **اتحدّث**، ومعاه رابط للجدول: «قبل ما تجاوب على أي بلاغ "الـAPI وقع أو اتأخر"، اقرا الجدول» |
| `docs/UAT-TEST-PLAN.md` §٠ | **عند الإبلاغ عن عطل:** اكتبوا **الوقت UTC بالدقيقة** والمسار، ومن غير الوقت اللوج مش هيقدر يجاوب |
| `backend/DEPLOY.md` — Troubleshooting | «الـAPI ما ردّش» ← اقرا لوج الدقيقة دي مع الجدول |
| `README.md` | قسم «Operations» جديد بيشاور على الجدول |
| تعليق الـmiddleware في الكود | بيشاور على الملف (من النشر اللي فات) |

**وطلب صغير منكم:** لما تبلّغونا عن عطل، **ابعتوا الوقت بالدقيقة UTC**. ده اللي بيخلّي الجدول يشتغل.

---

**مطلوب منكم:** عدّوا المرفقات (**١**).


---
---

# مرفق ١ من ١ — `docs/ops/access-log.md`

# Access log — what it records, and how to read what it doesn't

**Added:** 2026-10-01 · **Code:** `backend/app/Http/Middleware/AccessLog.php`
**Switch:** `ACCESS_LOG_ENABLED=true` in the server `.env` (off by default) · retention `ACCESS_LOG_DAYS` (14)
**File:** `storage/logs/access-YYYY-MM-DD.log` on each server (`~/domains/{api,staging}.mamsaa.com/app_core/`)

## What one line holds — exactly six fields

```json
{"t":"2026-10-01T23:05:12.345Z","ip":"203.0.113.7","path":"/units","status":200,"ms":84,"uid":12}
```

| Field | Meaning |
|---|---|
| `t` | When the response finished, UTC, milliseconds |
| `ip` | Client IP as Laravel resolves it (trusted proxies applied) |
| `path` | Path only, **no query string** |
| `status` | HTTP status sent |
| `ms` | From PHP receiving the request to the response being sent |
| `uid` | Authenticated user id, or `null` |

**Scope approved by the owner for production on 2026-10-01: these six and nothing else.**
No query string (signed document links carry their signature there), no body, no headers.
**Adding any field needs a new approval.**

## Privacy — why we keep IPs, and for how long

**The IP address and the user id are personal data.** We keep them for **one purpose only: diagnosing
faults**. Examples: was a failing request ever received, how long did it take, which account saw it
when a partner reports a problem. Nothing else reads them. No analytics, no profiling, no marketing,
and they're never sent to any third party.

- **Retention is 14 days**, enforced by the log channel itself (`ACCESS_LOG_DAYS`). Older daily files
  are deleted automatically, with no manual step that could be forgotten.
- **Minimised by design:** no query string, body or headers (so no tokens, passwords, OTPs or user
  agent), and the path only.
- **Not reachable from the web.** The files live in `app_core/storage/logs`, outside the docroot.
  Verified by real HTTP requests on both servers on 2026-10-01: `/storage/logs/access-2026-10-01.log`,
  `/storage/logs/access.log`, `/app_core/storage/logs/…`, `/../app_core/…`, `/logs/…` and `/access-….log`
  returned 404, 403 or 400, **none with log content**. On the server, following every symlink, no
  `access-*.log` is reachable under `public_html` (the only link is `storage → storage/app/public`). The
  403s come from Hostinger's filter (`x-rasp-block: 1`); safety doesn't depend on that filter.
- **Who can read them:** only someone with SSH access to the hosting account.
- **Switching it off** is one line: `ACCESS_LOG_ENABLED=false`, then `php artisan config:cache`.

## 🔴 The limit: it only sees requests that reach PHP

The line is written by the application. A request that never reaches PHP leaves **no line at all**.
That includes a connection timeout, a TLS failure, the host's web server or firewall refusing it,
and a PHP process that never started.

<a id="reading-an-absence"></a>
**So read an absence as evidence, not as "nothing happened":**

| What you see for the minute in question | What it means |
|---|---|
| Lines from the failing client, with 5xx or a large `ms` | The app received it and was slow or failed. Look in the code or `laravel.log` |
| No line from that client, **but lines from others in the same minute** | The request died **before** the app: network, host, or web server. Not the code |
| No lines from anyone for that minute | The app received nothing: the host or PHP was down. Check whether `ACCESS_LOG_ENABLED` was on before concluding that |

Example: the 30/09 23:05–23:11 UTC `CONNECT_TIMEOUT`s on staging. Under this log they would have
shown up as the second row.

## ⚠️ Known risk: the host keeps no access log we can read

Hostinger shared hosting gives **no web-server access log on disk**. There's only what hPanel shows.
**This is a property of the platform, not a gap we forgot.** Failures before PHP can be inferred
from absence, as described above, but never *seen*.

**If we ever need to see them** (decision recorded 2026-10-01; nothing is being implemented now):
- **A higher hosting tier, or a VPS**, where the web server's own access and error logs are available. Or
- **A CDN or proxy in front of the API** (e.g. Cloudflare) that logs every connection attempt,
  including the ones that never reach the origin.

Either one goes through an owner decision, because it changes hosting or DNS.

## Related: the daily Arabic-collation check

`ops:check-collation --alert` runs daily at 03:10 Riyadh time on both servers (staging and production
since 2026-10-01; production tag `prod-2026-10-01-collation`). If ICU loads anything
other than `ar` for `Collator('ar')`, it emails the operations recipients. These are the same
recipients as the complaint and licence alerts: `config('complaints.alert_recipients')`, falling
back to active SuperAdmins. The email states the locale that actually came back (for example `root`).
Run it by hand: `php artisan ops:check-collation`.

## Linked from

Wherever a fault report is handled, so the absence table is found when it's needed:
`docs/ops/HOST-NOTES-hostinger-shared.md` (incident questions) · `docs/UAT-TEST-PLAN.md` §0
(reporting a failure) · `backend/DEPLOY.md` (troubleshooting) · `README.md` · the docblock of
`backend/app/Http/Middleware/AccessLog.php`.
