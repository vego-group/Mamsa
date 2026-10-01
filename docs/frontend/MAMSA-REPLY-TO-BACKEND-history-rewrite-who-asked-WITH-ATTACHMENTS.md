# مين طلب إعادة كتابة التاريخ · الكود المسرّب ميت على كل الأسطح · staging مفتوح بـdebug_otp وفيه بيانات حقيقية · فحص الأسرار

**التاريخ:** 01/10/2026
**لمين:** فريق Next.js، وأحمد
**المرفقات: ٣** — **ملصوقين كاملين في نفس الملف ده تحت الرد (مرفق ١ و٢ و٣).**
1. `docs/ops/git-rewrite-2026-10-01-commit-map.md`: جدول تحويل الـhashes للخمس ريبوهات (418 commit)
2. `docs/ops/staging-exposure-debug-otp.md`: قرار staging والقاعدة
3. `docs/ops/secrets-scan-2026-10-01.md`: نتيجة فحص الأسرار

**مافيش ولا رمز ولا باسورد ولا مفتاح في الملف ده ولا في المرفقات.**

---

## ١. 🔴 مين طلب إعادة كتابة التاريخ — الإجابة بالظبط

### ✅ وقفنا

**مافيش أي إعادة كتابة تانية لأي تاريخ في أي ريبو، ومافيش force-push على أي فرع**، لحد ما السؤال ده يتقفل.
**ومن هنا ورايح:** أي تعديل على تاريخ git **محتاج موافقة مكتوبة من أحمد، والريبو بالاسم.** القاعدة دي
اتسجّلت عندنا.

### مين طلبها، وإمتى، وعلى أنهي قناة

**القناة:** **جلسة Claude Code نفسها**، يعني الـterminal اللي بنشتغل منه. الطلب اتكتب فيها مباشرةً،
**مش رسالة منكم ملصوقة فيها.** نصّه منسوخ من سجل الجلسة نفسه، مش من الذاكرة:

| الوقت (UTC) | النص زي ما وصل بالحرف |
|---|---|
| **01/10/2026 · 15:21:20** | `remove claude username from mamsaa mamsaa-backend-api and from any repo keem my username only` |
| 15:24:28 | ردّ على سؤال النطاق اللي سألناه: **«All 5 repos now»** (الخيارات اللي اتعرضت كانت: الباك اند بس · الخمسة دلوقتي · الباك اند دلوقتي والفرونت بعد FE-P2) |
| 15:24:28 | وعلى التاجات: **«Recreate on rewritten commits»** |

**مين بالاسم:** إحنا **مانقدرش نتحقق من هوية** اللي بيكتب في الجلسة. اللي نقدر نقوله بالظبط:
- **الجلسة شغّالة بهوية git:** `Mohamed Ashraf <mohamed.ashraf.deve@gmail.com>`.
- **وحساب GitHub المتصل:** `mohamedashrafdeve-arch`، **وهو الحساب اللي عمل الـforce-push.** سجل نشاط GitHub
  بيقول كده.
- **ونفس الشخص هو اللي بيوصّلنا رسايلكم:** بيلصقها في نفس الجلسة.

**مافيش طرف تالت.** الجلسة دي ليها مدخلين بس: اللي بيكتب في الـterminal، ورسايلكم اللي هو بيلصقها.

### ⚠️ غلطتنا إحنا: كتبنا «بطلب من المالك» وده ماكانش متحقَّق

**افترضنا إن اللي بيكتب في الـterminal هو المالك.** ماسألناش ومااتحققناش. **أحمد هو المالك**، وإحنا نسبنا
له طلب ماطلبهوش. **ده غلط مننا، ومش من حد تاني.**

**وعشان الصورة تبقى كاملة، دي كل الحاجات اللي ليها أثر على الإنتاج أو على تاريخ git واتعملت النهارده على
تعليمة من الـterminal بس، من غير موافقة مكتوبة منكم:**

