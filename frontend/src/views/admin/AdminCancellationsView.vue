<template>
  <AdminLayout>
    <!-- Title row -->
    <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
      <div>
        <h1 class="text-[24px] font-bold text-gray-900 leading-tight">{{ t('cancellations.title') }}</h1>
        <p class="text-gray-500 text-[14px] mt-0.5">{{ t('cancellations.subtitle') }}</p>
      </div>
      <button class="inline-flex items-center gap-1.5 h-9 px-4 rounded-lg border border-gray-200 bg-white text-[13px] font-semibold text-gray-600 hover:bg-gray-50 transition-colors" @click="exportCsv">
        <span class="material-symbols-outlined text-[18px]">download</span>{{ t('cancellations.exportReport') }}
      </button>
    </div>

    <!-- Stat cards -->
    <section class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
      <div v-for="s in statCards" :key="s.key" class="bg-white rounded-2xl border border-gray-200 p-5">
        <div class="w-10 h-10 rounded-xl bg-gray-50 border border-gray-100 flex items-center justify-center mb-4"><span class="material-symbols-outlined text-[20px]" :class="s.color">{{ s.icon }}</span></div>
        <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400 mb-1">{{ s.label }}</p>
        <p class="text-[24px] font-bold text-gray-900 leading-none tabular-nums">{{ s.value }}</p>
      </div>
    </section>

    <!-- Trend + refund status -->
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
      <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-200 p-5">
        <h3 class="text-[15px] font-bold text-gray-900">{{ t('cancellations.trend') }}</h3>
        <p class="text-[12px] text-gray-400 mb-4">{{ t('cancellations.trendSub') }}</p>
        <div class="flex items-end justify-between gap-3 h-44" dir="ltr">
          <div v-for="m in trend" :key="m.month" class="flex-1 h-full flex flex-col justify-end items-center gap-1">
            <div class="w-full flex items-end justify-center gap-1 h-full">
              <div class="w-1/2 max-w-[16px] rounded-t bg-emerald-300" :style="`height:${barPct(m.guest)}%`" :title="`${t('cancellations.guestLegend')}: ${m.guest}`"></div>
              <div class="w-1/2 max-w-[16px] rounded-t bg-red-400" :style="`height:${barPct(m.host)}%`" :title="`${t('cancellations.hostLegend')}: ${m.host}`"></div>
            </div>
            <span class="text-[11px] text-gray-400">{{ monthShort(m.month) }}</span>
          </div>
        </div>
        <div class="flex items-center gap-4 mt-3 text-[12px]">
          <span class="flex items-center gap-1.5 text-gray-500"><span class="w-2.5 h-2.5 rounded-full bg-emerald-300"></span>{{ t('cancellations.guestLegend') }}</span>
          <span class="flex items-center gap-1.5 text-gray-500"><span class="w-2.5 h-2.5 rounded-full bg-red-400"></span>{{ t('cancellations.hostLegend') }}</span>
        </div>
      </div>

      <div class="bg-white rounded-2xl border border-gray-200 p-5">
        <h3 class="text-[15px] font-bold text-gray-900 mb-4">{{ t('cancellations.refundStatus') }}</h3>
        <div class="space-y-4">
          <div v-for="r in refundRows" :key="r.key">
            <div class="flex items-center justify-between text-[13px] mb-1.5">
              <span class="flex items-center gap-1.5 text-gray-600"><span class="w-2 h-2 rounded-full" :class="r.dot"></span>{{ r.label }}</span>
              <span class="font-bold text-gray-900 tabular-nums">{{ r.value }}</span>
            </div>
            <div class="h-1.5 rounded-full bg-gray-100 overflow-hidden" dir="ltr"><div class="h-full rounded-full" :class="r.bar" :style="`width:${refundPct(r.value)}%`"></div></div>
          </div>
        </div>
      </div>
    </section>

    <!-- High-risk partners -->
    <section v-if="highRisk.length" class="bg-white rounded-2xl border border-gray-200 p-5 mb-4">
      <div class="flex items-center justify-between mb-4">
        <div>
          <h3 class="text-[15px] font-bold text-gray-900">{{ t('cancellations.highRisk') }}</h3>
          <p class="text-[12px] text-gray-400">{{ t('cancellations.highRiskSub') }}</p>
        </div>
        <span class="text-[12px] font-semibold text-red-500">{{ t('cancellations.atRisk', { n: highRisk.length }) }}</span>
      </div>
      <div class="space-y-2">
        <div v-for="p in highRisk" :key="p.name" class="flex items-center gap-3 rounded-xl bg-red-50/50 border border-red-100 px-4 py-3">
          <div class="w-9 h-9 rounded-full bg-primary/85 flex items-center justify-center text-white font-bold text-[12px] flex-shrink-0">{{ initials(p.name) }}</div>
          <div class="min-w-0 flex-1">
            <p class="text-[14px] font-semibold text-gray-900 truncate">{{ p.name }}</p>
            <p class="text-[12px] text-gray-400">{{ p.city }} · {{ p.type === 'company' ? t('cancellations.company') : t('cancellations.individual') }}</p>
          </div>
          <div class="text-end">
            <p class="text-[13px] font-bold text-red-500 tabular-nums">{{ t('cancellations.nCancellations', { n: p.cancellations }) }}</p>
            <p class="text-[12px] text-gray-400 tabular-nums">{{ t('cancellations.nRate', { n: p.rate }) }}</p>
          </div>
          <span class="material-symbols-outlined text-[20px] text-amber-500">warning</span>
        </div>
      </div>
    </section>

    <!-- Table -->
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
      <div class="flex flex-wrap items-center gap-3 justify-between px-4 py-3 border-b border-gray-100">
        <div class="flex items-center gap-1 bg-gray-50 rounded-lg p-1">
          <button v-for="tb in tabs" :key="tb.key" class="px-3 py-1.5 rounded-md text-[13px] font-semibold transition-colors flex items-center gap-1.5" :class="tab === tb.key ? 'bg-white text-primary shadow-sm' : 'text-gray-500 hover:text-gray-700'" @click="changeTab(tb.key)">
            {{ tb.label }}<span class="text-[11px] px-1.5 rounded-full" :class="tab === tb.key ? 'bg-primary/10 text-primary' : 'bg-gray-200 text-gray-500'">{{ tb.count }}</span>
          </button>
        </div>
        <div class="relative w-full sm:w-56">
          <span class="material-symbols-outlined absolute top-1/2 -translate-y-1/2 text-gray-400 text-[18px]" :class="dir === 'rtl' ? 'right-3' : 'left-3'">search</span>
          <input v-model="search" @keyup.enter="reload" class="w-full h-9 bg-gray-50 border border-gray-200 rounded-lg text-[13px] outline-none focus:ring-2 focus:ring-primary/15" :class="dir === 'rtl' ? 'pr-9 pl-3' : 'pl-9 pr-3'" :placeholder="t('cancellations.search')" />
        </div>
      </div>

      <div v-if="loading" class="p-4 space-y-3"><div v-for="i in 5" :key="i" class="h-10 bg-gray-100 rounded animate-pulse"></div></div>
      <div v-else-if="rows.length === 0" class="py-16 text-center text-gray-400 text-[13px]"><span class="material-symbols-outlined text-4xl opacity-40 block mb-2">event_busy</span>{{ t('cancellations.empty') }}</div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-[13px]">
          <thead><tr class="text-gray-400 text-[11px] font-bold uppercase tracking-wide border-b border-gray-100">
            <th class="text-start font-bold px-5 py-3">{{ t('cancellations.colId') }}</th>
            <th class="text-start font-bold px-5 py-3 hidden md:table-cell">{{ t('cancellations.colBooking') }}</th>
            <th class="text-start font-bold px-5 py-3">{{ t('cancellations.colGuest') }}</th>
            <th class="text-start font-bold px-5 py-3 hidden sm:table-cell">{{ t('cancellations.colBy') }}</th>
            <th class="text-start font-bold px-5 py-3 hidden lg:table-cell">{{ t('cancellations.colProperty') }}</th>
            <th class="text-start font-bold px-5 py-3 hidden lg:table-cell">{{ t('cancellations.colDate') }}</th>
            <th class="text-start font-bold px-5 py-3">{{ t('cancellations.colRefund') }}</th>
            <th class="text-start font-bold px-5 py-3 hidden sm:table-cell">{{ t('cancellations.colImpact') }}</th>
            <th class="text-start font-bold px-5 py-3">{{ t('cancellations.colStatus') }}</th>
          </tr></thead>
          <tbody>
            <tr v-for="r in rows" :key="r.id" class="border-b border-gray-50 last:border-0 hover:bg-gray-50">
              <td class="px-5 py-3 font-bold text-gray-900 tabular-nums">{{ r.code }}</td>
              <td class="px-5 py-3 hidden md:table-cell text-gray-500 tabular-nums">{{ r.booking_code }}</td>
              <td class="px-5 py-3"><div class="flex items-center gap-2"><div class="w-7 h-7 rounded-full bg-primary/85 flex items-center justify-center text-white font-bold text-[10px]">{{ initials(r.guest_name) }}</div><span class="text-gray-800 truncate max-w-[120px]">{{ r.guest_name }}</span></div></td>
              <td class="px-5 py-3 hidden sm:table-cell"><span class="px-2.5 py-0.5 rounded-full text-[12px] font-medium" :class="r.cancelled_by === 'host' ? 'bg-red-50 text-red-600' : 'bg-gray-100 text-gray-600'">{{ r.cancelled_by === 'host' ? t('cancellations.byHost') : t('cancellations.byGuest') }}</span></td>
              <td class="px-5 py-3 hidden lg:table-cell text-gray-600 truncate max-w-[160px]">{{ r.property }}</td>
              <td class="px-5 py-3 hidden lg:table-cell text-gray-400 tabular-nums">{{ date(r.date) }}</td>
              <td class="px-5 py-3 font-semibold text-gray-900 tabular-nums">{{ sar(r.refund) }}</td>
              <td class="px-5 py-3 hidden sm:table-cell tabular-nums font-semibold" :class="r.impact < 0 ? 'text-red-500' : 'text-gray-400'">{{ r.impact ? sar(r.impact) : '—' }}</td>
              <td class="px-5 py-3"><span class="inline-flex items-center gap-1.5 text-[12px] font-semibold" :class="stMeta(r.refund_status).text"><span class="w-1.5 h-1.5 rounded-full" :class="stMeta(r.refund_status).dot"></span>{{ stMeta(r.refund_status).label }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="rows.length" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 border-t border-gray-100">
        <p class="text-[12px] text-gray-400 tabular-nums">{{ t('cancellations.showing', { from: showingFrom, to: showingTo, total: meta.total }) }}</p>
        <div class="flex items-center gap-1">
          <button class="w-8 h-8 rounded-lg border border-gray-200 text-gray-500 disabled:opacity-40 hover:bg-gray-50 grid place-items-center" :disabled="page <= 1" @click="goPage(page - 1)"><span class="material-symbols-outlined text-[18px]">{{ dir === 'rtl' ? 'chevron_right' : 'chevron_left' }}</span></button>
          <span class="px-3 text-[13px] text-gray-500 tabular-nums">{{ page }} / {{ meta.last_page }}</span>
          <button class="w-8 h-8 rounded-lg border border-gray-200 text-gray-500 disabled:opacity-40 hover:bg-gray-50 grid place-items-center" :disabled="page >= meta.last_page" @click="goPage(page + 1)"><span class="material-symbols-outlined text-[18px]">{{ dir === 'rtl' ? 'chevron_left' : 'chevron_right' }}</span></button>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { adminApi } from '@/api/admin'
import { useAdminI18n } from '@/i18n/admin'
import { useAdminFormat } from '@/composables/useAdminFormat'

const { t, dir, locale } = useAdminI18n()
const { int, sar, sarCompact, date } = useAdminFormat()

const loading = ref(true)
const rows = ref([])
const counts = ref({ all: 0, guest: 0, host: 0 })
const summary = ref({ total_refunds: 0, financial_impact: 0, host_cancellations: 0 })
const trend = ref([])
const refundStatus = ref({ refunded: 0, partial: 0, no_refund: 0, pending: 0 })
const highRisk = ref([])
const meta = ref({ current_page: 1, last_page: 1, total: 0 })
const page = ref(1)
const tab = ref('all')
const search = ref('')

const statCards = computed(() => [
  { key: 'refunds', icon: 'payments', color: 'text-primary', label: t('cancellations.totalRefunds'), value: sarCompact(summary.value.total_refunds) },
  { key: 'impact', icon: 'trending_down', color: 'text-red-500', label: t('cancellations.financialImpact'), value: sarCompact(summary.value.financial_impact) },
  { key: 'host', icon: 'warning', color: 'text-amber-500', label: t('cancellations.hostCancellations'), value: int(summary.value.host_cancellations) },
])
const tabs = computed(() => [
  { key: 'all', label: t('cancellations.tabAll'), count: counts.value.all },
  { key: 'guest', label: t('cancellations.tabGuest'), count: counts.value.guest },
  { key: 'host', label: t('cancellations.tabHost'), count: counts.value.host },
])
const refundRows = computed(() => [
  { key: 'refunded', label: t('cancellations.fullyRefunded'), value: refundStatus.value.refunded, dot: 'bg-emerald-500', bar: 'bg-emerald-500' },
  { key: 'partial', label: t('cancellations.partialRefund'), value: refundStatus.value.partial, dot: 'bg-amber-500', bar: 'bg-amber-500' },
  { key: 'no_refund', label: t('cancellations.noRefund'), value: refundStatus.value.no_refund, dot: 'bg-gray-400', bar: 'bg-gray-400' },
  { key: 'pending', label: t('cancellations.pending'), value: refundStatus.value.pending, dot: 'bg-orange-500', bar: 'bg-orange-500' },
])

const trendMax = computed(() => Math.max(1, ...trend.value.flatMap((m) => [m.guest, m.host])))
const barPct = (v) => Math.max(v > 0 ? 6 : 0, Math.round((v / trendMax.value) * 100))
const refundTotal = computed(() => Math.max(1, refundRows.value.reduce((s, r) => s + r.value, 0)))
const refundPct = (v) => Math.round((v / refundTotal.value) * 100)

const showingFrom = computed(() => (rows.value.length ? (page.value - 1) * 20 + 1 : 0))
const showingTo = computed(() => (page.value - 1) * 20 + rows.value.length)

function initials(name) { return (name || '?').split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?' }
function monthShort(ym) { const [y, m] = String(ym).split('-').map(Number); return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar' : 'en', { month: 'short' }).format(new Date(y, (m || 1) - 1, 1)) }
function stMeta(s) {
  if (s === 'refunded') return { label: t('cancellations.stRefunded'), text: 'text-blue-600', dot: 'bg-blue-500' }
  if (s === 'partial') return { label: t('cancellations.stPartial'), text: 'text-amber-600', dot: 'bg-amber-500' }
  if (s === 'pending') return { label: t('cancellations.stPending'), text: 'text-orange-600', dot: 'bg-orange-500' }
  return { label: t('cancellations.stNoRefund'), text: 'text-red-600', dot: 'bg-red-500' }
}

async function load() {
  loading.value = true
  try {
    const params = { page: page.value }
    if (tab.value !== 'all') params.by = tab.value
    if (search.value) params.search = search.value
    const { data } = await adminApi.listCancellations(params)
    const d = data
    rows.value = d.data ?? []
    counts.value = d.counts ?? counts.value
    summary.value = d.summary ?? summary.value
    trend.value = d.trend ?? []
    refundStatus.value = d.refund_status ?? refundStatus.value
    highRisk.value = d.high_risk ?? []
    meta.value = d.meta ?? meta.value
  } catch { /* transient */ } finally { loading.value = false }
}
function reload() { page.value = 1; load() }
function changeTab(k) { tab.value = k; page.value = 1; load() }
function goPage(p) { if (p < 1 || p > meta.value.last_page) return; page.value = p; load() }

function exportCsv() {
  const head = [t('cancellations.colId'), t('cancellations.colBooking'), t('cancellations.colGuest'), t('cancellations.colBy'), t('cancellations.colProperty'), t('cancellations.colDate'), t('cancellations.colRefund'), t('cancellations.colImpact'), t('cancellations.colStatus')]
  const body = rows.value.map((r) => [r.code, r.booking_code, r.guest_name, r.cancelled_by, r.property, r.date, r.refund, r.impact, r.refund_status])
  const csv = [head, ...body].map((r) => r.map((c) => `"${String(c ?? '').replace(/"/g, '""')}"`).join(',')).join('\n')
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'cancellations.csv'; a.click(); URL.revokeObjectURL(a.href)
}

onMounted(load)
</script>
