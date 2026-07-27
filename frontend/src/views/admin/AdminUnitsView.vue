<template>
  <AdminLayout>
    <!-- Title row -->
    <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
      <div>
        <h1 class="text-[24px] font-bold text-gray-900 leading-tight">{{ t('units.title') }}</h1>
        <p class="text-gray-500 text-[14px] mt-0.5">{{ t('units.subtitle', { total: summary.total, published: summary.published }) }}</p>
      </div>
      <div class="flex items-center gap-2">
        <button class="inline-flex items-center gap-1.5 h-9 px-4 rounded-lg border border-gray-200 bg-white text-[13px] font-semibold text-gray-600 hover:bg-gray-50 transition-colors" @click="exportCsv">
          <span class="material-symbols-outlined text-[18px]">download</span>{{ t('units.export') }}
        </button>
        <RouterLink :to="{ name: 'admin-requests' }" class="inline-flex items-center gap-1.5 h-9 px-4 rounded-lg bg-primary text-white text-[13px] font-semibold hover:bg-primary-container transition-colors">
          <span class="material-symbols-outlined text-[18px]">add_home_work</span>{{ t('units.addUnit') }}
        </RouterLink>
      </div>
    </div>

    <!-- Stat cards -->
    <section class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
      <div v-for="s in statCards" :key="s.key" class="bg-white rounded-2xl border border-gray-200 p-5">
        <div class="w-10 h-10 rounded-xl bg-gray-50 border border-gray-100 flex items-center justify-center mb-4">
          <span class="material-symbols-outlined text-[20px] text-primary">{{ s.icon }}</span>
        </div>
        <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400 mb-1">{{ s.label }}</p>
        <p class="text-[24px] font-bold text-gray-900 leading-none tabular-nums">{{ s.value }}</p>
      </div>
    </section>

    <!-- Toolbar -->
    <div class="flex flex-wrap items-center gap-3 mb-5">
      <div class="relative flex-1 min-w-[200px]">
        <span class="material-symbols-outlined absolute top-1/2 -translate-y-1/2 text-gray-400 text-[18px]" :class="dir === 'rtl' ? 'right-3' : 'left-3'">search</span>
        <input v-model="search" @keyup.enter="reload" class="w-full h-10 bg-white border border-gray-200 rounded-lg text-[13px] outline-none focus:ring-2 focus:ring-primary/15 focus:border-primary/40" :class="dir === 'rtl' ? 'pr-9 pl-3' : 'pl-9 pr-3'" :placeholder="t('units.search')" />
      </div>
      <select v-model="statusFilter" @change="reload" class="h-10 px-3 bg-white border border-gray-200 rounded-lg text-[13px] text-gray-600 outline-none focus:ring-2 focus:ring-primary/15">
        <option value="">{{ t('units.allStatuses') }}</option>
        <option value="approved">{{ t('units.apprApproved') }}</option>
        <option value="pending">{{ t('units.apprPending') }}</option>
        <option value="rejected">{{ t('units.apprRejected') }}</option>
      </select>
      <select v-model="typeFilter" @change="reload" class="h-10 px-3 bg-white border border-gray-200 rounded-lg text-[13px] text-gray-600 outline-none focus:ring-2 focus:ring-primary/15">
        <option value="">{{ t('units.allTypes') }}</option>
        <option value="apartment">{{ t('units.typeApartment') }}</option>
        <option value="villa">{{ t('units.typeVilla') }}</option>
        <option value="studio">{{ t('units.typeStudio') }}</option>
      </select>
      <div class="flex items-center bg-white border border-gray-200 rounded-lg p-0.5">
        <button class="w-8 h-8 grid place-items-center rounded-md" :class="view === 'grid' ? 'bg-primary/10 text-primary' : 'text-gray-400'" @click="view = 'grid'"><span class="material-symbols-outlined text-[18px]">grid_view</span></button>
        <button class="w-8 h-8 grid place-items-center rounded-md" :class="view === 'list' ? 'bg-primary/10 text-primary' : 'text-gray-400'" @click="view = 'list'"><span class="material-symbols-outlined text-[18px]">view_list</span></button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <div v-for="i in 8" :key="i" class="h-[340px] bg-white rounded-2xl border border-gray-200 animate-pulse"></div>
    </div>

    <div v-else-if="units.length === 0" class="py-16 text-center text-gray-400 text-[13px] bg-white rounded-2xl border border-gray-200">
      <span class="material-symbols-outlined text-4xl opacity-40 block mb-2">apartment</span>{{ t('units.empty') }}
    </div>

    <!-- Card grid -->
    <div v-else-if="view === 'grid'" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <article v-for="u in units" :key="u.id" class="bg-white rounded-2xl border border-gray-200 overflow-hidden group">
        <div class="relative h-40 bg-gray-100">
          <img :src="u.image_url" :alt="u.name" class="w-full h-full object-cover" loading="lazy" />
          <span class="absolute top-2 flex items-center gap-1 px-2 py-1 rounded-md bg-white/90 backdrop-blur text-[11px] font-semibold" :class="[dir === 'rtl' ? 'right-2' : 'left-2', pubMeta(u.publication).text]">
            <span class="w-1.5 h-1.5 rounded-full" :class="pubMeta(u.publication).dot"></span>{{ pubMeta(u.publication).label }}
          </span>
          <span class="absolute top-2 flex items-center gap-1 px-2 py-1 rounded-md bg-white/90 backdrop-blur text-[11px] font-semibold" :class="[dir === 'rtl' ? 'left-2' : 'right-2', apprMeta(u.approval_status).text]">
            <span class="w-1.5 h-1.5 rounded-full" :class="apprMeta(u.approval_status).dot"></span>{{ apprMeta(u.approval_status).label }}
          </span>
          <button
            class="absolute bottom-2 w-8 h-8 grid place-items-center rounded-full bg-white/90 backdrop-blur transition-colors"
            :class="[dir === 'rtl' ? 'left-2' : 'right-2', u.is_featured ? 'text-amber-500' : 'text-gray-400 hover:text-amber-500']"
            :title="t('units.featured')" :disabled="busyId === u.id" @click="toggleFeatured(u)"
          >
            <span class="material-symbols-outlined text-[18px]" :style="u.is_featured ? `font-variation-settings:'FILL' 1` : ''">star</span>
          </button>
        </div>
        <div class="p-4">
          <h3 class="font-bold text-gray-900 text-[14px] truncate mb-1.5">{{ u.name }}</h3>
          <div class="flex items-center gap-2 text-[12px] text-gray-500 mb-3">
            <span class="flex items-center gap-0.5"><span class="material-symbols-outlined text-[15px]">location_on</span>{{ u.city }}</span>
            <span class="w-1 h-1 rounded-full bg-gray-300"></span>
            <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">{{ typeLabel(u.type) }}</span>
          </div>
          <div class="flex items-center gap-3 text-[12px] text-gray-600 mb-3">
            <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">bed</span>{{ u.beds }}</span>
            <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">group</span>{{ u.capacity }}</span>
            <span v-if="u.rating != null" class="flex items-center gap-0.5 text-amber-500 font-semibold ms-auto"><span class="material-symbols-outlined text-[15px]" style="font-variation-settings:'FILL' 1">star</span>{{ u.rating }} <span class="text-gray-400 font-normal">({{ u.reviews_count }})</span></span>
          </div>
          <div class="mb-3">
            <div class="flex items-center justify-between text-[11px] text-gray-400 mb-1"><span>{{ t('units.occupancy') }}</span><span class="font-semibold text-gray-700 tabular-nums">{{ u.occupancy }}%</span></div>
            <div class="h-1.5 rounded-full bg-gray-100 overflow-hidden" dir="ltr"><div class="h-full rounded-full bg-primary" :style="`width:${u.occupancy}%`"></div></div>
          </div>
          <div class="flex items-end justify-between border-t border-gray-100 pt-3">
            <div><p class="text-[11px] text-gray-400">{{ t('units.revenue') }}</p><p class="text-[13px] font-bold text-gray-900 tabular-nums">{{ sarCompact(u.revenue) }}</p></div>
            <div class="text-end"><p class="text-[11px] text-gray-400">{{ t('units.pricePerNight') }}</p><p class="text-[13px] font-bold text-gray-900 tabular-nums">{{ sar(u.price) }}</p></div>
          </div>
        </div>
      </article>
    </div>

    <!-- List view -->
    <div v-else class="bg-white rounded-2xl border border-gray-200 overflow-x-auto">
      <table class="w-full text-[13px]">
        <tbody>
          <tr v-for="u in units" :key="u.id" class="border-b border-gray-50 last:border-0 hover:bg-gray-50">
            <td class="px-4 py-3"><div class="flex items-center gap-3"><img :src="u.image_url" class="w-12 h-12 rounded-lg object-cover flex-shrink-0" /><div class="min-w-0"><p class="font-semibold text-gray-900 truncate">{{ u.name }}</p><p class="text-[11px] text-gray-400">{{ u.city }} · {{ typeLabel(u.type) }}</p></div></div></td>
            <td class="px-4 py-3 hidden sm:table-cell"><span class="inline-flex items-center gap-1 text-[12px] font-semibold" :class="pubMeta(u.publication).text"><span class="w-1.5 h-1.5 rounded-full" :class="pubMeta(u.publication).dot"></span>{{ pubMeta(u.publication).label }}</span></td>
            <td class="px-4 py-3 hidden md:table-cell text-gray-600 tabular-nums">{{ u.occupancy }}%</td>
            <td class="px-4 py-3 font-semibold text-gray-900 tabular-nums">{{ sar(u.price) }}</td>
            <td class="px-4 py-3 text-end"><button class="p-1.5 rounded-lg" :class="u.is_featured ? 'text-amber-500' : 'text-gray-300 hover:text-amber-500'" :disabled="busyId === u.id" @click="toggleFeatured(u)"><span class="material-symbols-outlined text-[18px]" :style="u.is_featured ? `font-variation-settings:'FILL' 1` : ''">star</span></button></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div v-if="units.length" class="flex flex-wrap items-center justify-between gap-3 mt-5">
      <p class="text-[12px] text-gray-400 tabular-nums">{{ t('units.showing', { from: showingFrom, to: showingTo, total: meta.total }) }}</p>
      <div class="flex items-center gap-1">
        <button class="w-8 h-8 rounded-lg border border-gray-200 text-gray-500 disabled:opacity-40 hover:bg-gray-50 grid place-items-center" :disabled="page <= 1" @click="goPage(page - 1)"><span class="material-symbols-outlined text-[18px]">{{ dir === 'rtl' ? 'chevron_right' : 'chevron_left' }}</span></button>
        <button v-for="pn in pageNumbers" :key="pn" class="min-w-8 h-8 px-2 rounded-lg text-[13px] font-semibold grid place-items-center transition-colors" :class="pn === page ? 'bg-primary text-white' : 'border border-gray-200 text-gray-600 hover:bg-gray-50'" @click="goPage(pn)">{{ pn }}</button>
        <button class="w-8 h-8 rounded-lg border border-gray-200 text-gray-500 disabled:opacity-40 hover:bg-gray-50 grid place-items-center" :disabled="page >= meta.last_page" @click="goPage(page + 1)"><span class="material-symbols-outlined text-[18px]">{{ dir === 'rtl' ? 'chevron_left' : 'chevron_right' }}</span></button>
      </div>
    </div>

    <transition name="fade">
      <div v-if="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-[80] px-5 py-3 rounded-xl shadow-lg text-white font-semibold text-[13px] bg-primary">{{ toast }}</div>
    </transition>
  </AdminLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { adminApi } from '@/api/admin'
