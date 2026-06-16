<template>
  <AdminLayout>
    <div class="mb-8">
      <h1 class="font-display-lg text-display-lg text-primary mb-1">الحساب</h1>
      <p class="text-on-surface-variant text-body-md">إدارة بيانات حسابك الشخصي وإعدادات الأمان</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Profile section -->
      <div class="lg:col-span-2 space-y-6">
        <!-- Basic info -->
        <div class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6">
          <h2 class="font-title-sm text-title-sm text-primary mb-1 pb-3 border-b border-outline-variant">البيانات الأساسية</h2>
          <div class="flex items-center gap-4 my-5">
            <div class="w-16 h-16 rounded-full bg-secondary-container flex items-center justify-center text-primary text-2xl font-bold flex-shrink-0">
              {{ initials(form.name) }}
            </div>
            <div>
              <p class="font-title-sm text-title-sm text-on-surface">{{ form.name }}</p>
              <p class="text-body-sm text-on-surface-variant">{{ form.role }}</p>
            </div>
          </div>
          <form class="space-y-4" @submit.prevent="saveProfile">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="block text-body-sm font-bold text-on-surface mb-1.5">الاسم الكامل</label>
                <input v-model="form.name" class="w-full px-4 py-2.5 bg-surface-container-low border border-outline-variant rounded-xl text-body-md focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition-all" />
              </div>
              <div>
                <label class="block text-body-sm font-bold text-on-surface mb-1.5">رقم الجوال</label>
                <input v-model="form.phone" class="w-full px-4 py-2.5 bg-surface-container-low border border-outline-variant rounded-xl text-body-md focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition-all" dir="ltr" />
              </div>
              <div class="sm:col-span-2">
                <label class="block text-body-sm font-bold text-on-surface mb-1.5">البريد الإلكتروني</label>
                <input v-model="form.email" type="email" class="w-full px-4 py-2.5 bg-surface-container-low border border-outline-variant rounded-xl text-body-md focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition-all" dir="ltr" />
              </div>
            </div>
            <div class="flex justify-end">
              <button type="submit" class="px-6 py-2.5 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors flex items-center gap-2">
                <span class="material-symbols-outlined text-[18px]">save</span>
                حفظ التغييرات
              </button>
            </div>
          </form>
        </div>

        <!-- Security -->
        <div class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6">
          <h2 class="font-title-sm text-title-sm text-primary mb-4 pb-3 border-b border-outline-variant">كلمة المرور والأمان</h2>
          <div class="space-y-4">
            <div class="flex items-center justify-between p-4 bg-surface-container-low rounded-xl">
              <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-primary">phone_iphone</span>
                <div>
                  <p class="font-body-md font-semibold text-on-surface">التحقق بالجوال (OTP)</p>
                  <p class="text-body-sm text-on-surface-variant">تسجيل الدخول يتطلب رمز تحقق</p>
                </div>
              </div>
              <span class="px-3 py-1 rounded-full bg-emerald-100 text-emerald-700 text-[12px] font-bold">مفعّل</span>
            </div>
            <div class="flex items-center justify-between p-4 bg-surface-container-low rounded-xl">
              <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-primary">devices</span>
                <div>
                  <p class="font-body-md font-semibold text-on-surface">الجلسات النشطة</p>
                  <p class="text-body-sm text-on-surface-variant">3 أجهزة مسجلة حالياً</p>
                </div>
              </div>
              <button class="text-error text-body-sm font-bold hover:underline">إنهاء الكل</button>
            </div>
          </div>
        </div>

        <!-- Notifications -->
        <div class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6">
          <h2 class="font-title-sm text-title-sm text-primary mb-4 pb-3 border-b border-outline-variant">الإشعارات</h2>
          <div class="space-y-4">
            <div v-for="notif in notifications" :key="notif.key" class="flex items-center justify-between">
              <div>
                <p class="font-body-md font-semibold text-on-surface">{{ notif.label }}</p>
                <p class="text-body-sm text-on-surface-variant">{{ notif.desc }}</p>
              </div>
              <button
                class="relative w-12 h-6 rounded-full transition-colors"
                :class="notif.enabled ? 'bg-primary' : 'bg-outline-variant'"
                @click="notif.enabled = !notif.enabled"
              >
                <span
                  class="absolute top-1 w-4 h-4 bg-white rounded-full shadow-sm transition-all"
                  :class="notif.enabled ? 'right-1' : 'left-1'"
                />
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Right sidebar -->
      <div class="space-y-6">
        <!-- Role badge -->
        <div class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6 text-center">
          <div class="w-16 h-16 bg-primary rounded-2xl flex items-center justify-center mx-auto mb-4">
            <span class="material-symbols-outlined text-on-primary text-3xl">admin_panel_settings</span>
          </div>
          <p class="font-title-sm text-title-sm text-on-surface mb-1">{{ form.role }}</p>
          <p class="text-body-sm text-on-surface-variant mb-4">صلاحيات كاملة على النظام</p>
          <span class="px-4 py-1.5 bg-primary/10 text-primary rounded-full text-body-sm font-bold">SuperAdmin</span>
        </div>

        <!-- Activity log -->
        <div class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6">
          <h3 class="font-title-sm text-title-sm text-primary mb-4">آخر النشاطات</h3>
          <div class="space-y-3">
            <div v-for="log in activityLog" :key="log.action" class="flex gap-3">
              <div class="w-8 h-8 rounded-lg bg-surface-container flex items-center justify-center flex-shrink-0">
                <span class="material-symbols-outlined text-[16px] text-primary">{{ log.icon }}</span>
              </div>
              <div>
                <p class="text-body-sm text-on-surface">{{ log.action }}</p>
                <p class="text-[11px] text-on-surface-variant font-numeric-data" dir="ltr">{{ log.time }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Danger zone -->
        <div class="bg-white rounded-2xl border border-error/30 shadow-sm p-6">
          <h3 class="font-title-sm text-title-sm text-error mb-4">منطقة الخطر</h3>
          <button class="w-full py-2.5 border border-error text-error rounded-xl font-bold text-body-sm hover:bg-error-container transition-colors">
            تعليق الحساب
          </button>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

<script setup>
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import AdminLayout from '@/layouts/AdminLayout.vue'

const auth = useAuthStore()

const form = ref({
  name:  auth.user?.name  || 'أحمد محمد',
  phone: auth.user?.phone || '+966501234567',
  email: auth.user?.email || 'admin@mamsaa.sa',
  role:  'المدير العام',
})

const notifications = ref([
  { key: 'new_requests', label: 'طلبات جديدة',  desc: 'إشعار عند ورود طلب جديد',          enabled: true },
  { key: 'bookings',     label: 'الحجوزات',      desc: 'إشعار عند تأكيد أو إلغاء حجز',     enabled: true },
  { key: 'reports',      label: 'التقارير الأسبوعية', desc: 'ملخص أسبوعي للأداء',           enabled: false },
])

const activityLog = [
  { action: 'موافقة على طلب C7HKHYA4',    icon: 'check_circle', time: '2026-06-15 14:32' },
  { action: 'رفض طلب I9J0K1L2',           icon: 'cancel',       time: '2026-06-15 11:20' },
  { action: 'إضافة مدير جديد',            icon: 'person_add',   time: '2026-06-14 16:05' },
  { action: 'تصدير تقرير شهر مايو',       icon: 'download',     time: '2026-06-14 09:30' },
]

function initials(name) {
  return name.split(' ').slice(0, 2).map(w => w[0]).join('')
}

function saveProfile() {
  auth.setUser({ ...auth.user, ...form.value })
}
</script>
