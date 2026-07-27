<template>
  <AdminLayout>
    <!-- Title row -->
    <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
      <div>
        <h1 class="text-[24px] font-bold text-gray-900 leading-tight">{{ t('partners.title') }}</h1>
        <p class="text-gray-500 text-[14px] mt-0.5">{{ t('partners.subtitle', { total: counts.all, verified: stats.verified, warnings: stats.high_risk }) }}</p>
      </div>
      <div class="flex items-center gap-2">
        <button class="inline-flex items-center gap-1.5 h-9 px-4 rounded-lg border border-gray-200 bg-white text-[13px] font-semibold text-gray-600 hover:bg-gray-50 transition-colors" @click="exportCsv">
          <span class="material-symbols-outlined text-[18px]">download</span>{{ t('partners.export') }}
        </button>
        <RouterLink :to="{ name: 'admin-users' }" class="inline-flex items-center gap-1.5 h-9 px-4 rounded-lg bg-primary text-white text-[13px] font-semibold hover:bg-primary-container transition-colors">
          <span class="material-symbols-outlined text-[18px]">add</span>{{ t('partners.addPartner') }}
        </RouterLink>
      </div>
    </div>

    <!-- Stat cards -->
    <section class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
      <div v-for="s in statCards" :key="s.key" class="bg-white rounded-2xl border border-gray-200 p-5">
        <div class="w-10 h-10 rounded-xl flex items-center justify-center mb-4" :class="s.iconBg">
          <span class="material-symbols-outlined text-[20px]" :class="s.iconColor">{{ s.icon }}</span>
        </div>
        <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400 mb-1">{{ s.label }}</p>
        <p class="text-[24px] font-bold text-gray-900 leading-none tabular-nums">{{ s.value }}</p>
      </div>
    </section>

    <!-- Table card -->
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
      <!-- Tabs + search -->
      <div class="flex flex-wrap items-center gap-3 justify-between px-4 py-3 border-b border-gray-100">
        <div class="flex items-center gap-1 bg-gray-50 rounded-lg p-1">
          <button
            v-for="tab in tabs" :key="tab.key"
            class="px-3 py-1.5 rounded-md text-[13px] font-semibold transition-colors flex items-center gap-1.5"
            :class="activeTab === tab.key ? 'bg-white text-primary shadow-sm' : 'text-gray-500 hover:text-gray-700'"
            @click="changeTab(tab.key)"
          >
            {{ tab.label }}
            <span class="text-[11px] px-1.5 rounded-full" :class="activeTab === tab.key ? 'bg-primary/10 text-primary' : 'bg-gray-200 text-gray-500'">{{ tab.count }}</span>
          </button>
        </div>
        <div class="relative w-full sm:w-64">
          <span class="material-symbols-outlined absolute top-1/2 -translate-y-1/2 text-gray-400 text-[18px]" :class="dir === 'rtl' ? 'right-3' : 'left-3'">search</span>
          <input
            v-model="search" @keyup.enter="reload"
            class="w-full h-9 bg-gray-50 border border-gray-200 rounded-lg text-[13px] outline-none focus:ring-2 focus:ring-primary/15 focus:border-primary/40 transition-all"
            :class="dir === 'rtl' ? 'pr-9 pl-3' : 'pl-9 pr-3'"
            :placeholder="t('partners.search')"
          />
        </div>
      </div>

      <div v-if="loading" class="p-4 space-y-3">
        <div v-for="i in 6" :key="i" class="animate-pulse flex items-center gap-3">
          <div class="w-9 h-9 rounded-full bg-gray-100"></div>
          <div class="flex-1 space-y-2"><div class="h-3 bg-gray-100 rounded w-1/3"></div><div class="h-2 bg-gray-100 rounded w-1/4"></div></div>
        </div>
      </div>

      <div v-else-if="partners.length === 0" class="py-16 text-center text-gray-400 text-[13px]">
        <span class="material-symbols-outlined text-4xl opacity-40 block mb-2">handshake</span>{{ t('partners.empty') }}
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-[13px]">
          <thead>
            <tr class="text-gray-400 text-[11px] font-bold uppercase tracking-wide border-b border-gray-100">
              <th class="text-start font-bold px-5 py-3">{{ t('partners.colPartner') }}</th>
              <th class="text-start font-bold px-5 py-3 hidden md:table-cell">{{ t('partners.colType') }}</th>
              <th class="text-start font-bold px-5 py-3 hidden lg:table-cell">{{ t('partners.colCity') }}</th>
              <th class="text-start font-bold px-5 py-3">{{ t('partners.colUnits') }}</th>
              <th class="text-start font-bold px-5 py-3 hidden sm:table-cell">{{ t('partners.colBookings') }}</th>
              <th class="text-start font-bold px-5 py-3 hidden sm:table-cell">{{ t('partners.colRevenue') }}</th>
              <th class="text-start font-bold px-5 py-3 hidden lg:table-cell">{{ t('partners.colRating') }}</th>
              <th class="text-start font-bold px-5 py-3 hidden md:table-cell">{{ t('partners.colVerified') }}</th>
              <th class="text-start font-bold px-5 py-3">{{ t('partners.colStatus') }}</th>
              <th class="px-5 py-3"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in partners" :key="p.user_id" class="border-b border-gray-50 last:border-0 hover:bg-gray-50 transition-colors cursor-pointer" @click="openDrawer(p)">
              <td class="px-5 py-3">
                <div class="flex items-center gap-3">
                  <div class="w-9 h-9 rounded-full bg-primary/85 flex items-center justify-center text-white font-bold text-[12px] flex-shrink-0">{{ initials(p.name) }}</div>
                  <div class="min-w-0">
                    <p class="font-semibold text-gray-900 leading-tight truncate flex items-center gap-1">
                      {{ p.name || '—' }}
                      <span v-if="p.high_risk" class="material-symbols-outlined text-[15px] text-amber-500" :title="t('partners.highRisk')">warning</span>
                    </p>
                    <p class="text-[11px] text-gray-400 tabular-nums">{{ p.code }}</p>
                  </div>
                </div>
              </td>
              <td class="px-5 py-3 hidden md:table-cell">
                <span class="inline-block px-2.5 py-0.5 rounded-full text-[12px] font-medium" :class="p.type === 'company' ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-600'">
                  {{ p.type === 'company' ? t('partners.typeCompany') : t('partners.typeIndividual') }}
                </span>
              </td>
              <td class="px-5 py-3 hidden lg:table-cell text-gray-600">{{ p.city || '—' }}</td>
              <td class="px-5 py-3 font-semibold text-gray-900 tabular-nums">{{ p.units_count }}</td>
              <td class="px-5 py-3 hidden sm:table-cell text-gray-700 tabular-nums">{{ int(p.bookings_count) }}</td>
              <td class="px-5 py-3 hidden sm:table-cell font-semibold text-gray-900 tabular-nums">{{ sar(p.revenue) }}</td>
              <td class="px-5 py-3 hidden lg:table-cell">
                <span v-if="p.rating != null" class="inline-flex items-center gap-0.5 text-amber-500 font-semibold tabular-nums"><span class="material-symbols-outlined text-[15px]" style="font-variation-settings:'FILL' 1">star</span>{{ p.rating }}</span>
                <span v-else class="text-gray-300">—</span>
              </td>
              <td class="px-5 py-3 hidden md:table-cell">
                <span class="inline-flex items-center gap-1 text-[12px] font-semibold" :class="p.verified ? 'text-emerald-600' : 'text-amber-600'">
                  <span class="material-symbols-outlined text-[15px]">{{ p.verified ? 'verified' : 'gpp_maybe' }}</span>{{ p.verified ? t('partners.verified') : t('partners.unverified') }}
                </span>
              </td>
              <td class="px-5 py-3">
                <span class="inline-flex items-center gap-1.5 text-[12px] font-semibold" :class="statusMeta(p.status).text">
                  <span class="w-1.5 h-1.5 rounded-full" :class="statusMeta(p.status).dot"></span>{{ statusMeta(p.status).label }}
                </span>
              </td>
              <td class="px-5 py-3" @click.stop>
                <button class="p-1.5 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-700 transition-colors" @click="openDrawer(p)">
                  <span class="material-symbols-outlined text-[18px]">visibility</span>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="partners.length" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 border-t border-gray-100">
        <p class="text-[12px] text-gray-400 tabular-nums">{{ t('partners.showing', { from: showingFrom, to: showingTo, total: meta.total }) }}</p>
        <div class="flex items-center gap-1">
          <button class="w-8 h-8 rounded-lg border border-gray-200 text-gray-500 disabled:opacity-40 hover:bg-gray-50 grid place-items-center" :disabled="page <= 1" @click="goPage(page - 1)">
            <span class="material-symbols-outlined text-[18px]">{{ dir === 'rtl' ? 'chevron_right' : 'chevron_left' }}</span>
          </button>
          <button v-for="pn in pageNumbers" :key="pn" class="min-w-8 h-8 px-2 rounded-lg text-[13px] font-semibold grid place-items-center transition-colors" :class="pn === page ? 'bg-primary text-white' : 'border border-gray-200 text-gray-600 hover:bg-gray-50'" @click="goPage(pn)">{{ pn }}</button>
          <button class="w-8 h-8 rounded-lg border border-gray-200 text-gray-500 disabled:opacity-40 hover:bg-gray-50 grid place-items-center" :disabled="page >= meta.last_page" @click="goPage(page + 1)">
            <span class="material-symbols-outlined text-[18px]">{{ dir === 'rtl' ? 'chevron_left' : 'chevron_right' }}</span>
          </button>
        </div>
      </div>
    </div>

    <PartnerDetailDrawer
      :open="drawerOpen" :partner-id="drawerId"
      @close="drawerOpen = false"
      @changed="onChanged"
      @error="showToast(t('partners.actionError'), 'error')"
    />

    <transition name="fade">
      <div v-if="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-[80] px-5 py-3 rounded-xl shadow-lg text-white font-semibold text-[13px]" :class="toast.type === 'error' ? 'bg-red-600' : 'bg-primary'">{{ toast.msg }}</div>
    </transition>
  </AdminLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import PartnerDetailDrawer from '@/components/admin/PartnerDetailDrawer.vue'