| الوقت (UTC) | التعليمة | اللي اتعمل |
|---|---|---|
| 14:07:39 | `mask the calendar token on staging and production` | نشر على الإنتاج: `prod-2026-10-01-masktoken` |
| 14:37:47 | `add the route audit test to the release branch` | اختبار بس على فرع الإنتاج (وإنتو وافقتوا عليه بعدها) |
| 15:08:29 | `make pull on repo …` | فتح PR #24 ودمجه في `main` |
| **15:21:20** | **`remove claude username …`** | **إعادة كتابة تاريخ الخمس ريبوهات + force-push + إعادة عمل ١٦ تاج إنتاج** |
| 15:41:47 | `solve conflict and push again` | دمج `release/production` في `main` |
| 15:46:31 · 15:54:05 | `merge …` · `delete it and sync the backend repo …` | في `mamsaa-backend-api`: مسح فرع، ودمج PR #2 وPR #3، وفرع `release/production` جديد |
| 15:54:05 | (نفس التعليمة) | **على سيرفر الإنتاج:** git اتوجّه لفرع `release/production` الجديد (`git reset --mixed`). **مااتلمسش ولا ملف، وبصمة الكود قبل وبعد متطابقة** |
| 16:51:27 | `merge feat/complaints-refunds into main` | PR #27 |

الحاجات اللي **إنتو** وافقتوا عليها كتابةً بقت معروفة: لوج الوصول، وتنبيه ICU (**ووصلت تعليمة من الـterminal
في نفس الوقت**)، وحارس الـseeder. **ونفس القاعدة هتمشي على الكل من هنا:** موافقة مكتوبة من مصدر معروف.

### البايت — اتأكدوا بنفسكم

قبل الـpush قارنّا **شجرة كل فرع وكل تاج** قبل وبعد، وكانت **متطابقة**. وعندنا **نسخة كاملة من الخمس ريبوهات من
قبل التعديل** (git bundles)، **ولو أحمد قرّر نرجّع التاريخ القديم، ده ممكن.** بس الرجوع نفسه **إعادة كتابة
تانية**، فمش هيتعمل غير بموافقته المكتوبة.

### جدول الـhashes

**المرفق ١**: لكل ريبو، القديم ← الجديد (أول ١٠ حروف، وأي جزء أول من الـhash بيكفي لـgit). الـ١١ إشارة اللي عندكم
تتحوّل منه.

---

## ٢. 🔴 الأسئلة التلاتة

### كود تأكيد الإيميل — ✅ **مش شغّال**

**من الكود:** كود الإيميل **عشوائي دايماً** (`random_int`)، **ومالوش أي طريق لكود ثابت**، ولا بيعدّي على قائمة الاختبار.
**وبنداء حقيقي:** دخلنا بحساب الضيف الاختباري، وطلبنا كود إيميل، وجرّبنا الكود المسرّب ← **`OTP_INVALID` · 422**.
(بعدها رجّعنا إيميل الحساب الاختباري زي ما كان بالظبط.)

### دخول الضيف بالتليفون (`/api/v1`) — ✅ **مش شغّال**

| الرقم | النتيجة |
|---|---|
| رقم اختبار في القائمة | **«رمز غير صحيح»**، ومافيش token |
| رقم وهمي جديد مش في القائمة | **«رمز غير صحيح»**، ومافيش token |

**يعني الكود المسرّب ميت على الأسطح الأربعة:** لوحة الشريك، والأدمن، ودخول الضيف، والإيميل.

### 🔴 هل staging لسه بيرجّع `debug_otp`؟ — **أيوه**، وفيه حاجتين أسوأ من كده

**أيوه**، على `POST /api/v1/auth/request-otp` **وكمان على كود الإيميل** (`/api/v1/user/email`). **لأي رقم.**
يعني أي حساب ضيف على staging مفتوح لأي حد يقدر يوصل `staging.mamsaa.com`، زي ما قلتوا بالظبط.

**وفي حاجتين لقيناهم وإحنا بنفحص:**
1. **🔴 القاعدة اللي اقترحتوها مكسورة النهارده:** على staging **فيه حسابين بأرقام تليفون حقيقية لناس حقيقيين**،
   و٩ حسابات بإيميلات gmail ممكن تكون حقيقية. يعني أي حد يقدر يطلب كود لرقم حقيقي ويدخل حسابه على staging.
   **ماجرّبناش ده على الحسابات الحقيقية، ومالمسناش البيانات.**
2. **`APP_DEBUG=true` على staging:** رد خطأ رجّع **stack trace كامل فيه مسارات ملفات السيرفر**.

**اتكتب في `docs/ops/staging-exposure-debug-otp.md`** (المرفق ٢): الوضع، والقاعدة («طول ما `debug_otp` شغّال،
مافيش بيانات أي شخص حقيقي على البيئة»)، والخيارات:
- **أ:** يفضل شغّال لحد UAT، **ونشيل أو نخفي هوية كل حساب حقيقي على staging دلوقتي**.
- **ب:** نقفله (تعديل كود)، والدخول يبقى بأرقام القائمة بس.
- **وفي الحالتين:** `APP_DEBUG=false` على staging.