import { useAdminI18n } from '@/i18n/admin'
import { useAdminFormat } from '@/composables/useAdminFormat'

const { t, dir } = useAdminI18n()
const { int, sar, sarCompact } = useAdminFormat()

const loading = ref(true)
const busyId = ref(null)
const units = ref([])
const summary = ref({ total: 0, published: 0, avg_occupancy: 0, total_revenue: 0 })
const meta = ref({ current_page: 1, last_page: 1, total: 0 })
const page = ref(1)
const search = ref('')
const statusFilter = ref('')
const typeFilter = ref('')
const view = ref('grid')
const toast = ref(null)

const statCards = computed(() => [
  { key: 'pub', icon: 'check_circle', label: t('units.published'),     value: int(summary.value.published) },
  { key: 'occ', icon: 'trending_up',  label: t('units.avgOccupancy'),  value: `${summary.value.avg_occupancy}%` },
  { key: 'rev', icon: 'payments',     label: t('units.totalRevenue'),  value: sarCompact(summary.value.total_revenue) },
])

const showingFrom = computed(() => (units.value.length ? (page.value - 1) * 20 + 1 : 0))
const showingTo = computed(() => (page.value - 1) * 20 + units.value.length)
const pageNumbers = computed(() => {
  const last = meta.value.last_page || 1
  const out = []
  for (let p = Math.max(1, page.value - 1); p <= Math.min(last, page.value + 2); p++) out.push(p)
  if (!out.includes(1)) out.unshift(1)
  return [...new Set(out)].sort((a, b) => a - b)
})

