<template>
  <div class="min-h-screen bg-surface flex items-center justify-center p-4" dir="rtl">
    <div class="w-full max-w-md">
      <!-- Logo -->
      <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-16 h-16 bg-primary rounded-2xl mb-4">
          <span class="material-symbols-outlined text-on-primary text-3xl" style="font-variation-settings:'FILL' 1">admin_panel_settings</span>
        </div>
        <h1 class="font-arabic text-display-lg text-primary">ممسى</h1>
        <p class="text-on-surface-variant text-body-sm mt-1">دخول لوحة التحكم</p>
      </div>

      <!-- Card -->
      <div class="bg-white rounded-2xl border border-outline-variant shadow-card p-8">
        <h2 class="font-arabic text-headline-md text-primary mb-6">تسجيل دخول المشرف</h2>

        <form class="space-y-5" @submit.prevent="submit">
          <!-- Email -->
          <div>
            <label class="block text-body-sm font-arabic font-bold text-on-surface mb-2">البريد الإلكتروني</label>
            <input
              v-model="email"
              type="email"
              dir="ltr"
              placeholder="admin@mamsaa.sa"
              class="input-field w-full"
              :class="{ 'border-error focus:border-error': error }"
              autocomplete="username"
              required
            />
          </div>

          <!-- Password -->
          <div>
            <label class="block text-body-sm font-arabic font-bold text-on-surface mb-2">كلمة المرور</label>
            <div class="relative">
              <input
                v-model="password"
                :type="showPassword ? 'text' : 'password'"
                dir="ltr"
                placeholder="••••••••"
                class="input-field w-full pl-11"
                :class="{ 'border-error focus:border-error': error }"
                autocomplete="current-password"
                required
              />
              <button
                type="button"
                class="absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant hover:text-primary transition-colors"
                tabindex="-1"
                @click="showPassword = !showPassword"
              >
                <span class="material-symbols-outlined text-[20px]">{{ showPassword ? 'visibility_off' : 'visibility' }}</span>
              </button>
            </div>
            <p v-if="error" class="text-error text-body-sm mt-2">{{ error }}</p>
          </div>

          <button type="submit" class="btn-primary w-full" :disabled="loading">
            <span v-if="loading" class="flex items-center justify-center gap-2">
              <span class="material-symbols-outlined animate-spin text-lg">progress_activity</span>
              جارٍ الدخول...
            </span>
            <span v-else>دخول</span>
          </button>
        </form>

        <!-- Link to OTP login for regular users -->
        <div class="mt-6 pt-5 border-t border-outline-variant text-center">
          <RouterLink :to="{ name: 'login' }" class="text-body-sm text-primary hover:underline">
            دخول المستخدمين برمز التحقق
          </RouterLink>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()

const email = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)
const showPassword = ref(false)

async function submit() {
  error.value = ''
  loading.value = true

  try {
    // Trim trailing/leading whitespace injected by mobile keyboards or autofill.
    await auth.adminLogin(email.value.trim(), password.value.trim())
    // Guard already restricts admin routes; redirect straight to the panel.
    router.replace({ name: 'admin-dashboard' })
  } catch (err) {
    // Laravel ValidationException → 422 with errors.email[0]
    error.value =
      err.response?.data?.errors?.email?.[0] ||
      err.response?.data?.message ||
      'تعذّر تسجيل الدخول، حاول مجدداً'
  } finally {
    loading.value = false
  }
}
</script>
