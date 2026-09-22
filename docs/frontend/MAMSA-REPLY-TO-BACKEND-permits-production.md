# تقرير النشر — التصاريح (المراحل ١ و٢ و٣) حيّة على الإنتاج

**التاريخ:** 22/09/2026
**التاج:** `prod-2026-09-22-permits` · **الأساس:** `prod-2026-09-22`

الـ dry-run طلع **بالظبط** زي المتوقع في تعليماتكم، فكمّلنا من غير انتظار.

---

## ١. الـ backfill — الـ dry-run والنتيجة

```
$ php artisan permits:backfill --dry-run
[dry run] permit rows: 3
  group permits     : 0
  standalone permits: 3
  scopes skipped    : 0
  unit rows whose mirror changed (normalisation): 0
```

**مطابق لتوقّعكم: ٣ صفوف unit-scoped، و#٣٥ و#٣٧ عليهم نفس الرقم `50047139`.** فكمّلنا.

```
permit #4  unit:34  number=50045324  lic=NULL                expires=NULL  current  covers 1
permit #5  unit:35  number=50047139  lic=private_hospitality expires=NULL  current  covers 1
permit #6  unit:37  number=50047139  lic=NULL                expires=NULL  current  covers 1

permits: 3 · permit_reminders: 0
units mirroring their permit number: 3/3
expires_at غير فاضي: 0   ← ولا تاريخ اتخمّن
```

`0 normalised` معناها إن الأرقام التلاتة كانت مكتوبة صح من الأول — مفيش مسافات ولا أرقام هندية، فالتطبيع ما غيّرش حاجة. و**كل التواريخ `NULL`**: مفيش سقف على أي وحدة، ومفيش إعلان هدي بسبب النشرة دي. `#35` بقى تصريحها `private_hospitality` من تنظيف الإنتاج، والـbackfill حملها معاه للصف الجديد.

**والاستثناء المسجّل باقٍ كما هو:** `50047139` على صفّين مستقلّين (`#35` منشورة، `#37` مخفية) لحد ما المالك يحسم `#37`. قاعدة التفرّد نفسها لسه ما اتبنتش — هي في المرحلة ٤، والاستثناء هيتنفّذ كقائمة هناك.

## ٢. الأوامر على الإنتاج

```
$ php artisan units:check-licenses
Every building agrees with itself, and every unit with its permit.        exit=0

$ php artisan permits:check-expiry
permit reminders: 0 sent, 0 skipped (renewal pending or already sent)     exit=0
```

`0 sent` هي الإجابة الصح: مفيش ولا تصريح له تاريخ، فمفيش حاجة توصل عتبة. والأمر مجدول **يومياً 09:00** بتوقيت الرياض (`Next Due: 15 hours from now`).

ورسالة `check-licenses` بقت الأطول («…and every unit with its permit») — دي علامة إن المرحلة ١ وصلت الإنتاج فعلاً؛ قبل النشرة كانت بتقول النص القصير.

## ٣. الـ snapshot قبل وبعد

```
قبل:  routes: 227   public shapes: 10
بعد:  routes: 233   public shapes: 10

+ ADDED route: GET  admin/permits
+ ADDED route: GET  admin/permit-renewals
+ ADDED route: POST admin/permit-renewals/{id}/approve
+ ADDED route: POST admin/permit-renewals/{id}/reject
+ ADDED route: GET  units/{id}/permit-renewals
+ ADDED route: POST units/{id}/permit-renewals
```

**٦ مسارات مضافة، ولا مسار اتشال، ولا شكل رد عام اتغيّر.** الأداة بتحذّر إن ده تغيير عقد ولازم يروح للواجهة قبل ما ينزل — وده حصل: العقد كان في تقرير المرحلة ٣ قبل النشر.

الملفان: `~/snap-before-permits.json` و`~/snap-after-permits.json`.

## ٤. حالة الإنتاج بعد النشر

```
/up 200 · /api/v1/units 200 · #34 200 · #35 200 · #37 404 (مخفية من التنظيف، متوقع)
listing: [#35, #34] باسم «ممسى»
تطبيق الضيف: مفيش permitExpiresAt ولا permitStatus ولا tourism_permit_no
blocked-dates: 0 مدى — مفيش سقف لأن مفيش تواريخ
/me · /admin/me · /units → 401
POST /api/v1/partner/units → 401 (المصادقة الأول؛ المسار لسه مقفول وراها)
migrations: 76
```

**وتطابق الملفات:** `342` ملف PHP على الإنتاج، `342` في التاج، **0 فرق** — يعني حالة الإنتاج دلوقتي هي `git checkout prod-2026-09-22-permits` بالظبط.

## ٥. إزاي اتنشرت — وده أول تطبيق للطريقة الجديدة

- **ولا ملف اتعدّل على السيرفر.** الأربع ملفات اللي نسخة الإنتاج منها مختلفة (`AdminPanel/UnitsController.php` و`routes/admin-panel.php` و`routes/dashboard.php` و`routes/console.php`) اتعدّلوا **في git** على `release/production`، وباقي الـ31 اتنسخوا زي ما هم.
- **الملفات اللي اتبعتت = ناتج `git diff --name-only prod-2026-09-22 prod-2026-09-22-permits`** — 35 ملف، مش اختيار بالذاكرة.
- **اللي اتحجز فضل محجوز:** مسار التوسيع على سطح الأدمن **مش في النشرة** — لا مساره ولا دالته (`function apartments` = 0 في UnitsController بتاع الإنتاج). ومسارات الشكاوى **ما اتحقنتش** في ملفات الـroutes (= 0).
- **migration الـ`refunds` مش موجود في الشجرة أصلاً** زي ما طلبتوا — مش متخطّى، **غايب**. الإثبات: `migrate:status` قبل التشغيل عرض تلات pending بس (`000001`, `000002`, `000004`)، و`000003` مش فيهم. فـ`migrate --force` ما كانش يقدر يلاقيه.
- **Backups قبل:** ملفات `~/backup-permits-prod-20260922-140201/before.tgz` (20 ملف موجود) وقاعدة بيانات `~/backup-prod-prepermits-20260922-140201.sql`.

## ٦. الجاي

- **`refunds`**: release لوحده بعد مراجعتكم، وهيتنشر بعد دي — التاج هيبقى على أساس `prod-2026-09-22-permits`.
- **المرحلة ٤** على staging: وضع أ (تصريح لكل شقة)، `permits[]` في `/apartments` و`/submit`، قاعدة التفرّد ومعاها استثناء `50047139`، وأكواد `PERMITS_COUNT_MISMATCH` و`DUPLICATE_PERMIT_NUMBER` و`PERMIT_MODE_MIXED`. **بدأت.**