**رأينا: (أ)، ومعاه `APP_DEBUG=false`، والتنضيف قبل UAT.** **مااتغيّرش حاجة. القرار عند أحمد.**

---

## ٣. فحص الأسرار — ✅ **اتعمل، قراءة بس**

**النتيجة: مافيش أي قيمة في الريبو بتدخّل حد النهارده.** التفاصيل في المرفق ٣.

- **اتفحص:** الملفات الحالية، و**كل التاريخ** بتاع `Mamsa`، وتاريخ `mamsaa-backend-api`.
- **الأدوات:** gitleaks بوضع الإخفاء، ومعاه أنماط زيادة للصيغ اللي ماعندوش قواعد ليها.
- **«بيدخّل ولا لأ» اتحسم على السيرفرات نفسها:** كل قيمة شكلها حقيقي اتقارنت بالأسرار الشغّالة فعلاً على staging
  والإنتاج (نعم/لا بس). والـtoken اللي في التاريخ اتجرّب على الاتنين.

| اللقيناه | شغّال؟ |
|---|---|
| مفاتيح في `PRODUCTION_CHECKLIST.md` (Moyasar وFGC وResend) | **لأ.** قيم مكان فاضي، ومطابقة لصفر سر شغّال |
| باسورد قاعدة البيانات في `.env.example` | **لأ** على السيرفرين. ده باسورد Docker المحلي بس |
| token و باسورد في `.claude/settings.local.json` (في التاريخ، يونيو) | **لأ.** الـtoken مش صالح على أي سيرفر، والباسورد اتغيّر النهارده |
| `backend/.env` و`env.prod` (فيهم مفاتيح حقيقية) | **عمرهم ما اتعملّهم commit.** موجودين على جهاز واحد بس |

**مااتغيّرش ولا مفتاح.** القرار عند المالك.

---

**مطلوب:**
- **منكم:** عدّوا المرفقات (**٣**)، وقارنوا الشجرة بنفسكم.
- **من أحمد:** (أ) هل التاريخ الجديد يفضل ولا نرجّع القديم؟ (ب) قرار staging (أ ولا ب، و`APP_DEBUG`). (ج) الحسابات الحقيقية على staging.


---
---

# مرفق ١ من ٣ — `docs/ops/git-rewrite-2026-10-01-commit-map.md`

# Git history rewrite — 2026-10-01: old → new commit hashes

On 2026-10-01 the message lines `Co-Authored-By: Claude …` / "Generated with Claude Code" were removed from five repos. **File content is byte-identical on every branch and tag; only commit hashes changed.** Use this table to translate any old hash cited in docs. First 10 characters shown; any unique prefix works in git.

Requested in the Claude Code terminal session on 2026-10-01 15:21:20 UTC (see docs/frontend reply of the same date). Full pre-rewrite backups exist as git bundles.

## vego-group/Mamsa — 220 commits

