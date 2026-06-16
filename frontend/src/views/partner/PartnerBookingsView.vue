<template>
  <PartnerLayout>
    <div class="mb-6">
      <h1 class="font-display-lg text-display-lg text-primary mb-1">الحجوزات</h1>
      <p class="text-on-surface-variant text-body-md">جميع الحجوزات على وحداتك</p>
    </div>

    <!-- Filter -->
    <div class="flex gap-2 overflow-x-auto pb-1 mb-6">
      <button
        v-for="t in tabs"
        :key="t.key"
        class="whitespace-nowrap px-5 py-2 rounded-full font-title-sm text-[14px] transition-all"
        :class="activeTab === t.key ? 'bg-primary text-on-primary shadow-sm' : 'bg-white border border-outline-variant text-on-surface-variant hover:bg-surface-container'"
        @click="activeTab = t.key"
      >
        {{ t.label }}
      </button>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-20 text-on-surface-variant">
      <span class="material-symbols-outlined animate-spin text-3xl">progress_activity</span>
    </div>

    <div v-else-if="filteredBookings.length === 0" class="text-center py-16">
      <span class="material-symbols-outlined text-5xl mb-3 block text-on-surface-variant">calendar_today</span>
      <p class="font-title-sm text-title-sm text-on-surface">لا توجد حجوزات بعد</p>
    </div>

    <div v-else class="bg-white rounded-2xl border border-outline-variant shadow-sm overflow-x-auto">
      <table class="w-full min-w-[640px]">
        <thead>
          <tr class="bg-surface-container-low border-b border-outline-variant">
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">#</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">الوحدة</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">التواريخ</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">الضيوف</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">المبلغ</th>
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant">الحالة</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="b in filteredBookings" :key="b.id" class="border-b border-outline-variant/50 last:border-0 hover:bg-surface-container-low/50 transition-colors">
            <td class="py-3 px-4 font-numeric-data text-body-sm text-primary font-bold">#{{ b.id }}</td>
            <td class="py-3 px-4 text-body-sm text-on-surface">{{ b.unit?.name || '—' }}</td>
            <td class="py-3 px-4">
              <p class="font-numeric-data text-body-sm text-on-surface" dir="ltr">{{ b.start_date }}</p>
              <p class="font-numeric-data text-body-sm text-on-surface-variant" dir="ltr">{{ b.end_date }}</p>
            </td>
            <td class="py-3 px-4 font-numeric-data text-body-sm text-on-surface">{{ b.guests }}</td>
            <td class="py-3 px-4 font-numeric-data text-body-sm font-bold text-on-surface">{{ formatMoney(b.total_amount) }} ر.س</td>
            <td class="py-3 px-4">
              <span class="px-2.5 py-1 rounded-full text-[12px] font-bold" :class="statusClass(b.status)">{{ b.status_label }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </PartnerLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import PartnerLayout from '@/layouts/PartnerLayout.vue'
import { partnerApi } from '@/api/partner'

const loading = ref(true)
const bookings = ref([])
const activeTab = ref('all')

const tabs = [
  { key: 'all',       label: 'الكل' },
  { key: 'confirmed', label: 'مؤكد' },
  { key: 'pending',   label: 'قيد الانتظار' },
  { key: 'cancelled', label: 'ملغى' },
]

const filteredBookings = computed(() =>
  activeTab.value === 'all' ? bookings.value : bookings.value.filter((b) => b.status === activeTab.value),
)

function formatMoney(v) {
  return new Intl.NumberFormat('en-US').format(Number(v) || 0)
}
function statusClass(s) {
  return {
    confirmed: 'bg-emerald-100 text-emerald-700',
    pending:   'bg-amber-100 text-amber-700',
    cancelled: 'bg-red-100 text-red-700',
  }[s] || 'bg-surface-container text-on-surface-variant'
}

onMounted(async () => {
  try {
    const { data } = await partnerApi.listBookings()
    bookings.value = data.data ?? data ?? []
  } catch (e) {
    // keep empty
  } finally {
    loading.value = false
  }
})
</script>
