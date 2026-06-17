<template>
  <AdminLayout>
    <!-- Welcome header -->
    <div class="mb-8">
      <h1 class="font-display-lg text-display-lg text-primary mb-1">أهلاً بك، {{ auth.user?.name || 'المدير' }}</h1>
      <p class="text-on-surface-variant text-body-md">نظرة عامة على أداء العقارات اليوم</p>
    </div>

    <!-- Loading skeleton -->
    <div v-if="loading" class="grid grid-cols-2 lg:grid-cols-4 gap-4">
      <div v-for="i in 4" :key="i" class="h-28 bg-white rounded-2xl border border-outline-variant animate-pulse"></div>
    </div>

    <template v-else>
      <!-- Pending alert -->
      <div v-if="data.units.pending > 0" class="mb-8 p-4 bg-error-container text-on-error-container rounded-xl flex items-center gap-4 border border-error/20">
        <span class="material-symbols-outlined text-error">notification_important</span>
        <span class="font-title-sm text-title-sm">يوجد حالياً {{ data.units.pending }} وحدة معلقة تحتاج لمراجعتك</span>
        <RouterLink :to="{ name: 'admin-requests' }" class="mr-auto text-body-sm font-bold text-error underline whitespace-nowrap">عرض الطلبات</RouterLink>
      </div>

      <!-- KPI Grid -->
      <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <!-- Pending units CTA -->
        <div class="col-span-2 p-5 bg-white rounded-2xl border border-outline-variant shadow-sm flex flex-col gap-4 relative overflow-hidden">
          <div class="absolute top-0 right-0 w-1.5 h-full bg-amber-500 rounded-r-2xl"></div>
          <div class="flex justify-between items-start">
            <div>
              <span class="text-on-surface-variant font-label-caps text-label-caps mb-1 block">وحدات تنتظر الموافقة</span>
              <div class="font-display-lg text-display-lg text-on-surface leading-none">{{ pad(data.units.pending) }}</div>
            </div>
            <div class="p-2 bg-amber-50 rounded-lg">
              <span class="material-symbols-outlined text-amber-600">pending_actions</span>
            </div>
          </div>
          <RouterLink :to="{ name: 'admin-requests' }" class="w-full py-3 bg-amber-600 text-white rounded-lg font-title-sm text-center hover:bg-amber-700 transition-colors">
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
            <h3 class="font-title-sm text-title-sm text-primary">الإيرادات الشهرية (ر.س)</h3>
            <span class="text-body-sm text-on-surface-variant">آخر 6 أشهر</span>
          </div>
          <div v-if="maxRevenue > 0" class="flex items-end justify-between h-40 gap-2 px-2">
            <div v-for="(bar, i) in data.monthly_revenue" :key="i" class="flex flex-col items-center gap-1 flex-1 h-full justify-end group">
              <span class="text-[10px] text-on-surface-variant opacity-0 group-hover:opacity-100 transition-opacity font-numeric-data">{{ formatMoney(bar.total) }}</span>
              <div class="w-full rounded-t-sm transition-all" :class="i === data.monthly_revenue.length - 1 ? 'bg-primary' : 'bg-surface-container'" :style="`height: ${barHeight(bar.total)}%`"></div>
            </div>
          </div>
          <div v-else class="h-40 flex items-center justify-center text-on-surface-variant text-body-sm">لا توجد إيرادات بعد</div>
          <div class="flex justify-between mt-3 text-[10px] text-on-surface-variant font-numeric-data">
            <span v-for="bar in data.monthly_revenue" :key="bar.month">{{ bar.label }}</span>
          </div>
        </div>

        <!-- Bookings donut -->
        <div class="p-6 bg-white rounded-2xl border border-outline-variant shadow-sm">
          <div class="flex justify-between items-center mb-6">
            <h3 class="font-title-sm text-title-sm text-primary">حالة الحجوزات</h3>
            <span class="text-body-sm text-on-surface-variant">الإجمالي {{ data.bookings.total }}</span>
          </div>
          <div class="flex items-center gap-6">
            <div class="relative w-32 h-32 flex-shrink-0">
              <svg class="w-full h-full -rotate-90" viewBox="0 0 128 128">
                <circle cx="64" cy="64" r="50" fill="transparent" stroke="#e4f1e7" stroke-width="12" />
                <circle cx="64" cy="64" r="50" fill="transparent" stroke="#163c24" stroke-width="12"
                        :stroke-dasharray="circumference" :stroke-dashoffset="confirmedOffset" stroke-linecap="round" />
              </svg>
              <div class="absolute inset-0 flex flex-col items-center justify-center">
                <span class="font-numeric-data text-2xl font-bold text-on-surface leading-none">{{ data.bookings.confirmed }}</span>
                <span class="text-[10px] text-on-surface-variant">مؤكد</span>
              </div>
            </div>
            <div class="flex flex-col gap-3 flex-1">
              <div v-for="row in bookingRows" :key="row.label" class="flex items-center gap-2">
                <div class="w-3 h-3 rounded-full" :class="row.dot"></div>
                <span class="text-body-sm text-on-surface-variant flex-1">{{ row.label }}</span>
                <span class="font-numeric-data text-body-sm font-bold">{{ row.value }}</span>
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
        <div v-if="data.recent_requests.length === 0" class="bg-white rounded-2xl border border-outline-variant p-10 text-center text-on-surface-variant">
          <span class="material-symbols-outlined text-4xl mb-2 block">inbox</span>
          <p class="text-body-sm">لا توجد طلبات معلقة حالياً</p>
        </div>
        <div v-else class="bg-white rounded-2xl border border-outline-variant shadow-sm overflow-hidden">
          <RouterLink
            v-for="req in data.recent_requests"
            :key="req.id"
            :to="{ name: 'admin-request-detail', params: { id: req.id } }"
            class="flex items-center justify-between p-4 border-b border-outline-variant/50 last:border-0 hover:bg-surface-container-low transition-colors"
          >
            <div class="flex items-center gap-3 min-w-0">
              <div class="w-10 h-10 rounded-lg bg-surface-container flex items-center justify-center flex-shrink-0">
                <span class="material-symbols-outlined text-primary">{{ req.type === 'Company' ? 'business' : 'person' }}</span>
              </div>
              <div class="min-w-0">
                <div class="font-body-md font-semibold text-on-surface truncate">{{ req.name }}</div>
                <div class="text-body-sm text-on-surface-variant truncate">{{ req.unit_name }}{{ req.city ? ` - ${req.city}` : '' }}</div>
              </div>
            </div>
            <div class="flex items-center gap-3 flex-shrink-0">
              <span class="px-3 py-1 rounded-full text-[12px] font-medium bg-amber-100 text-amber-700">معلق</span>
              <span class="material-symbols-outlined text-[18px] text-on-surface-variant">chevron_left</span>
            </div>
          </RouterLink>
        </div>
      </section>
    </template>
  </AdminLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { adminApi } from '@/api/admin'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const loading = ref(true)

