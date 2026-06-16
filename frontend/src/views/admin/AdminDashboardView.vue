<template>
  <AdminLayout>
    <!-- Welcome header -->
    <div class="mb-8">
      <h1 class="font-display-lg text-display-lg text-primary mb-1">أهلاً بك، {{ auth.user?.name || 'المدير' }}</h1>
      <p class="text-on-surface-variant text-body-md">نظرة عامة على أداء العقارات اليوم</p>
    </div>

    <!-- Pending alert -->
    <div class="mb-8 p-4 bg-error-container text-on-error-container rounded-xl flex items-center gap-4 border border-error/20">
      <span class="material-symbols-outlined text-error">notification_important</span>
      <span class="font-title-sm text-title-sm">يوجد حالياً 12 طلباً معلقاً يحتاج لمراجعتك</span>
      <RouterLink :to="{ name: 'admin-requests' }" class="mr-auto text-body-sm font-bold text-error underline">
        عرض الطلبات
      </RouterLink>
    </div>

    <!-- KPI Grid -->
    <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
      <!-- Pending units CTA -->
      <div class="col-span-2 p-5 bg-white rounded-2xl border border-outline-variant shadow-sm flex flex-col gap-4 relative overflow-hidden">
        <div class="absolute top-0 right-0 w-1.5 h-full bg-amber-500 rounded-r-2xl"></div>
        <div class="flex justify-between items-start">
          <div>
            <span class="text-on-surface-variant font-label-caps text-label-caps mb-1 block">وحدات تنتظر الموافقة</span>
            <div class="font-display-lg text-display-lg text-on-surface leading-none">08</div>
          </div>
          <div class="p-2 bg-amber-50 rounded-lg">
            <span class="material-symbols-outlined text-amber-600">pending_actions</span>
          </div>
        </div>
        <RouterLink
          :to="{ name: 'admin-requests' }"
          class="w-full py-3 bg-amber-600 text-white rounded-lg font-title-sm text-center hover:bg-amber-700 transition-colors"
        >
          عرض الطلبات
        </RouterLink>
      </div>

      <div v-for="stat in kpiStats" :key="stat.label" class="p-4 bg-white rounded-2xl border border-outline-variant shadow-sm flex flex-col gap-2">
        <div class="p-2 bg-surface-container w-fit rounded-lg">
          <span class="material-symbols-outlined text-primary">{{ stat.icon }}</span>
        </div>
        <span class="text-on-surface-variant font-label-caps text-label-caps">{{ stat.label }}</span>
        <div class="font-display-lg text-display-lg text-on-surface">{{ stat.value }}</div>
        <span v-if="stat.sub" class="text-body-sm text-on-surface-variant">{{ stat.sub }}</span>
      </div>
    </section>

    <!-- Charts row -->
    <section class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
      <!-- Revenue bar chart -->
      <div class="p-6 bg-white rounded-2xl border border-outline-variant shadow-sm">
        <div class="flex justify-between items-center mb-6">
          <h3 class="font-title-sm text-title-sm text-primary">الإيرادات الشهرية</h3>
          <span class="text-body-sm text-on-surface-variant">2026</span>
        </div>
        <div class="flex items-end justify-between h-40 gap-2 px-2">
          <div v-for="(bar, i) in revenueBars" :key="i" class="flex flex-col items-center gap-1 flex-1">
            <div class="w-full rounded-t-sm transition-all" :class="bar.active ? 'bg-primary' : 'bg-surface-container'" :style="`height: ${bar.h}%`"></div>
          </div>
        </div>
        <div class="flex justify-between mt-3 text-[10px] text-on-surface-variant font-numeric-data">
          <span v-for="m in months" :key="m">{{ m }}</span>
        </div>
      </div>

      <!-- Bookings donut -->
      <div class="p-6 bg-white rounded-2xl border border-outline-variant shadow-sm">
        <div class="flex justify-between items-center mb-6">
          <h3 class="font-title-sm text-title-sm text-primary">حالة الحجوزات</h3>
          <span class="text-body-sm text-on-surface-variant">هذا الأسبوع</span>
        </div>
        <div class="flex items-center gap-6">
          <div class="relative w-32 h-32 flex-shrink-0">
            <svg class="w-full h-full -rotate-90" viewBox="0 0 128 128">
              <circle cx="64" cy="64" r="50" fill="transparent" stroke="#e4f1e7" stroke-width="12" />
              <circle cx="64" cy="64" r="50" fill="transparent" stroke="#163c24" stroke-width="12" stroke-dasharray="314" stroke-dashoffset="80" />
            </svg>
            <div class="absolute inset-0 flex flex-col items-center justify-center">
              <span class="font-numeric-data text-2xl font-bold text-on-surface leading-none">62</span>
              <span class="text-[10px] text-on-surface-variant">حجز</span>
            </div>
          </div>
          <div class="flex flex-col gap-3 flex-1">
            <div class="flex items-center gap-2">
              <div class="w-3 h-3 rounded-full bg-primary"></div>
              <span class="text-body-sm text-on-surface-variant flex-1">مكتمل</span>
              <span class="font-numeric-data text-body-sm font-bold">48</span>
            </div>
            <div class="flex items-center gap-2">
              <div class="w-3 h-3 rounded-full bg-amber-500"></div>
              <span class="text-body-sm text-on-surface-variant flex-1">قيد المعالجة</span>
              <span class="font-numeric-data text-body-sm font-bold">14</span>
            </div>
            <div class="flex items-center gap-2">
              <div class="w-3 h-3 rounded-full bg-error"></div>
              <span class="text-body-sm text-on-surface-variant flex-1">ملغي</span>
              <span class="font-numeric-data text-body-sm font-bold">0</span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Recent requests -->
    <section>
      <div class="flex justify-between items-center mb-4">
        <h3 class="font-title-sm text-title-sm text-primary">آخر الطلبات</h3>
        <RouterLink :to="{ name: 'admin-requests' }" class="text-primary text-body-sm underline">عرض الكل</RouterLink>
      </div>
      <div class="bg-white rounded-2xl border border-outline-variant shadow-sm overflow-hidden">
        <div v-for="(req, i) in recentRequests" :key="i" class="flex items-center justify-between p-4 border-b border-outline-variant/50 last:border-0 hover:bg-surface-container-low transition-colors">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-surface-container flex items-center justify-center flex-shrink-0">
              <span class="material-symbols-outlined text-primary">person</span>
            </div>
            <div>
              <div class="font-body-md font-semibold text-on-surface">{{ req.name }}</div>
              <div class="text-body-sm text-on-surface-variant">{{ req.unit }}</div>
            </div>
          </div>
          <div class="flex items-center gap-3">
            <span class="px-3 py-1 rounded-full text-[12px] font-medium" :class="statusClass(req.status)">{{ req.statusLabel }}</span>
            <RouterLink :to="{ name: 'admin-request-detail', params: { id: req.id } }" class="text-on-surface-variant hover:text-primary">
              <span class="material-symbols-outlined text-[18px]">chevron_left</span>
            </RouterLink>
          </div>
        </div>
      </div>
    </section>
  </AdminLayout>
