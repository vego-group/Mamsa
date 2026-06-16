<template>
  <div class="min-h-screen bg-surface flex items-center justify-center p-4" dir="rtl">
    <div class="w-full max-w-lg">
      <!-- Header -->
      <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-16 h-16 bg-primary rounded-2xl mb-4">
          <span class="material-symbols-outlined text-on-primary text-3xl" style="font-variation-settings:'FILL' 1">handshake</span>
        </div>
        <h1 class="font-arabic text-display-lg text-primary">انضم كشريك</h1>
        <p class="text-on-surface-variant text-body-sm mt-1">اعرض وحداتك على ممسى وابدأ باستقبال الحجوزات</p>
      </div>

      <!-- Stepper -->
      <div class="flex items-center justify-center gap-2 mb-6">
        <div class="flex items-center gap-2">
          <span class="w-7 h-7 rounded-full flex items-center justify-center text-body-sm font-bold" :class="step === 1 ? 'bg-primary text-on-primary' : 'bg-emerald-100 text-emerald-700'">
            <span v-if="step > 1" class="material-symbols-outlined text-[16px]">check</span>
            <span v-else>1</span>
          </span>
          <span class="text-body-sm font-bold" :class="step === 1 ? 'text-primary' : 'text-on-surface-variant'">البيانات</span>
        </div>
        <div class="w-8 h-px bg-outline-variant"></div>
        <div class="flex items-center gap-2">
          <span class="w-7 h-7 rounded-full flex items-center justify-center text-body-sm font-bold" :class="step === 2 ? 'bg-primary text-on-primary' : 'bg-surface-container text-on-surface-variant'">2</span>
          <span class="text-body-sm font-bold" :class="step === 2 ? 'text-primary' : 'text-on-surface-variant'">التحقق</span>
        </div>
      </div>

      <div class="bg-white rounded-2xl border border-outline-variant shadow-card p-8">
        <!-- STEP 1: details -->
        <form v-if="step === 1" class="space-y-5" @submit.prevent="requestOtp">
          <!-- Type toggle -->
          <div>
            <label class="block text-body-sm font-arabic font-bold text-on-surface mb-2">نوع الشريك</label>
            <div class="grid grid-cols-2 gap-3">
              <button
                v-for="opt in typeOptions"
                :key="opt.value"
                type="button"
                class="p-4 rounded-xl border-2 transition-all flex flex-col items-center gap-2"
                :class="form.type === opt.value ? 'border-primary bg-primary/5' : 'border-outline-variant hover:bg-surface-container-low'"
                @click="form.type = opt.value"
              >
                <span class="material-symbols-outlined text-2xl" :class="form.type === opt.value ? 'text-primary' : 'text-on-surface-variant'">{{ opt.icon }}</span>
                <span class="font-bold text-body-sm" :class="form.type === opt.value ? 'text-primary' : 'text-on-surface'">{{ opt.label }}</span>
              </button>
            </div>
          </div>

          <!-- Name -->
          <div>
            <label class="block text-body-sm font-arabic font-bold text-on-surface mb-2">
              {{ form.type === 'company' ? 'اسم الشركة' : 'الاسم الكامل' }}
            </label>
            <input v-model="form.name" type="text" class="input-field w-full" :class="{ 'border-error': errors.name }" :placeholder="form.type === 'company' ? 'شركة المثال للضيافة' : 'محمد عبدالله'" required />
            <p v-if="errors.name" class="text-error text-body-sm mt-1">{{ errors.name }}</p>
          </div>

          <!-- Phone -->
          <div>
            <label class="block text-body-sm font-arabic font-bold text-on-surface mb-2">رقم الجوال</label>
            <div class="flex gap-2">
              <div class="flex items-center gap-2 px-3 rounded-lg border border-outline-variant bg-surface-container-low text-on-surface-variant text-body-sm font-data whitespace-nowrap">
                <span class="text-base">🇸🇦</span><span>+966</span>
              </div>
              <input v-model="phoneLocal" type="tel" dir="ltr" placeholder="5XXXXXXXX" class="input-field flex-1" :class="{ 'border-error': errors.phone }" maxlength="9" inputmode="numeric" required />
            </div>
            <p v-if="errors.phone" class="text-error text-body-sm mt-1">{{ errors.phone }}</p>
          </div>

          <!-- Conditional ID -->
          <div v-if="form.type === 'individual'">
            <label class="block text-body-sm font-arabic font-bold text-on-surface mb-2">رقم الهوية الوطنية</label>
            <input v-model="form.national_id" type="text" dir="ltr" class="input-field w-full" :class="{ 'border-error': errors.national_id }" placeholder="10XXXXXXXX" maxlength="20" required />
            <p v-if="errors.national_id" class="text-error text-body-sm mt-1">{{ errors.national_id }}</p>
          </div>
          <div v-else>
            <label class="block text-body-sm font-arabic font-bold text-on-surface mb-2">رقم السجل التجاري</label>
            <input v-model="form.cr_number" type="text" dir="ltr" class="input-field w-full" :class="{ 'border-error': errors.cr_number }" placeholder="40XXXXXXXX" maxlength="20" required />
            <p v-if="errors.cr_number" class="text-error text-body-sm mt-1">{{ errors.cr_number }}</p>
          </div>

          <!-- Email (optional) -->
          <div>
            <label class="block text-body-sm font-arabic font-bold text-on-surface mb-2">البريد الإلكتروني (اختياري)</label>
            <input v-model="form.email" type="email" dir="ltr" class="input-field w-full" placeholder="example@email.com" />
          </div>

          <button type="submit" class="btn-primary w-full" :disabled="loading">
            <span v-if="loading" class="flex items-center justify-center gap-2">
              <span class="material-symbols-outlined animate-spin text-lg">progress_activity</span>
              جارٍ الإرسال...
            </span>
            <span v-else>إرسال رمز التحقق</span>
          </button>

          <p v-if="errors.general" class="text-error text-body-sm text-center">{{ errors.general }}</p>
        </form>

        <!-- STEP 2: OTP -->
        <form v-else class="space-y-5" @submit.prevent="submitRegistration">
          <div class="text-center mb-2">
            <p class="text-on-surface-variant text-body-sm">أدخل الرمز المُرسل إلى</p>
            <p class="font-data font-bold text-on-surface" dir="ltr">{{ fullPhone }}</p>
            <button type="button" class="text-primary text-body-sm mt-1 hover:underline" @click="step = 1">تعديل البيانات</button>
          </div>

          <!-- Dev debug banner -->
          <div v-if="debugOtp" class="flex items-center justify-between bg-amber-50 border border-amber-300 rounded-xl px-4 py-3">
            <span class="text-amber-700 text-body-sm font-arabic">رمز التطوير:</span>
            <button type="button" class="font-data font-bold text-lg tracking-widest text-amber-800 hover:text-primary" @click="code = debugOtp">{{ debugOtp }}</button>
          </div>

          <div>
            <label class="block text-body-sm font-arabic font-bold text-on-surface mb-2">رمز التحقق</label>
            <input v-model="code" type="text" dir="ltr" inputmode="numeric" maxlength="6" class="input-field w-full text-center text-2xl font-data tracking-[0.5em]" :class="{ 'border-error': errors.code }" placeholder="––––––" required />
            <p v-if="errors.code" class="text-error text-body-sm mt-1">{{ errors.code }}</p>
          </div>

          <button type="submit" class="btn-primary w-full" :disabled="loading">
            <span v-if="loading" class="flex items-center justify-center gap-2">
              <span class="material-symbols-outlined animate-spin text-lg">progress_activity</span>
              جارٍ التسجيل...
            </span>
            <span v-else>إكمال التسجيل</span>
          </button>

          <p v-if="errors.general" class="text-error text-body-sm text-center">{{ errors.general }}</p>
        </form>

        <!-- Footer link -->
        <div class="mt-6 pt-5 border-t border-outline-variant text-center">
          <RouterLink :to="{ name: 'login' }" class="text-body-sm text-primary hover:underline">
            لديك حساب؟ تسجيل الدخول
          </RouterLink>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { authApi } from '@/api/auth'

