<template>
  <AdminLayout>
    <div class="mb-8">
      <h1 class="font-display-lg text-display-lg text-primary mb-1">الطلبات</h1>
      <p class="text-on-surface-variant text-body-md">مراجعة وإدارة طلبات الوحدات المقدّمة من الشركاء</p>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
      <div v-for="stat in statCards" :key="stat.label" class="p-4 bg-white rounded-2xl border border-outline-variant shadow-sm relative overflow-hidden">
        <div class="absolute top-0 right-0 w-1 h-full rounded-r-2xl" :class="stat.accent"></div>
        <p class="font-label-caps text-label-caps text-on-surface-variant mb-1">{{ stat.label }}</p>
        <p class="font-numeric-data text-2xl font-bold" :class="stat.textColor">{{ stat.value }}</p>
      </div>
    </div>

    <!-- Tabs -->
    <div class="flex gap-1 border-b border-outline-variant mb-6">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        class="px-6 py-3 font-title-sm text-[15px] transition-all border-b-4"
        :class="filters.status === tab.key ? 'text-primary border-primary' : 'text-on-surface-variant border-transparent hover:text-primary'"
        @click="changeTab(tab.key)"
      >
        {{ tab.label }}
      </button>
    </div>

    <!-- Search -->
    <div class="relative mb-6">
      <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-on-surface-variant">search</span>
      <input
        v-model="filters.search"
        @keyup.enter="load"
        class="w-full sm:w-96 pr-12 pl-4 py-2.5 bg-white border border-outline-variant rounded-full text-body-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition-all"
        placeholder="البحث في الطلبات... (اضغط Enter)"
      />
    </div>

    <!-- Loading -->
    <div v-if="loading" class="space-y-3">
      <div v-for="i in 3" :key="i" class="animate-pulse bg-white rounded-xl border border-outline-variant p-4 flex items-center gap-4">
        <div class="w-12 h-12 bg-surface-container rounded-xl"></div>
        <div class="flex-1 space-y-2"><div class="h-4 bg-surface-container rounded w-1/4"></div><div class="h-3 bg-surface-container rounded w-1/2"></div></div>
      </div>
    </div>

    <!-- Empty -->
    <div v-else-if="requests.length === 0" class="text-center py-16 text-on-surface-variant bg-white rounded-2xl border border-outline-variant">
      <span class="material-symbols-outlined text-5xl mb-3 block">assignment</span>
      <p class="font-title-sm text-title-sm">لا توجد طلبات مطابقة</p>
    </div>

    <!-- List -->
    <div v-else class="space-y-3">
      <div v-for="req in requests" :key="req.id" class="bg-white rounded-xl border border-outline-variant shadow-sm hover:shadow-card transition-all">
        <div class="p-4 flex items-center gap-4">
          <div class="w-12 h-12 rounded-xl bg-surface-container flex items-center justify-center flex-shrink-0">
            <span class="material-symbols-outlined text-primary">{{ req.type === 'Company' ? 'business' : 'person' }}</span>
          </div>

          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-0.5 flex-wrap">
              <h3 class="font-title-sm text-title-sm text-on-surface">{{ req.name }}</h3>
              <span class="px-2 py-0.5 rounded-full text-[10px] font-bold border" :class="typeBadge(req.type)">{{ req.type === 'Company' ? 'شركة' : 'فرد' }}</span>
            </div>
            <p class="text-body-sm text-on-surface-variant truncate">{{ req.unit_name }}{{ req.city ? ` - ${req.city}` : '' }}</p>
            <div class="flex items-center gap-3 mt-1">
              <span class="font-numeric-data text-[11px] text-on-surface-variant" dir="ltr">{{ formatDate(req.created_at) }}</span>
              <span class="font-numeric-data text-[11px] text-primary font-bold">{{ req.code }}</span>
            </div>
          </div>

          <div class="flex items-center gap-3 flex-shrink-0">
            <span class="hidden sm:inline px-3 py-1 rounded-full text-[12px] font-bold" :class="statusClass(req.approval_status)">{{ statusLabel(req.approval_status) }}</span>
            <RouterLink :to="{ name: 'admin-request-detail', params: { id: req.id } }" class="w-9 h-9 flex items-center justify-center rounded-lg border border-outline-variant text-on-surface-variant hover:text-primary hover:bg-surface-container transition-colors">
              <span class="material-symbols-outlined text-[18px]">chevron_left</span>
            </RouterLink>
          </div>
        </div>

        <div v-if="req.approval_status === 'pending'" class="px-4 pb-4 flex gap-2">
          <button class="flex-1 py-2 rounded-lg bg-emerald-600 text-white font-bold text-sm hover:bg-emerald-700 transition-colors flex items-center justify-center gap-1.5 disabled:opacity-50" :disabled="busyId === req.id" @click="approve(req)">
            <span class="material-symbols-outlined text-[16px]">check_circle</span>
            موافقة
          </button>
          <button class="flex-1 py-2 rounded-lg border border-error text-error font-bold text-sm hover:bg-error-container transition-colors flex items-center justify-center gap-1.5 disabled:opacity-50" :disabled="busyId === req.id" @click="rejectTarget = req">
            <span class="material-symbols-outlined text-[16px]">cancel</span>
            رفض
          </button>
        </div>
      </div>
    </div>

    <!-- Pagination -->
    <div v-if="!loading && meta.last_page > 1" class="flex items-center justify-center gap-2 mt-6">
      <button class="px-3 py-1.5 rounded-lg border border-outline-variant text-body-sm disabled:opacity-40" :disabled="page <= 1" @click="goPage(page - 1)">السابق</button>
      <span class="text-body-sm text-on-surface-variant">{{ page }} / {{ meta.last_page }}</span>
      <button class="px-3 py-1.5 rounded-lg border border-outline-variant text-body-sm disabled:opacity-40" :disabled="page >= meta.last_page" @click="goPage(page + 1)">التالي</button>
    </div>

    <!-- Reject modal -->
    <Teleport to="body">
      <div v-if="rejectTarget" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl border border-outline-variant shadow-xl w-full max-w-md p-6" dir="rtl">
          <h2 class="font-headline-md text-headline-md text-on-surface mb-2">سبب الرفض</h2>
          <p class="text-body-sm text-on-surface-variant mb-4">سيُرسل هذا السبب إلى الشريك ({{ rejectTarget.name }}).</p>
          <textarea v-model="rejectReason" rows="4" class="w-full px-4 py-3 bg-surface-container-low border border-outline-variant rounded-xl text-body-sm resize-none focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none mb-4" placeholder="اذكر سبب الرفض..."></textarea>
          <div class="flex gap-3">
            <button class="flex-1 py-3 bg-error text-on-error rounded-xl font-bold hover:opacity-90 transition-opacity disabled:opacity-50" :disabled="!rejectReason.trim() || busyId === rejectTarget.id" @click="reject">رفض الطلب</button>
            <button class="flex-1 py-3 border border-outline-variant rounded-xl font-bold text-on-surface hover:bg-surface-container transition-colors" @click="closeReject">إلغاء</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Toast -->
    <Transition name="fade">
      <div v-if="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 px-5 py-3 rounded-xl shadow-lg text-white font-bold text-body-sm" :class="toast.type === 'error' ? 'bg-error' : 'bg-primary'">
        {{ toast.msg }}
      </div>
    </Transition>
  </AdminLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { adminApi } from '@/api/admin'

