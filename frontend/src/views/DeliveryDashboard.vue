<script setup>
/**
 * DeliveryDashboard — ywsel operations overview.
 * Composition demo of the primitives (StatCard, StatusBadge, UiButton) inside DashboardShell.
 * Replace the mock data with store/API calls.
 */
import DashboardShell from '@/layouts/DashboardShell.vue'
import StatCard from '@/components/ui/StatCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import UiButton from '@/components/ui/UiButton.vue'
import AreaChart from '@/components/ui/AreaChart.vue'

// Orders per day, last 7 days (mock — swap for API series).
const week = {
  labels: ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'],
  data:   [620, 740, 690, 880, 810, 1020, 942],
}

const kpis = [
  { label: 'طلبات نشطة',   value: '1,284', delta: '+12%', trend: 'up',   icon: 'package_2' },
  { label: 'تم التوصيل اليوم', value: '942', delta: '+8%',  trend: 'up',   icon: 'task_alt' },
  { label: 'في الطريق',     value: '186',   delta: '+3%',  trend: 'up',   icon: 'local_shipping' },
  { label: 'مندوبون متصلون', value: '64',   delta: '−5',   trend: 'down', icon: 'two_wheeler' },
]

const orders = [
  { id: '#YW-90412', customer: 'أحمد سمير',  zone: 'المعادي',     courier: 'محمود علي',  status: 'in_transit', total: '320' },
  { id: '#YW-90411', customer: 'سارة فؤاد',  zone: 'مدينة نصر',   courier: 'كريم حسن',  status: 'delivered',  total: '145' },
  { id: '#YW-90410', customer: 'محمد خالد',  zone: 'الزمالك',     courier: '—',          status: 'pending',    total: '560' },
  { id: '#YW-90409', customer: 'منى رضا',    zone: 'مصر الجديدة', courier: 'أحمد جابر',  status: 'cancelled',  total: '90'  },
  { id: '#YW-90408', customer: 'يوسف طارق',  zone: 'الهرم',       courier: 'سامي نبيل',  status: 'delivered',  total: '410' },
]

const couriers = [
  { name: 'محمود علي', zone: 'المعادي',   load: 6, status: 'in_transit' },
  { name: 'كريم حسن',  zone: 'مدينة نصر', load: 3, status: 'delivered' },
  { name: 'سامي نبيل', zone: 'الهرم',     load: 4, status: 'in_transit' },
  { name: 'أحمد جابر', zone: 'مصر الجديدة', load: 0, status: 'pending' },
]
</script>

