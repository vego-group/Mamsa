<template>
  <PartnerLayout>
    <div class="mb-6 flex items-end justify-between">
      <div>
        <h1 class="font-display-lg text-display-lg text-primary mb-1">وحداتي</h1>
        <p class="text-on-surface-variant text-body-md">إدارة وحداتك السكنية وتقديمها للموافقة</p>
      </div>
      <RouterLink
        :to="{ name: 'partner-unit-form' }"
        class="flex items-center gap-2 px-5 py-3 bg-primary text-on-primary rounded-xl font-bold shadow-sm hover:bg-primary-container transition-colors"
      >
        <span class="material-symbols-outlined text-[18px]">add</span>
        أضف وحدة
      </RouterLink>
    </div>

    <!-- Filter chips -->
    <div class="flex gap-2 overflow-x-auto pb-1 mb-6">
      <button
        v-for="f in filters"
        :key="f.key"
        class="whitespace-nowrap px-5 py-2 rounded-full font-title-sm text-[14px] transition-all"
        :class="activeFilter === f.key ? 'bg-primary text-on-primary shadow-sm' : 'bg-white border border-outline-variant text-on-surface-variant hover:bg-surface-container'"
        @click="activeFilter = f.key"
      >
        {{ f.label }}
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-20 text-on-surface-variant">
      <span class="material-symbols-outlined animate-spin text-3xl">progress_activity</span>
    </div>

    <!-- Empty -->
    <div v-else-if="filteredUnits.length === 0" class="text-center py-16">
      <span class="material-symbols-outlined text-5xl mb-3 block text-on-surface-variant">apartment</span>
      <p class="font-title-sm text-title-sm text-on-surface mb-1">لا توجد وحدات</p>
      <p class="text-body-sm text-on-surface-variant mb-5">ابدأ بإضافة أول وحدة سكنية لك</p>
      <RouterLink :to="{ name: 'partner-unit-form' }" class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary rounded-xl font-bold">
        <span class="material-symbols-outlined text-[18px]">add</span>
        أضف وحدة
      </RouterLink>
    </div>

    <!-- Units grid -->
    <div v-else class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
      <div v-for="unit in filteredUnits" :key="unit.id" class="bg-white rounded-xl border border-outline-variant shadow-sm hover:shadow-card transition-all">
        <div class="p-4">
          <div class="flex justify-between items-start mb-2 gap-2">
            <h3 class="font-title-sm text-title-sm text-on-surface truncate">{{ unit.name }}</h3>
            <span class="flex-shrink-0 px-2 py-0.5 rounded-full text-[10px] font-bold border" :class="statusBadge(unit.approval_status)">
              {{ statusLabel(unit.approval_status) }}
            </span>
          </div>
          <div class="flex items-center gap-1.5 text-on-surface-variant mb-1">
            <span class="material-symbols-outlined text-[14px]">qr_code_2</span>
            <span class="font-numeric-data text-body-sm">{{ unit.code || '—' }}</span>
          </div>
          <div class="flex items-center gap-1.5 text-on-surface-variant mb-1">
            <span class="material-symbols-outlined text-[14px]">location_on</span>
            <span class="text-body-sm">{{ unit.city || '—' }}{{ unit.district ? ` - ${unit.district}` : '' }}</span>
          </div>
          <div class="flex items-center justify-between mt-2">
            <span class="text-primary font-bold text-body-md">{{ formatMoney(unit.price) }} ر.س</span>
            <span class="text-body-sm text-on-surface-variant">{{ typeLabel(unit.type) }}</span>
          </div>

          <!-- Rejection reason -->
          <div v-if="unit.approval_status === 'rejected' && unit.rejection_reason" class="mt-3 p-2.5 bg-error-container rounded-lg">
            <p class="text-[12px] text-on-error-container"><strong>سبب الرفض:</strong> {{ unit.rejection_reason }}</p>
          </div>
        </div>

        <!-- Actions -->
        <div class="px-4 pb-4 pt-2 border-t border-outline-variant/50 flex gap-2">
          <RouterLink
            :to="{ name: 'partner-unit-edit', params: { id: unit.id } }"
            class="flex-1 py-2 rounded-lg border border-outline-variant text-primary font-title-sm text-sm text-center hover:bg-surface-container-low transition-colors flex items-center justify-center gap-1.5"
          >
            <span class="material-symbols-outlined text-[16px]">edit</span>
            تعديل
          </RouterLink>

          <!-- Submit for approval (draft/rejected only) -->
          <button
            v-if="['draft', 'rejected'].includes(unit.approval_status)"
            class="flex-1 py-2 rounded-lg bg-primary text-on-primary font-title-sm text-sm hover:bg-primary-container transition-colors flex items-center justify-center gap-1.5 disabled:opacity-50"
            :disabled="busyId === unit.id"
            @click="submitUnit(unit)"
          >
            <span class="material-symbols-outlined text-[16px]">send</span>
            تقديم
          </button>

          <button
            class="py-2 px-3 rounded-lg border border-outline-variant text-error hover:bg-error-container transition-colors disabled:opacity-50"
            :disabled="busyId === unit.id"
            @click="confirmDelete(unit)"
          >
            <span class="material-symbols-outlined text-[18px]">delete</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Toast -->
    <Transition name="fade">
      <div v-if="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 px-5 py-3 rounded-xl shadow-lg text-white font-bold text-body-sm" :class="toast.type === 'error' ? 'bg-error' : 'bg-primary'">
        {{ toast.msg }}
      </div>
    </Transition>

    <!-- Delete confirm -->
    <Teleport to="body">
      <div v-if="deleteTarget" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl border border-outline-variant shadow-xl w-full max-w-sm p-6 text-center" dir="rtl">
          <div class="w-16 h-16 bg-error-container rounded-full flex items-center justify-center mx-auto mb-4">
            <span class="material-symbols-outlined text-error text-3xl">delete_forever</span>
          </div>
          <h2 class="font-headline-md text-headline-md text-on-surface mb-2">تأكيد الحذف</h2>
          <p class="text-body-md text-on-surface-variant mb-6">حذف وحدة <strong>{{ deleteTarget.name }}</strong>؟ لا يمكن التراجع.</p>
          <div class="flex gap-3">
            <button class="flex-1 py-3 bg-error text-on-error rounded-xl font-bold hover:opacity-90 transition-opacity disabled:opacity-50" :disabled="busyId === deleteTarget.id" @click="deleteUnit">حذف</button>
            <button class="flex-1 py-3 border border-outline-variant rounded-xl font-bold text-on-surface hover:bg-surface-container transition-colors" @click="deleteTarget = null">إلغاء</button>
          </div>
        </div>
      </div>
    </Teleport>
  </PartnerLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import PartnerLayout from '@/layouts/PartnerLayout.vue'
