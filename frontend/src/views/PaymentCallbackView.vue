<template>
  <div class="min-h-screen bg-[#F7F7F4]" dir="rtl">
    <PublicHeader />

    <div class="max-w-md mx-auto px-4 py-20 text-center">
      <!-- Verifying -->
      <template v-if="state === 'verifying'">
        <span class="material-symbols-outlined animate-spin text-4xl text-primary mb-4 block">progress_activity</span>
        <h1 class="font-title-sm text-title-sm text-on-surface">جارٍ التحقق من الدفع...</h1>
      </template>

      <!-- Success -->
      <template v-else-if="state === 'paid'">
        <div class="w-20 h-20 bg-emerald-100 rounded-full flex items-center justify-center mx-auto mb-5">
          <span class="material-symbols-outlined text-emerald-600 text-4xl" style="font-variation-settings:'FILL' 1">check_circle</span>
        </div>
        <h1 class="font-display-lg text-[28px] text-primary mb-2">تم تأكيد حجزك!</h1>
        <p class="text-on-surface-variant text-body-md mb-6">تم الدفع بنجاح وتأكيد حجزك.</p>
        <RouterLink :to="{ name: 'account' }" class="inline-flex items-center justify-center gap-2 w-full py-3 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors">
          عرض حجوزاتي
        </RouterLink>
      </template>

      <!-- Failed -->
      <template v-else>
        <div class="w-20 h-20 bg-error-container rounded-full flex items-center justify-center mx-auto mb-5">
          <span class="material-symbols-outlined text-error text-4xl">error</span>
        </div>
        <h1 class="font-display-lg text-[28px] text-error mb-2">لم يكتمل الدفع</h1>
        <p class="text-on-surface-variant text-body-md mb-6">{{ message || 'تعذّر إتمام عملية الدفع. لم يتم خصم أي مبلغ.' }}</p>
        <RouterLink v-if="bookingId" :to="{ name: 'payment', params: { id: bookingId } }" class="inline-flex items-center justify-center gap-2 w-full py-3 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors mb-3">
          إعادة المحاولة
        </RouterLink>
        <RouterLink :to="{ name: 'account' }" class="text-primary font-bold hover:underline">العودة لحجوزاتي</RouterLink>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import PublicHeader from '@/components/public/PublicHeader.vue'
import { paymentApi } from '@/api/public'

const route = useRoute()
const state = ref('verifying') // verifying | paid | failed
const message = ref('')
const bookingId = ref(null)

onMounted(async () => {
  // Moyasar appends: id (payment id), status, message, plus our own pid
  const moyasarId = route.query.id
  const pid = route.query.pid
  const moyasarStatus = route.query.status

  if (!moyasarId || !pid) {
    state.value = 'failed'
    message.value = 'بيانات الدفع غير مكتملة'
    return
  }

  // If Moyasar already reports a non-paid status, surface it without a round-trip.
  if (moyasarStatus && moyasarStatus !== 'paid') {
    state.value = 'failed'
    message.value = route.query.message || ''
  }

  try {
    // Always re-verify server-side (never trust client-reported status).
    const { data } = await paymentApi.verify(Number(pid), String(moyasarId))
    const result = data.data ?? data
    bookingId.value = result.booking_id ?? null
    if (result.status === 'paid') {
      state.value = 'paid'
    } else {
      state.value = 'failed'
      message.value = result.message || ''
    }
  } catch (e) {
    state.value = 'failed'
    message.value = e.response?.data?.message || ''
  }
})
</script>
