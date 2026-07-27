<template>
  <AdminLayout>
    <!-- Title row -->
    <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
      <div>
        <h1 class="text-[24px] font-bold text-gray-900 leading-tight">{{ t('dashboard.title') }}</h1>
        <p class="text-gray-500 text-[14px] mt-0.5">{{ t('dashboard.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2">
        <span class="inline-flex items-center gap-1.5 h-9 px-3 rounded-lg border border-gray-200 bg-white text-[13px] font-semibold text-gray-600">
          <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>{{ t('dashboard.live') }}
        </span>
        <button class="inline-flex items-center gap-1.5 h-9 px-4 rounded-lg bg-primary text-white text-[13px] font-semibold hover:bg-primary-container transition-colors">
          <span class="material-symbols-outlined text-[18px]">download</span>{{ t('dashboard.exportReport') }}
        </button>
      </div>
    </div>

    <!-- Loading skeleton -->
    <div v-if="loading" class="grid grid-cols-2 lg:grid-cols-4 gap-4">
      <div v-for="i in 4" :key="i" class="h-32 bg-white rounded-2xl border border-gray-200 animate-pulse"></div>
    </div>

    <template v-else>
      <!-- KPI row -->
      <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
        <div v-for="kpi in primaryKpis" :key="kpi.key" class="bg-white rounded-2xl border border-gray-200 p-5">
          <div class="flex items-start justify-between mb-4">
            <div class="w-10 h-10 rounded-xl bg-gray-50 border border-gray-100 flex items-center justify-center">
              <span class="material-symbols-outlined text-[20px] text-primary">{{ kpi.icon }}</span>
            </div>
            <span
              v-if="kpi.change != null"
              class="inline-flex items-center gap-0.5 text-[12px] font-bold px-2 py-0.5 rounded-full"
              :class="kpi.change >= 0 ? 'text-emerald-600 bg-emerald-50' : 'text-red-600 bg-red-50'"
            >
              {{ kpi.change >= 0 ? '↑' : '↓' }} {{ Math.abs(kpi.change) }}%
            </span>
          </div>
          <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400 mb-1">{{ kpi.label }}</p>
          <p class="text-[26px] font-bold text-gray-900 leading-none tabular-nums">{{ kpi.value }}</p>
        </div>
      </section>

      <!-- Secondary KPI row -->
      <section class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div v-for="kpi in secondaryKpis" :key="kpi.key" class="bg-white rounded-2xl border border-gray-200 p-5">
          <div class="w-10 h-10 rounded-xl bg-gray-50 border border-gray-100 flex items-center justify-center mb-4">
            <span class="material-symbols-outlined text-[20px] text-primary">{{ kpi.icon }}</span>
          </div>
          <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400 mb-1">{{ kpi.label }}</p>
          <p class="text-[24px] font-bold text-gray-900 leading-none tabular-nums">{{ kpi.value }}</p>
        </div>
      </section>

      <!-- Charts row 1: area + donut -->
      <section class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
        <!-- Revenue & Commission -->
        <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-200 p-5">
          <div class="flex items-start justify-between mb-4">
            <div>
              <h3 class="text-[15px] font-bold text-gray-900">{{ t('charts.revenueCommission') }}</h3>
              <p class="text-[12px] text-gray-400">{{ t('charts.monthlyPerformance') }}</p>
            </div>
            <div class="flex items-center gap-0.5 bg-gray-50 border border-gray-200 rounded-lg p-0.5">
              <button
                v-for="r in [3, 6, 12]" :key="r"
                class="px-2.5 py-1 rounded-md text-[12px] font-semibold transition-colors"
                :class="range === r ? 'bg-white text-primary shadow-sm' : 'text-gray-400 hover:text-gray-600'"
                @click="range = r"
              >{{ r }}M</button>
            </div>
          </div>

          <div v-if="chart.hasData" dir="ltr">
            <div class="flex items-center gap-4 mb-2 text-[12px]">
              <span class="flex items-center gap-1.5 text-gray-500"><span class="w-2.5 h-2.5 rounded-sm bg-primary"></span>{{ t('charts.revenue') }}</span>
              <span class="flex items-center gap-1.5 text-gray-500"><span class="w-2.5 h-2.5 rounded-sm bg-emerald-400"></span>{{ t('charts.commission') }}</span>
            </div>
            <svg :viewBox="`0 0 ${chart.W} ${chart.H}`" class="w-full" :style="`height:${chart.H}px;max-height:280px`" preserveAspectRatio="none">
              <defs>
                <linearGradient id="revGrad" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stop-color="#163c24" stop-opacity="0.18" />
                  <stop offset="100%" stop-color="#163c24" stop-opacity="0" />
                </linearGradient>
              </defs>
              <!-- Y gridlines + labels -->
              <g v-for="(tk, i) in chart.yTicks" :key="i">
                <line :x1="chart.padL" :y1="tk.y" :x2="chart.W - chart.padR" :y2="tk.y" stroke="#f1f1ef" stroke-width="1" />
                <text :x="chart.padL - 8" :y="tk.y + 3" text-anchor="end" class="fill-gray-400" style="font-size:10px">{{ tk.label }}</text>
              </g>
              <!-- Revenue area + line -->
              <path :d="chart.area" fill="url(#revGrad)" />
              <path :d="chart.revLine" fill="none" stroke="#163c24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
              <!-- Commission line -->
              <path :d="chart.comLine" fill="none" stroke="#34d399" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
              <!-- X labels -->
              <text v-for="(xl, i) in chart.xLabels" :key="'x' + i" v-show="xl.show" :x="xl.x" :y="chart.H - 8" text-anchor="middle" class="fill-gray-400" style="font-size:10px">{{ xl.label }}</text>
            </svg>
          </div>
          <div v-else class="h-[220px] flex items-center justify-center text-gray-400 text-[13px]">{{ t('charts.noData') }}</div>
        </div>

        <!-- Booking Status donut -->
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
          <h3 class="text-[15px] font-bold text-gray-900">{{ t('charts.bookingStatus') }}</h3>
          <p class="text-[12px] text-gray-400 mb-2">{{ t('charts.distributionOverview') }}</p>
          <div class="flex flex-col items-center">
            <svg viewBox="0 0 128 128" class="w-36 h-36 my-2 -rotate-90">
              <circle cx="64" cy="64" r="52" fill="none" stroke="#f1f1ef" stroke-width="16" />
              <circle
                v-for="seg in donut.segs" :key="seg.key"
                cx="64" cy="64" r="52" fill="none" stroke-width="16" stroke-linecap="butt"
                :stroke="seg.color" :stroke-dasharray="seg.dash" :stroke-dashoffset="seg.offset"
              />
            </svg>
            <div class="w-full space-y-2 mt-3">
              <div v-for="seg in donut.segs" :key="seg.key" class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full" :style="`background:${seg.color}`"></span>
                <span class="text-[13px] text-gray-500 flex-1">{{ t('charts.' + seg.key) }}</span>
                <span class="text-[13px] font-bold text-gray-900 tabular-nums">{{ int(seg.value) }}</span>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Charts row 2: revenue by city + weekly bookings -->
      <section class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <!-- Revenue by City -->
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
          <h3 class="text-[15px] font-bold text-gray-900">{{ t('charts.revenueByCity') }}</h3>
          <p class="text-[12px] text-gray-400 mb-4">{{ t('charts.topLocations') }}</p>
          <div v-if="cityData.length" class="flex items-end justify-between gap-3 h-44" dir="ltr">
            <div v-for="c in cityData" :key="c.city" class="flex-1 h-full flex flex-col items-center justify-end gap-2 group">
              <span class="text-[10px] text-gray-500 font-semibold opacity-0 group-hover:opacity-100 transition-opacity tabular-nums">{{ money(c.total) }}</span>
              <div class="w-full max-w-[42px] rounded-t-md bg-primary transition-all" :style="`height:${barPct(c.total, cityMax)}%`"></div>
              <span class="text-[11px] text-gray-500 truncate max-w-full">{{ c.city }}</span>
            </div>
          </div>
          <div v-else class="h-44 flex items-center justify-center text-gray-400 text-[13px]">{{ t('charts.noData') }}</div>
        </div>

        <!-- Weekly Bookings -->
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
          <h3 class="text-[15px] font-bold text-gray-900">{{ t('charts.weeklyBookings') }}</h3>
          <p class="text-[12px] text-gray-400 mb-4">{{ t('charts.dayByDay') }}</p>
          <div class="flex items-end justify-between gap-2 h-44" dir="ltr">
            <div v-for="d in weekData" :key="d.day" class="flex-1 h-full flex flex-col items-center justify-end gap-2 group">
              <span class="text-[10px] text-gray-500 font-semibold opacity-0 group-hover:opacity-100 transition-opacity tabular-nums">{{ d.count }}</span>
              <div
                class="w-full max-w-[36px] rounded-t-md transition-all"
                :class="d.count === weekMax && weekMax > 0 ? 'bg-primary' : 'bg-primary/25'"
                :style="`height:${barPct(d.count, weekMax)}%`"
              ></div>
              <span class="text-[11px] text-gray-500">{{ t('days.' + d.day) }}</span>
            </div>
          </div>
        </div>
      </section>

      <!-- Latest Pending Requests -->
      <section class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
          <div>
            <h3 class="text-[15px] font-bold text-gray-900">{{ t('table.latestRequests') }}</h3>
            <p class="text-[12px] text-gray-400">{{ t('table.awaitingReview') }}</p>
          </div>
          <RouterLink :to="{ name: 'admin-requests' }" class="inline-flex items-center gap-1 text-[13px] font-semibold text-primary hover:underline">
            <span class="material-symbols-outlined text-[16px]">{{ dir === 'rtl' ? 'arrow_back' : 'arrow_forward' }}</span>{{ t('table.viewAll') }}
          </RouterLink>
        </div>

        <div v-if="requests.length === 0" class="py-14 text-center text-gray-400 text-[13px]">
          <span class="material-symbols-outlined text-3xl opacity-40 block mb-2">inbox</span>{{ t('table.empty') }}
        </div>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-[13px]">
            <thead>
              <tr class="text-gray-400 text-[11px] font-bold uppercase tracking-wide">
                <th class="text-start font-bold px-5 py-3">{{ t('table.requestId') }}</th>
                <th class="text-start font-bold px-5 py-3">{{ t('table.partner') }}</th>
                <th class="text-start font-bold px-5 py-3">{{ t('table.property') }}</th>
                <th class="text-start font-bold px-5 py-3">{{ t('table.type') }}</th>
                <th class="text-end font-bold px-5 py-3">{{ t('table.submitted') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="req in requests" :key="req.id"
                class="border-t border-gray-100 hover:bg-gray-50 cursor-pointer transition-colors"
                @click="goToRequest(req.id)"
              >
                <td class="px-5 py-3.5 font-bold text-gray-900 tabular-nums whitespace-nowrap">{{ req.code || `APR-${req.id}` }}</td>
                <td class="px-5 py-3.5 text-gray-700 whitespace-nowrap">{{ req.name }}</td>
                <td class="px-5 py-3.5 text-gray-600 max-w-[220px] truncate">{{ req.unit_name }}</td>
                <td class="px-5 py-3.5">
                  <span v-if="req.unit_type" class="inline-block px-2.5 py-0.5 rounded-full bg-primary/8 text-primary text-[11px] font-semibold">{{ req.unit_type }}</span>
                  <span v-else class="text-gray-300">—</span>
                </td>
                <td class="px-5 py-3.5 text-gray-400 text-end whitespace-nowrap tabular-nums">{{ formatDate(req.created_at) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </AdminLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { adminApi } from '@/api/admin'
import { useAdminI18n } from '@/i18n/admin'

const { t, dir, locale } = useAdminI18n()
const router = useRouter()
const loading = ref(true)
const range = ref(12) // 3 / 6 / 12 months

const data = ref({
  users: { total: 0, partners: 0, customers: 0 },
  units: { total: 0, draft: 0, pending: 0, approved: 0, rejected: 0 },
  bookings: { total: 0, pending: 0, confirmed: 0, cancelled: 0 },
  revenue: { total: 0, this_month: 0, commission: 0, commission_this_month: 0, currency: 'SAR' },
  occupancy_rate: 0,
  monthly_revenue: [],
  recent_requests: [],
  active_partners: 0,
  avg_booking_value: 0,
  monthly_growth: 0,
  changes: { users: 0, commission: 0, bookings: 0, partners: 0, avg_value: 0 },
  revenue_by_city: [],
  weekly_bookings: [],
})

/* ── formatting ── */
const nfInt = new Intl.NumberFormat('en-US')
const nfCompact = new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 })
const int = (v) => nfInt.format(Number(v) || 0)
const money = (v) => nfCompact.format(Number(v) || 0)
const sar = (v) => `${money(v)} ${t('common.sar')}`
function formatDate(iso) {
  if (!iso) return '—'
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar' : 'en', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(iso))
}

/* ── KPI cards ── */
const primaryKpis = computed(() => [
  { key: 'users',      icon: 'group',         label: t('kpi.totalUsers'),         value: int(data.value.users.total),          change: data.value.changes?.users },
  { key: 'commission', icon: 'trending_up',   label: t('kpi.platformCommission'), value: sar(data.value.revenue.commission),   change: data.value.changes?.commission },
  { key: 'bookings',   icon: 'calendar_month',label: t('kpi.totalBookings'),      value: int(data.value.bookings.total),       change: data.value.changes?.bookings },
  { key: 'partners',   icon: 'store',         label: t('kpi.activePartners'),     value: int(data.value.active_partners),      change: data.value.changes?.partners },
])
const secondaryKpis = computed(() => [
  { key: 'pending', icon: 'schedule',      label: t('kpi.pendingRequests'), value: String(data.value.units.pending).padStart(2, '0') },
  { key: 'growth',  icon: 'trending_up',   label: t('kpi.monthlyGrowth'),   value: `${data.value.monthly_growth}%` },
  { key: 'avg',     icon: 'payments',      label: t('kpi.avgBookingValue'), value: sar(data.value.avg_booking_value) },
])

/* ── Revenue & Commission area chart ── */
function niceMax(v) {
  if (v <= 0) return 1
  const pow = Math.pow(10, Math.floor(Math.log10(v)))
  const n = v / pow
  const nice = n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10
  return nice * pow
}
function smoothPath(pts) {
  if (!pts.length) return ''
  if (pts.length === 1) return `M ${pts[0].x} ${pts[0].y}`
  let d = `M ${pts[0].x.toFixed(1)} ${pts[0].y.toFixed(1)}`
  for (let i = 0; i < pts.length - 1; i++) {
    const p0 = pts[i], p1 = pts[i + 1], cx = (p0.x + p1.x) / 2
    d += ` C ${cx.toFixed(1)} ${p0.y.toFixed(1)}, ${cx.toFixed(1)} ${p1.y.toFixed(1)}, ${p1.x.toFixed(1)} ${p1.y.toFixed(1)}`
  }
  return d
}
function monthShort(ym) {
  const [y, m] = String(ym).split('-').map(Number)
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar' : 'en', { month: 'short' }).format(new Date(y, (m || 1) - 1, 1))
}
const chart = computed(() => {
  const s = data.value.monthly_revenue.slice(-range.value)
  const W = 720, H = 260, padL = 46, padR = 12, padT = 12, padB = 28
  const cw = W - padL - padR, ch = H - padT - padB
  const hasData = s.some((d) => d.total > 0 || d.commission > 0)
  const top = niceMax(Math.max(1, ...s.map((d) => d.total), ...s.map((d) => d.commission)))
  const n = s.length
  const xAt = (i) => (n <= 1 ? padL + cw / 2 : padL + (i / (n - 1)) * cw)
  const yAt = (v) => padT + ch - (v / top) * ch
  const revPts = s.map((d, i) => ({ x: xAt(i), y: yAt(d.total) }))
  const comPts = s.map((d, i) => ({ x: xAt(i), y: yAt(d.commission) }))
  const revLine = smoothPath(revPts)
  const baseY = padT + ch
  const last = revPts[revPts.length - 1] || { x: padL }
  const first = revPts[0] || { x: padL }
  const area = revPts.length ? `${revLine} L ${last.x.toFixed(1)} ${baseY} L ${first.x.toFixed(1)} ${baseY} Z` : ''
  const yTicks = [1, 0.75, 0.5, 0.25, 0].map((f) => ({ y: padT + ch - f * ch, label: money(top * f) }))
  const step = n > 6 ? Math.ceil(n / 6) : 1
  const xLabels = s.map((d, i) => ({ x: xAt(i), label: monthShort(d.month), show: i % step === 0 || i === n - 1 }))
  return { W, H, padL, padR, area, revLine, comLine: smoothPath(comPts), yTicks, xLabels, baseY, hasData }
})

/* ── Booking status donut ── */
const donut = computed(() => {
  const b = data.value.bookings
  const segs = [
    { key: 'completed', value: b.confirmed || 0, color: '#2e7d46' },
    { key: 'pending',   value: b.pending || 0,   color: '#f5a623' },
    { key: 'cancelled', value: b.cancelled || 0, color: '#ef5a3c' },
  ]
  const total = segs.reduce((s, x) => s + x.value, 0) || 1
  const C = 2 * Math.PI * 52
  let acc = 0
  segs.forEach((s) => {
    const frac = s.value / total
    s.dash = `${(frac * C).toFixed(1)} ${(C - frac * C).toFixed(1)}`
    s.offset = (-acc * C).toFixed(1)
    acc += frac
  })
  return { segs, total }
})

/* ── Bar charts ── */
const cityData = computed(() => data.value.revenue_by_city || [])
const cityMax = computed(() => Math.max(1, ...cityData.value.map((c) => c.total)))
const weekData = computed(() => (data.value.weekly_bookings?.length
  ? data.value.weekly_bookings
  : ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'].map((day) => ({ day, count: 0 }))))
const weekMax = computed(() => Math.max(0, ...weekData.value.map((d) => d.count)))
const barPct = (v, max) => (max > 0 ? Math.max(v > 0 ? 4 : 0, Math.round((v / max) * 100)) : 0)

/* ── Requests table ── */
const requests = computed(() => data.value.recent_requests || [])
function goToRequest(id) {
  router.push({ name: 'admin-request-detail', params: { id } })
}

onMounted(async () => {
  try {
    const res = await adminApi.dashboard()
    data.value = { ...data.value, ...(res.data.data ?? res.data) }
  } catch (e) {
    /* keep zeros on failure */
  } finally {
    loading.value = false
  }
})
</script>
