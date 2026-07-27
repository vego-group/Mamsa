<template>
  <AdminLayout>
    <!-- Title row -->
    <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
      <div>
        <h1 class="text-[24px] font-bold text-gray-900 leading-tight">{{ t('approvals.title') }}</h1>
        <p class="text-gray-500 text-[14px] mt-0.5">{{ t('approvals.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2">
        <select v-model="statusFilter" @change="reload" class="h-9 px-3 rounded-lg border border-gray-200 bg-white text-[13px] font-semibold text-gray-600 outline-none focus:ring-2 focus:ring-primary/15">
          <option value="pending">{{ t('units.apprPending') }}</option>
          <option value="approved">{{ t('units.apprApproved') }}</option>
          <option value="rejected">{{ t('units.apprRejected') }}</option>
          <option value="">{{ t('units.allStatuses') }}</option>
        </select>
      </div>
    </div>

    <!-- Stat cards -->
    <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
      <div v-for="s in statCards" :key="s.key" class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
        <span class="material-symbols-outlined text-[26px] mb-2 inline-block" :class="s.color">{{ s.icon }}</span>
        <p class="text-[28px] font-bold text-gray-900 leading-none tabular-nums">{{ s.value }}</p>
        <p class="text-[12px] text-gray-400 mt-1.5">{{ s.label }}</p>
      </div>
    </section>

    <!-- Loading -->
    <div v-if="loading" class="space-y-3">
      <div v-for="i in 5" :key="i" class="h-24 bg-white rounded-2xl border border-gray-200 animate-pulse"></div>
    </div>

    <div v-else-if="requests.length === 0" class="py-16 text-center text-gray-400 text-[13px] bg-white rounded-2xl border border-gray-200">
      <span class="material-symbols-outlined text-4xl opacity-40 block mb-2">inbox</span>{{ t('approvals.empty') }}
    </div>

    <!-- Request rows -->
    <div v-else class="space-y-3">
      <div v-for="r in requests" :key="r.id" class="bg-white rounded-2xl border border-gray-200 p-4 flex items-center gap-4 hover:border-gray-300 transition-colors">
        <div class="w-11 h-11 rounded-xl bg-gray-50 border border-gray-100 flex items-center justify-center flex-shrink-0">
          <span class="material-symbols-outlined text-[22px] text-primary">apartment</span>
        </div>
        <div class="min-w-0 flex-1">
          <div class="flex items-center gap-2 mb-0.5">
            <span class="text-[13px] font-bold text-gray-900 tabular-nums">{{ r.code || `APR-${r.id}` }}</span>
            <span class="inline-flex items-center gap-1 text-[12px] font-semibold" :class="priorityMeta(r).text">
              <span class="w-1.5 h-1.5 rounded-full" :class="priorityMeta(r).dot"></span>{{ priorityMeta(r).label }}
            </span>
          </div>
          <p class="text-[14px] font-semibold text-gray-800 truncate">{{ r.unit_name || r.name }}</p>
          <p class="text-[12px] text-gray-400 truncate">{{ date(r.created_at) }} · {{ r.name }}</p>
        </div>
        <span v-if="r.unit_type" class="hidden sm:inline-block px-2.5 py-0.5 rounded-full bg-gray-100 text-gray-600 text-[12px] flex-shrink-0">{{ r.unit_type }}</span>
        <RouterLink :to="{ name: 'admin-request-detail', params: { id: r.id } }" class="inline-flex items-center gap-1.5 h-9 px-4 rounded-lg bg-primary text-white text-[13px] font-semibold hover:bg-primary-container transition-colors flex-shrink-0">
          <span class="material-symbols-outlined text-[16px]">visibility</span>{{ t('approvals.review') }}
        </RouterLink>
      </div>
    </div>

    <!-- Pagination -->
    <div v-if="requests.length && meta.last_page > 1" class="flex items-center justify-center gap-1 mt-5">
      <button class="w-8 h-8 rounded-lg border border-gray-200 text-gray-500 disabled:opacity-40 hover:bg-gray-50 grid place-items-center" :disabled="page <= 1" @click="goPage(page - 1)"><span class="material-symbols-outlined text-[18px]">{{ dir === 'rtl' ? 'chevron_right' : 'chevron_left' }}</span></button>
      <span class="px-3 text-[13px] text-gray-500 tabular-nums">{{ page }} / {{ meta.last_page }}</span>
      <button class="w-8 h-8 rounded-lg border border-gray-200 text-gray-500 disabled:opacity-40 hover:bg-gray-50 grid place-items-center" :disabled="page >= meta.last_page" @click="goPage(page + 1)"><span class="material-symbols-outlined text-[18px]">{{ dir === 'rtl' ? 'chevron_left' : 'chevron_right' }}</span></button>
    </div>
  </AdminLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { adminApi } from '@/api/admin'
import { useAdminI18n } from '@/i18n/admin'
import { useAdminFormat } from '@/composables/useAdminFormat'

const { t, dir } = useAdminI18n()
const { date } = useAdminFormat()

const loading = ref(true)
const requests = ref([])
const stats = ref({ pending: 0, approved_today: 0, rejected_today: 0, avg_review_hours: 0 })
const meta = ref({ current_page: 1, last_page: 1, total: 0 })
const page = ref(1)
const statusFilter = ref('pending')

const statCards = computed(() => [
  { key: 'pending',  icon: 'schedule',    color: 'text-amber-500',   value: stats.value.pending,        label: t('approvals.pendingReview') },
  { key: 'approved', icon: 'check_circle', color: 'text-emerald-500', value: stats.value.approved_today, label: t('approvals.approvedToday') },
  { key: 'rejected', icon: 'cancel',       color: 'text-red-500',     value: stats.value.rejected_today, label: t('approvals.rejectedToday') },
  { key: 'avg',      icon: 'timer',        color: 'text-gray-400',    value: `${stats.value.avg_review_hours}h`, label: t('approvals.avgReviewTime') },
])

// No priority column in the schema — derive from how long it has waited.
function priorityMeta(r) {
  const days = r.created_at ? (Date.now() - new Date(r.created_at).getTime()) / 86400000 : 0
  if (days >= 5) return { label: t('approvals.prioHigh'), text: 'text-red-500', dot: 'bg-red-500' }
  if (days >= 2) return { label: t('approvals.prioNormal'), text: 'text-emerald-600', dot: 'bg-emerald-500' }
  return { label: t('approvals.prioLow'), text: 'text-gray-400', dot: 'bg-gray-400' }
}

async function load() {
  loading.value = true
  try {
    const params = { page: page.value }
    if (statusFilter.value) params.status = statusFilter.value
    const { data } = await adminApi.listRequests(params)
    requests.value = data.data ?? []
    stats.value = data.stats ?? stats.value
    meta.value = data.meta ?? meta.value
  } catch {
    /* transient */
  } finally {
    loading.value = false
  }
}
function reload() { page.value = 1; load() }
function goPage(p) { if (p < 1 || p > meta.value.last_page) return; page.value = p; load() }

onMounted(load)
</script>
