# قرارات أحمد: نسخة الإنتاج اتمسحت · الرقمين اتخفوا · `national_id` اتعمله تعمية · `debug_otp` بقى ورا متغيّر · الرقمين بتوع مين · وقائع الإخطار

**التاريخ:** 01/10/2026
**لمين:** فريق Next.js، وأحمد
**المرفقات: ١** — `docs/ops/incident-local-production-copy-2026-09-27.md` (وقائع البند ٦)، **ملصوق كامل في آخر الملف**.
**مافيش ولا رمز ولا باسورد ولا بيانات شخصية حقيقية في الملف ده.** الحسابات بالـid بس.

**المصدر في كل بند:** «أحمد» = قراره المكتوب. وكل خطوة على سيرفر **أخدت كمان إذن من مشغّل جلسة الباك اند**
قبلها، وده بناءً على طلب منه في الجلسة: «اسأل قبل أي حاجة على سيرفر».

---

## ١. 🔴 نسخة البروفة — ✅ **اتمسحت النهارده** (أحمد)

| | |
|---|---|
| **قبل المسح مباشرة** | `chmod 600` على الـdump والـ`.env` والأرشيف، و`chmod -R go-rwx` على الفولدر |
| **اتمسح · 2026-10-01 18:35:39 UTC** | الـdump، و`app_core` ومعاه `.env`، والأرشيف، والـcontainer `mamsa_rehearsal_db` والـvolume بتاعه |
| **وكمان** | الـcontainer `mamsa_rehearsal_app`، لأنه كان مربوط بنفس `app_core`، فهو جزء من نفس النسخة |
| **اتأكدنا بعد المسح** | الفولدر مش موجود · ٠ containers · ٠ volume · **مافيش أي dump تاني للإنتاج على الجهاز** |

**الملفات اللي لازم تفضل اتعملها `chmod 600`:** `env.prod` و`backend/.env` كانوا **مقروءين لأي مستخدم**، وبقوا
`600`. ومفتاح SSH وtoken GitHub كانوا `600` من الأول. وDocker المحلي لسه شغّال (`/up` = 200).

**القواعد اتكتبت في `known-risks-local-copies.md`:**
- نسخة الإنتاج للبروفة تتمسح يوم ما البروفة تخلص، والتقرير يكتب إمتى.
- مافيش نسخة إنتاج تفضل على جهاز بين بروفتين.

**وتقرير بروفة ٢٧/٠٩ نفسه** بقى فيه سطر إنها اتمسحت ١٠/٠١، **وإنها فضلت ٤ أيام بعد البروفة، عكس القاعدة.**

---

## ٢. النسخة الاحتياطية للـ١٦ حساب — ✅ **٧ أيام، وتاريخ المسح اتكتب** (أحمد)

`chmod 600`، و**🗓 المسح يوم 2026-10-08**، ومكتوب في `staging-exposure-debug-otp.md`. **ونفس التاريخ للنسخة الاحتياطية
بتاعة `national_id` (§٣).** والمسح نفسه خطوة على السيرفر، **فهنسأل المشغّل الأول يومها.**

---

## ٣. `national_id` الاتنين — ✅ **اتعملهم تعمية** (أحمد)

المستخدمين **١٩ و٢٢** على staging: **اتعمل نسخة احتياطية الأول** (`chmod 600`، والمسح ١٠/٠٨)، وبعدين القيمة
اتبدّلت بقيمة اصطناعية. **القيم الأصلية اللي فاضلة: ٠.**

---

## ٤. 🔴 الرقمين في الريبو العام — ✅ **اتخفوا في الـ٧ ملفات** (أحمد)

**commit عادي** (`c69ff2c`)، بالصيغة `+96653*****67` و`+96650*****80`. **الملفات اللي فاضل فيها الرقمين: ٠.**
**ومافيش إعادة كتابة تاريخ**، وده الصح زي ما بيّنتوا: GitHub لسه بيقدّم الـcommits القديمة من refs الـPRs.
والحل الحقيقي إن الريبو يبقى private، **ومستني أدمن على الـorg.**

