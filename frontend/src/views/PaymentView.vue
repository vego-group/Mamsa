<template>
  <div class="min-h-screen bg-[#F7F7F4]" dir="rtl">
    <PublicHeader />

    <div v-if="loading" class="flex items-center justify-center py-32 text-on-surface-variant">
      <span class="material-symbols-outlined animate-spin text-3xl">progress_activity</span>
    </div>

    <!-- Success (test-mode simulate path) -->
    <div v-else-if="paid" class="max-w-md mx-auto px-4 py-16 text-center">
      <div class="w-20 h-20 bg-emerald-100 rounded-full flex items-center justify-center mx-auto mb-5">
        <span class="material-symbols-outlined text-emerald-600 text-4xl" style="font-variation-settings:'FILL' 1">check_circle</span>
      </div>
      <h1 class="font-display-lg text-[28px] text-primary mb-2">تم تأكيد حجزك!</h1>
      <p class="text-on-surface-variant text-body-md mb-6">تم الدفع بنجاح وتأكيد حجزك.</p>
      <RouterLink :to="{ name: 'account' }" class="inline-flex items-center justify-center gap-2 w-full py-3 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors">
        عرض حجوزاتي
      </RouterLink>
    </div>

    <!-- Payment -->
    <div v-else-if="info" class="max-w-3xl mx-auto px-4 py-8">
      <nav class="flex items-center gap-2 text-body-sm text-on-surface-variant mb-6">
        <RouterLink :to="{ name: 'home' }" class="hover:text-primary">الرئيسية</RouterLink>
        <span class="material-symbols-outlined text-[16px]">chevron_left</span>
        <span class="text-primary font-bold">إتمام الدفع</span>
      </nav>

      <h1 class="font-display-lg text-display-lg text-primary mb-6">إتمام الدفع</h1>

      <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
        <div class="lg:col-span-3 space-y-5">
          <!-- TEST MODE: simulate -->
          <template v-if="info.test_mode">
            <div class="flex items-start gap-3 bg-amber-50 border border-amber-300 rounded-xl px-4 py-3">
              <span class="material-symbols-outlined text-amber-600">science</span>
              <div>
                <p class="font-bold text-amber-800 text-body-sm">وضع التجربة (محاكاة)</p>
                <p class="text-amber-700 text-body-sm">لم تُضف مفاتيح Moyasar. سيتم محاكاة دفع ناجح.</p>
              </div>
            </div>
            <div class="bg-white rounded-2xl border border-outline-variant p-6">
              <button
                class="w-full py-3 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors flex items-center justify-center gap-2 disabled:opacity-50"
                :disabled="paying"
                @click="simulatePay"
              >
                <span v-if="paying" class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span>
                <span v-else class="material-symbols-outlined text-[18px]">lock</span>
                محاكاة الدفع
              </button>
              <p v-if="errorMsg" class="text-error text-body-sm text-center mt-3">{{ errorMsg }}</p>
            </div>
          </template>

          <!-- LIVE MODE: Moyasar hosted form -->
          <template v-else>
            <div class="bg-white rounded-2xl border border-outline-variant p-6">
              <h2 class="font-title-sm text-title-sm text-primary mb-4">بيانات الدفع</h2>
              <!-- Moyasar renders its secure form inside this element -->
              <div class="mysr-form"></div>
              <p v-if="formError" class="text-error text-body-sm text-center mt-3">{{ formError }}</p>
            </div>
            <p class="text-center text-body-sm text-on-surface-variant">
              بطاقة تجريبية: <span class="font-data" dir="ltr">4111 1111 1111 1111</span> — أي تاريخ مستقبلي و CVC
            </p>
            <p class="text-center text-[12px] text-on-surface-variant flex items-center justify-center gap-1">
              <span class="material-symbols-outlined text-[14px]">info</span>
              يظهر Apple Pay تلقائياً على متصفح Safari وأجهزة Apple
            </p>
          </template>
        </div>

        <!-- Summary -->
        <div class="lg:col-span-2">
          <div class="bg-white rounded-2xl border border-outline-variant p-6 sticky top-20">
            <h2 class="font-title-sm text-title-sm text-primary mb-4">ملخص الطلب</h2>
            <p class="text-body-md font-semibold text-on-surface pb-4 border-b border-outline-variant">{{ info.description }}</p>
            <div class="flex justify-between items-center py-4">
              <span class="font-bold text-on-surface">الإجمالي</span>
              <span class="font-numeric-data text-[24px] text-primary font-bold">{{ formatMoney(info.amount) }} <span class="text-body-sm">ر.س</span></span>
            </div>
            <div class="flex items-center justify-center gap-1.5 text-on-surface-variant">
              <span class="material-symbols-outlined text-[16px]">verified_user</span>
              <span class="text-[12px]">دفع آمن عبر Moyasar</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Error -->
    <div v-else class="text-center py-32 text-on-surface-variant">
      <span class="material-symbols-outlined text-5xl mb-3 block">error_outline</span>
      <p class="font-title-sm text-title-sm mb-4">تعذّر تحميل بيانات الدفع</p>
      <RouterLink :to="{ name: 'home' }" class="text-primary font-bold underline">العودة للرئيسية</RouterLink>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import PublicHeader from '@/components/public/PublicHeader.vue'