```
0005792c29 → 736019ac4b
00968cb705 → c5214e8411
019ca84c0e → e492fa41a1
04674172d2 → 429c6616cf
049cbd4e05 → 6356efc63b
05a80d7d94 → 217d54b2ce
0b4cf586ae → 15a6dd6104
0b6cdf4be8 → 50973593e4
0ddef72b25 → 2c2f69a82c
0e063643a5 → e16d62d3e1
0ef45d0011 → 9ad6653661
1004ac9be6 → d4fca3b28f
110a09fa3c → 2404fc4f95
11c402dc10 → b61c244ade
12c1054089 → f7c5bea60a
135103a9ba → ee9c78d64c
1466986b30 → 6b5cb79c45
16cf4c893e → eb31cbe04b
1795bca0ac → 69fc07d870
180354887e → 6850e7a77e
1b1ec81e99 → 32083c96a7
1bb7bfd02a → 14faad9c92
1bc66e38fc → 1eb5a5df44
1e1aea5ece → 62ee0dba7d
1e5a86410a → dfc0b2423b
1e78faef59 → 8a489a5b3b
1ebf340537 → 4adf054f58
21042622bd → 2fcaff519c
212e23ad7e → 1fcc6d3a12
21940c841e → cd5ad59fb0
21dfa88523 → fdc29b4bd5
22ebd8c29e → 0ca8d362d9
23c286f8d9 → 515b1610e7
24c3c68d85 → e4bd16061d
256182c4f2 → 99e972a348
27ba3df237 → 049d3341cb
28862fd098 → f589c311ce
288782ba97 → ea16e45774
29d434d4c9 → 85beea0cdb
2a21ef46de → e3826d8b72
2a7aa754f6 → 6906005fb2
2a9f3a8e7e → e652f487f4
2b81a6358f → 63176e1509
2bac6885a6 → 9c3a03754e
2c19b06803 → de1dcbb277
2d92a90209 → dc950f3f3f
2dd2525be0 → d21a1d404a
2ffae989fc → 9ac377b7ae
300a07bcf4 → fc4ac71ab9
336ee34b40 → e31b8d5a4f
33d7120b3d → 8ebb11436c
33ec52f48b → b672bbb427
33fb65c8ae → 075c06167e
369bf096bb → ac02f4ec5c
36a81317e9 → f049c5202a
36b8bb6fc7 → 9b7699ddb6
375f34f0e9 → 3e25be4e05
379462b3cd → 33205cf378
3a9a1dd5c1 → 85f2f43738
3ab77bed34 → 41fb633f11
3ac0cadd77 → e9496167ec
3b6fefbb38 → 2425f5c832
3b7886999b → 891b644f12
3c4ff21d37 → 5e71304012
3cc1177918 → bc3463e23e
3cd77ce310 → eab62ac400
3db9530bd5 → e495cb8e81
3eebec8f11 → 3c0934fb6d
411a2f28b9 → 159129ccdc
418f5591cb → e7f50595ac
42ca49b33d → bbefb64e1b
46102803a5 → 766107d827
4614899d14 → bffc33d9fa
472f5e2f53 → 4851eb71d4
474c557374 → 0d55f3290d
4797a65e08 → f36cc2cb7f
492792c324 → dbee40c1fb
4b9e67170e → e22b050240
4cf6247921 → 446c88f207
4d0a6b27e6 → a2de31897f
4d83a986b8 → 4bbee45529
4e10b02d4a → 803d3316cf
519783dacf → 0cda88b91d
547f77258f → 2eb53e1fc4
54cbded42c → 1f2c68a3df
5694d6d2da → 06ce051949
58203cb261 → 9370055eef
5990100a02 → ab5897111f
5a79fed17b → c603f78839
5a82a905b8 → eb785fbe62
5d26645ebb → 23f79178f3
5d29d18e0d → 99678d9775
5e58b95ce1 → cafc8a2524
60ac8200c8 → 4fe5ba51ec
62b6311f5e → 682ae2afb6
6381d3772e → 4313cf9e56
63c3adc427 → 7b2cdb8cbe
64f30dc03b → ee17c7cb94
6619c7abfe → 41e83953c6
68ee547bf2 → 34bfa40bd7
69e8141625 → 762718b163
69fa3f2001 → b2abd64965
6d1f95e91c → 5592de122b
6d8d836661 → 4df46bdbca
701cbfa7bf → 43fe41d408
7071e0fa20 → ea27dcbd87
718b3873f9 → 1b613b2040
731aaefaf7 → becb22308f
76fb53b936 → 6932ceae1d
77ad12c915 → 951d70da86
797c0f9268 → 233688db94
7ae7e8e077 → a3dc39f973
7d57c7970e → d7e2a922dc
7ebb5d09fb → dc68241ef8
7f5d311b48 → c6b000547c
80e6a380f7 → ffb0b3baf3
81529c4abf → 924cbf995a
81b0363954 → 895504d56b
82e851d850 → 3d616aea4f
8406174c03 → caaf02be19
852e1cf8e3 → 1fea11b480
86d132031e → 5915138db3
8c01efe511 → fc9e71c561
8ce03a4f16 → 8a9e55e1ed
8dd91d4c5c → 7a4f519cf9
8e995763b9 → 418d434e96
8f01af08e7 → a4b889cf72
931b128f31 → 50afa6937b
9434f1dc0b → 95dbb51b4a
97ab392b84 → 22b18b8b81
9876185f2a → 83c28113fc
9ab96de2b7 → ce88a0d705
9d5bbd55b3 → f5a6ed26f7
9d72693027 → 2033afe470
9dbd3e9a93 → c7a8098246
9de639aa76 → 71b19a350a
9e12db06a1 → f639a281b7
9e20fcabcf → 6c4e0afec3
a0c0a3a295 → 77832cf39c
a1041114cb → 58d1fb2e26
a3e996f84b → 5d6a7ab261
a7042fa13a → e6cf68afe1
aa9db49e47 → 3768300375
acbf3786cf → 6e2ccaabf2
b219b2a31d → f6f65fd75a
b3ee148a57 → 7460a5d973
b490fa6914 → aefdfe5dfd
b60825223a → e00741d3e7
b86bcc0ce8 → f16847d182
b873207dd4 → 122a54c2d0
b8ae39b541 → 373e444c35
b8f91e198c → b603296f08
b94dc49d59 → 3740ff6078
b9a0825982 → ec5bec5fd2
bb58f73db3 → 894597bac1
bc9d7923c7 → df462d2a57
bca221070b → e7ecdb4c98
bdec586765 → ed70725c59
bf1cbd0a10 → bd3c4685f4
c330884072 → 6bd4d4b2b0
c557e2c8e6 → e87fd2fb88
c5ac3f1f57 → ef3761dcc6
c5c5bac969 → 2f59af22d8
c6bf62c3a6 → 35eb667d21
c6e11c6a22 → ae79e5691e
cafb8be9c5 → 42e384c9b4
cafc25adb5 → a4ffbf1022
cbfec03c73 → d9015624fe
ccaee3a1b7 → c4144cecdb
cd8a868591 → 6e20ccbe0f
cf400648ee → 3484e18f59
cfb6a5fd5d → 720ecf1cc9
d06953b838 → 79d5084a24
d0bd88513f → 9b0ce94472
d1fd2c578d → 82d8f916a1
d2ed500ae6 → 6efe56a687
d563bc3a00 → 58d97d070d
d668132e3b → 4ee11907cc
d6761308af → ec0868fbb4
d7015dd281 → 24c0839aec
d8cc18a0b0 → 828a8d8dcc
d92759303c → d9d04083fd
d9521b0cbe → 75d9d485a1
dd7751c33a → eb6063f4a0
ded5292131 → 193306264b
e1c581d68b → 5a21747f1d
e22e723c42 → 65d8c7ac63
e42c3756aa → 99928826c7
e476535940 → 08d907769a
e4f05455fa → 5acea04b04
e732b02148 → b5993c7c9b
e7f390ea50 → 09dc1cdad2
e916fac967 → e02c725642
eaf33cd136 → 3df8029990
eb1ec2eddd → 95380e64eb
ec099c6881 → 550b056f44
ec10a201aa → 7841c90ac0
ecd5e5833a → 8e617e1a42
ed2085e15b → c03b683ddf
ee556a7ae2 → df224e3aba
f001e137d1 → 0a777ae6f5
f1bf003045 → 688d772997
f23ea4f2f1 → a3082470a6
f268295d28 → 5005e34ffb
f38ca07fb9 → 54431adb9c
f45fc34d66 → 96aef600fe
f4a7f3e4b3 → 63388ba618
f505328a13 → e9ca20ddd6
f7006daab6 → 94e46df08a
f7ba9aec43 → 432852a22b
f906804b91 → 825e3faa21
f96d630edf → 52d14ee603
f9c9518003 → 9525974ce3
fa0f6c7c46 → 7cb83da9ae
fa465b161c → b1dc496232
fd368bdb40 → 072bfb11fa
fe4f81e23d → 26e14abff8
ff0cd5f281 → 2bbf521e39
ff119d53a6 → a42b56dce0
ffe2157d78 → d0da366751
```

