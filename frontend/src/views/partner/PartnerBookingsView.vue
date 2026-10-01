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
            <th class="text-right py-3 px-4 font-label-caps text-label-caps text-on-surface-variant"></th>
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
            <td class="py-3 px-4 text-left">
              <button
                v-if="canCancel(b)"
                @click="openCancel(b)"
                class="px-3 py-1.5 rounded-full text-[13px] font-bold border border-red-200 text-red-700 hover:bg-red-50 transition-colors whitespace-nowrap"
              >إلغاء الحجز</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Host cancellation. Irreversible and the guest is refunded in full, so
         the consequence is stated before the reason is even typed. -->
    <div v-if="cancelTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="closeCancel">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
        <h2 class="font-title-lg text-title-lg text-on-surface mb-2">إلغاء الحجز #{{ cancelTarget.id }}</h2>
        <p class="text-body-sm text-on-surface-variant mb-4">
          سيتم استرداد <strong class="text-on-surface">{{ formatMoney(cancelTarget.total_amount) }} ر.س</strong>
          كاملة للضيف، ولن تحصل على أي مبلغ من هذا الحجز. لا يمكن التراجع عن هذا الإجراء.
        </p>

        <label class="block font-title-sm text-title-sm text-on-surface mb-1">سبب الإلغاء</label>
        <textarea
          v-model="cancelReason"
          rows="3"
          placeholder="مثال: الوحدة محجوزة في منصة أخرى"
          class="w-full rounded-xl border border-outline-variant p-3 text-body-sm focus:outline-none focus:ring-2 focus:ring-primary/40"
        ></textarea>

        <p v-if="cancelError" class="text-body-sm text-red-600 mt-2">{{ cancelError }}</p>

        <div class="flex gap-2 justify-end mt-5">
          <button @click="closeCancel" :disabled="cancelling"
            class="px-4 py-2 rounded-full font-title-sm text-title-sm text-on-surface-variant hover:bg-surface-container">تراجع</button>
          <button @click="confirmCancel" :disabled="cancelling || cancelReason.trim().length < 3"
            class="px-5 py-2 rounded-full font-title-sm text-title-sm bg-red-600 text-white hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed">
            {{ cancelling ? 'جارٍ الإلغاء…' : 'تأكيد الإلغاء' }}
          </button>
        </div>
      </div>
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

/* ---- host cancellation ---- */

const cancelTarget = ref(null)
const cancelReason = ref('')
const cancelError = ref('')
const cancelling = ref(false)

/**
 * Only a confirmed, future stay can be host-cancelled — the API refuses a
 * check-in that has already passed. Hiding the button matches that rather than
 * letting the partner discover it as an error.
 */
function canCancel(b) {
  if (b.status !== 'confirmed') return false
  const start = new Date(`${b.start_date}T15:00:00`)
  return start.getTime() > Date.now()
}

function openCancel(b) {
  cancelTarget.value = b
  cancelReason.value = ''
  cancelError.value = ''
}

function closeCancel() {
  if (cancelling.value) return
  cancelTarget.value = null
}

async function confirmCancel() {
  cancelling.value = true
  cancelError.value = ''
  try {
    const { data } = await partnerApi.cancelBooking(cancelTarget.value.id, cancelReason.value.trim())
    const updated = data.data ?? data
    const i = bookings.value.findIndex((x) => x.id === cancelTarget.value.id)
    if (i !== -1 && updated) bookings.value[i] = { ...bookings.value[i], ...updated }
    else if (i !== -1) bookings.value[i].status = 'cancelled'
    cancelTarget.value = null
  } catch (e) {
    // Three envelopes reach this screen: Laravel validation ({message, errors}),
    // the v1 wrapper ({message}) and the dashboard one ({error:{code,message}}).
    // Reading only the first two turned "the payment gateway refused the
    // refund" into a generic "try again", which sent someone hunting for a bug
    // that the API had already named precisely.
    const r = e?.response?.data
    cancelError.value =
      r?.error?.message ||
      r?.message ||
      r?.errors?.reason?.[0] ||
      'تعذّر إلغاء الحجز، حاول مرة أخرى'
  } finally {
    cancelling.value = false
  }
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
