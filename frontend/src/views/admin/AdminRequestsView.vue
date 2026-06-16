<template>
  <AdminLayout>
    <div class="mb-8">
      <h1 class="font-display-lg text-display-lg text-primary mb-1">الطلبات</h1>
      <p class="text-on-surface-variant text-body-md">مراجعة وإدارة طلبات الانضمام للمنصة كشريك</p>
    </div>

    <!-- Summary cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
      <div v-for="stat in stats" :key="stat.label" class="p-4 bg-white rounded-2xl border border-outline-variant shadow-sm relative overflow-hidden">
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
        :class="activeTab === tab.key
          ? 'text-primary border-primary'
          : 'text-on-surface-variant border-transparent hover:text-primary'"
        @click="activeTab = tab.key"
      >
        {{ tab.label }}
      </button>
    </div>

    <!-- Search -->
    <div class="relative mb-6">
      <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-on-surface-variant">search</span>
      <input
        v-model="search"
        class="w-full sm:w-96 pr-12 pl-4 py-2.5 bg-white border border-outline-variant rounded-full text-body-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition-all"
        placeholder="البحث في الطلبات والوحدات..."
      />
    </div>

    <!-- Requests list -->
    <div class="space-y-3">
      <div
        v-for="req in filteredRequests"
        :key="req.id"
        class="bg-white rounded-xl border border-outline-variant shadow-sm hover:shadow-card transition-all"
      >
        <div class="p-4 flex items-center gap-4">
          <!-- Avatar -->
          <div class="w-12 h-12 rounded-xl bg-surface-container flex items-center justify-center flex-shrink-0">
            <span class="material-symbols-outlined text-primary">{{ req.type === 'Company' ? 'business' : 'person' }}</span>
          </div>

          <!-- Info -->
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-0.5 flex-wrap">
              <h3 class="font-title-sm text-title-sm text-on-surface">{{ req.name }}</h3>
              <span class="px-2 py-0.5 rounded-full text-[10px] font-bold border" :class="typeBadge(req.type)">
                {{ req.type === 'Company' ? 'شركة' : 'فرد' }}
              </span>
            </div>
            <p class="text-body-sm text-on-surface-variant truncate">{{ req.unitName }}</p>
            <div class="flex items-center gap-3 mt-1">
              <span class="font-numeric-data text-[11px] text-on-surface-variant" dir="ltr">{{ req.date }}</span>
              <span class="font-numeric-data text-[11px] text-primary font-bold">{{ req.code }}</span>
            </div>
          </div>

          <!-- Status + action -->
          <div class="flex items-center gap-3 flex-shrink-0">
            <span class="hidden sm:inline px-3 py-1 rounded-full text-[12px] font-bold" :class="statusClass(req.status)">
              {{ req.statusLabel }}
            </span>
            <RouterLink
              :to="{ name: 'admin-request-detail', params: { id: req.id } }"
              class="w-9 h-9 flex items-center justify-center rounded-lg border border-outline-variant text-on-surface-variant hover:text-primary hover:bg-surface-container transition-colors"
            >
              <span class="material-symbols-outlined text-[18px]">chevron_left</span>
            </RouterLink>
          </div>
        </div>

        <!-- Quick actions for pending -->
        <div v-if="req.status === 'pending'" class="px-4 pb-4 flex gap-2">
          <button class="flex-1 py-2 rounded-lg bg-emerald-600 text-white font-bold text-sm hover:bg-emerald-700 transition-colors flex items-center justify-center gap-1.5" @click="approve(req)">
            <span class="material-symbols-outlined text-[16px]">check_circle</span>
            موافقة
          </button>
          <button class="flex-1 py-2 rounded-lg border border-error text-error font-bold text-sm hover:bg-error-container transition-colors flex items-center justify-center gap-1.5" @click="reject(req)">
            <span class="material-symbols-outlined text-[16px]">cancel</span>
            رفض
          </button>
        </div>
      </div>
    </div>

    <!-- Empty state -->
    <div v-if="filteredRequests.length === 0" class="text-center py-16 text-on-surface-variant">
      <span class="material-symbols-outlined text-5xl mb-3 block">assignment</span>
      <p class="font-title-sm text-title-sm">لا توجد طلبات في هذه الفئة</p>
    </div>
  </AdminLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'

const search = ref('')
const activeTab = ref('all')

const tabs = [
  { key: 'all',      label: 'الكل' },
  { key: 'pending',  label: 'معلقة' },
  { key: 'approved', label: 'مقبولة' },
  { key: 'rejected', label: 'مرفوضة' },
]

const stats = [
  { label: 'إجمالي الطلبات', value: '48', accent: 'bg-primary',    textColor: 'text-primary' },
  { label: 'معلقة',          value: '12', accent: 'bg-amber-500',  textColor: 'text-amber-600' },
  { label: 'مقبولة',         value: '30', accent: 'bg-emerald-500',textColor: 'text-emerald-600' },
  { label: 'مرفوضة',         value: '6',  accent: 'bg-error',      textColor: 'text-error' },
]

const requests = ref([
  { id: 1, name: 'محمد الفهد',    type: 'Individual', unitName: 'فيلا مودرن - الرياض',        code: 'C7HKHYA4', date: '2026-06-15', status: 'pending',  statusLabel: 'معلق' },
  { id: 2, name: 'شركة الأفق',    type: 'Company',    unitName: 'مجمع شقق - جدة',              code: 'A1B2C3D4', date: '2026-06-14', status: 'pending',  statusLabel: 'معلق' },
  { id: 3, name: 'خالد الشمري',   type: 'Individual', unitName: 'شالية فاخر - الدمام',         code: 'E5F6G7H8', date: '2026-06-12', status: 'approved', statusLabel: 'مقبول' },
  { id: 4, name: 'مجموعة الخليج', type: 'Company',    unitName: 'فندق صغير - مكة',             code: 'I9J0K1L2', date: '2026-06-10', status: 'rejected', statusLabel: 'مرفوض' },
  { id: 5, name: 'نورة القحطاني', type: 'Individual', unitName: 'شقة عائلية - الطائف',         code: 'M3N4O5P6', date: '2026-06-08', status: 'pending',  statusLabel: 'معلق' },
  { id: 6, name: 'هند العتيبي',   type: 'Individual', unitName: 'استوديو مريح - الرياض',       code: 'Q7R8S9T0', date: '2026-06-05', status: 'approved', statusLabel: 'مقبول' },
])

const filteredRequests = computed(() => {
  return requests.value.filter(r => {
    const matchSearch = !search.value || r.name.includes(search.value) || r.unitName.includes(search.value) || r.code.includes(search.value)
    const matchTab = activeTab.value === 'all' || r.status === activeTab.value
    return matchSearch && matchTab
  })
})

function typeBadge(type) {
  return type === 'Company'
    ? 'bg-blue-100 text-blue-700 border-blue-200'
    : 'bg-secondary-container text-on-secondary-container border-secondary-fixed-dim'
}

function statusClass(status) {
  return {
    pending:  'bg-amber-100 text-amber-700',
    approved: 'bg-emerald-100 text-emerald-700',
    rejected: 'bg-red-100 text-red-700',
  }[status]
}

function approve(req) {
  req.status = 'approved'
  req.statusLabel = 'مقبول'
}

function reject(req) {
  req.status = 'rejected'
  req.statusLabel = 'مرفوض'
}
</script>