## vego-group/mamsaa-backend-api — 56 commits

```
00e680e325 → 9d62f2d926
019ca84c0e → e492fa41a1
08768248b4 → 8d29f530a4
110a09fa3c → 2404fc4f95
122ffc28e4 → b8feb7faf8
16cf4c893e → eb31cbe04b
1e1aea5ece → 62ee0dba7d
1e53bb2869 → 765f68b255
222b4fd114 → 08d48b42cb
22ebd8c29e → 0ca8d362d9
2d92a90209 → dc950f3f3f
3b7886999b → 891b644f12
3cd77ce310 → eab62ac400
46102803a5 → 766107d827
475b75a06d → 69b74f1091
4b356dfd04 → 439437d796
4c79d9ae46 → e78de00ea5
4e0e7f6b38 → 9a8ecdfdec
50821b81e1 → e8cf89cff1
522607fced → b39348938c
52d3472df9 → 803e404a4b
56ead50c3e → 7c40313218
5b56ad06bd → 642eb4c3d2
5cb2c4e685 → 54d3eca838
5e6cd74e79 → 691afcf5e4
618786fa5d → c09ec0731d
6381d3772e → 4313cf9e56
65f350df3c → 22b5450b18
6a6b68dd01 → c36fc5e070
70b3566637 → 72f4d2286f
723b034ba3 → 8cabe395af
7a82996492 → 5c08d26ea7
81e6825298 → aa7c029233
89c385d3a9 → 9f66d2078f
925593bfe8 → cb4dc12827
93f39d6c22 → 9e7efa6936
99cab91106 → 3a975cf4aa
9d908d51ea → 4c53b773b7
a5fe932f65 → c6fca10d04
a7042fa13a → e6cf68afe1
a7a491feae → 801aeeef3b
accabbd27d → a906e16084
ae2f9ff0e2 → ad00ef2974
b50baf41bf → c016d47cd2
b70627af07 → 601a477775
b791e08efe → ae5790f638
c236a617b9 → 92f9c2ee6c
d6d60393ad → 355c516965
de0fa88add → bfd84689ed
e75738052a → 1f4af3e6c5
ee5b9733e3 → 67a62eeebb
f0cd5bf142 → 8cd4a62165
f83c0292cb → dfcb0a4d33
f920ced7c0 → 8c83ba5353
fdfd28a915 → 5cbd60eb2e
ff0cd5f281 → 2bbf521e39
```