import { paymentApi } from '@/api/public'

const MOYASAR_VERSION = '1.14.0'

const route = useRoute()
const bookingId = route.params.id

const loading = ref(true)
const paying = ref(false)
const paid = ref(false)
const info = ref(null)
const errorMsg = ref('')
const formError = ref('')

function formatMoney(v) {
  return new Intl.NumberFormat('en-US').format(Number(v) || 0)
}

// ── Dynamic Moyasar.js loader ─────────────────────────────────────
function loadMoyasarAssets() {
  return new Promise((resolve, reject) => {
    if (window.Moyasar) return resolve()

    if (!document.getElementById('moyasar-css')) {
      const link = document.createElement('link')
      link.id = 'moyasar-css'
      link.rel = 'stylesheet'
      link.href = `https://cdn.moyasar.com/mpf/${MOYASAR_VERSION}/moyasar.css`
      document.head.appendChild(link)
    }

    const script = document.createElement('script')
    script.src = `https://cdn.moyasar.com/mpf/${MOYASAR_VERSION}/moyasar.js`
    script.onload = () => resolve()
    script.onerror = () => reject(new Error('فشل تحميل بوابة الدفع'))
    document.body.appendChild(script)
  })
}

function initMoyasarForm() {
  // On completion Moyasar redirects to callback_url with the payment id; our
  // callback page then verifies server-side. pid links back to our Payment row.
  const callbackUrl = `${window.location.origin}/payment/callback?pid=${info.value.payment_id}`

  window.Moyasar.init({
    element: '.mysr-form',
    amount: info.value.amount_halalas,
    currency: info.value.currency,
    description: info.value.description,
    publishable_api_key: info.value.publishable_key,
    callback_url: callbackUrl,
    // Apple Pay only renders on Safari/Apple devices over HTTPS with a
    // domain verified in the Moyasar dashboard. Moyasar handles merchant
    // validation internally for the hosted form.
    methods: ['creditcard', 'applepay'],
    apple_pay: {
      country: 'SA',
      label: 'Mamsa',           // shown on the Apple Pay sheet
      validate_merchant_url: 'https://api.moyasar.com/v1/applepay/initiate',
    },
    metadata: {
      payment_id: info.value.payment_id,
      booking_id: info.value.booking_id,
    },
  })
}

// ── Test-mode simulate path ───────────────────────────────────────
async function simulatePay() {
  errorMsg.value = ''
  paying.value = true
  try {
    const { data } = await paymentApi.pay({ payment_id: info.value.payment_id })
    if ((data.data ?? data).status === 'paid') paid.value = true
    else errorMsg.value = 'تعذّر إتمام الدفع'
  } catch (e) {
    errorMsg.value = e.response?.data?.message || 'تعذّر إتمام الدفع'
  } finally {
    paying.value = false
  }
}

onMounted(async () => {
  try {
    const { data } = await paymentApi.initiate(Number(bookingId))
    info.value = data.data ?? data
  } catch (e) {
    info.value = null
    loading.value = false
    return
  }
  loading.value = false

  // Live mode → mount the secure Moyasar form
  if (info.value && !info.value.test_mode) {
    try {
      await loadMoyasarAssets()
      // Wait a tick so the .mysr-form element is in the DOM
      setTimeout(initMoyasarForm, 0)
    } catch (e) {
      formError.value = e.message || 'تعذّر تحميل بوابة الدفع'
    }
  }
})
</script>