const router = useRouter()
const auth = useAuthStore()

const step = ref(1)
const loading = ref(false)
const phoneLocal = ref('')
const code = ref('')
const debugOtp = ref(null)
const errors = reactive({})

const typeOptions = [
  { value: 'individual', label: 'فرد', icon: 'person' },
  { value: 'company', label: 'شركة', icon: 'business' },
]

const form = reactive({
  type: 'individual',
  name: '',
  email: '',
  national_id: '',
  cr_number: '',
})

const fullPhone = computed(() => `+966${phoneLocal.value.replace(/\D/g, '')}`)

function clearErrors() {
  Object.keys(errors).forEach((k) => delete errors[k])
}

async function requestOtp() {
  clearErrors()
  const raw = phoneLocal.value.replace(/\D/g, '')
  if (raw.length < 9) {
    errors.phone = 'أدخل رقم جوال صحيح (9 أرقام)'
    return
  }
  if (form.type === 'individual' && !form.national_id.trim()) {
    errors.national_id = 'رقم الهوية مطلوب'
    return
  }
  if (form.type === 'company' && !form.cr_number.trim()) {
    errors.cr_number = 'رقم السجل التجاري مطلوب'
    return
  }

  loading.value = true
  try {
    const res = await authApi.requestOtp(fullPhone.value)
    debugOtp.value = res.data?.data?.debug_otp || null
    step.value = 2
  } catch (err) {
    errors.general = err.response?.data?.message || 'تعذّر إرسال الرمز، حاول مجدداً'
  } finally {
    loading.value = false
  }
}

async function submitRegistration() {
  clearErrors()
  if (!code.value || code.value.length < 4) {
    errors.code = 'أدخل رمز التحقق'
    return
  }

  loading.value = true
  try {
    await auth.partnerRegister({
      type: form.type,
      name: form.name.trim(),
      phone: fullPhone.value,
      code: code.value,
      email: form.email || undefined,
      national_id: form.type === 'individual' ? form.national_id.trim() : undefined,
      cr_number: form.type === 'company' ? form.cr_number.trim() : undefined,
    })
    router.replace({ name: 'partner-dashboard' })
  } catch (err) {
    const data = err.response?.data
    if (err.response?.status === 422 && data?.errors) {
      for (const [field, msgs] of Object.entries(data.errors)) errors[field] = msgs[0]
      // OTP errors should keep the user on step 2; data errors send them back.
      if (!errors.code) errors.general = data.message || 'يرجى مراجعة البيانات'
    } else {
      errors.general = data?.message || 'تعذّر التسجيل، حاول مجدداً'
    }
  } finally {
    loading.value = false
  }
}
</script>