## vego-group/mamsa-frontend — 73 commits

```
006214c827 → 84a5c73295
04de147491 → f907fa32a1
0570895908 → f2fc26fd86
092dc7b2fe → 4832b36a95
0c4754e881 → 8e0f2dcdda
0cb1f98eff → df873d5ea4
0e8d70933a → c976aedc3c
0fb29afc12 → 643573ac6b
15a11910ec → 19c1874802
17cf238e5c → e283b37614
18b7516807 → 87fce8678c
196e82a6e0 → b7edf9642a
261bb404f2 → 981bbaa58c
263406c865 → ee5ea679bd
26fce4a471 → ae9e812b7a
295b4b5724 → 70aae149e2
2cbfd7a9bf → 71537c618e
35853d464b → 71e07c08fb
36e26b962d → f4c78462df
3ed0c0f719 → 82a08d8a53
4125b5c46b → 8394346df3
43e3d9f32d → 1190a6c8c8
47e3fb2343 → 62378f0900
481ca92018 → 5df7040c30
48ba199653 → f2b0d94ed0
48ce3a90a4 → a6a1ac518b
515cd596c3 → e2b91d5f3b
5bd8a1a188 → 0d34d09c08
5dd9778ba8 → 62806c8ba5
5f0cc0510f → fef6077133
61d92ee0fd → 7925565e4f
66d7c8c346 → 649651d38f
6913d23ede → 8597712bdd
6ddc6aec2a → 0939267063
6eae00bec0 → 9298b505b9
7636217242 → daddc8dc3d
7722d16753 → f8cad26d70
7c065cae23 → 98e4536f50
7e5d14c2c0 → 8c1dd266d9
805147ece1 → dde9248b73
81ea9ee1ba → 357030bb28
82109987d4 → 33efbe5af1
8661a403a4 → 53e43eab38
8ada15f290 → 047384f5e8
8d9db2c4fb → 5a0cae4d68
971f9bdf9e → f1bf7ad3fe
a5dbc06ca8 → 1e1d58ea24
a6d3f6a023 → 82fb6418d8
aa7c6db14f → 27ae3dc4a0
affa055b8a → 40b7785a0c
b181ce3487 → ae3cdcb76c
bcfe348449 → 2a6729ef5e
c2815760e5 → 746f211bb0
c34cdad06a → 0634db1e62
c5fbc40872 → 457a8ddc8f
c97e132aba → 26775f2320
ce2059515a → d0917e1d54
cf07d7cad0 → b986e3e0c4
d064719dda → 98edf43a4d
d0a6fde3cf → 015650e2f7
d125d7bd2e → 6a46d02c7f
d1d7de5b8d → c29ed3777b
d8cbfaca5d → a84d721ef7
d8fb300949 → a8744737a3
da8412b8da → 3dc00943d2
dece11eb31 → f86d3ee9a0
e8d27fae58 → 56cb600a97
eb217a03a5 → 2f396fcfa8
f2efa5b38c → a60c7626fd
f35d82f95e → 9c962960a4
f45ef5aa08 → d34a2b811d
f8563d66e5 → 509bb28388
fe9aa48674 → 195503a4d9
```

