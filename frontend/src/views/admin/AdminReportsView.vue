<template>
  <AdminLayout>
    <div class="mb-8">
      <h1 class="font-display-lg text-display-lg text-primary mb-1">تقارير النظام</h1>
      <p class="text-on-surface-variant text-body-md">تحليل الأداء والإيرادات والنشاط</p>
    </div>

    <div v-if="loading" class="grid grid-cols-2 lg:grid-cols-4 gap-4">
      <div v-for="i in 4" :key="i" class="h-28 bg-white rounded-2xl border border-outline-variant animate-pulse"></div>
    </div>

    <template v-else>
      <!-- KPI summary -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div v-for="stat in kpiCards" :key="stat.label" class="p-5 bg-white rounded-2xl border border-outline-variant shadow-sm">
          <div class="p-2 rounded-lg w-fit mb-3" :class="stat.iconBg">
            <span class="material-symbols-outlined" :class="stat.iconColor">{{ stat.icon }}</span>
          </div>
          <p class="font-label-caps text-label-caps text-on-surface-variant mb-1">{{ stat.label }}</p>
          <p class="font-numeric-data text-xl font-bold text-on-surface">{{ stat.value }}</p>
          <p v-if="stat.sub" class="text-[11px] text-on-surface-variant mt-0.5">{{ stat.sub }}</p>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <!-- Revenue chart -->
        <div class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6">
          <h3 class="font-title-sm text-title-sm text-primary mb-6">الإيرادات الشهرية (ر.س)</h3>
          <div v-if="maxRevenue > 0" class="flex items-end justify-between h-48 gap-2 px-1 mb-3">
            <div v-for="(bar, i) in data.monthly_revenue" :key="i" class="flex flex-col items-center gap-1 flex-1 h-full justify-end group">
              <span class="font-numeric-data text-[10px] text-on-surface-variant opacity-0 group-hover:opacity-100 transition-opacity">{{ formatMoney(bar.total) }}</span>
              <div class="w-full rounded-t-lg transition-all" :class="i === data.monthly_revenue.length - 1 ? 'bg-primary' : 'bg-surface-container'" :style="`height: ${barHeight(bar.total)}%`"></div>
            </div>
          </div>
          <div v-else class="h-48 flex items-center justify-center text-on-surface-variant text-body-sm">لا توجد إيرادات بعد</div>
          <div class="flex justify-between text-[10px] text-on-surface-variant font-numeric-data">
            <span v-for="bar in data.monthly_revenue" :key="bar.month">{{ bar.label }}</span>
          </div>
        </div>

        <!-- Units distribution -->
        <div class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6">
          <h3 class="font-title-sm text-title-sm text-primary mb-6">توزيع الوحدات</h3>
          <div class="flex items-center gap-6">
            <div class="relative w-36 h-36 flex-shrink-0">
              <svg class="w-full h-full -rotate-90" viewBox="0 0 128 128">
                <circle cx="64" cy="64" r="50" fill="transparent" stroke="#e4f1e7" stroke-width="14" />
                <circle v-for="seg in donutSegments" :key="seg.label" cx="64" cy="64" r="50" fill="transparent"
                        :stroke="seg.color" stroke-width="14" :stroke-dasharray="seg.dash"
                        :stroke-dashoffset="seg.offset" />
              </svg>
              <div class="absolute inset-0 flex flex-col items-center justify-center">
                <span class="font-numeric-data text-2xl font-bold leading-none">{{ data.units_by_status.total }}</span>
                <span class="text-[11px] text-on-surface-variant">وحدة</span>
              </div>
            </div>
            <div class="flex flex-col gap-3 flex-1">
              <div v-for="seg in unitLegend" :key="seg.label" class="flex items-center gap-2">
                <div class="w-3 h-3 rounded-full flex-shrink-0" :style="`background:${seg.color}`"></div>
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
        <div v-if="data.bookings_by_city.length === 0" class="text-center text-on-surface-variant text-body-sm py-6">لا توجد حجوزات بعد</div>
        <div v-else class="space-y-4">
          <div v-for="city in data.bookings_by_city" :key="city.city" class="flex items-center gap-4">
            <div class="w-20 text-body-sm text-on-surface font-bold text-right flex-shrink-0 truncate">{{ city.city }}</div>
            <div class="flex-1 bg-surface-container rounded-full h-3 overflow-hidden">
              <div class="h-full bg-primary rounded-full transition-all" :style="`width: ${cityPct(city.count)}%`"></div>
            </div>
            <span class="font-numeric-data text-body-sm text-on-surface w-10 text-left flex-shrink-0">{{ city.count }}</span>
          </div>
        </div>
      </div>

      <!-- Top units -->
      <div class="bg-white rounded-2xl border border-outline-variant shadow-sm overflow-hidden">
        <div class="p-6 border-b border-outline-variant">
          <h3 class="font-title-sm text-title-sm text-primary">أفضل الوحدات أداءً</h3>
        </div>
        <div v-if="data.top_units.length === 0" class="text-center text-on-surface-variant text-body-sm py-10">لا توجد بيانات كافية بعد</div>
        <table v-else class="w-full">
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
            <tr v-for="(unit, i) in data.top_units" :key="i" class="border-t border-outline-variant/50 hover:bg-surface-container-low/50">
              <td class="py-3 px-4 font-numeric-data text-on-surface-variant">{{ i + 1 }}</td>
              <td class="py-3 px-4 font-body-md font-semibold text-on-surface">{{ unit.name }}</td>
              <td class="py-3 px-4 text-body-sm text-on-surface-variant hidden md:table-cell">{{ unit.city }}</td>
              <td class="py-3 px-4 font-numeric-data text-body-sm text-on-surface">{{ unit.bookings }}</td>
              <td class="py-3 px-4 font-numeric-data text-body-sm font-bold text-primary">{{ formatMoney(unit.revenue) }} ر.س</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </AdminLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { adminApi } from '@/api/admin'

