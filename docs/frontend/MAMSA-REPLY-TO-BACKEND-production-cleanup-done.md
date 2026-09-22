# تقرير التنفيذ — تنظيف الإنتاج تم · وقفل الأسطح القديمة حيّ على الإنتاج

**التاريخ:** 22/09/2026
**رداً على:** الموافقة الصريحة على القسم ٣، والموافقة على نشر `LEGACY_UNIT_WRITES`

**كل الأرقام وقت التنفيذ طابقت الـ dry-run بالظبط. ما وقفناش ولا مرة.**

---

## ١. الـ backup

```
/home/u184390120/backup-prod-preclean-20260922-122146.sql
154,638 بايت · 43 جدول
md5 158860ff6df13ec882d5b1165cc9a5cd   ← اتأكدنا منه تاني قبل التنفيذ مباشرة
```

---

## ٢. الصفوف اللي اتحذفت — حسب الجدول

| الجدول | صفوف | |
|---|---|---|
| `units` | **1** | `#39` |
| `bookings` | **2** | `#112`، `#113` |
| `payments` | **2** | `#34`، `#35` (٥ ريال لكل، Moyasar حقيقي — سجلاتهم عندهم باقية) |
| `wallet_transactions` | **2** | `PAY-2026-000112`، `PAY-2026-000113` |
| `unit_images` | **3** | |
| `unit_features` | **6** | |
| `notifications` | **7** | ٤ خاصة بـ`#39` + ٣ للحسابين المحذوفين |
| `dashboard_uploads` | **39** | التفصيل تحت |
| `personal_access_tokens` | **27** | |
| `refresh_tokens` | **27** | |
| `partner_wallets` | **1** | بتاعة `#15` (رصيد صفر) |
| `partner_details` | **1** | بتاعة `#15` |
| `model_has_roles` | **2** | |
| `users` | **2** | `#15`، `#16` |
| `refunds` · `reviews` · `favorites` · `unit_blocked_dates` | **0** | ماكانش فيه أصلاً |
| **ملفات من القرص** | **33** | |

## ٣. الـ 39 رفعة — حسب صاحبها

| صاحبها | عدد |
|---|---|
| `#14` (مشرف تجريبي) | **20** |
| `#15` (حساب اختبار — اتحذف) | **16** |
| `#19` | **2** — ملفا 16/09 و19/09 |
| `#20` | **1** — `Mamsa_SRS_v1.0.pdf` المرفوع كـ`license_pdf` |
| **الإجمالي** | **39** |

**ملاحظة على `#39`:** طلبتوا التقسيم يشمله، وهو **وحدة مش صاحب رفعات**. صوره التلاتة **ماكانش ليها صفوف في `dashboard_uploads` أصلاً** (اتكتبت مباشرة في `unit_images`) — فبايتاتها اتحذفت ضمن الـ33 ملف، من غير ما يبقى ليها صف تتحسب فيه. عشان كده الإجمالي ٣٩ موزّع على أربع حسابات مش خمسة.

**بعد التنفيذ: عدد الرفعات اليتيمة على الإنتاج = صفر.**

## ٤. `#35` و`#37`

```
GET /admin/units/35                      GET /admin/units/37
  status              approved             status              approved
  licenseType         private_hospitality  licenseType         null
  licensedUnitsCount  null                 licensedUnitsCount  null
  groupSize           1                    groupSize           1
  tourismPermitNo     50047139             tourismPermitNo     50047139
  mamsaOwned          true                 mamsaOwned          true
  partnerId / Name    23 / ممسى            partnerId / Name    23 / ممسى
  permitFileUrl       رابط /documents موقّع  permitFileUrl       رابط /documents موقّع
  images              6                    images              6
```

**الاعتماد ما اتغيّرش في الاتنين** — زي ما طلبتوا بالظبط. و`#37` بقت `status = unavailable`:

```
GET /api/v1/units/35 → 200      GET /api/v1/units/37 → 404
قائمة المتجر: [#35, #34]  (#37 مش فيها)
```

## ٥. `units:check-licenses`

```
Every building agrees with itself.
exit=0
```
(ده نص الإنتاج — الرسالة الأطول «…and every unit with its permit» جاية مع المرحلة ١، وهي على staging بس.)

## ٦. حالة الإنتاج بعد التنظيف

```
units 3 (#34 #35 #37)   bookings 0   payments 0   dashboard_uploads 22
users 6: #14 #19 #20 #21 #22 #23      personal_access_tokens 4   refresh_tokens 10
```
`#14` و`#19` و`#20` و`#21` و`#22` و`#23` ما اتلمسوش — زي ما هو مكتوب في قسم ٣.٣ بتاعكم.

