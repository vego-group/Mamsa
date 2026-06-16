<template>
  <div class="min-h-screen flex" dir="rtl">
    <!-- Sidebar -->
    <aside class="fixed right-0 top-0 h-full w-sidebar-width bg-primary-container text-on-primary flex flex-col z-50">
      <div class="p-6 flex flex-col gap-2">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-white/10 rounded-lg flex items-center justify-center">
            <span class="material-symbols-outlined text-on-primary-fixed" style="font-variation-settings:'FILL' 1">apartment</span>
          </div>
          <div>
            <h1 class="font-arabic text-title-sm text-on-primary-fixed leading-none">ممسى</h1>
            <p class="text-xs text-secondary-fixed-dim opacity-80">إدارة الوحدات السكنية</p>
          </div>
        </div>
      </div>

      <nav class="flex-1 px-3 mt-4 space-y-1">
        <a
          v-for="item in navItems"
          :key="item.name"
          href="#"
          class="flex items-center gap-3 px-4 py-3 rounded-lg text-secondary-fixed-dim hover:text-on-primary hover:bg-white/5 transition-colors"
          :class="{ 'border-r-4 border-secondary-fixed-dim bg-white/10 text-on-primary font-bold': item.active }"
        >
          <span class="material-symbols-outlined">{{ item.icon }}</span>
          <span class="font-arabic text-title-sm">{{ item.label }}</span>
        </a>
      </nav>

      <div class="p-4 border-t border-white/10">
        <div class="flex items-center gap-3 px-4 py-2 mb-2 text-on-primary-fixed">
          <span class="material-symbols-outlined">admin_panel_settings</span>
          <span class="font-arabic text-body-sm">{{ auth.user?.name || 'Super Admin' }}</span>
        </div>
        <button
          class="w-full flex items-center justify-center gap-2 bg-white/10 hover:bg-white/20 text-on-primary py-3 rounded-xl transition-all"
          @click="handleLogout"
        >
          <span class="material-symbols-outlined">logout</span>
          <span class="font-arabic">تسجيل الخروج</span>
        </button>
      </div>
    </aside>

    <!-- Main content -->
    <main class="mr-sidebar-width flex-1 min-h-screen bg-[#F7F7F4]">
      <!-- Top bar -->
      <header class="sticky top-0 bg-white border-b border-outline-variant shadow-sm z-40 flex flex-row-reverse justify-between items-center px-8 py-4">
        <div class="flex items-center gap-4 flex-row-reverse">
          <div class="relative cursor-pointer">
            <span class="material-symbols-outlined text-on-surface-variant hover:text-primary transition-colors">notifications</span>
          </div>
        </div>
        <h2 class="font-arabic text-headline-md text-primary">لوحة التحكم</h2>
      </header>

      <!-- KPI cards -->
      <section class="p-8 space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
          <div v-for="kpi in kpis" :key="kpi.label" class="bg-white p-6 rounded-2xl border border-outline-variant shadow-card">
            <p class="text-on-surface-variant text-body-sm mb-1">{{ kpi.label }}</p>
            <p class="font-data text-4xl font-bold text-primary">{{ kpi.value }}</p>
          </div>
        </div>

        <!-- Empty state chart placeholder -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <div class="bg-white p-6 rounded-2xl border border-outline-variant">
            <h4 class="font-arabic text-title-sm text-on-surface mb-6">التغير الشهري في الإيرادات</h4>
            <div class="h-48 rounded-xl border-2 border-dashed border-surface-container flex flex-col items-center justify-center gap-2">
              <span class="material-symbols-outlined text-4xl text-on-primary-container opacity-40">query_stats</span>
              <p class="text-on-primary-container text-body-sm font-arabic">لا توجد بيانات بعد</p>
            </div>
          </div>
          <div class="bg-white p-6 rounded-2xl border border-outline-variant">
            <h4 class="font-arabic text-title-sm text-on-surface mb-6">عدد الحجوزات (آخر 12 شهر)</h4>
            <div class="h-48 rounded-xl border-2 border-dashed border-surface-container flex flex-col items-center justify-center gap-2">
              <span class="material-symbols-outlined text-4xl text-on-primary-container opacity-40">bar_chart</span>
              <p class="text-on-primary-container text-body-sm font-arabic">لا توجد بيانات بعد</p>
            </div>
          </div>
        </div>
      </section>
    </main>
  </div>
</template>

<script setup>
import { useRouter } from 'vue-router'
import { onBeforeMount } from 'vue'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth   = useAuthStore()

// Admins/partners are bounced to their panel; regular users stay on this view.
onBeforeMount(() => {
  if (auth.isAdmin || auth.isPartner) {
    router.replace(auth.homeRoute())
  }
})

const navItems = [
  { label: 'الرئيسية',    icon: 'home',             active: true  },
  { label: 'المستخدمون',  icon: 'group',            active: false },
  { label: 'الوحدات',     icon: 'apartment',        active: false },
  { label: 'الحجوزات',    icon: 'calendar_today',   active: false },
  { label: 'التقارير',    icon: 'analytics',        active: false },
  { label: 'الطلبات',     icon: 'assignment',       active: false },
  { label: 'الحساب',      icon: 'person',           active: false },
]

const kpis = [
  { label: 'عدد الوحدات (الكل)',        value: '٠' },
  { label: 'وحدات تنتظر الموافقة',      value: '٠' },
  { label: 'عدد المستخدمين',            value: '٠' },
  { label: 'عدد الحجوزات (الكل)',       value: '٠' },
  { label: 'إجمالي الإيرادات',          value: '٠٫٠٠ ر.س' },
]

async function handleLogout() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>
