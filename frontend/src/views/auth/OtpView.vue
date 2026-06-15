<template>
  <div class="min-h-screen bg-surface flex items-center justify-center p-4" dir="rtl">
    <div class="w-full max-w-md">
      <!-- Logo -->
      <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-16 h-16 bg-primary-container rounded-2xl mb-4">
          <span class="material-symbols-outlined text-on-primary-fixed text-3xl" style="font-variation-settings:'FILL' 1">apartment</span>
        </div>
        <h1 class="font-arabic text-display-lg text-primary">ممسى</h1>
      </div>

      <!-- Card -->
      <div class="bg-white rounded-2xl border border-outline-variant shadow-card p-8">
        <h2 class="font-arabic text-headline-md text-primary mb-2">رمز التحقق</h2>
        <p class="text-on-surface-variant text-body-sm mb-6">
          أُرسل رمز مكوّن من 6 أرقام إلى
          <span class="font-data font-bold text-on-surface" dir="ltr">{{ phone }}</span>
        </p>

        <!-- Dev-only OTP hint -->
        <div v-if="debugOtp" class="mb-4 flex items-center justify-between bg-amber-50 border border-amber-300 rounded-xl px-4 py-3">
          <span class="text-amber-700 text-body-sm font-arabic">رمز التطوير:</span>
          <button
            class="font-data font-bold text-lg tracking-widest text-amber-800 hover:text-primary transition-colors"
            @click="fillDebugOtp"
          >{{ debugOtp }}</button>
        </div>

        <!-- 6-box OTP input -->
        <div class="flex gap-2 justify-center mb-2" dir="ltr">
          <input
            v-for="(_, i) in 6"
            :key="i"
            :ref="(el) => (inputs[i] = el)"
            v-model="digits[i]"
            type="text"
            inputmode="numeric"
            maxlength="1"
            class="w-12 h-14 text-center text-xl font-data font-bold rounded-xl border-2 border-outline-variant
                   focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all
                   bg-surface-container-lowest"
            :class="{ 'border-error': error }"
            @keydown="onKeydown($event, i)"
            @input="onInput($event, i)"
            @paste.prevent="onPaste($event)"
          />
        </div>

        <p v-if="error" class="text-error text-body-sm text-center mb-4">{{ error }}</p>

        <!-- Countdown / Resend -->
        <div class="text-center mb-6 text-body-sm">
          <span v-if="countdown > 0" class="text-on-surface-variant">
            إعادة الإرسال بعد
            <span class="font-data font-bold text-primary">{{ countdown }}</span>
            ثانية
          </span>
          <button
            v-else
            class="text-primary font-bold hover:underline"
            :disabled="resending"
            @click="resend"
          >
            {{ resending ? 'جارٍ الإرسال...' : 'إعادة إرسال الرمز' }}
          </button>
        </div>

        <button
          class="btn-primary w-full"
          :disabled="digits.join('').length < 6 || loading"
          @click="submit"
        >
          <span v-if="loading" class="flex items-center justify-center gap-2">
            <span class="material-symbols-outlined animate-spin text-lg">progress_activity</span>
            جارٍ التحقق...
          </span>
          <span v-else>تحقق وادخل</span>
        </button>

        <!-- Back -->
        <button
          class="mt-4 w-full text-on-surface-variant text-body-sm hover:text-primary transition-colors"
          @click="$router.push({ name: 'login' })"
        >
          ← تغيير رقم الجوال
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { authApi } from '@/api/auth'

const route   = useRoute()
const router  = useRouter()
const auth    = useAuthStore()

const phone    = route.query.phone || ''
const debugOtp = route.query.debug_otp || null
const digits   = ref(['', '', '', '', '', ''])
const inputs  = ref([])
const error   = ref('')
const loading = ref(false)
const resending = ref(false)
const countdown = ref(60)
let timer = null

onMounted(() => {
  if (!phone) { router.replace({ name: 'login' }); return }
  inputs.value[0]?.focus()
  startCountdown()
})

onUnmounted(() => clearInterval(timer))

function fillDebugOtp() {
  if (!debugOtp) return
  debugOtp.split('').forEach((ch, i) => { digits.value[i] = ch })
  inputs.value[5]?.focus()
}

function startCountdown() {
  countdown.value = 60
  clearInterval(timer)
  timer = setInterval(() => {
    if (countdown.value > 0) countdown.value--
    else clearInterval(timer)
  }, 1000)
}

function onInput(e, i) {
  const val = e.target.value.replace(/\D/g, '')
  digits.value[i] = val.slice(-1)
  if (val && i < 5) inputs.value[i + 1]?.focus()
}

function onKeydown(e, i) {
  if (e.key === 'Backspace' && !digits.value[i] && i > 0) {
    inputs.value[i - 1]?.focus()
  }
}

function onPaste(e) {
  const text = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '')
  if (!text) return
  text.slice(0, 6).split('').forEach((ch, i) => { digits.value[i] = ch })
  inputs.value[Math.min(text.length, 5)]?.focus()
}

async function submit() {
  error.value = ''
  loading.value = true
  try {
    await auth.verify(phone, digits.value.join(''))
    if (auth.needsProfile) {
      router.replace({ name: 'complete-profile' })
    } else {
      router.replace({ name: 'dashboard' })
    }
  } catch (err) {
    error.value = err.response?.data?.errors?.code?.[0]
      || err.response?.data?.message
      || 'رمز غير صحيح أو منتهي الصلاحية'
    digits.value = ['', '', '', '', '', '']
    inputs.value[0]?.focus()
  } finally {
    loading.value = false
  }
}

async function resend() {
  resending.value = true
  error.value = ''
  try {
    const res = await authApi.resendOtp(phone)
    if (res.data?.data?.debug_otp) {
      router.replace({ name: 'otp', query: { phone, debug_otp: res.data.data.debug_otp } })
    }
    digits.value = ['', '', '', '', '', '']
    inputs.value[0]?.focus()
    startCountdown()
  } catch (err) {
    error.value = err.response?.data?.message || 'فشل إعادة الإرسال'
  } finally {
    resending.value = false
  }
}
</script>
