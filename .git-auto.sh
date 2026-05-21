#!/bin/bash

# إيقاف السكريبت فوراً إذا فشل أي أمر (لتجنب الكوارث)
set -e

# استقبال المتغيرات من المستخدم
BRANCH_NAME=$1
COMMIT_MSG=$2

# التحقق من إدخال اسم الفرع ورسالة الكوميت
if [ -z "$BRANCH_NAME" ] || [ -z "$COMMIT_MSG" ]; then
    echo "❌ خطأ: يرجى إدخال اسم الفرع ورسالة الكوميت."
    echo "💡 الاستخدام الصحيح: ./git-auto.sh feature-branch \"feat: setup language support files\""
    exit 1
fi

echo "🚀 جاري بدء مسار العمل للفرع: $BRANCH_NAME ..."

# 1. إنشاء الفرع الجديد والانتقال إليه
git switch -c "$BRANCH_NAME"

# 2. إضافة التعديلات وعمل الكوميت
git status
git add .
git commit -m "$COMMIT_MSG"

# 3. رفع الفرع الجديد إلى GitHub
git push -u origin "$BRANCH_NAME"

echo "🔀 جاري الدمج مع فرع main ..."

# 4. العودة إلى main وتحديثه (خطوة أمان)
git switch main
git pull origin main

# 5. الدمج والرفع
git merge "$BRANCH_NAME"
git push origin main

echo "🧹 جاري تنظيف الفروع المحلية ..."

# 6. حذف الفرع المحلي بعد الانتهاء
git branch -d "$BRANCH_NAME"

echo "✅ تمت العملية بنجاح وتم رفع التعديلات إلى main!"
