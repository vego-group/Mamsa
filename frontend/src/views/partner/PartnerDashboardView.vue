<template>
  <PartnerLayout>
    <!-- Welcome -->
    <div class="mb-8 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
      <div>
        <h1 class="font-display-lg text-display-lg text-primary mb-1">أهلاً، {{ auth.user?.name || 'شريك' }}</h1>
        <p class="text-on-surface-variant text-body-md">نظرة عامة على وحداتك وحجوزاتك</p>
      </div>
      <RouterLink
        :to="{ name: 'partner-unit-form' }"
        class="flex items-center gap-2 px-5 py-3 bg-primary text-on-primary rounded-xl font-bold shadow-sm hover:bg-primary-container transition-colors w-fit"
      >
        <span class="material-symbols-outlined text-[18px]">add</span>
        أضف وحدة جديدة
      </RouterLink>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-20 text-on-surface-variant">
      <span class="material-symbols-outlined animate-spin text-3xl">progress_activity</span>
    </div>

    <template v-else>
      <!-- Pending alert -->
      <div
        v-if="stats.units.pending > 0"
        class="mb-8 p-4 bg-amber-50 text-amber-800 rounded-xl flex items-center gap-4 border border-amber-200"
      >
        <span class="material-symbols-outlined text-amber-600">pending_actions</span>
        <span class="font-title-sm text-title-sm">
          لديك {{ stats.units.pending }} وحدة قيد المراجعة من قبل الإدارة
        </span>
      </div>

      <!-- KPI grid -->
      <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div v-for="kpi in kpis" :key="kpi.label" class="p-5 bg-white rounded-2xl border border-outline-variant shadow-sm">
          <div class="p-2 rounded-lg w-fit mb-3" :class="kpi.iconBg">
            <span class="material-symbols-outlined" :class="kpi.iconColor">{{ kpi.icon }}</span>
          </div>
          <p class="font-label-caps text-label-caps text-on-surface-variant mb-1">{{ kpi.label }}</p>
          <p class="font-display-lg text-display-lg text-on-surface leading-none">{{ kpi.value }}</p>
          <p v-if="kpi.sub" class="text-body-sm text-on-surface-variant mt-1">{{ kpi.sub }}</p>
        </div>
      </section>

      <!-- Units status breakdown + quick actions -->
      <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Units status -->
        <div class="lg:col-span-2 bg-white rounded-2xl border border-outline-variant shadow-sm p-6">
          <h3 class="font-title-sm text-title-sm text-primary mb-5">حالة الوحدات</h3>
          <div class="space-y-4">
            <div v-for="row in unitStatusRows" :key="row.label" class="flex items-center gap-4">
              <div class="w-24 flex items-center gap-2 flex-shrink-0">
                <span class="w-2.5 h-2.5 rounded-full" :class="row.dot"></span>
                <span class="text-body-sm text-on-surface">{{ row.label }}</span>
              </div>
              <div class="flex-1 bg-surface-container rounded-full h-2.5 overflow-hidden">
                <div class="h-full rounded-full transition-all" :class="row.bar" :style="`width: ${row.pct}%`"></div>
              </div>
              <span class="font-numeric-data text-body-sm font-bold w-8 text-left flex-shrink-0">{{ row.count }}</span>
            </div>
          </div>
        </div>

        <!-- Quick links -->
        <div class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6">
          <h3 class="font-title-sm text-title-sm text-primary mb-5">إجراءات سريعة</h3>
          <div class="space-y-3">
            <RouterLink :to="{ name: 'partner-unit-form' }" class="flex items-center gap-3 p-3 rounded-xl border border-outline-variant hover:bg-surface-container-low transition-colors">
              <span class="material-symbols-outlined text-primary">add_home</span>
              <span class="text-body-md font-semibold">إضافة وحدة</span>
            </RouterLink>
            <RouterLink :to="{ name: 'partner-units' }" class="flex items-center gap-3 p-3 rounded-xl border border-outline-variant hover:bg-surface-container-low transition-colors">
              <span class="material-symbols-outlined text-primary">apartment</span>
              <span class="text-body-md font-semibold">إدارة وحداتي</span>
            </RouterLink>
            <RouterLink :to="{ name: 'partner-bookings' }" class="flex items-center gap-3 p-3 rounded-xl border border-outline-variant hover:bg-surface-container-low transition-colors">
              <span class="material-symbols-outlined text-primary">calendar_today</span>
              <span class="text-body-md font-semibold">عرض الحجوزات</span>
            </RouterLink>
          </div>
        </div>
      </section>
    </template>
  </PartnerLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import PartnerLayout from '@/layouts/PartnerLayout.vue'
import { partnerApi } from '@/api/partner'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const loading = ref(true)

const stats = ref({
  units: { total: 0, pending: 0, approved: 0 },
  bookings: { total: 0, confirmed: 0 },
  revenue: { total: 0, gross: 0, commission: 0, net: 0, currency: 'SAR' },
})

const kpis = computed(() => [
  { label: 'إجمالي الوحدات', value: stats.value.units.total,    icon: 'apartment',      iconBg: 'bg-blue-50',    iconColor: 'text-blue-600' },
  { label: 'وحدات معتمدة',   value: stats.value.units.approved, icon: 'check_circle',   iconBg: 'bg-emerald-50', iconColor: 'text-emerald-600' },
  { label: 'إجمالي الحجوزات',value: stats.value.bookings.total, icon: 'calendar_today', iconBg: 'bg-purple-50',  iconColor: 'text-purple-600' },
  { label: 'صافي الأرباح',   value: formatMoney(stats.value.revenue.net), sub: `ر.س — بعد عمولة ممسى (${formatMoney(stats.value.revenue.commission)} ر.س)`, icon: 'payments', iconBg: 'bg-amber-50', iconColor: 'text-amber-600' },
])

const unitStatusRows = computed(() => {
  const u = stats.value.units
  const total = u.total || 1
  const draftCount = Math.max(0, u.total - u.pending - u.approved)
  return [
    { label: 'معتمدة',       count: u.approved, pct: (u.approved / total) * 100, dot: 'bg-emerald-500', bar: 'bg-emerald-500' },
    { label: 'قيد المراجعة', count: u.pending,  pct: (u.pending / total) * 100,  dot: 'bg-amber-500',   bar: 'bg-amber-500' },
    { label: 'مسودة',        count: draftCount, pct: (draftCount / total) * 100, dot: 'bg-outline',     bar: 'bg-outline' },
  ]
})

function formatMoney(v) {
  return new Intl.NumberFormat('en-US').format(Number(v) || 0)
}

onMounted(async () => {
  try {
    const { data } = await partnerApi.dashboard()
    stats.value = data
  } catch (e) {
    // Silent fail keeps zeros; surfaced elsewhere if needed.
  } finally {
    loading.value = false
  }
})
</script>