</template>

<script setup>
import AdminLayout from '@/layouts/AdminLayout.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const kpiStats = [
  { label: 'إجمالي الوحدات', icon: 'apartment',   value: '142' },
  { label: 'نسبة الإشغال',   icon: 'trending_up',  value: '84%' },
  { label: 'إجمالي المستخدمين', icon: 'group',    value: '1,248' },
  { label: 'إيرادات الشهر',  icon: 'payments',     value: '128K', sub: 'ر.س' },
]

const revenueBars = [
  { h: 75, active: false },
  { h: 50, active: false },
  { h: 85, active: true  },
  { h: 65, active: false },
  { h: 80, active: false },
  { h: 50, active: false },
]

const months = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو']

const recentRequests = [
  { id: 1, name: 'محمد الفهد',   unit: 'فيلا مودرن - الرياض',  status: 'pending',  statusLabel: 'معلق' },
  { id: 2, name: 'سارة العتيبي', unit: 'شقة استوديو - جدة',     status: 'approved', statusLabel: 'مقبول' },
  { id: 3, name: 'خالد الشمري', unit: 'شالية بالدمام',          status: 'pending',  statusLabel: 'معلق' },
  { id: 4, name: 'نورة القحطاني',unit: 'شقة عائلية - مكة',      status: 'rejected', statusLabel: 'مرفوض' },
]

function statusClass(status) {
  return {
    pending:  'bg-amber-100 text-amber-700',
    approved: 'bg-emerald-100 text-emerald-700',
    rejected: 'bg-red-100 text-red-700',
  }[status] || 'bg-surface-container text-on-surface-variant'
}
</script>