const loading = ref(true)
const data = ref({
  kpis: { total_revenue: 0, occupancy_rate: 0, avg_nights: 0, avg_rating: 0, reviews_count: 0 },
  monthly_revenue: [],
  units_by_status: { total: 0, approved: 0, pending: 0, rejected: 0, draft: 0 },
  bookings_by_city: [],
  top_units: [],
})

const circumference = 2 * Math.PI * 50

const kpiCards = computed(() => [
  { label: 'إجمالي الإيرادات', value: `${formatMoney(data.value.kpis.total_revenue)} ر.س`, sub: 'حجوزات مؤكدة', icon: 'payments',      iconBg: 'bg-emerald-50', iconColor: 'text-emerald-600' },
  { label: 'نسبة الإشغال',     value: `${data.value.kpis.occupancy_rate}%`, sub: 'من الوحدات المعتمدة', icon: 'trending_up', iconBg: 'bg-blue-50',    iconColor: 'text-blue-600' },
  { label: 'متوسط مدة الإقامة', value: `${data.value.kpis.avg_nights} ليلة`, sub: 'لكل حجز مؤكد', icon: 'calendar_today',          iconBg: 'bg-purple-50',  iconColor: 'text-purple-600' },
  { label: 'تقييم المنصة',     value: data.value.kpis.reviews_count > 0 ? `${data.value.kpis.avg_rating}/5` : '—', sub: `${data.value.kpis.reviews_count} تقييم`, icon: 'star', iconBg: 'bg-amber-50', iconColor: 'text-amber-600' },
])

const maxRevenue = computed(() => Math.max(0, ...data.value.monthly_revenue.map((m) => m.total)))

const unitLegend = computed(() => [
  { label: 'معتمدة',       count: data.value.units_by_status.approved, color: '#163c24' },
  { label: 'قيد المراجعة', count: data.value.units_by_status.pending,  color: '#d97706' },
  { label: 'مرفوضة',       count: data.value.units_by_status.rejected, color: '#ba1a1a' },
  { label: 'مسودة',        count: data.value.units_by_status.draft,    color: '#c1c8c0' },
])

// Stacked donut: each arc is a dash of its own length, positioned by a
// negative offset equal to the cumulative length of preceding arcs.
const donutSegments = computed(() => {
  const total = data.value.units_by_status.total || 1
  let cumulative = 0
  return unitLegend.value
    .filter((s) => s.count > 0)
    .map((s) => {
      const segLen = (s.count / total) * circumference
      const seg = {
        label: s.label,
        color: s.color,
        dash: `${segLen} ${circumference - segLen}`,
        offset: -cumulative,
      }
      cumulative += segLen
      return seg
    })
})

const maxCity = computed(() => Math.max(1, ...data.value.bookings_by_city.map((c) => c.count)))

function barHeight(v) {
  return maxRevenue.value <= 0 ? 0 : Math.max(4, Math.round((v / maxRevenue.value) * 100))
}
function cityPct(count) {
  return Math.round((count / maxCity.value) * 100)
}
function formatMoney(v) {
  return new Intl.NumberFormat('en-US').format(Number(v) || 0)
}

onMounted(async () => {
  try {
    const res = await adminApi.reports()
    data.value = res.data.data ?? res.data
  } catch (e) {
    // keep defaults
  } finally {
    loading.value = false
  }
})
</script>