<template>
  <DashboardShell title="نظرة عامة">
    <!-- Page header -->
    <div class="flex flex-wrap items-center justify-between gap-3 mb-5 md:mb-6">
      <div>
        <h1 class="text-[24px] md:text-[28px] font-bold leading-tight">نظرة عامة على العمليات</h1>
        <p class="text-sm text-fg-muted mt-0.5">آخر تحديث منذ دقيقتين · توقيت القاهرة</p>
      </div>
      <div class="flex items-center gap-2">
        <UiButton variant="secondary" size="md" icon="download">تصدير</UiButton>
        <UiButton variant="primary" size="md" icon="add">طلب جديد</UiButton>
      </div>
    </div>

    <!-- KPI grid: 1 → 2 → 4 -->
    <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 md:gap-4">
      <StatCard v-for="k in kpis" :key="k.label" v-bind="k" />
    </section>

    <!-- Orders trend chart -->
    <section class="bg-ink-900 border border-ink-700 rounded-2xl p-4 md:p-5 mt-4 md:mt-6">
      <header class="flex items-start justify-between gap-3 mb-4">
        <div>
          <h2 class="text-[17px] font-semibold">حجم الطلبات</h2>
          <p class="text-sm text-fg-muted mt-0.5">آخر 7 أيام</p>
        </div>
        <div class="text-end">
          <p class="font-data tabular-nums text-2xl font-bold leading-none">5,702</p>
          <span class="inline-flex items-center gap-0.5 mt-1 text-[12px] text-ok">
            <span class="material-symbols-outlined text-[14px]">trending_up</span>+14% عن الأسبوع الماضي
          </span>
        </div>
      </header>
      <AreaChart :data="week.data" :labels="week.labels" :height="200"
                 aria-label="حجم الطلبات خلال آخر سبعة أيام" />
    </section>

    <!-- Split: orders table (2/3) + couriers (1/3) -->
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-4 md:mt-6">
      <!-- Orders -->
      <div class="lg:col-span-2 bg-ink-900 border border-ink-700 rounded-2xl overflow-hidden">
        <header class="flex items-center justify-between px-4 md:px-5 h-14 border-b border-ink-700">
          <h2 class="text-[17px] font-semibold">أحدث الطلبات</h2>
          <UiButton variant="ghost" size="sm" icon="open_in_new">عرض الكل</UiButton>
        </header>

        <!-- Desktop table -->
        <div class="hidden md:block overflow-x-auto">
          <table class="w-full border-collapse text-start">
            <thead>
              <tr class="text-[11px] uppercase tracking-wider text-fg-subtle">
                <th class="text-start font-bold px-5 py-3">رقم الطلب</th>
                <th class="text-start font-bold px-5 py-3">العميل</th>
                <th class="text-start font-bold px-5 py-3">المنطقة</th>
                <th class="text-start font-bold px-5 py-3">المندوب</th>
                <th class="text-start font-bold px-5 py-3">الحالة</th>
                <th class="text-end font-bold px-5 py-3">الإجمالي</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="o in orders" :key="o.id" class="transition-colors hover:bg-ink-850">
                <td class="px-5 py-3.5 border-t border-ink-700 font-data text-sm text-brand-300">{{ o.id }}</td>
                <td class="px-5 py-3.5 border-t border-ink-700 text-sm">{{ o.customer }}</td>
                <td class="px-5 py-3.5 border-t border-ink-700 text-sm text-fg-muted">{{ o.zone }}</td>
                <td class="px-5 py-3.5 border-t border-ink-700 text-sm text-fg-muted">{{ o.courier }}</td>
                <td class="px-5 py-3.5 border-t border-ink-700"><StatusBadge :status="o.status" /></td>
                <td class="px-5 py-3.5 border-t border-ink-700 text-end font-data tabular-nums text-sm">
                  {{ o.total }} <span class="text-fg-subtle text-xs">ج.م</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobile cards (table → stacked) -->
        <ul class="md:hidden divide-y divide-ink-700">
          <li v-for="o in orders" :key="o.id" class="p-4 flex items-center justify-between gap-3">
            <div class="min-w-0">
              <p class="font-data text-sm text-brand-300">{{ o.id }}</p>
              <p class="text-sm truncate mt-0.5">{{ o.customer }} · <span class="text-fg-muted">{{ o.zone }}</span></p>
            </div>
            <div class="flex flex-col items-end gap-1.5 shrink-0">
              <StatusBadge :status="o.status" />
              <span class="font-data tabular-nums text-sm">{{ o.total }} <span class="text-fg-subtle text-xs">ج.م</span></span>
            </div>
          </li>
        </ul>
      </div>

      <!-- Couriers -->
      <div class="bg-ink-900 border border-ink-700 rounded-2xl">
        <header class="flex items-center justify-between px-4 md:px-5 h-14 border-b border-ink-700">
          <h2 class="text-[17px] font-semibold">المندوبون النشطون</h2>
          <span class="text-xs text-fg-subtle font-data">4 / 64</span>
        </header>
        <ul class="p-3 space-y-1">
          <li v-for="c in couriers" :key="c.name"
              class="flex items-center gap-3 p-2.5 rounded-xl hover:bg-ink-850 transition-colors">
            <div class="relative">
              <div class="size-9 grid place-items-center rounded-full bg-ink-800 text-fg font-data text-sm font-bold">
                {{ c.name.split(' ').map(w => w[0]).join('') }}
              </div>
              <span class="absolute -bottom-0.5 -end-0.5 size-3 rounded-full ring-2 ring-ink-900"
                    :class="{ 'bg-info': c.status === 'in_transit', 'bg-ok': c.status === 'delivered', 'bg-fg-subtle': c.status === 'pending' }" />
            </div>
            <div class="min-w-0 flex-1">
              <p class="text-sm font-medium truncate">{{ c.name }}</p>
              <p class="text-xs text-fg-subtle truncate">{{ c.zone }}</p>
            </div>
            <span class="font-data tabular-nums text-sm text-fg-muted">{{ c.load }}
              <span class="material-symbols-outlined text-[15px] align-middle">package_2</span>
            </span>
          </li>
        </ul>
      </div>
    </section>
  </DashboardShell>
</template>
