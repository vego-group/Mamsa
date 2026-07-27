<template>
  <div class="min-h-screen flex bg-gray-50" :dir="dir">
    <!-- Left: brand panel -->
    <div class="hidden lg:flex relative w-1/2 xl:w-[55%] overflow-hidden">
      <div class="absolute inset-0 bg-gradient-to-br from-primary via-primary-container to-[#0e2a18]"></div>
      <div class="absolute inset-0 opacity-[0.07]" style="background-image:radial-gradient(circle at 20% 30%, #fff 0, transparent 40%),radial-gradient(circle at 80% 70%, #fff 0, transparent 35%)"></div>
      <div class="relative z-10 p-10 flex flex-col justify-between w-full">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-xl bg-white/15 flex items-center justify-center">
            <span class="material-symbols-outlined text-white text-[22px]" style="font-variation-settings:'FILL' 1">home_work</span>
          </div>
          <div>
            <p class="text-white font-bold text-[16px] leading-tight">{{ t('brand.title') }}</p>
            <p class="text-white/50 text-[11px] uppercase tracking-wider">{{ t('login.brandSub') }}</p>
          </div>
        </div>
        <div class="text-white/80 text-[13px]">© {{ year }} {{ t('brand.title') }}</div>
      </div>
    </div>

    <!-- Right: form -->
    <div class="flex-1 flex items-center justify-center p-6 relative">
      <button class="absolute top-5 h-9 px-3 rounded-lg border border-gray-200 bg-white text-[12px] font-bold text-gray-600 flex items-center gap-1.5 hover:bg-gray-50" :class="dir === 'rtl' ? 'left-5' : 'right-5'" @click="toggleLocale">
        <span class="material-symbols-outlined text-[16px]">language</span>{{ locale === 'en' ? 'AR' : 'EN' }}
      </button>

      <div class="w-full max-w-sm">
        <!-- Mobile brand -->
        <div class="lg:hidden flex items-center gap-3 mb-8">
          <div class="w-10 h-10 rounded-xl bg-primary flex items-center justify-center">
            <span class="material-symbols-outlined text-white text-[22px]" style="font-variation-settings:'FILL' 1">home_work</span>
          </div>
          <p class="text-gray-900 font-bold text-[18px]">{{ t('brand.title') }}</p>
        </div>

        <p class="text-[12px] font-bold uppercase tracking-[0.12em] text-primary mb-2">{{ t('login.portal') }}</p>
        <h1 class="text-[30px] font-bold text-gray-900 leading-tight mb-1">{{ t('login.welcome') }} <span class="inline-block">👋</span></h1>
        <p class="text-gray-500 text-[14px] mb-8">{{ t('login.subtitle') }}</p>

        <form class="space-y-4" @submit.prevent="submit">
          <div>
            <label class="block text-[13px] font-semibold text-gray-700 mb-1.5">{{ t('login.email') }}</label>
            <input v-model="email" type="email" dir="ltr" placeholder="admin@mamsa.sa" autocomplete="username" required
              class="w-full h-12 px-4 bg-white border rounded-xl text-[14px] outline-none focus:ring-2 focus:ring-primary/15 focus:border-primary/50 transition-all"
              :class="error ? 'border-red-400' : 'border-gray-200'" />
          </div>
          <div>
            <label class="block text-[13px] font-semibold text-gray-700 mb-1.5">{{ t('login.password') }}</label>
            <div class="relative">
              <input v-model="password" :type="showPassword ? 'text' : 'password'" dir="ltr" placeholder="••••••••" autocomplete="current-password" required
                class="w-full h-12 px-4 bg-white border rounded-xl text-[14px] outline-none focus:ring-2 focus:ring-primary/15 focus:border-primary/50 transition-all"
                :class="[error ? 'border-red-400' : 'border-gray-200', dir === 'rtl' ? 'pl-11' : 'pr-11']" />
              <button type="button" tabindex="-1" class="absolute top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700" :class="dir === 'rtl' ? 'left-3' : 'right-3'" @click="showPassword = !showPassword">
                <span class="material-symbols-outlined text-[20px]">{{ showPassword ? 'visibility_off' : 'visibility' }}</span>
              </button>
            </div>
            <p v-if="error" class="text-red-600 text-[13px] mt-2">{{ error }}</p>
          </div>

          <button type="submit" class="w-full h-12 rounded-xl bg-primary text-white font-semibold text-[14px] hover:bg-primary-container transition-colors disabled:opacity-60 flex items-center justify-center gap-2" :disabled="loading">
            <span v-if="loading" class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span>
            {{ loading ? t('login.signingIn') : t('login.signIn') }}
          </button>
        </form>

        <div class="mt-6 pt-5 border-t border-gray-100 text-center">
          <RouterLink :to="{ name: 'login' }" class="text-[13px] text-primary hover:underline">{{ t('login.otpLink') }}</RouterLink>
        </div>

        <p class="text-center text-[12px] text-gray-400 mt-8">
          © {{ year }} {{ t('brand.title') }} · <a href="#" class="hover:text-gray-600">{{ t('login.privacy') }}</a> · <a href="#" class="hover:text-gray-600">{{ t('login.terms') }}</a>
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useAdminI18n } from '@/i18n/admin'

const router = useRouter()
const auth = useAuthStore()
const { t, dir, locale, toggleLocale } = useAdminI18n()

const email = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)
const showPassword = ref(false)
const year = new Date().getFullYear()

async function submit() {
  error.value = ''
  loading.value = true
  try {
    await auth.adminLogin(email.value.trim(), password.value.trim())
    router.replace({ name: 'admin-dashboard' })
  } catch (err) {
    error.value =
      err.response?.data?.errors?.email?.[0] ||
      err.response?.data?.message ||
      t('login.error')
  } finally {
    loading.value = false
  }
}
</script>
