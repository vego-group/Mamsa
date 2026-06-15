<template>
  <div class="min-h-screen bg-surface flex items-center justify-center p-4" dir="rtl">
    <div class="w-full max-w-md">
      <!-- Logo -->
      <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-16 h-16 bg-primary-container rounded-2xl mb-4">
          <span class="material-symbols-outlined text-on-primary-fixed text-3xl" style="font-variation-settings:'FILL' 1">apartment</span>
        </div>
        <h1 class="font-arabic text-display-lg text-primary">ممسى</h1>
        <p class="text-on-surface-variant text-body-sm mt-1">لوحة تحكم المشرف العام</p>
      </div>

      <!-- Card -->
      <div class="bg-white rounded-2xl border border-outline-variant shadow-card p-8">
        <h2 class="font-arabic text-headline-md text-primary mb-6">تسجيل الدخول</h2>

        <form @submit.prevent="submit" class="space-y-5">
          <div>
            <label class="block text-body-sm font-arabic font-bold text-on-surface mb-2">
              رقم الجوال
            </label>
            <div class="flex gap-2">
              <!-- Country prefix -->
              <div class="flex items-center gap-2 px-3 rounded-lg border border-outline-variant bg-surface-container-low text-on-surface-variant text-body-sm font-data whitespace-nowrap">
                <span class="text-base">🇸🇦</span>
                <span>+966</span>
              </div>
              <input
                v-model="phone"
                type="tel"
                dir="ltr"
                placeholder="5XXXXXXXX"
                class="input-field flex-1"
                :class="{ 'border-error focus:border-error': error }"
                maxlength="9"
                inputmode="numeric"
                autocomplete="tel"
                required
              />
            </div>
            <p v-if="error" class="text-error text-body-sm mt-1">{{ error }}</p>
          </div>

          <button type="submit" class="btn-primary w-full" :disabled="loading">
            <span v-if="loading" class="flex items-center justify-center gap-2">
              <span class="material-symbols-outlined animate-spin text-lg">progress_activity</span>
              جارٍ الإرسال...
            </span>
            <span v-else>إرسال رمز التحقق</span>
          </button>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { authApi } from '@/api/auth'

const router = useRouter()
const phone  = ref('')
const error  = ref('')
const loading = ref(false)

async function submit() {
  error.value = ''
  const raw = phone.value.replace(/\D/g, '')

  if (!raw || raw.length < 9) {
    error.value = 'أدخل رقم جوال صحيح (9 أرقام)'
    return
  }

  loading.value = true
  try {
    const fullPhone = `+966${raw}`
    const res = await authApi.requestOtp(fullPhone)
    const query = { phone: fullPhone }
    if (res.data?.data?.debug_otp) query.debug_otp = res.data.data.debug_otp
    router.push({ name: 'otp', query })
  } catch (err) {
    error.value = err.response?.data?.message || 'حدث خطأ، حاول مجدداً'
  } finally {
    loading.value = false
  }
}
</script>