import { adminApi } from '@/api/admin'
import { useAdminI18n } from '@/i18n/admin'
import { useAdminFormat } from '@/composables/useAdminFormat'

const { t, dir } = useAdminI18n()
const { int, sar, sarCompact } = useAdminFormat()

const loading = ref(true)
const partners = ref([])
const counts = ref({ all: 0, individuals: 0, companies: 0 })
const stats = ref({ active: 0, verified: 0, total_revenue: 0, high_risk: 0 })
const meta = ref({ current_page: 1, last_page: 1, total: 0 })
const page = ref(1)
const activeTab = ref('all')
const search = ref('')
const toast = ref(null)
const drawerOpen = ref(false)
const drawerId = ref(null)

const tabs = computed(() => [
  { key: 'all',        label: t('partners.tabAll'),         count: counts.value.all },
  { key: 'individual', label: t('partners.tabIndividuals'), count: counts.value.individuals },
  { key: 'company',    label: t('partners.tabCompanies'),   count: counts.value.companies },
])

const statCards = computed(() => [
  { key: 'active',  icon: 'verified_user', iconBg: 'bg-emerald-50', iconColor: 'text-emerald-600', label: t('partners.activePartners'), value: int(stats.value.active) },
  { key: 'revenue', icon: 'trending_up',   iconBg: 'bg-gray-50',    iconColor: 'text-primary',     label: t('partners.totalRevenue'),   value: sarCompact(stats.value.total_revenue) },
  { key: 'risk',    icon: 'warning',       iconBg: 'bg-amber-50',   iconColor: 'text-amber-600',   label: t('partners.highRisk'),       value: int(stats.value.high_risk) },
])

