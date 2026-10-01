# لوج الوصول: **اتعمل** — على staging والإنتاج · تنبيه ICU: على staging، والإنتاج مستني موافقة

**التاريخ:** 01/10/2026
**لمين:** فريق Next.js — لوحة الشريك ولوحة الأدمن
**المرفقات: ١** — `docs/ops/access-log.md` (ملف العمليات اللي طلبتوه في البند ٢)، **ملصوق كامل في آخر الملف ده**.

---

## ١. لوج الوصول — ✅ **اتعمل، على staging وعلى الإنتاج**

ما كتبناش «اتعمل» غير بعد ما شفنا السطور مكتوبة فعلاً على كل سيرفر:

**staging** (11:09 UTC) — تلات طلبات حقيقية، تلات سطور:
```json
{"t":"2026-10-01T11:09:06.559Z","ip":"197.32.167.71","path":"/api/v1/config","status":200,"ms":30,"uid":null}
{"t":"2026-10-01T11:09:07.333Z","ip":"197.32.167.71","path":"/config","status":200,"ms":12,"uid":null}
{"t":"2026-10-01T11:09:08.101Z","ip":"197.32.167.71","path":"/api/v1/units/999999999","status":404,"ms":17,"uid":null}
```

**الإنتاج** (11:17 UTC، تاج `prod-2026-10-01-accesslog`):
```json
{"t":"2026-10-01T11:17:34.035Z","ip":"197.32.167.71","path":"/api/v1/units","status":200,"ms":29,"uid":null}
{"t":"2026-10-01T11:17:35.848Z","ip":"197.32.167.71","path":"/api/v1/units/999999999","status":404,"ms":9,"uid":null}
```

**بالنطاق اللي وافقتوا عليه بالظبط، والحقول ستة بس:** `t` · `ip` · `path` · `status` · `ms` · `uid`.
⚠️ **ملاحظة صريحة:** المثال اللي في ردنا اللي فات كان فيه كمان `m` (الـmethod) و`route` و`ua`
(الـuser agent). موافقتكم كانت على الحقول الستة بالاسم، **فشلنا التلاتة الزيادة قبل النشر**. لو
احتجنا أي واحد منهم بعدين، هنطلب موافقة.

**اتأكدنا من:**
- **الـquery string ما بيتسجّلش:** بعتنا `?probe=should-not-appear` على البيئتين، ولقينا **صفر** تطابق في اللوج.
- **الرد ما اتغيّرش:** على الإنتاج نفس عدد المسارات (٢٥٠) ونفس أشكال الردود العامة قبل وبعد،
  وبودي ٦ ردود عامة **متطابقة بالبايت** قبل وبعد النشر.
- **الإنتاج = التاج**، ملف بملف (٣٧٩ ملف).
- **مسح بعد ١٤ يوم**، ملف لكل يوم: `storage/logs/access-YYYY-MM-DD.log`.

**حاجة واحدة لسه ما شفناهاش في ترافيك حقيقي: `uid` بقيمة.** لحد دلوقتي كل الطلبات اللي جت كانت من غير
تسجيل دخول. الحقل مثبّت باختبار بيدخّل مستخدم وبيتأكد إن الـid بتاعه اتكتب، بس **أول ما يجي طلب
بمستخدم داخل هنبص على السطر بتاعه بنفسنا.**

---

## ٢. الحد والخطر المعروف — ✅ **اتكتبوا** في `docs/ops/access-log.md`

الاتنين مكتوبين دلوقتي في ملف العمليات، **وملصوق كامل تحت**:

- **إزاي تقرا الغياب:** جدول من تلات حالات. الأهم فيها: مافيش سطر من العميل ده، **بس فيه سطور من
  غيره في نفس الدقيقة** — يبقى الطلب مات **قبل** التطبيق (شبكة أو استضافة)، مش في الكود. ومكتوب
  صراحة إن الغياب **دليل، مش «مفيش مشكلة»**.
- **الخطر المعروف:** Hostinger shared مابيديناش access log على الديسك. **ده قيد على المنصة، مش
  حاجة نسيناها.** ولو احتجنا نشوف اللي بيقع قبل PHP: خطة أعلى أو VPS، أو CDN/proxy (زي Cloudflare)
  قدام الـAPI يسجّل كل محاولة اتصال. **مافيش تنفيذ دلوقتي**؛ القرار مكتوب، وأي واحد من الخيارين
  محتاج قرار من المالك لأنه بيغيّر الاستضافة أو الـDNS.

مربوط بالكود كمان: تعليق الـmiddleware نفسه بيشاور على الملف، فاللي يفتح الكود يلاقي الشرح.

---

## ٣. تنبيه ICU — ✅ **على staging** · ⏸ **الإنتاج مستني موافقة صريحة**

`ops:check-collation --alert` — يومياً **03:10 بتوقيت الرياض**. لو `Collator('ar')` رجّع أي حاجة غير
`ar`، بيبعت إيميل لمستلمين تنبيهات العمليات (نفس قناة تنبيهات الشكاوى والتراخيص).

**الإيميل بيقول القيمة اللي رجعت فعلاً**، زي ما طلبتوا:

> **العنوان:** ⚠ ICU رجّع "root" بدل "ar" — ترتيب الأبواب مختلف عن الواجهة
> السيرفر: … · القيمة اللي رجعت من Collator('ar'): **root** — المتوقع: **ar** · نسخة ICU و PHP
> الأثر · السبب الغالب · **العلاج: رجّعوا امتداد intl بـICU كامل، وبعدين اتأكدوا بالأمر `php artisan ops:check-collation`**

**على staging:** الأمر اتشغّل بإيدنا ورجّع `Collator('ar') loads ar (ICU 64.2)`، ومتسجّل في الجدول.
**الاختبار بيثبت إن الإيميل فيه `"root"` و`المتوقع: ar`**؛ لو اتغيّر لنص عام زي «فيه مشكلة»، بيقع.

**ليه مش على الإنتاج:** موافقتكم المكتوبة على الإنتاج كانت على **لوج الوصول**. على تنبيه ICU قلتوا
إن الفحص والإيميل «كفاية»، ودي موافقة على التصميم، **مش على النشر على الإنتاج**. والقاعدة عندنا إن
أي نشر على الإنتاج محتاج موافقة صريحة، فشلناه من الـrelease. **لو موافقين، قولوا «انشروا ICU على
الإنتاج»** وهينزل بنفس الخطوات: تاج، ومقارنة الملفات قبل وبعد، وتشغيل الأمر هناك.

---

## ٤. من ناحيتكم

حارس ICU عندكم (Node 24.11.1، ICU 77.1، مع اختبار عربي جنب لاتيني اتجرّب إنه يقع) — **كده
الطرفين متغطيين**. الباك اند فيه دلوقتي إيميل يومي، والواجهة فيها اختبار بيقع.

**مطلوب منكم:** عدّوا المرفقات (**١**)، وردّوا على سؤال نشر ICU على الإنتاج.


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

## 🔴 The limit: it only sees requests that reach PHP

The line is written by the application. A request that never reaches PHP leaves **no line at all**.
That includes a connection timeout, a TLS failure, the host's web server or firewall refusing it,
and a PHP process that never started.

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

`ops:check-collation --alert` runs daily at 03:10 Riyadh time on both servers. If ICU loads anything
other than `ar` for `Collator('ar')`, it emails the operations recipients. These are the same
recipients as the complaint and licence alerts: `config('complaints.alert_recipients')`, falling
back to active SuperAdmins. The email states the locale that actually came back (for example `root`).
Run it by hand: `php artisan ops:check-collation`.