**بند واحد فاضل ومش في نطاق الموافقة:** صفّان في `wallet_transactions` للمستخدم `#20` من 19/08 (`PAY-2026-000111` و`REF-2026-000004`) بـ`booking_id = NULL` — بتاعين حجز اتحذف قبل كده. **سايبينهم**؛ قولوا لو تحبوا يتشالوا في جولة جاية.

---

## ٧. قفل `LEGACY_UNIT_WRITES` على الإنتاج — حيّ

### الـ snapshot قبل وبعد

```
$ php artisan api:snapshot --out=snap-before-retire.json     → routes: 227  public shapes: 10
   … النشر …
$ php artisan api:snapshot --out=snap-after-retire.json      → routes: 227  public shapes: 10
$ php artisan api:snapshot --diff=before --against=after
✓ No contract change: routes and public response shapes are identical.
```
الملفان على السيرفر: `~/snap-before-retire.json` و`~/snap-after-retire.json`.

**«مفيش تغيير في العقد» هي النتيجة الصح هنا**، ومش معناها إن مفيش حاجة اتعملت: المسارات لسه **مسجّلة** (عشان ترد `410` وتتسجّل بدل ما ترد `404`)، واللي اتغيّر هو الـ middleware عليها — وده مش حاجة الـ snapshot بيقيسها. الدليل على القفل نفسه تحت.

### اللي اتنشر — ٥ ملفات، وبإيد

`routes/api.php` و`config/units.php` **ما اتنسخوش من الريبو**: نسخة الإنتاج من `routes/api.php` **ما فيهاش مسارات الشكاوى** (الفرع بس)، فالتعديل اتطبّق على نسخة الإنتاج نفسها واتأكدنا إن الشكاوى ما اتحقنتش. الملفات: `RetiredEndpoint.php` (جديد)، `bootstrap/app.php`، `config/logging.php`، `routes/api.php`، `config/units.php`.

⚠️ **والمرحلة ١ ما اتنشرتش**: `PermitWriter` وجدول `permits` وتعديلات الـ controllers **لسه على staging بس**. اتأكدنا إن الخمس ملفات دي ما بتعتمدش عليها (الكلمة «permits» فيها في التعليقات بس).

### الدليل على الإنتاج

**١٦ مسار** بيحملوا الـ middleware — لا واحد زيادة ولا واحد ناقص:

```
POST   api/v1/partner/units                         PUT    api/v1/partner/units/{unit}
DELETE api/v1/partner/units/{unit}                  POST   api/v1/partner/units/{unit}/submit
POST   api/v1/partner/units/{unit}/apartments       PUT    api/v1/partner/units/{unit}/calendar
POST   api/v1/partner/units/{unit}/blocked-dates    DELETE api/v1/partner/units/{unit}/blocked-dates/{block}
POST   api/v1/partner/units/{unit}/images           DELETE api/v1/partner/units/{unit}/images/{image}
POST   api/v1/partner/units/{unit}/images/{image}/main
POST   api/v1/partner/units/{unit}/documents        DELETE api/v1/partner/units/{unit}/documents/{type}
POST   api/v1/admin/requests/{unit}/approve         POST   api/v1/admin/requests/{unit}/reject
PATCH  api/v1/admin/units/{unit}/featured
```

**والقراءة على نفس البادئة مش مقفولة:** `GET units` · `GET units/{unit}` · `GET units/{unit}/calendar`.

`config('units.legacy_unit_writes') = false`، و`LEGACY_UNIT_WRITES` **مش موجود في `.env`** — يعني القفل بالقيمة الافتراضية. الرجوع = إضافة السطر + `config:cache`.

**الصحة بعد النشر:** `/up` 200 · `/api/v1/units` 200 · `/api/v1/units/34` و`/35` 200 · `/me` و`/admin/me` و`/units` 401 · قائمة المتجر: `#35` و`#34` باسم «ممسى».

**سلوك `410` نفسه** اتثبت end-to-end على staging بـ token حقيقي (في تقرير الأسطح القديمة). على الإنتاج، حسابات الشركاء الحقيقية بتدخل بـ OTP برسالة حقيقية ومفيش وضع اختبار، فما نقدرش نعمل نداء مصادَق من هنا — ومش هنعمل token لحساب شخص حقيقي عشان نجرّب. الدليل هو ربط الـ middleware فوق، وهو مصدر الحقيقة نفسه.

---

## ٨. الجاي

**المرحلة ٢** على staging — السقف والتقويم والبحث والحالة المحسوبة والـ allocator. التقرير بنفس الشكل.
