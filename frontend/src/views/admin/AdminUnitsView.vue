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
          class="w-full pr-12 pl-4 py-2.5 bg-white border border-outline-variant rounded-xl text-body-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition-all"
          placeholder="ابحث بالاسم أو الكود..."
        />
      </div>
      <div class="flex gap-2 overflow-x-auto pb-1">
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
    </div>

    <!-- Units grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
      <div
        v-for="unit in filteredUnits"
        :key="unit.id"
        class="bg-white rounded-xl border border-outline-variant shadow-sm hover:shadow-card transition-all"
      >
        <div class="p-4 flex gap-4">
          <div class="w-24 h-24 rounded-lg bg-surface-container flex items-center justify-center flex-shrink-0 overflow-hidden">
            <span class="material-symbols-outlined text-3xl text-on-surface-variant">apartment</span>
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex justify-between items-start mb-1 gap-2">
              <h3 class="font-title-sm text-title-sm text-on-surface truncate">{{ unit.name }}</h3>
              <span class="flex-shrink-0 px-2 py-0.5 rounded-full text-[10px] font-bold border" :class="statusBadge(unit.status)">
                {{ unit.statusLabel }}
              </span>
            </div>
            <div class="flex items-center gap-1.5 text-on-surface-variant mb-1">
              <span class="material-symbols-outlined text-[14px]">qr_code_2</span>
              <span class="font-numeric-data text-body-sm">{{ unit.code }}</span>
            </div>
            <div class="flex items-center gap-1.5 text-on-surface-variant mb-1">
              <span class="material-symbols-outlined text-[14px]">location_on</span>
              <span class="text-body-sm">{{ unit.city }}</span>
            </div>
            <div class="text-primary font-bold text-body-md">{{ unit.price }} ر.س</div>
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
          <button v-if="unit.status === 'pending'" class="flex-1 py-2 rounded-lg bg-emerald-600 text-white font-title-sm text-sm hover:bg-emerald-700 transition-colors" @click="approve(unit)">
            موافقة
          </button>
          <button v-if="unit.status === 'pending'" class="py-2 px-3 rounded-lg border border-outline-variant text-error hover:bg-error-container transition-colors">
            <span class="material-symbols-outlined text-[18px]">close</span>
          </button>
          <button v-else class="py-2 px-3 rounded-lg border border-outline-variant text-on-surface-variant hover:bg-surface-container transition-colors">
            <span class="material-symbols-outlined text-[18px]">more_vert</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Empty state -->
    <div v-if="filteredUnits.length === 0" class="text-center py-16 text-on-surface-variant">
      <span class="material-symbols-outlined text-5xl mb-3 block">apartment</span>
      <p class="font-title-sm text-title-sm">لا توجد وحدات في هذه الفئة</p>
    </div>
  </AdminLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'

const search = ref('')
const activeFilter = ref('all')

const filters = [
  { key: 'all',      label: 'الكل' },
  { key: 'pending',  label: 'قيد الموافقة' },
  { key: 'approved', label: 'معتمدة' },
  { key: 'rejected', label: 'مرفوضة' },
  { key: 'draft',    label: 'مسودة' },
]

const units = ref([
  { id: 1, name: 'شقة مودرن هادئة في حي الملقا',  code: 'C7HKHYA4', city: 'الرياض',  price: '667.00',   status: 'pending',  statusLabel: 'قيد الموافقة' },
  { id: 2, name: 'شالية فاخر بالرياض',              code: 'A1B2C3D4', city: 'الرياض',  price: '2,400.00', status: 'approved', statusLabel: 'معتمدة' },
  { id: 3, name: 'استوديو بسرير ماستر - جدة',       code: 'E5F6G7H8', city: 'جدة',     price: '450.00',   status: 'pending',  statusLabel: 'قيد الموافقة' },
  { id: 4, name: 'شقة عائلية - مكة المكرمة',        code: 'I9J0K1L2', city: 'مكة',     price: '850.00',   status: 'approved', statusLabel: 'معتمدة' },
  { id: 5, name: 'فيلا الياسمين - النموذج أ',        code: 'M3N4O5P6', city: 'الرياض',  price: '4,500.00', status: 'rejected', statusLabel: 'مرفوضة' },
  { id: 6, name: 'غرفة ديلوكس - الدمام',            code: 'Q7R8S9T0', city: 'الدمام',  price: '320.00',   status: 'draft',    statusLabel: 'مسودة' },
])

const filteredUnits = computed(() => {
  return units.value.filter(u => {
    const matchSearch = !search.value || u.name.includes(search.value) || u.code.includes(search.value)
    const matchFilter = activeFilter.value === 'all' || u.status === activeFilter.value
    return matchSearch && matchFilter
  })
})

function statusBadge(status) {
  return {
    pending:  'bg-amber-100 text-amber-700 border-amber-200',
    approved: 'bg-emerald-100 text-emerald-700 border-emerald-200',
    rejected: 'bg-red-100 text-red-700 border-red-200',
    draft:    'bg-surface-container text-on-surface-variant border-outline-variant',
  }[status]
}

function approve(unit) {
  unit.status = 'approved'
  unit.statusLabel = 'معتمدة'
}
</script>
