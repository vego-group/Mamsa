<template>
  <AdminLayout>
    <!-- Title row -->
    <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
      <div>
        <h1 class="text-[24px] font-bold text-gray-900 leading-tight">{{ t('bookings.title') }}</h1>
        <p class="text-gray-500 text-[14px] mt-0.5">{{ t('bookings.subtitle', { count: summary.total, revenue: sarCompact(summary.revenue) }) }}</p>
      </div>
      <div class="flex items-center gap-2">
        <button class="inline-flex items-center gap-1.5 h-9 px-4 rounded-lg border border-gray-200 bg-white text-[13px] font-semibold text-gray-600 hover:bg-gray-50 transition-colors" @click="exportCsv">
          <span class="material-symbols-outlined text-[18px]">download</span>{{ t('bookings.exportCsv') }}
        </button>
        <button class="inline-flex items-center gap-1.5 h-9 px-4 rounded-lg border border-gray-200 bg-white text-[13px] font-semibold text-gray-600 hover:bg-gray-50 transition-colors" @click="printPdf">
          <span class="material-symbols-outlined text-[18px]">picture_as_pdf</span>{{ t('bookings.exportPdf') }}
        </button>
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

    <!-- Table card -->
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
      <div class="flex flex-wrap items-center gap-3 justify-between px-4 py-3 border-b border-gray-100">
        <div class="relative w-full sm:w-72">
          <span class="material-symbols-outlined absolute top-1/2 -translate-y-1/2 text-gray-400 text-[18px]" :class="dir === 'rtl' ? 'right-3' : 'left-3'">search</span>
          <input
            v-model="search" @keyup.enter="reload"
            class="w-full h-9 bg-gray-50 border border-gray-200 rounded-lg text-[13px] outline-none focus:ring-2 focus:ring-primary/15 focus:border-primary/40 transition-all"
            :class="dir === 'rtl' ? 'pr-9 pl-3' : 'pl-9 pr-3'"
            :placeholder="t('bookings.search')"
          />
        </div>
        <select v-model="statusFilter" @change="reload" class="h-9 px-3 bg-gray-50 border border-gray-200 rounded-lg text-[13px] text-gray-600 outline-none focus:ring-2 focus:ring-primary/15">
          <option value="">{{ t('bookings.allStatuses') }}</option>
          <option value="confirmed">{{ t('bookings.statusCompleted') }}</option>
          <option value="pending">{{ t('bookings.statusPending') }}</option>
          <option value="cancelled">{{ t('bookings.statusCancelled') }}</option>
        </select>
      </div>

      <div v-if="loading" class="p-4 space-y-3">
        <div v-for="i in 6" :key="i" class="animate-pulse flex items-center gap-3">
          <div class="w-9 h-9 rounded-full bg-gray-100"></div>
          <div class="flex-1 space-y-2"><div class="h-3 bg-gray-100 rounded w-1/3"></div><div class="h-2 bg-gray-100 rounded w-1/4"></div></div>
        </div>
      </div>

      <div v-else-if="bookings.length === 0" class="py-16 text-center text-gray-400 text-[13px]">
        <span class="material-symbols-outlined text-4xl opacity-40 block mb-2">event_busy</span>{{ t('bookings.empty') }}
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-[13px]">
          <thead>
            <tr class="text-gray-400 text-[11px] font-bold uppercase tracking-wide border-b border-gray-100">
              <th class="text-start font-bold px-5 py-3">{{ t('bookings.colId') }}</th>
              <th class="text-start font-bold px-5 py-3">{{ t('bookings.colGuest') }}</th>
              <th class="text-start font-bold px-5 py-3 hidden md:table-cell">{{ t('bookings.colProperty') }}</th>
              <th class="text-start font-bold px-5 py-3 hidden lg:table-cell">{{ t('bookings.colDates') }}</th>
              <th class="text-start font-bold px-5 py-3">{{ t('bookings.colAmount') }}</th>
              <th class="text-start font-bold px-5 py-3 hidden sm:table-cell">{{ t('bookings.colCommission') }}</th>
              <th class="text-start font-bold px-5 py-3 hidden lg:table-cell">{{ t('bookings.colPayment') }}</th>
              <th class="text-start font-bold px-5 py-3 hidden md:table-cell">{{ t('bookings.colPayStatus') }}</th>
              <th class="text-start font-bold px-5 py-3">{{ t('bookings.colStatus') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="b in bookings" :key="b.id" class="border-b border-gray-50 last:border-0 hover:bg-gray-50 transition-colors">
              <td class="px-5 py-3.5 font-bold text-gray-900 tabular-nums whitespace-nowrap">BKG-{{ b.id }}</td>
              <td class="px-5 py-3.5">
                <div class="flex items-center gap-2.5">
                  <div class="w-8 h-8 rounded-full bg-primary/85 flex items-center justify-center text-white font-bold text-[11px] flex-shrink-0">{{ initials(b.guest_name) }}</div>
                  <span class="text-gray-800 truncate max-w-[140px]">{{ b.guest_name || '—' }}</span>
                </div>
              </td>
              <td class="px-5 py-3.5 hidden md:table-cell max-w-[200px]">
                <p class="text-gray-800 truncate">{{ b.unit?.name || '—' }}</p>
                <p class="text-[11px] text-gray-400 truncate">{{ b.unit?.city }}</p>
              </td>
              <td class="px-5 py-3.5 hidden lg:table-cell text-gray-500 tabular-nums whitespace-nowrap">{{ date(b.start_date) }}</td>
              <td class="px-5 py-3.5 font-bold text-gray-900 tabular-nums whitespace-nowrap">{{ sar(b.total_amount) }}</td>
              <td class="px-5 py-3.5 hidden sm:table-cell font-semibold text-blue-600 tabular-nums whitespace-nowrap">{{ sar(b.commission_amount) }}</td>
              <td class="px-5 py-3.5 hidden lg:table-cell text-gray-600 whitespace-nowrap">{{ paymentLabel(b.payment?.payment_method) }}</td>
              <td class="px-5 py-3.5 hidden md:table-cell">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-semibold" :class="payMeta(b.payment?.payment_status).cls">
                  <span class="w-1.5 h-1.5 rounded-full" :class="payMeta(b.payment?.payment_status).dot"></span>{{ payMeta(b.payment?.payment_status).label }}
                </span>
              </td>
              <td class="px-5 py-3.5">
                <span class="inline-flex items-center gap-1.5 text-[12px] font-semibold whitespace-nowrap" :class="statusMeta(b.status).text">
                  <span class="w-1.5 h-1.5 rounded-full" :class="statusMeta(b.status).dot"></span>{{ statusMeta(b.status).label }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="bookings.length" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 border-t border-gray-100">
        <p class="text-[12px] text-gray-400 tabular-nums">{{ t('bookings.showing', { from: showingFrom, to: showingTo, total: meta.total }) }}</p>
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
  </AdminLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { adminApi } from '@/api/admin'
import { useAdminI18n } from '@/i18n/admin'
import { useAdminFormat } from '@/composables/useAdminFormat'

const { t, dir } = useAdminI18n()
const { sar, sarCompact, date } = useAdminFormat()

const loading = ref(true)
const bookings = ref([])
const summary = ref({ total: 0, revenue: 0, commission: 0, avg_value: 0 })
const meta = ref({ current_page: 1, last_page: 1, total: 0 })
const page = ref(1)
const search = ref('')
const statusFilter = ref('')

const statCards = computed(() => [
  { key: 'rev', icon: 'attach_money',    label: t('bookings.totalRevenue'),       value: sarCompact(summary.value.revenue) },
  { key: 'com', icon: 'trending_up',     label: t('bookings.platformCommission'), value: sarCompact(summary.value.commission) },
  { key: 'avg', icon: 'credit_card',     label: t('bookings.avgBookingValue'),    value: sarCompact(summary.value.avg_value) },
])

const showingFrom = computed(() => (bookings.value.length ? (page.value - 1) * 20 + 1 : 0))
const showingTo = computed(() => (page.value - 1) * 20 + bookings.value.length)
const pageNumbers = computed(() => {
  const last = meta.value.last_page || 1
  const out = []
  for (let p = Math.max(1, page.value - 1); p <= Math.min(last, page.value + 2); p++) out.push(p)
  if (!out.includes(1)) out.unshift(1)
  return [...new Set(out)].sort((a, b) => a - b)
})

const paymentLabels = { creditcard: 'Credit Card', mada: 'Mada', applepay: 'Apple Pay', stcpay: 'STC Pay', banktransfer: 'Bank Transfer', wallet: 'Wallet' }
function paymentLabel(method) {
  if (!method) return '—'
  const k = String(method).toLowerCase().replace(/[\s_-]/g, '')
  return paymentLabels[k] || method.charAt(0).toUpperCase() + method.slice(1)
}
function initials(name) {
  return (name || '?').split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?'
}
function statusMeta(s) {
  if (s === 'cancelled') return { label: t('bookings.statusCancelled'), text: 'text-red-600', dot: 'bg-red-500' }
  if (s === 'pending') return { label: t('bookings.statusPending'), text: 'text-amber-600', dot: 'bg-amber-500' }
  return { label: t('bookings.statusCompleted'), text: 'text-emerald-600', dot: 'bg-emerald-500' }
}
function payMeta(s) {
  if (s === 'refunded') return { label: t('bookings.payRefunded'), cls: 'bg-blue-50 text-blue-600', dot: 'bg-blue-500' }
  if (s === 'pending') return { label: t('bookings.payPending'), cls: 'bg-amber-50 text-amber-600', dot: 'bg-amber-500' }
  if (s === 'paid') return { label: t('bookings.payPaid'), cls: 'bg-gray-100 text-gray-600', dot: 'bg-gray-400' }
  return { label: t('bookings.payUnpaid'), cls: 'bg-gray-100 text-gray-500', dot: 'bg-gray-300' }
}

async function load() {
  loading.value = true
  try {
    const params = { page: page.value }
    if (statusFilter.value) params.status = statusFilter.value
    if (search.value) params.search = search.value
    const { data } = await adminApi.listBookings(params)
    bookings.value = data.data ?? []
    summary.value = data.summary ?? summary.value
    meta.value = data.meta ?? meta.value
  } catch {
    /* transient */
  } finally {
    loading.value = false
  }
}
function reload() { page.value = 1; load() }
function goPage(p) { if (p < 1 || p > meta.value.last_page) return; page.value = p; load() }

function exportCsv() {
  const head = [t('bookings.colId'), t('bookings.colGuest'), t('bookings.colProperty'), t('bookings.colDates'), t('bookings.colAmount'), t('bookings.colCommission'), t('bookings.colPayment'), t('bookings.colPayStatus'), t('bookings.colStatus')]
  const rows = bookings.value.map((b) => [`BKG-${b.id}`, b.guest_name, b.unit?.name || '', b.start_date, b.total_amount, b.commission_amount, paymentLabel(b.payment?.payment_method), b.payment?.payment_status || '', b.status])
  const csv = [head, ...rows].map((r) => r.map((c) => `"${String(c ?? '').replace(/"/g, '""')}"`).join(',')).join('\n')
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = 'bookings.csv'
  a.click()
  URL.revokeObjectURL(a.href)
}
function printPdf() { window.print() }

onMounted(load)
</script>
