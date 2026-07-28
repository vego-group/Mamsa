<template>
  <AdminLayout>
    <!-- Title row -->
    <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
      <div>
        <h1 class="text-[24px] font-bold text-gray-900 leading-tight">{{ t('reports.title') }}</h1>
        <p class="text-gray-500 text-[14px] mt-0.5">{{ t('reports.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2">
        <button class="inline-flex items-center gap-1.5 h-9 px-4 rounded-lg border border-gray-200 bg-white text-[13px] font-semibold text-gray-600 hover:bg-gray-50 transition-colors" @click="exportCsv">
          <span class="material-symbols-outlined text-[18px]">download</span>{{ t('reports.exportCsv') }}
        </button>
        <button class="inline-flex items-center gap-1.5 h-9 px-4 rounded-lg bg-primary text-white text-[13px] font-semibold hover:bg-primary-container transition-colors" @click="printPdf">
          <span class="material-symbols-outlined text-[18px]">picture_as_pdf</span>{{ t('reports.exportPdf') }}
        </button>
      </div>
    </div>

    <!-- Tabs + period -->
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
      <div class="flex items-center gap-1 bg-gray-100 rounded-lg p-1">
        <button v-for="tb in tabs" :key="tb.key" class="px-3.5 py-1.5 rounded-md text-[13px] font-semibold transition-colors" :class="tab === tb.key ? 'bg-white text-primary shadow-sm' : 'text-gray-500 hover:text-gray-700'" @click="tab = tb.key">{{ tb.label }}</button>
      </div>
      <span class="h-9 px-3 inline-flex items-center rounded-lg border border-gray-200 bg-white text-[13px] text-gray-500">{{ t('reports.thisYear') }}</span>
    </div>

    <div v-if="loading" class="grid grid-cols-2 lg:grid-cols-4 gap-4">
      <div v-for="i in 4" :key="i" class="h-28 bg-white rounded-2xl border border-gray-200 animate-pulse"></div>
    </div>

    <template v-else>
      <!-- ══ REVENUE TAB ══ -->
      <template v-if="tab === 'revenue'">
        <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
          <div v-for="k in revenueKpis" :key="k.key" class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="w-10 h-10 rounded-xl bg-gray-50 border border-gray-100 flex items-center justify-center mb-3"><span class="material-symbols-outlined text-[20px] text-primary">{{ k.icon }}</span></div>
            <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400 mb-1">{{ k.label }}</p>
            <p class="text-[24px] font-bold text-gray-900 leading-none tabular-nums">{{ k.value }}</p>
          </div>
        </section>

        <div class="bg-white rounded-2xl border border-gray-200 p-5 mb-4">
          <h3 class="text-[15px] font-bold text-gray-900">{{ t('reports.revCommTime') }}</h3>
          <p class="text-[12px] text-gray-400 mb-3">{{ t('reports.monthlyBreakdown') }}</p>
          <AreaChart :series="data.monthly_revenue" :fmt="money" />
          <div class="flex items-center justify-center gap-4 mt-2 text-[12px]">
            <span class="flex items-center gap-1.5 text-gray-500"><span class="w-2.5 h-2.5 rounded-full bg-emerald-400"></span>{{ t('reports.commission') }}</span>
            <span class="flex items-center gap-1.5 text-gray-500"><span class="w-2.5 h-2.5 rounded-full bg-primary"></span>{{ t('reports.revenue') }}</span>
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <h3 class="text-[15px] font-bold text-gray-900 mb-4">{{ t('reports.revenueByCity') }}</h3>
            <HBars :items="cityRevenue" :fmt="money" />
          </div>
          <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <h3 class="text-[15px] font-bold text-gray-900 mb-4">{{ t('reports.bookingStatus') }}</h3>
            <Donut :segs="donutSegs" />
          </div>
        </div>
      </template>

      <!-- ══ BOOKINGS TAB ══ -->
      <template v-else-if="tab === 'bookings'">
        <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
          <div v-for="k in bookingKpis" :key="k.key" class="bg-white rounded-2xl border border-gray-200 p-5">
            <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400 mb-1">{{ k.label }}</p>
            <p class="text-[24px] font-bold text-gray-900 leading-none tabular-nums">{{ k.value }}</p>
          </div>
        </section>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <h3 class="text-[15px] font-bold text-gray-900 mb-4">{{ t('reports.bookingsByCity') }}</h3>
            <HBars :items="cityBookings" :fmt="int" />
          </div>
          <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <h3 class="text-[15px] font-bold text-gray-900 mb-4">{{ t('reports.bookingStatus') }}</h3>
            <Donut :segs="donutSegs" />
          </div>
        </div>
      </template>

      <!-- ══ PARTNERS / TOP UNITS TAB ══ -->
      <template v-else-if="tab === 'partners'">
        <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
          <div class="px-5 py-4 border-b border-gray-100"><h3 class="text-[15px] font-bold text-gray-900">{{ t('reports.topUnits') }}</h3></div>
          <div v-if="(data.top_units || []).length === 0" class="py-14 text-center text-gray-400 text-[13px]">{{ t('reports.noData') }}</div>
          <table v-else class="w-full text-[13px]">
            <thead><tr class="text-gray-400 text-[11px] font-bold uppercase tracking-wide border-b border-gray-100">
              <th class="text-start font-bold px-5 py-3">{{ t('reports.unitName') }}</th>
              <th class="text-start font-bold px-5 py-3 hidden sm:table-cell">{{ t('reports.city') }}</th>
              <th class="text-start font-bold px-5 py-3">{{ t('reports.bookings') }}</th>
              <th class="text-start font-bold px-5 py-3">{{ t('reports.unitRevenue') }}</th>
            </tr></thead>
            <tbody>
              <tr v-for="(u, i) in data.top_units" :key="i" class="border-b border-gray-50 last:border-0 hover:bg-gray-50">
                <td class="px-5 py-3 font-semibold text-gray-800 truncate max-w-[240px]">{{ u.name }}</td>
                <td class="px-5 py-3 hidden sm:table-cell text-gray-500">{{ u.city }}</td>
                <td class="px-5 py-3 tabular-nums text-gray-700">{{ int(u.bookings) }}</td>
                <td class="px-5 py-3 tabular-nums font-semibold text-gray-900">{{ sar(u.revenue) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <!-- ══ OCCUPANCY TAB ══ -->
      <template v-else>
        <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
          <div v-for="k in occKpis" :key="k.key" class="bg-white rounded-2xl border border-gray-200 p-5">
            <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400 mb-1">{{ k.label }}</p>
            <p class="text-[24px] font-bold text-gray-900 leading-none tabular-nums">{{ k.value }}</p>
          </div>
        </section>
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
          <h3 class="text-[15px] font-bold text-gray-900 mb-4">{{ t('reports.unitsByStatus') }}</h3>
          <HBars :items="unitStatusBars" :fmt="int" />
        </div>
      </template>
    </template>
  </AdminLayout>
</template>

<script setup>
import { ref, computed, onMounted, h } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { adminApi } from '@/api/admin'
import { useAdminI18n } from '@/i18n/admin'
import { useAdminFormat } from '@/composables/useAdminFormat'

const { t, locale } = useAdminI18n()
const { int, sar, sarCompact, compact } = useAdminFormat()
const money = (v) => compact(v)

const loading = ref(true)
const tab = ref('revenue')
const data = ref({ kpis: {}, monthly_revenue: [], units_by_status: {}, bookings_by_city: [], revenue_by_city: [], booking_status: {}, top_units: [] })

const tabs = computed(() => [
  { key: 'revenue', label: t('reports.tabRevenue') },
  { key: 'bookings', label: t('reports.tabBookings') },
  { key: 'partners', label: t('reports.tabPartners') },
  { key: 'occupancy', label: t('reports.tabOccupancy') },
])

const k = computed(() => data.value.kpis || {})
const revenueKpis = computed(() => [
  { key: 'rev', icon: 'attach_money', label: t('reports.totalRevenue'), value: sarCompact(k.value.total_revenue) },
  { key: 'com', icon: 'trending_up', label: t('reports.totalCommission'), value: sarCompact(k.value.total_commission) },
  { key: 'bk', icon: 'bar_chart', label: t('reports.totalBookings'), value: int(k.value.total_bookings) },
  { key: 'avg', icon: 'insights', label: t('reports.avgMonthly'), value: sarCompact(k.value.avg_monthly_revenue) },
])
const bookingKpis = computed(() => [
  { key: 'total', label: t('reports.totalBookings'), value: int(k.value.total_bookings) },
  { key: 'done', label: t('reports.completed'), value: int(data.value.booking_status?.completed) },
  { key: 'pend', label: t('reports.pending'), value: int(data.value.booking_status?.pending) },
  { key: 'canc', label: t('reports.cancelled'), value: int(data.value.booking_status?.cancelled) },
])
const occKpis = computed(() => [
  { key: 'occ', label: t('reports.occupancyRate'), value: `${k.value.occupancy_rate ?? 0}%` },
  { key: 'nights', label: t('reports.avgNights'), value: k.value.avg_nights ?? 0 },
  { key: 'rating', label: t('reports.avgRating'), value: k.value.avg_rating ?? 0 },
  { key: 'reviews', label: t('reports.reviewsCount'), value: int(k.value.reviews_count) },
])

const cityRevenue = computed(() => (data.value.revenue_by_city || []).map((c) => ({ label: c.city, value: c.total })))
const cityBookings = computed(() => (data.value.bookings_by_city || []).map((c) => ({ label: c.city, value: c.count })))
const unitStatusBars = computed(() => {
  const s = data.value.units_by_status || {}
  return [
    { label: t('units.apprApproved'), value: s.approved || 0 },
    { label: t('reports.pending'), value: s.pending || 0 },
    { label: t('reports.rejected'), value: s.rejected || 0 },
    { label: t('reports.draft'), value: s.draft || 0 },
  ]
})
const donutSegs = computed(() => {
  const b = data.value.booking_status || {}
  return [
    { label: t('reports.completed'), value: b.completed || 0, color: '#2e7d46' },
    { label: t('reports.approved'), value: b.confirmed || 0, color: '#2196a5' },
    { label: t('reports.pending'), value: b.pending || 0, color: '#f5a623' },
    { label: t('reports.cancelled'), value: b.cancelled || 0, color: '#ef5a3c' },
  ]
})

/* ── inline chart components ── */
function niceMax(v) {
  if (v <= 0) return 1
  const pow = Math.pow(10, Math.floor(Math.log10(v)))
  const n = v / pow
  return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10) * pow
}
function smooth(pts) {
  if (pts.length < 2) return pts.length ? `M ${pts[0].x} ${pts[0].y}` : ''
  let d = `M ${pts[0].x.toFixed(1)} ${pts[0].y.toFixed(1)}`
  for (let i = 0; i < pts.length - 1; i++) { const p0 = pts[i], p1 = pts[i + 1], cx = (p0.x + p1.x) / 2; d += ` C ${cx.toFixed(1)} ${p0.y.toFixed(1)}, ${cx.toFixed(1)} ${p1.y.toFixed(1)}, ${p1.x.toFixed(1)} ${p1.y.toFixed(1)}` }
  return d
}

const AreaChart = {
  props: { series: Array, fmt: Function },
  setup(props) {
    return () => {
      const s = props.series || []
      const W = 760, H = 260, padL = 46, padR = 12, padT = 12, padB = 28, cw = W - padL - padR, ch = H - padT - padB
      if (!s.length) return h('div', { class: 'h-[220px] grid place-items-center text-gray-400 text-[13px]' }, t('reports.noData'))
      const top = niceMax(Math.max(1, ...s.map((d) => d.total), ...s.map((d) => d.commission || 0)))
      const n = s.length
      const xAt = (i) => (n <= 1 ? padL + cw / 2 : padL + (i / (n - 1)) * cw)
      const yAt = (v) => padT + ch - (v / top) * ch
      const rev = s.map((d, i) => ({ x: xAt(i), y: yAt(d.total) }))
      const com = s.map((d, i) => ({ x: xAt(i), y: yAt(d.commission || 0) }))
      const baseY = padT + ch
      const area = `${smooth(rev)} L ${rev[rev.length - 1].x.toFixed(1)} ${baseY} L ${rev[0].x.toFixed(1)} ${baseY} Z`
      const monthShort = (ym) => { const [y, m] = String(ym).split('-').map(Number); return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar' : 'en', { month: 'short' }).format(new Date(y, (m || 1) - 1, 1)) }
      const step = n > 6 ? Math.ceil(n / 6) : 1
      return h('svg', { viewBox: `0 0 ${W} ${H}`, class: 'w-full', style: 'height:260px', preserveAspectRatio: 'none', dir: 'ltr' }, [
        h('defs', {}, [h('linearGradient', { id: 'rgrad', x1: 0, y1: 0, x2: 0, y2: 1 }, [h('stop', { offset: '0%', 'stop-color': '#163c24', 'stop-opacity': 0.15 }), h('stop', { offset: '100%', 'stop-color': '#163c24', 'stop-opacity': 0 })])]),
        ...[1, 0.75, 0.5, 0.25, 0].map((f) => h('g', {}, [
          h('line', { x1: padL, y1: padT + ch - f * ch, x2: W - padR, y2: padT + ch - f * ch, stroke: '#f1f1ef' }),
          h('text', { x: padL - 8, y: padT + ch - f * ch + 3, 'text-anchor': 'end', style: 'font-size:10px', fill: '#9ca3af' }, props.fmt(top * f)),
        ])),
        h('path', { d: area, fill: 'url(#rgrad)' }),
        h('path', { d: smooth(rev), fill: 'none', stroke: '#163c24', 'stroke-width': 2 }),
        h('path', { d: smooth(com), fill: 'none', stroke: '#34d399', 'stroke-width': 2 }),
        ...s.map((d, i) => (i % step === 0 || i === n - 1) ? h('text', { x: xAt(i), y: H - 8, 'text-anchor': 'middle', style: 'font-size:10px', fill: '#9ca3af' }, monthShort(d.month)) : null),
      ])
    }
  },
}
const HBars = {
  props: { items: Array, fmt: Function },
  setup(props) {
    return () => {
      const items = props.items || []
      if (!items.length) return h('div', { class: 'h-32 grid place-items-center text-gray-400 text-[13px]' }, t('reports.noData'))
      const max = Math.max(1, ...items.map((i) => i.value))
      return h('div', { class: 'space-y-3' }, items.map((it) => h('div', { class: 'flex items-center gap-3' }, [
        h('span', { class: 'w-16 text-[12px] text-gray-500 truncate text-end shrink-0' }, it.label),
        h('div', { class: 'flex-1 h-5 rounded bg-gray-50 overflow-hidden', dir: 'ltr' }, [h('div', { class: 'h-full rounded bg-primary', style: `width:${Math.max(2, (it.value / max) * 100)}%` })]),
        h('span', { class: 'w-16 text-[12px] font-semibold text-gray-700 tabular-nums shrink-0' }, props.fmt(it.value)),
      ])))
    }
  },
}
const Donut = {
  props: { segs: Array },
  setup(props) {
    return () => {
      const segs = props.segs || []
      const total = segs.reduce((s, x) => s + x.value, 0) || 1
      const C = 2 * Math.PI * 52
      let acc = 0
      const circles = segs.map((s) => { const frac = s.value / total; const el = h('circle', { cx: 64, cy: 64, r: 52, fill: 'none', 'stroke-width': 16, stroke: s.color, 'stroke-dasharray': `${(frac * C).toFixed(1)} ${(C - frac * C).toFixed(1)}`, 'stroke-dashoffset': (-acc * C).toFixed(1) }); acc += frac; return el })
      return h('div', { class: 'flex items-center gap-5 flex-wrap' }, [
        h('svg', { viewBox: '0 0 128 128', class: 'w-32 h-32 -rotate-90 shrink-0' }, [h('circle', { cx: 64, cy: 64, r: 52, fill: 'none', stroke: '#f1f1ef', 'stroke-width': 16 }), ...circles]),
        h('div', { class: 'flex-1 space-y-2 min-w-[140px]' }, segs.map((s) => h('div', { class: 'flex items-center gap-2' }, [
          h('span', { class: 'w-2.5 h-2.5 rounded-full', style: `background:${s.color}` }),
          h('span', { class: 'text-[13px] text-gray-500 flex-1' }, s.label),
          h('span', { class: 'text-[13px] font-bold text-gray-900 tabular-nums' }, int(s.value)),
        ]))),
      ])
    }
  },
}

function exportCsv() {
  const rows = [['Month', 'Revenue', 'Commission'], ...(data.value.monthly_revenue || []).map((m) => [m.month, m.total, m.commission])]
  const csv = rows.map((r) => r.map((c) => `"${String(c ?? '').replace(/"/g, '""')}"`).join(',')).join('\n')
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'report.csv'; a.click(); URL.revokeObjectURL(a.href)
}
function printPdf() { window.print() }

onMounted(async () => {
  try {
    const res = await adminApi.reports()
    data.value = { ...data.value, ...(res.data.data ?? res.data) }
  } catch { /* keep defaults */ } finally { loading.value = false }
})
</script>
