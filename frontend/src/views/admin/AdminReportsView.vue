<template>
  <AdminLayout>
    <div class="mb-8 flex items-end justify-between">
      <div>
        <h1 class="font-display-lg text-display-lg text-primary mb-1">تقارير النظام</h1>
        <p class="text-on-surface-variant text-body-md">تحليل الأداء والإيرادات والنشاط</p>
      </div>
      <div class="flex gap-3">
        <select class="px-4 py-2.5 bg-white border border-outline-variant rounded-xl text-body-sm focus:ring-2 focus:ring-primary/20 outline-none">
          <option>يونيو 2026</option>
          <option>مايو 2026</option>
          <option>أبريل 2026</option>
        </select>
        <button class="flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors">
          <span class="material-symbols-outlined text-[18px]">download</span>
          تصدير PDF
        </button>
      </div>
    </div>

    <!-- KPI Summary -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
      <div v-for="stat in kpis" :key="stat.label" class="p-5 bg-white rounded-2xl border border-outline-variant shadow-sm">
        <div class="flex items-start justify-between mb-3">
          <div class="p-2 rounded-lg" :class="stat.iconBg">
            <span class="material-symbols-outlined" :class="stat.iconColor">{{ stat.icon }}</span>
          </div>
          <span class="text-[12px] font-bold" :class="stat.trend > 0 ? 'text-emerald-600' : 'text-error'">
            {{ stat.trend > 0 ? '+' : '' }}{{ stat.trend }}%
          </span>
        </div>
        <p class="font-label-caps text-label-caps text-on-surface-variant mb-1">{{ stat.label }}</p>
        <p class="font-numeric-data text-xl font-bold text-on-surface">{{ stat.value }}</p>
        <p class="text-[11px] text-on-surface-variant mt-0.5">{{ stat.sub }}</p>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
      <!-- Revenue chart -->
      <div class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6">
        <div class="flex justify-between items-center mb-6">
          <h3 class="font-title-sm text-title-sm text-primary">الإيرادات الشهرية (ر.س)</h3>
          <span class="material-symbols-outlined text-on-surface-variant cursor-pointer">more_vert</span>
        </div>
        <div class="flex items-end justify-between h-48 gap-2 px-1 mb-3">
          <div v-for="(bar, i) in revenueData" :key="i" class="flex flex-col items-center gap-1 flex-1">
            <span class="font-numeric-data text-[10px] text-on-surface-variant">{{ bar.label }}</span>
            <div
              class="w-full rounded-t-lg transition-all relative group"
              :class="bar.current ? 'bg-primary' : 'bg-surface-container hover:bg-primary/30'"
              :style="`height: ${bar.h}%`"
            ></div>
          </div>
        </div>
        <div class="flex justify-between text-[10px] text-on-surface-variant font-numeric-data">
          <span v-for="bar in revenueData" :key="bar.month">{{ bar.month }}</span>
        </div>
      </div>

      <!-- Units by status -->
      <div class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6">
        <h3 class="font-title-sm text-title-sm text-primary mb-6">توزيع الوحدات</h3>
        <div class="flex items-center gap-6 mb-6">
          <div class="relative w-36 h-36 flex-shrink-0">
            <svg class="w-full h-full -rotate-90" viewBox="0 0 128 128">
              <circle cx="64" cy="64" r="50" fill="transparent" stroke="#e4f1e7" stroke-width="14" />
              <circle cx="64" cy="64" r="50" fill="transparent" stroke="#163c24" stroke-width="14" stroke-dasharray="314" stroke-dashoffset="47" />
              <circle cx="64" cy="64" r="50" fill="transparent" stroke="#d97706" stroke-width="14" stroke-dasharray="314" stroke-dashoffset="235" />
            </svg>
            <div class="absolute inset-0 flex flex-col items-center justify-center">
              <span class="font-numeric-data text-2xl font-bold leading-none">142</span>
              <span class="text-[11px] text-on-surface-variant">وحدة</span>
            </div>
          </div>
          <div class="flex flex-col gap-3 flex-1">
            <div v-for="seg in unitSegments" :key="seg.label" class="flex items-center gap-2">
              <div class="w-3 h-3 rounded-full flex-shrink-0" :class="seg.color"></div>
              <span class="text-body-sm text-on-surface-variant flex-1">{{ seg.label }}</span>
              <span class="font-numeric-data text-body-sm font-bold">{{ seg.count }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Bookings by city -->
    <div class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6 mb-6">
      <h3 class="font-title-sm text-title-sm text-primary mb-6">الحجوزات حسب المدينة</h3>
      <div class="space-y-4">
        <div v-for="city in cityData" :key="city.name" class="flex items-center gap-4">
          <div class="w-16 text-body-sm text-on-surface font-bold text-right flex-shrink-0">{{ city.name }}</div>
          <div class="flex-1 bg-surface-container rounded-full h-3 overflow-hidden">
            <div class="h-full bg-primary rounded-full transition-all" :style="`width: ${city.pct}%`"></div>
          </div>
          <span class="font-numeric-data text-body-sm text-on-surface w-12 text-left flex-shrink-0">{{ city.count }}</span>
        </div>
      </div>
    </div>

    <!-- Top units table -->
    <div class="bg-white rounded-2xl border border-outline-variant shadow-sm overflow-hidden">
      <div class="p-6 border-b border-outline-variant">
        <h3 class="font-title-sm text-title-sm text-primary">أفضل الوحدات أداءً</h3>
      </div>
      <table class="w-full">
        <thead>
          <tr class="bg-surface-container-low">
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">#</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">الوحدة</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant hidden md:table-cell">المدينة</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">الحجوزات</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">الإيراد</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(unit, i) in topUnits" :key="unit.name" class="border-t border-outline-variant/50 hover:bg-surface-container-low/50">
            <td class="py-3 px-4 font-numeric-data text-on-surface-variant">{{ i + 1 }}</td>
            <td class="py-3 px-4 font-body-md font-semibold text-on-surface">{{ unit.name }}</td>
            <td class="py-3 px-4 text-body-sm text-on-surface-variant hidden md:table-cell">{{ unit.city }}</td>
            <td class="py-3 px-4 font-numeric-data text-body-sm text-on-surface">{{ unit.bookings }}</td>
            <td class="py-3 px-4 font-numeric-data text-body-sm font-bold text-primary">{{ unit.revenue }} ر.س</td>
          </tr>
        </tbody>
      </table>
    </div>
  </AdminLayout>
</template>

<script setup>
import AdminLayout from '@/layouts/AdminLayout.vue'

const kpis = [
  { label: 'إجمالي الإيرادات', value: '128,400 ر.س', sub: 'هذا الشهر', trend: 12.4, icon: 'payments',     iconBg: 'bg-emerald-50', iconColor: 'text-emerald-600' },
  { label: 'نسبة الإشغال',     value: '84%',          sub: 'من 142 وحدة',trend: 3.1, icon: 'trending_up',  iconBg: 'bg-blue-50',    iconColor: 'text-blue-600' },
  { label: 'متوسط مدة الإقامة',value: '3.2 ليالٍ',    sub: 'لكل حجز',  trend: -0.5, icon: 'calendar_today',iconBg: 'bg-purple-50', iconColor: 'text-purple-600' },
  { label: 'تقييم المنصة',     value: '4.7/5',         sub: 'من 890 تقييم',trend: 1.2,icon: 'star',         iconBg: 'bg-amber-50',   iconColor: 'text-amber-600' },
]

const revenueData = [
  { month: 'يناير',  label: '92K',  h: 55, current: false },
  { month: 'فبراير', label: '78K',  h: 45, current: false },
  { month: 'مارس',   label: '110K', h: 65, current: false },
  { month: 'أبريل',  label: '95K',  h: 57, current: false },
  { month: 'مايو',   label: '118K', h: 71, current: false },
  { month: 'يونيو',  label: '128K', h: 80, current: true },
]

const unitSegments = [
  { label: 'معتمدة',       count: 118, color: 'bg-primary' },
  { label: 'قيد الموافقة', count: 16,  color: 'bg-amber-500' },
  { label: 'مرفوضة',       count: 8,   color: 'bg-error' },
]

const cityData = [
  { name: 'الرياض', count: 89,  pct: 100 },
  { name: 'جدة',    count: 34,  pct: 38 },
  { name: 'مكة',    count: 12,  pct: 13 },
  { name: 'الدمام', count: 7,   pct: 8 },
]

const topUnits = [
  { name: 'شالية فاخر - الملقا',     city: 'الرياض',  bookings: 24, revenue: '57,600' },
  { name: 'شقة ديلوكس - النخيل',    city: 'الرياض',  bookings: 18, revenue: '12,600' },
  { name: 'استوديو - جدة الشمالية', city: 'جدة',     bookings: 31, revenue: '13,950' },
  { name: 'فيلا الياسمين',           city: 'الرياض',  bookings: 9,  revenue: '40,500' },
  { name: 'غرفة ماستر - الدمام',    city: 'الدمام',  bookings: 22, revenue: '7,040' },
]
</script>