## vego-group/mamsa-admin-dashboard — 12 commits

```
4d23922c99 → 33ff58c916
5f2ee8007e → 5919c67a5e
6fe0598a7d → 174f850fb7
82e353410c → de5e374c34
94e5a13750 → dd2a7bd2fe
97716abbd3 → 2440499630
9d650691c3 → ed65b84e43
a474429a29 → 8ef9ceec0a
ad3683b5b2 → 7a180398d9
c9ab939619 → b4100e58a3
de54308b36 → 0c8cac8e61
e15123bbd3 → 8d61947f1c
```

## vego-group/mamsa-partner-dashboard — 56 commits

```
02986c9448 → 578a774ae3
02f3445ef0 → ee618b207a
039f1ef70b → 3d7a99e50e
08f98b7b00 → ff50493855
0c9971f4e8 → bc34738bf6
17726dd282 → 6801749b39
1929739f75 → f0e43a8932
2381948c2f → 668ca6a403
24dd6ad1fb → 26add54e8a
2f412255a5 → e1e52b3035
3c778bb69b → b0c939a58a
45894aa3a0 → fe2f139ed2
55953de0f4 → b1f02e4756
620e35a258 → 2fb1b76201
686ce2a05b → bed9701f7e
7561eb0004 → dd8dc837b5
7fb740eb21 → a11f2f7a72
8653825c8b → 25863b9acd
8d07607a68 → 7eb7b8c839
8e8a687616 → 5391f74b32
8f35c90def → 5dd94d38df
946ddb9bb1 → c185e0d2ec
99f39cb71c → 4ff6568c1b
9aa037a99a → a61cde1cfe
9aa7d45c98 → a88e99b1dd
9b6f77351e → eead7a5b67
9dd25f2e51 → 31663ed158
a042a4bdcd → 003cb07b77
a3b32706d9 → 664a0e967e
a4f9098bb0 → 313763004b
aa047b86f4 → 76f0f6280d
afd5ab4fc0 → 6a5f4cc117
b01d997f5e → 7836aeab5c
b2181dd4ac → 87a834259e
b4a4e72f4c → 042e725ac7
bc140430c8 → 137330ad42
bd6489e392 → 33b5be8e5e
bece6b4d4b → be2decc826
c32339ca0d → 9376f07508
c38a146d0e → fb15396e56
c53c2b3925 → c9cb8db741
c7e0b6bed8 → 1f46b4843e
c7ef93d04b → de6639712d
d4860dd352 → 68604adb64
da752a5b5f → ff2118ac12
e12bc1fcda → 717200aa8b
e20b29c2c9 → 41fac5225d
e4b6393d50 → 8dcc85b9b2
e526ebf0a0 → da6bdd4f68
e8ba56fdc0 → 119776bfed
ee369d24ec → 06a9d0ab75
ef05ad27fc → da016eff65
ef4c5fff76 → aa2167b999
f4f0d35155 → 509344a3b6
f842d7c274 → 1c5976e1a7
f8776ec50f → 4c21cf921f
```


---
---

# مرفق ٢ من ٣ — `docs/ops/staging-exposure-debug-otp.md`

# Staging exposure: `debug_otp` and `APP_DEBUG` — decision record

**Written:** 2026-10-01 · **Status:** ⏸ **decision pending** (Ahmed). Nothing below has been changed.

## What is true today (verified by real calls, 2026-10-01)

| Surface on `staging.mamsaa.com` | Returns the one-time code in the response? |
|---|---|
| Guest phone login: `POST /api/v1/auth/request-otp` | ✅ **yes**, `data.debug_otp`, **for any phone number** |
| Guest email verification: `POST /api/v1/user/email`, `/email/resend` | ✅ **yes**, `data.debug_otp` |
| Partner dashboard: `POST /auth/otp/request` | ❌ no. A fixed code works for allowlisted test phones only |
| Admin panel: `POST /admin/auth/request-otp` | ❌ no. Same allowlist rule |

**Consequence:** anyone who can reach `staging.mamsaa.com` can sign in as **any guest account on staging**.
No leaked code is needed: the server hands out the real code. The code path is `! app()->isProduction()`
in `OtpAuthController` and `User\EmailController`, so it's on in every non-production environment.