import { partnerApi } from '@/api/partner'

const loading = ref(true)
const busyId = ref(null)
const units = ref([])
const activeFilter = ref('all')
const deleteTarget = ref(null)
const toast = ref(null)

const filters = [
  { key: 'all',      label: 'الكل' },
  { key: 'draft',    label: 'مسودة' },
  { key: 'pending',  label: 'قيد المراجعة' },
  { key: 'approved', label: 'معتمدة' },
  { key: 'rejected', label: 'مرفوضة' },
]

const filteredUnits = computed(() =>
  activeFilter.value === 'all'
    ? units.value
    : units.value.filter((u) => u.approval_status === activeFilter.value),
)

function showToast(msg, type = 'success') {
  toast.value = { msg, type }
  setTimeout(() => (toast.value = null), 2800)
}

function formatMoney(v) {
  return new Intl.NumberFormat('en-US').format(Number(v) || 0)
}

function statusLabel(s) {
  return { draft: 'مسودة', pending: 'قيد المراجعة', approved: 'معتمدة', rejected: 'مرفوضة' }[s] || s
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
    const { data } = await partnerApi.listUnits()
    units.value = data.data ?? data ?? []
  } catch (e) {
    showToast('تعذّر تحميل الوحدات', 'error')
  } finally {
    loading.value = false
  }
}

async function submitUnit(unit) {
  busyId.value = unit.id
  try {
    await partnerApi.submitUnit(unit.id)
    unit.approval_status = 'pending'
    showToast('تم تقديم الوحدة للموافقة')
  } catch (e) {
    showToast(e.response?.data?.message || 'تعذّر التقديم', 'error')
  } finally {
    busyId.value = null
  }
}

function confirmDelete(unit) {
  deleteTarget.value = unit
}

async function deleteUnit() {
  const unit = deleteTarget.value
  busyId.value = unit.id
  try {
    await partnerApi.deleteUnit(unit.id)
    units.value = units.value.filter((u) => u.id !== unit.id)
    showToast('تم حذف الوحدة')
    deleteTarget.value = null
  } catch (e) {
    showToast(e.response?.data?.message || 'تعذّر الحذف', 'error')
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
