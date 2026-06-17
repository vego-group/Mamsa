<template>
  <AdminLayout>
    <div class="mb-8">
      <h1 class="font-display-lg text-display-lg text-primary mb-1">إدارة الوحدات</h1>
      <p class="text-on-surface-variant text-body-md">إدارة ومراجعة كافة الوحدات السكنية في المنصة</p>
    </div>

    <!-- Search + filter -->
    <div class="flex flex-col sm:flex-row gap-3 mb-6">
      <div class="relative flex-1">
        <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-on-surface-variant">search</span>
        <input
          v-model="search"
          @keyup.enter="reload"
          class="w-full pr-12 pl-4 py-2.5 bg-white border border-outline-variant rounded-xl text-body-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition-all"
          placeholder="ابحث بالاسم أو الكود أو المدينة... (اضغط Enter)"
        />
      </div>
      <div class="flex gap-2 overflow-x-auto pb-1">
        <button
          v-for="f in filters"
          :key="f.key"
          class="whitespace-nowrap px-5 py-2 rounded-full font-title-sm text-[14px] transition-all"
          :class="activeFilter === f.key ? 'bg-primary text-on-primary shadow-sm' : 'bg-white border border-outline-variant text-on-surface-variant hover:bg-surface-container'"
          @click="changeFilter(f.key)"
        >
          {{ f.label }}
        </button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
      <div v-for="i in 6" :key="i" class="h-56 bg-white rounded-xl border border-outline-variant animate-pulse"></div>
    </div>

    <!-- Empty -->
    <div v-else-if="units.length === 0" class="text-center py-16 bg-white rounded-2xl border border-outline-variant">
      <span class="material-symbols-outlined text-5xl mb-3 block text-on-surface-variant">apartment</span>
      <p class="font-title-sm text-title-sm text-on-surface">لا توجد وحدات مطابقة</p>
    </div>

    <!-- Grid -->
    <div v-else class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
      <div v-for="unit in units" :key="unit.id" class="bg-white rounded-xl border border-outline-variant shadow-sm hover:shadow-card transition-all overflow-hidden">
        <div class="h-36 bg-surface-container relative">
          <img v-if="mainImage(unit)" :src="mainImage(unit)" :alt="unit.name" class="w-full h-full object-cover" />
          <div v-else class="w-full h-full flex items-center justify-center"><span class="material-symbols-outlined text-3xl text-on-surface-variant">apartment</span></div>
          <span class="absolute top-2 right-2 px-2 py-0.5 rounded-full text-[10px] font-bold border" :class="statusBadge(unit.approval_status)">
            {{ statusLabel(unit.approval_status) }}
          </span>
        </div>
        <div class="p-4">
          <div class="flex justify-between items-start gap-2 mb-1">
            <h3 class="font-title-sm text-title-sm text-on-surface truncate">{{ unit.name }}</h3>
            <span class="text-body-sm text-on-surface-variant whitespace-nowrap">{{ typeLabel(unit.type) }}</span>
          </div>
          <div class="flex items-center gap-1.5 text-on-surface-variant mb-1">
            <span class="material-symbols-outlined text-[14px]">person</span>
            <span class="text-body-sm truncate">{{ unit.owner?.name || '—' }}</span>
          </div>
          <div class="flex items-center justify-between">
            <span class="font-numeric-data text-[11px] text-on-surface-variant">{{ unit.code }}</span>
            <span class="text-primary font-bold text-body-md">{{ formatMoney(unit.price) }} ر.س</span>
          </div>
        </div>
        <div class="px-4 pb-4 pt-2 border-t border-outline-variant/50 flex gap-2">
          <RouterLink
            :to="{ name: 'admin-request-detail', params: { id: unit.id } }"
            class="flex-1 py-2 rounded-lg border border-outline-variant text-primary font-title-sm text-sm text-center hover:bg-surface-container-low transition-colors flex items-center justify-center gap-1.5"
          >
            <span class="material-symbols-outlined text-[16px]">visibility</span>
            عرض
          </RouterLink>
          <template v-if="unit.approval_status === 'pending'">
            <button class="flex-1 py-2 rounded-lg bg-emerald-600 text-white font-title-sm text-sm hover:bg-emerald-700 transition-colors disabled:opacity-50" :disabled="busyId === unit.id" @click="approve(unit)">موافقة</button>
            <button class="py-2 px-3 rounded-lg border border-error text-error hover:bg-error-container transition-colors disabled:opacity-50" :disabled="busyId === unit.id" @click="rejectTarget = unit">
              <span class="material-symbols-outlined text-[18px]">close</span>
            </button>
          </template>
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
          <p class="text-body-sm text-on-surface-variant mb-4">سيُرسل هذا السبب للشريك ({{ rejectTarget.name }}).</p>
          <textarea v-model="rejectReason" rows="4" class="w-full px-4 py-3 bg-surface-container-low border border-outline-variant rounded-xl text-body-sm resize-none focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none mb-4" placeholder="اذكر سبب الرفض..."></textarea>
          <div class="flex gap-3">
            <button class="flex-1 py-3 bg-error text-on-error rounded-xl font-bold hover:opacity-90 transition-opacity disabled:opacity-50" :disabled="!rejectReason.trim() || busyId === rejectTarget.id" @click="reject">رفض الوحدة</button>
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
import { ref, onMounted } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { adminApi } from '@/api/admin'