const showingFrom = computed(() => (partners.value.length ? (page.value - 1) * 20 + 1 : 0))
const showingTo = computed(() => (page.value - 1) * 20 + partners.value.length)
const pageNumbers = computed(() => {
  const last = meta.value.last_page || 1
  const out = []
  for (let p = Math.max(1, page.value - 1); p <= Math.min(last, page.value + 2); p++) out.push(p)
  if (!out.includes(1)) out.unshift(1)
  return [...new Set(out)].sort((a, b) => a - b)
})

function initials(name) {
  return (name || '?').split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?'
}
function statusMeta(s) {
  if (s === 'pending') return { label: t('partners.statusPending'), text: 'text-amber-600', dot: 'bg-amber-500' }
  if (s === 'inactive') return { label: t('partners.statusInactive'), text: 'text-gray-500', dot: 'bg-gray-400' }
  return { label: t('partners.statusActive'), text: 'text-emerald-600', dot: 'bg-emerald-500' }
}
function showToast(msg, type = 'success') {
  toast.value = { msg, type }
  setTimeout(() => (toast.value = null), 2600)
}

async function load() {
  loading.value = true
  try {
    const params = { page: page.value }
    if (activeTab.value !== 'all') params.type = activeTab.value
    if (search.value) params.search = search.value
    const { data } = await adminApi.listPartners(params)
    partners.value = data.data ?? []
    counts.value = data.counts ?? counts.value
    stats.value = data.stats ?? stats.value
    meta.value = data.meta ?? meta.value
  } catch {
    showToast(t('partners.loadError'), 'error')
  } finally {
    loading.value = false
  }
}
function reload() { page.value = 1; load() }
function changeTab(key) { activeTab.value = key; page.value = 1; load() }
function goPage(p) { if (p < 1 || p > meta.value.last_page) return; page.value = p; load() }

function openDrawer(p) { drawerId.value = p.user_id; drawerOpen.value = true }
function onChanged(key) {
  if (key) showToast(t(`partners.${key}`))
  load()
}

function exportCsv() {
  const head = [t('partners.colPartner'), 'Code', t('partners.colType'), t('partners.colCity'), t('partners.colUnits'), t('partners.colBookings'), t('partners.colRevenue'), t('partners.colRating'), t('partners.colStatus')]
  const rows = partners.value.map((p) => [p.name, p.code, p.type, p.city || '', p.units_count, p.bookings_count, p.revenue, p.rating ?? '', p.status])
  const csv = [head, ...rows].map((r) => r.map((c) => `"${String(c ?? '').replace(/"/g, '""')}"`).join(',')).join('\n')
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = 'partners.csv'
  a.click()
  URL.revokeObjectURL(a.href)
}

onMounted(load)
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.25s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
