<template>
  <AdminLayout>
    <div class="mb-8">
      <h1 class="font-display-lg text-display-lg text-primary mb-1">جميع الحجوزات</h1>
      <p class="text-on-surface-variant text-body-md">إدارة ومتابعة كافة العمليات العقارية في المنصة</p>
    </div>

    <!-- Summary cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
      <div v-for="s in summaryCards" :key="s.label" class="p-4 bg-white rounded-2xl border border-outline-variant shadow-sm">
        <p class="font-label-caps text-label-caps text-on-surface-variant mb-1">{{ s.label }}</p>
        <p class="font-numeric-data text-2xl font-bold" :class="s.color">{{ s.value }}</p>
      </div>
    </div>

    <!-- Filter tabs -->
    <div class="flex gap-2 overflow-x-auto pb-1 mb-6">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        class="whitespace-nowrap px-5 py-2 rounded-full font-title-sm text-[14px] transition-all"
        :class="activeTab === tab.key ? 'bg-primary text-on-primary shadow-sm' : 'bg-white border border-outline-variant text-on-surface-variant hover:bg-surface-container'"
        @click="changeTab(tab.key)"
      >
        {{ tab.label }}
      </button>
    </div>

    <!-- Search -->
    <div class="relative mb-6">
      <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-on-surface-variant">search</span>
      <input
        v-model="search"
        @keyup.enter="reload"
        class="w-full sm:w-96 pr-12 pl-4 py-2.5 bg-white border border-outline-variant rounded-full text-body-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition-all"
        placeholder="ابحث برقم الحجز أو العميل أو الوحدة... (اضغط Enter)"
      />
    </div>

    <!-- Loading -->
    <div v-if="loading" class="bg-white rounded-2xl border border-outline-variant p-4 space-y-3">
      <div v-for="i in 5" :key="i" class="animate-pulse h-10 bg-surface-container rounded"></div>
    </div>

    <!-- Empty -->
    <div v-else-if="bookings.length === 0" class="text-center py-16 bg-white rounded-2xl border border-outline-variant text-on-surface-variant">
      <span class="material-symbols-outlined text-5xl mb-3 block">calendar_today</span>
      <p class="font-title-sm text-title-sm">لا توجد حجوزات مطابقة</p>
    </div>

    <!-- Table -->
    <div v-else class="bg-white rounded-2xl border border-outline-variant shadow-sm overflow-x-auto">
      <table class="w-full min-w-[820px]">
        <thead>
          <tr class="bg-surface-container-low border-b border-outline-variant">
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">رقم الحجز</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">العميل</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">الوحدة</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">التواريخ</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">المبلغ</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">الدفع</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">الحالة</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="b in bookings" :key="b.id" class="border-b border-outline-variant/50 last:border-0 hover:bg-surface-container-low/50 transition-colors">
            <td class="py-3 px-4 font-numeric-data text-body-sm text-primary font-bold">#{{ b.id }}</td>
            <td class="py-3 px-4">
              <p class="font-body-md font-semibold text-on-surface">{{ b.user?.name || '—' }}</p>
              <p class="text-body-sm text-on-surface-variant" dir="ltr">{{ b.user?.phone }}</p>
            </td>
            <td class="py-3 px-4">
              <p class="text-body-sm text-on-surface">{{ b.unit?.name || '—' }}</p>
              <p class="text-body-sm text-on-surface-variant">{{ b.unit?.city }}</p>
            </td>
            <td class="py-3 px-4">
              <p class="font-numeric-data text-body-sm text-on-surface" dir="ltr">{{ b.start_date }}</p>
              <p class="font-numeric-data text-body-sm text-on-surface-variant" dir="ltr">{{ b.end_date }}</p>
            </td>
            <td class="py-3 px-4 font-numeric-data text-body-sm font-bold text-on-surface">{{ formatMoney(b.total_amount) }} ر.س</td>
            <td class="py-3 px-4">
              <span class="px-2 py-0.5 rounded-full text-[11px] font-bold" :class="payClass(b)">{{ payLabel(b) }}</span>
            </td>
            <td class="py-3 px-4">
              <span class="px-2.5 py-1 rounded-full text-[12px] font-bold" :class="statusClass(b.status)">{{ b.status_label }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div v-if="!loading && meta.last_page > 1" class="flex items-center justify-center gap-2 mt-4">
      <button class="px-3 py-1.5 rounded-lg border border-outline-variant text-body-sm disabled:opacity-40" :disabled="page <= 1" @click="goPage(page - 1)">السابق</button>
      <span class="text-body-sm text-on-surface-variant">{{ page }} / {{ meta.last_page }}</span>
      <button class="px-3 py-1.5 rounded-lg border border-outline-variant text-body-sm disabled:opacity-40" :disabled="page >= meta.last_page" @click="goPage(page + 1)">التالي</button>
    </div>

    <Transition name="fade">
      <div v-if="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 px-5 py-3 rounded-xl shadow-lg text-white font-bold text-body-sm bg-error">
        {{ toast }}
      </div>
    </Transition>
  </AdminLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { adminApi } from '@/api/admin'

const loading = ref(true)
const bookings = ref([])
const meta = ref({ last_page: 1 })
const summary = ref({ total: 0, confirmed: 0, pending: 0, cancelled: 0, revenue: 0 })
const page = ref(1)
const activeTab = ref('all')
const search = ref('')
const toast = ref(null)

const tabs = [
  { key: 'all',       label: 'الكل' },
  { key: 'confirmed', label: 'مؤكد' },
  { key: 'pending',   label: 'قيد الانتظار' },
  { key: 'cancelled', label: 'ملغى' },
]

const summaryCards = computed(() => [
  { label: 'إجمالي الحجوزات', value: summary.value.total, color: 'text-on-surface' },
  { label: 'مؤكدة',           value: summary.value.confirmed, color: 'text-emerald-600' },
  { label: 'قيد الانتظار',    value: summary.value.pending, color: 'text-amber-600' },
  { label: 'إيرادات المنصة',  value: `${formatMoney(summary.value.revenue)} ر.س`, color: 'text-primary' },
])

function showToast(msg) {
  toast.value = msg
  setTimeout(() => (toast.value = null), 2800)
}
function formatMoney(v) {
  return new Intl.NumberFormat('en-US').format(Number(v) || 0)
}
function statusClass(s) {
  return { confirmed: 'bg-emerald-100 text-emerald-700', pending: 'bg-amber-100 text-amber-700', cancelled: 'bg-red-100 text-red-700' }[s] || 'bg-surface-container text-on-surface-variant'
}
function payLabel(b) {
  const ps = b.payment?.payment_status
  if (ps === 'paid') return 'مدفوع'
  if (ps === 'failed') return 'فشل'
  return 'غير مدفوع'
}
function payClass(b) {
  const ps = b.payment?.payment_status
  if (ps === 'paid') return 'bg-emerald-100 text-emerald-700'
  if (ps === 'failed') return 'bg-red-100 text-red-700'
  return 'bg-surface-container text-on-surface-variant'
}

async function load() {
  loading.value = true
  try {
    const params = { page: page.value }
    if (activeTab.value !== 'all') params.status = activeTab.value
    if (search.value) params.search = search.value
    const { data } = await adminApi.listBookings(params)
    bookings.value = data.data ?? []
    meta.value = data.meta ?? { last_page: 1 }
    summary.value = data.summary ?? summary.value
  } catch (e) {
    showToast('تعذّر تحميل الحجوزات')
  } finally {
    loading.value = false
  }
}

function reload() {
  page.value = 1
  load()
}
function changeTab(key) {
  activeTab.value = key
  page.value = 1
  load()
}
function goPage(p) {
  page.value = p
  load()
}

onMounted(load)
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.25s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