**وكلامكم في محلّه:** ٣ ملفات من الـ٧ **إحنا كتبناهم النهارده**، منهم رد كان موضوعه حماية البيانات. **القاعدة اتسجّلت
عندنا:** بيانات شخصية حقيقية ماتتكتبش في أي ملف، زي الأسرار بالظبط، والحساب يتشاور عليه بالـid. **وطبّقناها كمان
على ملاحظات الجلسة الداخلية:** كان فيها الرقمين، واسم الشخص، وإيميله. اتشالوا كلهم، وبقى الحساب بالـid بس.

---

## ٥. الرقمين بتوع مين؟ — **شخص واحد من فريق المشروع، مش عملاء**

| | |
|---|---|
| **على الإنتاج** | حسابي **prod-user-19** (**SuperAdmin**) و**prod-user-20** (شريك)، **لنفس الشخص** |
| **ليه اتعملوا** | في أغسطس، عشان يبقى فيه حساب أدمن **يقدر يستقبل SMS حقيقي**. وقتها كل أرقام الأدمن كانت اصطناعية، والإنتاج كان مقفول على الكل |
| **على staging** | الإيميلات كانت على **دومين الشركة نفسها** |

**يعني دول حسابات تشغيل داخلية لعضو في الفريق، مش عملاء سجّلوا على الإنتاج.** والإجابة دي مبنية على السجلات
اللي عندنا من أغسطس، **ومافتحناش الإنتاج عشانها.** لو محتاجين تأكيد من الإنتاج نفسه، ده قراية على السيرفر،
**وهنسأل المشغّل قبلها.**

---

## ٦. الإخطار — **الوقائع مكتوبة، والقرار عند المسؤول في VEGO**

**المرفق ١** فيه الوقائع بتاريخها، من غير أي بيانات شخصية:
- **اتعملت إمتى:** 2026-09-27 09:33:45 UTC.
- **اتمسحت إمتى:** 2026-10-01 18:35:39 UTC.
- **مين كان عنده دخول:** الحسابات اللي ليها shell على الجهاز (`root` و`acl` و`jenkins`)، ومستخدم Windows، وجلسة
  الباك اند نفسها.
- **هل فيه دليل على وصول غير مصرّح؟ مالقيناش.** **وكاتبين حدود الإجابة دي بالظبط:**
  - **الملفين الحسّاسين ماحدش قراهم بعد ٢٨/٠٩،** حسب نظام الملفات (`relatime`).
  - **قاعدة البيانات ماكانتش متاحة من الشبكة**، وماشتغلتش غير يوم ٢٧/٠٩.
  - **مانقدرش نستبعد** قراية في أول ٢٤ ساعة، ولا أي حاجة اتعملت من ناحية Windows، ومافيش audit log.
  - **وقراية واحدة للأرشيف النهارده الساعة 18:08 كانت مننا إحنا:** عرض أسماء الملفات بس.

---

## ٧. (ب) `debug_otp` ورا متغيّر بيئة — ✅ **اتعمل واتنشر على staging**

| | |
|---|---|
| **القاعدة الجديدة** | الكود بيرجع في الرد **بس** لو البيئة قررت (`OTP_DEBUG_RESPONSE=true`)، **وعمره ما بيرجع على الإنتاج** حتى لو المتغيّر شغّال. **الافتراضي مقفول**، يعني أي بيئة جديدة تبدأ مقفولة |
| **الاختبار** | **اتشاف فاشل الأول:** staging كان بيرجّع الكود والمتغيّر مقفول. **ولو رجّعنا أي شرط من الاتنين، الاختبار بيقع.** وكل الاختبارات: 879 ناجح |
| **staging** | `OTP_DEBUG_RESPONSE=true`، **قرار مقصود للبيئة دي** (اختيار أحمد «أ»: يفضل شغّال لحد UAT). اتأكدنا بنداء حقيقي إن الكود بيرجع |
| **الإنتاج** | **مانشرناش.** ومافيش أي تغيير في السلوك: الإنتاج عمره ما رجّع الكود. نشره هناك محتاج موافقة أحمد والمشغّل |

