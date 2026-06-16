<template>
  <AdminLayout>
    <div class="mb-8 flex items-end justify-between">
      <div>
        <h1 class="font-display-lg text-display-lg text-primary mb-1">جميع الحجوزات</h1>
        <p class="text-on-surface-variant text-body-md">إدارة ومتابعة كافة العمليات العقارية في المنصة</p>
      </div>
      <button class="flex items-center gap-2 px-5 py-3 bg-primary text-on-primary rounded-xl font-bold shadow-sm hover:bg-primary-container transition-colors">
        <span class="material-symbols-outlined text-[18px]">download</span>
        تصدير
      </button>
    </div>

    <!-- Summary cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
      <div v-for="stat in stats" :key="stat.label" class="p-4 bg-white rounded-2xl border border-outline-variant shadow-sm">
        <p class="font-label-caps text-label-caps text-on-surface-variant mb-1">{{ stat.label }}</p>
        <p class="font-numeric-data text-2xl font-bold text-on-surface">{{ stat.value }}</p>
      </div>
    </div>

    <!-- Filter tabs -->
    <div class="flex gap-2 overflow-x-auto pb-1 mb-6">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        class="whitespace-nowrap px-5 py-2 rounded-full font-title-sm text-[14px] transition-all"
        :class="activeTab === tab.key ? 'bg-primary text-on-primary shadow-sm' : 'bg-white border border-outline-variant text-on-surface-variant hover:bg-surface-container'"
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
        placeholder="البحث في الحجوزات..."
      />
    </div>

    <!-- Bookings table -->
    <div class="bg-white rounded-2xl border border-outline-variant shadow-sm overflow-x-auto">
      <table class="w-full min-w-[700px]">
        <thead>
          <tr class="bg-surface-container-low border-b border-outline-variant">
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">رقم الحجز</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">المستخدم</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">الوحدة</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">تاريخ الحجز</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">المبلغ</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">الحالة</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="booking in filteredBookings"
            :key="booking.id"
            class="border-b border-outline-variant/50 last:border-0 hover:bg-surface-container-low/50 transition-colors"
          >
            <td class="py-3 px-4">
              <span class="font-numeric-data text-body-sm text-primary font-bold">#{{ booking.id }}</span>
            </td>
            <td class="py-3 px-4">
              <p class="font-body-md font-semibold text-on-surface">{{ booking.user }}</p>
              <p class="text-body-sm text-on-surface-variant" dir="ltr">{{ booking.phone }}</p>
            </td>
            <td class="py-3 px-4">
              <p class="text-body-sm text-on-surface">{{ booking.unit }}</p>
              <p class="text-body-sm text-on-surface-variant">{{ booking.city }}</p>
            </td>
            <td class="py-3 px-4">
              <p class="font-numeric-data text-body-sm text-on-surface" dir="ltr">{{ booking.startDate }}</p>
              <p class="font-numeric-data text-body-sm text-on-surface-variant" dir="ltr">{{ booking.endDate }}</p>
            </td>
            <td class="py-3 px-4">
              <span class="font-numeric-data text-body-sm font-bold text-on-surface">{{ booking.amount }} ر.س</span>
            </td>
            <td class="py-3 px-4">
              <span class="px-2.5 py-1 rounded-full text-[12px] font-bold" :class="statusClass(booking.status)">
                {{ booking.statusLabel }}
              </span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Empty state -->
    <div v-if="filteredBookings.length === 0" class="text-center py-16 text-on-surface-variant">
      <span class="material-symbols-outlined text-5xl mb-3 block">calendar_today</span>
      <p class="font-title-sm text-title-sm">لا توجد حجوزات</p>
    </div>
  </AdminLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'

const search = ref('')
const activeTab = ref('all')

const tabs = [
  { key: 'all',       label: 'الكل' },
  { key: 'confirmed', label: 'مؤكد' },
  { key: 'pending',   label: 'قيد المعالجة' },
  { key: 'cancelled', label: 'ملغي' },
  { key: 'completed', label: 'مكتمل' },
]

const stats = [
  { label: 'إجمالي الحجوزات', value: '248' },
  { label: 'مؤكدة',           value: '156' },
  { label: 'قيد المعالجة',    value: '42' },
  { label: 'إيرادات المنصة',  value: '128,400 ر.س' },
]

const bookings = ref([
  { id: 1024, user: 'محمد الفهد',    phone: '+966501234567', unit: 'شقة مودرن هادئة',   city: 'الرياض', startDate: '2026-06-15', endDate: '2026-06-20', amount: '3,335.00', status: 'confirmed', statusLabel: 'مؤكد' },
  { id: 1023, user: 'سارة العتيبي',  phone: '+966509876543', unit: 'شالية فاخر',         city: 'الرياض', startDate: '2026-06-10', endDate: '2026-06-14', amount: '9,600.00', status: 'completed', statusLabel: 'مكتمل' },
  { id: 1022, user: 'خالد الشمري',   phone: '+966551234567', unit: 'غرفة ديلوكس',        city: 'الدمام', startDate: '2026-06-18', endDate: '2026-06-19', amount: '320.00',   status: 'pending',   statusLabel: 'قيد المعالجة' },
  { id: 1021, user: 'نورة القحطاني', phone: '+966561234567', unit: 'شقة عائلية',          city: 'مكة',    startDate: '2026-06-05', endDate: '2026-06-08', amount: '2,550.00', status: 'cancelled', statusLabel: 'ملغي' },
  { id: 1020, user: 'هند العتيبي',   phone: '+966581234567', unit: 'استوديو بسرير ماستر', city: 'جدة',    startDate: '2026-06-22', endDate: '2026-06-25', amount: '1,350.00', status: 'confirmed', statusLabel: 'مؤكد' },
])

const filteredBookings = computed(() => {
  return bookings.value.filter(b => {
    const matchSearch = !search.value || b.user.includes(search.value) || b.unit.includes(search.value) || String(b.id).includes(search.value)
    const matchTab = activeTab.value === 'all' || b.status === activeTab.value
    return matchSearch && matchTab
  })
})

function statusClass(status) {
  return {
    confirmed: 'bg-emerald-100 text-emerald-700',
    pending:   'bg-amber-100 text-amber-700',
    cancelled: 'bg-red-100 text-red-700',
    completed: 'bg-blue-100 text-blue-700',
  }[status]
}
</script>
