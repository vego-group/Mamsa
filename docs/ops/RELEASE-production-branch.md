# نشر الإنتاج من git — الطريقة من 22/09/2026 وطالع

## المشكلة اللي بتحلها

الإنتاج كان بيتنشر بنسخ ملفات مختارة بالإيد، ومرتين اضطرينا نعدّل ملف على السيرفر
بدل ما ننسخه: `routes/api.php` (الفرع فيه مسارات شكاوى الإنتاج ما عندهوش
controllers ليها) و`config/units.php`. النتيجة إن حالة الإنتاج ماكانتش تتبني من
أي فرع — كانت تتقري من السيرفر بس. ومنها طلع خلل حقيقي: `BookingPaymentSettler`
اتنشر وهو بيكتب أعمدة في `refunds` جاية مع شغل الشكاوى اللي ما اتنشرش.

## الطريقة

**فرع `release/production` هو الإنتاج.** `backend/{app,config,routes,database,bootstrap}`
فيه مطابق بايت-ببايت للسيرفر. أول لقطة: تاج `prod-2026-09-22` (327 ملف PHP).

### النشر

```bash
# 1. جهّز التغيير على release/production — cherry-pick من فرع الميزة
git checkout release/production
git cherry-pick <sha>            # أو عدّل بالإيد هنا لو الملف مختلف على الإنتاج
#    الفرق عن الأول: التعديل بالإيد بيحصل في git مش على السيرفر

# 2. شوف بالظبط إيه اللي هيتغير
git diff --stat prod-<آخر تاج> HEAD -- backend/

# 3. لقطة العقد قبل
ssh mamsa 'cd ~/domains/api.mamsaa.com/app_core && php84 artisan api:snapshot --out=~/snap-before.json'

# 4. ابعت الملفات اللي اتغيرت بس
git diff --name-only prod-<آخر تاج> HEAD -- backend/ | sed 's|^backend/||' > /tmp/changed.txt
tar czf /tmp/deploy.tgz -C backend -T /tmp/changed.txt
scp /tmp/deploy.tgz mamsa:~/domains/api.mamsaa.com/app_core/
ssh mamsa 'cd ~/domains/api.mamsaa.com/app_core && tar czf ~/backup-$(date +%Y%m%d-%H%M%S).tgz -T /tmp/changed.txt 2>/dev/null; tar xzf deploy.tgz && rm deploy.tgz'

# 5. migrate + caches + خطوة الصفر
ssh mamsa '… php84 artisan migrate --force && config:cache && route:cache && route:list --name=<الجديد>'

# 6. لقطة بعد + diff، وتأكيد التطابق
ssh mamsa '… api:snapshot --out=~/snap-after.json && api:snapshot --diff=~/snap-before.json --against=~/snap-after.json'

# 7. تاج جديد — الإنتاج بقى هو ده
git tag -a prod-$(date +%Y-%m-%d) -m "…"; git push origin release/production --tags
```

### التحقق الدوري — الإنتاج لسه == الفرع؟

```bash
# محلياً على release/production
cd backend && find app config routes database/migrations database/seeders bootstrap \
  -name '*.php' -type f | sort | xargs md5sum > /tmp/branch.md5
ssh mamsa 'cd ~/domains/api.mamsaa.com/app_core && find app config routes database/migrations \
  database/seeders bootstrap -name "*.php" -type f | sort | xargs md5sum' > /tmp/prod.md5
diff <(sort /tmp/branch.md5) <(sort /tmp/prod.md5 | grep -v bootstrap/cache)
```
**فرق = حد عدّل على السيرفر، أو نشرة ما اتسجلتش.** يتشغّل قبل كل نشرة.

## القواعد

1. **مفيش تعديل ملفات على السيرفر.** أي اختلاف بين الفرع والإنتاج بيتصلح على
   `release/production` الأول.
2. **الميزة اللي مش متفق على نشرها ما تدخلش الفرع ده.** الشكاوى والاستردادات
   ومرحلة ١ للتصاريح مش عليه، وعشان كده `routes/api.php` بتاعه ما فيهوش مسارات
   شكاوى — مفيش حاجة تتشال بالإيد وقت النشر.
3. **الـ migrations بتتنشر مع الكود اللي بيحتاجها، في نفس النشرة.** الخلل بتاع
   `refunds` حصل لأن الكود سبق الأعمدة بتاعته.
4. 🔴 **قبل أي نشرة: افحص كل ملف داخل فيها على الأعمدة والجداول اللي الإنتاج ما
   عندهوش — مش على الكلاسات بس.** القائمة بتتبني من `migrate:status` بتاع الإنتاج
   نفسه: أي migration مش متشغّلة = أعمدة وجداول ممنوع أي كود منشور يلمسها.

   ```bash
   ssh mamsa 'cd ~/domains/api.mamsaa.com/app_core && php84 artisan migrate:status' | grep -i pending
   # لكل واحدة، استخرج الأعمدة/الجداول اللي بتضيفها، وبعدين افحص الشجرة اللي هتتنشر:
   grep -rn "<column_or_table>" backend/app backend/routes --include=*.php
   ```

   **حصل فعلاً (22/09/2026):** نشرة التصاريح شحنت `BookingController.php` من الفرع
   وهو بيكتب `bookings.hold_expires_at` من migration ما اتنشرتش — فكل حجز ضيف كان
   هيفشل عند الـINSERT. الفحص وقتها كان على الكلاسات والمزايا المحجوزة مش على
   الأعمدة، رغم إن تقرير المطابقة نفس اليوم كان مسمّي الـmigration دي كواحدة مش
   متشغّلة. التفاصيل في
   `docs/frontend/MAMSA-REPLY-TO-BACKEND-refunds-deployed.md` قسم ٥.
5. **`bootstrap/cache` مش في git** — بتتولد بـ`config:cache` و`route:cache`.
6. **الاختبارات مش على الفرع ده كمرجع** — الإنتاج ما فيهوش حزمة اختبارات. الفرع
   بيحمل نسخة من فرع الميزة لكنها مش authoritative.