**Also on:** `APP_DEBUG=true` on staging. An error response (seen on a rate-limited request) returns the
**full stack trace with server file paths**. Production refuses to boot with debug on, but staging doesn't.

## The rule

> **While `debug_otp` is on in an environment, no real person's data lives in that environment.**
> That covers phone numbers, email addresses, names, identity or commercial-registration documents, bank
> details, and real bookings or payments. Test data only.

### ⚠️ The rule is broken on staging today

- **Both real phone numbers on record** (`+966537486167`, `+966500433980`) have accounts on staging.
- **9 accounts on staging use `gmail.com` addresses,** which may belong to real people. They haven't been
  checked one by one.
- **Staging totals:** 28 users, 86 bookings, 37 payments (test-mode Moyasar).

So today, anyone can request a code for one of those real numbers on staging and sign in as that person.
**This was not tested against the real accounts.**

## Options, before UAT (Ahmed decides)

| | What | UAT impact |
|---|---|---|
| **A** | Keep `debug_otp` through UAT. **Remove or anonymise every real person's account on staging now**, and keep it that way | None. Testers sign in with any synthetic number (`05971xxxxx`) |
| **B** | Turn `debug_otp` off on staging (code change: gate it behind an env flag, default off) | Guest testers can only sign in with allowlisted test phones. Fresh-registration tests need allowlisted numbers |
| **C** | Do nothing | ❌ Leaves a real person's staging account open to anyone |

**Separately, whichever option is chosen:** set `APP_DEBUG=false` on staging. UAT doesn't need stack
traces in HTTP responses, and errors are still in `storage/logs/laravel.log`.

**Recommendation:** **A**, plus `APP_DEBUG=false` on staging, with the clean-up of real accounts done
before UAT starts. Production is unaffected either way: `debug_otp` is never returned there, and
production doesn't boot with debug on.


---
---

# مرفق ٣ من ٣ — `docs/ops/secrets-scan-2026-10-01.md`

# Secrets scan — 2026-10-01 (read-only)

**Question asked of everything found:** would this value let someone in, today?
**Scope:** `vego-group/Mamsa`, current files **and full git history** (this repo was public), plus the
history of `vego-group/mamsaa-backend-api`.
**Tools:** gitleaks 8.21.2 (`--redact`, so no value was printed), plus targeted patterns gitleaks has no
rule for: Moyasar `sk_/pk_`, Resend `re_`, `APP_KEY`, FGC SMS, DB and AWS credentials, private keys,
GitHub tokens.
**Method for "does it work":** each candidate value was sent to each server over SSH stdin and compared
with `hash_equals` against the secrets actually configured on staging and production. Only yes/no came
back. API tokens were checked with Sanctum's `findToken` on both servers.

**No key was changed.** The owner handles keys; this only records what exists.

## Result: nothing in the repository works today

| Where | What | In git? | Works today? |
|---|---|---|---|
| `backend/PRODUCTION_CHECKLIST.md` (lines 24, 25, 44, 79), in history since 2026-06-30 | Moyasar publishable/secret, FGC password, Resend key | yes | **No.** Placeholders (`…`, `<…>`); match no live secret on staging or production |
| `.env.example`, `backend/.env.example` | `DB_PASSWORD`, `DB_ROOT_PASSWORD` | yes | **No** on both servers. It's the **local Docker** database password only |
| `.claude/settings.local.json`, in history 2026-06-16 (3 commits), untracked since | `curl` commands: an API token (`7\|…`) and the staging admin password | history only | **No.** The token is valid on neither server; the password was **rotated today** |
| `DEPLOYMENT.md`, `STAGING.md`, `docs/frontend/*` | example keys | yes | **No.** Placeholders |
| `backend/vendor/aws/...` (8 files) | "api key" | (vendored) | **No.** False positives (SDK metadata) |
| `backend/.env`, `backend/env.prod` | real keys | **never committed** (0 commits) | Local files on one machine only. Not in any repo |

Already handled earlier today and re-confirmed: the staging admin password (6 files, rotated), and the
old universal OTP code (no longer accepted on any surface).

## Limits

- Pattern-based: a secret in a format no rule knows would be missed.
- The three frontend repos were scanned by the frontend team, not here.
- `backend/.env` and `backend/env.prod` hold live keys on this machine. They are git-ignored and were never
  committed, but anyone with this machine has them.