const loading = ref(true)
const busyId = ref(null)
const requests = ref([])
const meta = ref({ last_page: 1 })
const page = ref(1)
const stats = ref({ total: 0, pending: 0, approved: 0, rejected: 0 })
const rejectTarget = ref(null)
const rejectReason = ref('')
const toast = ref(null)

const filters = reactive({ search: '', status: 'all' })

const tabs = [
  { key: 'all',      label: 'الكل' },
  { key: 'pending',  label: 'معلقة' },
  { key: 'approved', label: 'مقبولة' },
  { key: 'rejected', label: 'مرفوضة' },
]

const statCards = computed(() => [
  { label: 'إجمالي الطلبات', value: stats.value.total,    accent: 'bg-primary',     textColor: 'text-primary' },
  { label: 'معلقة',          value: stats.value.pending,  accent: 'bg-amber-500',   textColor: 'text-amber-600' },
  { label: 'مقبولة',         value: stats.value.approved, accent: 'bg-emerald-500', textColor: 'text-emerald-600' },
  { label: 'مرفوضة',         value: stats.value.rejected, accent: 'bg-error',       textColor: 'text-error' },
])

function showToast(msg, type = 'success') {
  toast.value = { msg, type }
  setTimeout(() => (toast.value = null), 2800)
}
function typeBadge(type) {
  return type === 'Company' ? 'bg-blue-100 text-blue-700 border-blue-200' : 'bg-secondary-container text-on-secondary-container border-secondary-fixed-dim'
}
function statusClass(status) {
  return { pending: 'bg-amber-100 text-amber-700', approved: 'bg-emerald-100 text-emerald-700', rejected: 'bg-red-100 text-red-700' }[status] || ''
}
function statusLabel(status) {
  return { pending: 'معلق', approved: 'مقبول', rejected: 'مرفوض' }[status] || status
}
function formatDate(dateString) {
  if (!dateString) return ''
  return new Date(dateString).toLocaleDateString('en-CA')
}

async function load() {
  loading.value = true
  try {
    const params = { page: page.value }
    if (filters.status !== 'all') params.status = filters.status
    if (filters.search) params.search = filters.search
    const { data } = await adminApi.listRequests(params)
    requests.value = data.data ?? []
    meta.value = data.meta ?? { last_page: 1 }
    stats.value = data.stats ?? stats.value
  } catch (e) {
    requests.value = []
    showToast('تعذّر تحميل الطلبات', 'error')
  } finally {
    loading.value = false
  }
}

function changeTab(statusKey) {
  filters.status = statusKey
  page.value = 1
  load()
}
function goPage(p) {
  page.value = p
  load()
}

async function approve(req) {
  busyId.value = req.id
  try {
    await adminApi.approveRequest(req.id)
    req.approval_status = 'approved'
    stats.value.pending = Math.max(0, stats.value.pending - 1)
    stats.value.approved += 1
    showToast('تمت الموافقة على الطلب')
  } catch (e) {
    showToast(e.response?.data?.message || 'تعذّر الموافقة', 'error')
  } finally {
    busyId.value = null
  }
}

function closeReject() {
  rejectTarget.value = null
  rejectReason.value = ''
}
async function reject() {
  const req = rejectTarget.value
  busyId.value = req.id
  try {
    await adminApi.rejectRequest(req.id, rejectReason.value.trim())
    req.approval_status = 'rejected'
    stats.value.pending = Math.max(0, stats.value.pending - 1)
    stats.value.rejected += 1
    showToast('تم رفض الطلب')
    closeReject()
  } catch (e) {
    showToast(e.response?.data?.message || 'تعذّر الرفض', 'error')
  } finally {
    busyId.value = null
  }
}

onMounted(load)
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.25s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