const loading = ref(true)
const busyId = ref(null)
const units = ref([])
const meta = ref({ last_page: 1 })
const page = ref(1)
const search = ref('')
const activeFilter = ref('all')
const rejectTarget = ref(null)
const rejectReason = ref('')
const toast = ref(null)

const filters = [
  { key: 'all',      label: 'الكل' },
  { key: 'pending',  label: 'قيد الموافقة' },
  { key: 'approved', label: 'معتمدة' },
  { key: 'rejected', label: 'مرفوضة' },
  { key: 'draft',    label: 'مسودة' },
]

function showToast(msg, type = 'success') {
  toast.value = { msg, type }
  setTimeout(() => (toast.value = null), 2800)
}
function mainImage(unit) {
  const imgs = unit.images || []
  return (imgs.find((i) => i.is_main) || imgs[0])?.url || null
}
function formatMoney(v) {
  return new Intl.NumberFormat('en-US').format(Number(v) || 0)
}
function statusLabel(s) {
  return { draft: 'مسودة', pending: 'قيد الموافقة', approved: 'معتمدة', rejected: 'مرفوضة' }[s] || s
}
function statusBadge(s) {
  return {
    draft:    'bg-surface-container text-on-surface-variant border-outline-variant',
    pending:  'bg-amber-100 text-amber-700 border-amber-200',
    approved: 'bg-emerald-100 text-emerald-700 border-emerald-200',
    rejected: 'bg-red-100 text-red-700 border-red-200',
  }[s]
}
function typeLabel(t) {
  return { apartment: 'شقة', studio: 'استوديو', villa: 'فيلا' }[t] || t
}

async function load() {
  loading.value = true
  try {
    const params = { page: page.value }
    if (activeFilter.value !== 'all') params.approval_status = activeFilter.value
    if (search.value) params.search = search.value
    const { data } = await adminApi.listUnits(params)
    units.value = data.data ?? []
    meta.value = data.meta ?? { last_page: 1 }
  } catch (e) {
    showToast('تعذّر تحميل الوحدات', 'error')
  } finally {
    loading.value = false
  }
}

function reload() {
  page.value = 1
  load()
}
function changeFilter(key) {
  activeFilter.value = key
  page.value = 1
  load()
}
function goPage(p) {
  page.value = p
  load()
}

async function approve(unit) {
  busyId.value = unit.id
  try {
    await adminApi.approveRequest(unit.id)
    unit.approval_status = 'approved'
    showToast('تمت الموافقة على الوحدة')
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
  const unit = rejectTarget.value
  busyId.value = unit.id
  try {
    await adminApi.rejectRequest(unit.id, rejectReason.value.trim())
    unit.approval_status = 'rejected'
    showToast('تم رفض الوحدة')
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