const typeLabels = { apartment: 'typeApartment', villa: 'typeVilla', studio: 'typeStudio' }
const typeLabel = (ty) => t(`units.${typeLabels[ty] || 'typeApartment'}`)
function pubMeta(s) {
  if (s === 'under_review') return { label: t('units.pubUnderReview'), text: 'text-amber-600', dot: 'bg-amber-500' }
  if (s === 'unpublished') return { label: t('units.pubUnpublished'), text: 'text-gray-500', dot: 'bg-gray-400' }
  return { label: t('units.pubPublished'), text: 'text-emerald-600', dot: 'bg-emerald-500' }
}
function apprMeta(s) {
  if (s === 'pending') return { label: t('units.apprPending'), text: 'text-amber-600', dot: 'bg-amber-500' }
  if (s === 'rejected') return { label: t('units.apprRejected'), text: 'text-red-600', dot: 'bg-red-500' }
  return { label: t('units.apprApproved'), text: 'text-emerald-600', dot: 'bg-emerald-500' }
}
function showToast(msg) { toast.value = msg; setTimeout(() => (toast.value = null), 2400) }

async function load() {
  loading.value = true
  try {
    const params = { page: page.value }
    if (statusFilter.value) params.approval_status = statusFilter.value
    if (typeFilter.value) params.type = typeFilter.value
    if (search.value) params.search = search.value
    const { data } = await adminApi.listUnits(params)
    units.value = data.data ?? []
    summary.value = data.summary ?? summary.value
    meta.value = data.meta ?? meta.value
  } catch {
    showToast(t('units.loadError'))
  } finally {
    loading.value = false
  }
}
function reload() { page.value = 1; load() }
function goPage(p) { if (p < 1 || p > meta.value.last_page) return; page.value = p; load() }

async function toggleFeatured(u) {
  busyId.value = u.id
  try {
    await adminApi.setUnitFeatured(u.id, !u.is_featured)
    u.is_featured = !u.is_featured
    showToast(u.is_featured ? t('units.featureOn') : t('units.featureOff'))
  } catch {
    showToast(t('units.loadError'))
  } finally {
    busyId.value = null
  }
}

function exportCsv() {
  const head = ['Code', t('units.title'), t('units.occupancy'), t('units.revenue'), t('units.pricePerNight'), 'Status', 'Approval']
  const rows = units.value.map((u) => [u.code, u.name, u.occupancy + '%', u.revenue, u.price, u.publication, u.approval_status])
  const csv = [head, ...rows].map((r) => r.map((c) => `"${String(c ?? '').replace(/"/g, '""')}"`).join(',')).join('\n')
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = 'units.csv'
  a.click()
  URL.revokeObjectURL(a.href)
}

onMounted(load)
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.25s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