const data = ref({
  users: { total: 0, partners: 0, customers: 0 },
  units: { total: 0, draft: 0, pending: 0, approved: 0, rejected: 0 },
  bookings: { total: 0, pending: 0, confirmed: 0, cancelled: 0 },
  revenue: { total: 0, this_month: 0, currency: 'SAR' },
  occupancy_rate: 0,
  monthly_revenue: [],
  recent_requests: [],
})

const kpiStats = computed(() => [
  { label: 'إجمالي الوحدات', icon: 'apartment',   value: data.value.units.total },
  { label: 'نسبة الإشغال',   icon: 'trending_up',  value: `${data.value.occupancy_rate}%` },
  { label: 'إجمالي المستخدمين', icon: 'group',    value: formatMoney(data.value.users.total) },
  { label: 'إيرادات الشهر',  icon: 'payments',     value: formatMoney(data.value.revenue.this_month), sub: 'ر.س' },
])

const bookingRows = computed(() => [
  { label: 'مؤكد',       value: data.value.bookings.confirmed, dot: 'bg-primary' },
  { label: 'قيد المعالجة', value: data.value.bookings.pending,   dot: 'bg-amber-500' },
  { label: 'ملغي',       value: data.value.bookings.cancelled, dot: 'bg-error' },
])

const maxRevenue = computed(() => Math.max(0, ...data.value.monthly_revenue.map((m) => m.total)))

const circumference = 2 * Math.PI * 50 // r = 50
const confirmedOffset = computed(() => {
  const total = data.value.bookings.total || 1
  const frac = data.value.bookings.confirmed / total
  return circumference * (1 - frac)
})

function barHeight(v) {
  if (maxRevenue.value <= 0) return 0
  return Math.max(4, Math.round((v / maxRevenue.value) * 100))
}
function pad(n) {
  return String(n).padStart(2, '0')
}
function formatMoney(v) {
  return new Intl.NumberFormat('en-US').format(Number(v) || 0)
}

onMounted(async () => {
  try {
    const res = await adminApi.dashboard()
    data.value = res.data.data ?? res.data
  } catch (e) {
    // keep zeros on failure
  } finally {
    loading.value = false
  }
})
</script>