---

**مطلوب:**
- **منكم:** عدّوا المرفقات (**١**).
- **من أحمد:** الريبو private (مستني أدمن)، وهل نحتاج تأكيد §٥ من الإنتاج نفسه.


---
---

# مرفق ١ من ١ — `docs/ops/incident-local-production-copy-2026-09-27.md`

# Facts: a copy of the production database on a developer machine (27/09 → 01/10/2026)

**Purpose:** the facts a legal decision on notification can rest on. **The decision is not ours.**
Ahmed is asking the responsible person at VEGO.
**Written:** 2026-10-01 by the backend session. **No personal data in this file.**

## Timeline (UTC)

| When | What | Source |
|---|---|---|
| **2026-09-27 09:33:45** | Full production database dump pulled from the production server to a developer machine (`/root/rehearsal/prod-2026-09-27.sql`, 45 tables, 127 KB, sha256 `cc33b0f0525088e5…`). Purpose: a pre-release rehearsal on a restored production copy | file timestamp; rehearsal report `REPORT-rehearsal-2026-09-27.md` |
| 2026-09-27 09:34:00 | Production `app_core` archive copied (code + its `.env`, so likely production's live secrets) | file timestamp |
| 2026-09-27 09:34:09 | Dump restored into a local MariaDB container `mamsa_rehearsal_db` (Docker volume) | container metadata |
| 2026-09-27 09:35:14–22 | 4 failed DB logins as `prodcopy@localhost`, **before** the database finished starting (09:35:26): the setup script retrying, inside the container | container log |
| 2026-09-27 20:50:05 | Both rehearsal containers stopped. Never started again | container metadata |
| 2026-09-27 → 2026-10-01 | Files stayed on disk, **readable by every local account** (`-rw-r--r--`) | file permissions |
| **2026-10-01 18:08:26** | The code archive was read once, by the backend session, **listing file names only**, to check whether a `.env` was inside | file access time; session log |
| 2026-10-01 ~18:35 | `chmod 600` on the remaining files | session log |
| **2026-10-01 18:35:39** | **Deleted:** the dump, `app_core` with its `.env`, the archive, both rehearsal containers, and the data volume. A search found **no other production dumps** on the machine | session log; post-deletion check |

## Who had access

- **Accounts on the machine that can log in:** `root`, `acl`, `jenkins`. The files were readable by all three.
- **The Windows user of the host machine:** WSL2's filesystem is reachable from Windows (`\\wsl$`).
- **The backend Claude Code session**, which runs as `root` on that machine.
- **Network:** both rehearsal containers had **no published ports**, so the restored database was not
  reachable from the network. The files were not served by any web server.

## Evidence of unauthorised access

**None found.** What that rests on, and where it stops:
- **The dump and the `.env` were not read after 2026-09-28.** This filesystem (`relatime`) updates a file's
  read time whenever it's read more than 24 h after its previous recorded read. Both files still show
  2026-09-27 09:33:45 and 09:46:01. So any read after that window would have changed them.
- **The database was never reachable from the network**, and it ran only on 27/09, 09:34–20:50.
- **Limits:**
  - `relatime` can't rule out a read **within** the first 24 h, on 27/09.
  - `root` can reset access times.
  - The machine has no audit logging (`auditd`).
  - We can't see what the Windows side did.

## What the copy contained

Production's users (a small number of real accounts), their bookings, payments and the related records,
as of 2026-09-27. Plus production's `.env`, likely holding the live payment, SMS, email and database secrets.
**Note:** if anything below is ever in doubt, the secrets-rotation order is in `known-risks-local-copies.md`.
