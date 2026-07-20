<template>
  <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4" @click.self="close">
    <div class="bg-white rounded-2xl w-full max-w-md p-6" dir="rtl">
      <div class="flex items-center justify-between mb-4">
        <h3 class="font-title-sm text-title-sm text-on-surface">توثيق البريد الإلكتروني</h3>
        <button class="text-on-surface-variant hover:text-on-surface" @click="close">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>

      <!-- Step 1: email input -->
      <template v-if="step === 'email'">
        <p class="text-body-sm text-on-surface-variant mb-4">سنرسل تأكيد الحجز والتذكيرات على بريدك.</p>
        <label class="block text-[12px] font-bold text-on-surface-variant mb-1.5">البريد الإلكتروني</label>
        <input
          v-model.trim="email" type="email" dir="ltr" placeholder="example@email.com"
          class="w-full px-3.5 py-2.5 bg-surface-container-low border border-outline-variant rounded-xl text-body-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition-all mb-2"
          @keyup.enter="sendCode"
        />
        <p v-if="error" class="text-body-sm text-red-600 mb-3">{{ error }}</p>
        <button
          class="w-full py-3 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors disabled:opacity-50 flex items-center justify-center gap-2"
          :disabled="busy || !email" @click="sendCode"
        >
          <span v-if="busy" class="material-symbols-outlined animate-spin text-[20px]">progress_activity</span>
          أرسل رمز التحقق
        </button>
      </template>

      <!-- Step 2: OTP input -->
      <template v-else>
        <p class="text-body-sm text-on-surface-variant mb-4">
          أدخل الرمز المكوّن من 6 أرقام المرسل إلى
          <span class="font-bold text-on-surface" dir="ltr">{{ email }}</span>
        </p>
        <input
          v-model="code" inputmode="numeric" maxlength="6" dir="ltr" placeholder="••••••"
          class="w-full px-3.5 py-3 bg-surface-container-low border border-outline-variant rounded-xl text-center tracking-[8px] text-[20px] font-numeric-data focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition-all mb-2"
          :disabled="codeDead"
          @input="code = code.replace(/\D/g, '')"
          @keyup.enter="submitCode"
        />
        <p v-if="error" class="text-body-sm text-red-600 mb-3">{{ error }}</p>
        <button
          class="w-full py-3 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors disabled:opacity-50 flex items-center justify-center gap-2 mb-3"
          :disabled="busy || codeDead || code.length !== 6" @click="submitCode"
        >
          <span v-if="busy" class="material-symbols-outlined animate-spin text-[20px]">progress_activity</span>
          تحقق
        </button>
        <div class="flex items-center justify-between text-body-sm">
          <button class="text-on-surface-variant hover:text-primary" @click="step = 'email'; error = ''">تغيير البريد</button>
          <button
            class="text-primary font-bold disabled:opacity-40 disabled:font-normal"
            :disabled="countdown > 0 || busy" @click="resend"
          >
            {{ countdown > 0 ? `إعادة الإرسال بعد ${countdown} ث` : 'إعادة إرسال الرمز' }}
          </button>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
/**
 * Email OTP verification (NEXTJS-EMAIL-VERIFICATION contract, Vue twin).
 * Branches on machine codes only; the resend countdown always comes from the
 * server (`resend_available_in` / `retry_after`), never a hardcoded 60.
 */
import { ref, watch, onBeforeUnmount } from 'vue'
import { userApi } from '@/api/user'
import { useAuthStore } from '@/stores/auth'

const props = defineProps({
  open: { type: Boolean, default: false },
})
const emit = defineEmits(['close', 'verified'])

const auth = useAuthStore()

const step = ref('email')
const email = ref('')
const code = ref('')
const error = ref('')
const busy = ref(false)
const codeDead = ref(false)
const countdown = ref(0)
let timer = null

watch(() => props.open, (v) => {
  if (v) {
    step.value = 'email'
    email.value = auth.user?.email || ''
    code.value = ''
    error.value = ''
    codeDead.value = false
    stopTimer()
  }
})

function startCountdown(seconds) {
  countdown.value = Math.max(0, Number(seconds) || 0)
  stopTimer()
  timer = setInterval(() => {
    countdown.value -= 1
    if (countdown.value <= 0) stopTimer()
  }, 1000)
}
function stopTimer() {
  if (timer) clearInterval(timer)
  timer = null
}
onBeforeUnmount(stopTimer)

async function sendCode() {
  error.value = ''
  busy.value = true
  try {
    const { data } = await userApi.addEmail(email.value)
    step.value = 'code'
    codeDead.value = false
    startCountdown(data.data?.resend_available_in ?? 60)
  } catch (e) {
    const res = e.response?.data
    if (res?.code === 'RATE_LIMITED') {
      // A live code already exists for this address — go straight to entry.
      step.value = 'code'
      startCountdown(res.retry_after ?? 60)
    } else {
      error.value = res?.message || 'تعذّر إرسال الرمز، حاول مجدداً'
    }
  } finally {
    busy.value = false
  }
}

async function submitCode() {
  error.value = ''
  busy.value = true
  try {
    await userApi.verifyEmailOtp(code.value)
    auth.setUser({ ...auth.user, email: email.value, email_verified: true })
    emit('verified')
    emit('close')
  } catch (e) {
    const res = e.response?.data
    if (res?.code === 'OTP_MAX_ATTEMPTS' || res?.code === 'OTP_EXPIRED') {
      // Only a fresh code helps now.
      codeDead.value = true
      code.value = ''
      error.value = res.message + ' اضغط "إعادة إرسال الرمز".'
    } else {
      error.value = res?.message || 'رمز غير صحيح'
    }
  } finally {
    busy.value = false
  }
}

async function resend() {
  error.value = ''
  busy.value = true
  try {
    const { data } = await userApi.resendEmailOtp()
    codeDead.value = false
    code.value = ''
    startCountdown(data.data?.resend_available_in ?? 60)
  } catch (e) {
    const res = e.response?.data
    if (res?.code === 'RATE_LIMITED') startCountdown(res.retry_after ?? 60)
    else error.value = res?.message || 'تعذّرت إعادة الإرسال'
  } finally {
    busy.value = false
  }
}

function close() {
  emit('close')
}
</script>
