<template>
  <AdminLayout>
    <div class="mb-6">
      <h1 class="text-[24px] font-bold text-gray-900 leading-tight">{{ t('profile.title') }}</h1>
      <p class="text-gray-500 text-[14px] mt-0.5">{{ t('profile.subtitle') }}</p>
    </div>

    <div class="max-w-3xl space-y-4">
      <!-- Identity card -->
      <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <div class="flex items-center gap-4">
          <div class="relative">
            <div class="w-16 h-16 rounded-2xl bg-primary flex items-center justify-center text-white font-bold text-[24px]">{{ initials }}</div>
            <span v-if="verified" class="absolute -bottom-1 -right-1 w-5 h-5 rounded-full bg-emerald-500 border-2 border-white flex items-center justify-center"><span class="material-symbols-outlined text-white text-[12px]" style="font-variation-settings:'FILL' 1">check</span></span>
          </div>
          <div class="min-w-0">
            <h2 class="text-[20px] font-bold text-gray-900 truncate">{{ user?.name || t('brand.subtitle') }}</h2>
            <p class="text-[13px] text-gray-400 truncate">{{ user?.email || '—' }}</p>
            <div class="flex items-center gap-2 mt-1.5">
              <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-gray-100 text-gray-600 text-[12px] font-semibold"><span class="material-symbols-outlined text-[13px]">shield_person</span>{{ roleLabel }}</span>
              <span v-if="verified" class="text-emerald-600 text-[12px] font-semibold">{{ t('profile.verified') }}</span>
            </div>
          </div>
        </div>
        <div class="grid grid-cols-3 gap-4 mt-5 pt-5 border-t border-gray-100">
          <div v-for="s in stats" :key="s.key">
            <p class="text-[18px] font-bold text-gray-900 tabular-nums">{{ s.value }}</p>
            <p class="text-[12px] text-gray-400">{{ s.label }}</p>
          </div>
        </div>
      </div>

      <!-- Personal info -->
      <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h3 class="text-[16px] font-bold text-gray-900 mb-4">{{ t('profile.personalInfo') }}</h3>
        <div class="space-y-4">
          <div>
            <label class="block text-[13px] font-semibold text-gray-700 mb-1.5">{{ t('profile.fullName') }}</label>
            <input :value="user?.name" readonly class="pfield" />
          </div>
          <div>
            <label class="block text-[13px] font-semibold text-gray-700 mb-1.5">{{ t('profile.email') }}</label>
            <div class="flex gap-2">
              <input :value="user?.email" readonly dir="ltr" class="pfield flex-1" />
              <button class="h-11 px-4 rounded-xl border border-gray-200 text-[13px] font-semibold text-gray-400 cursor-not-allowed" disabled>{{ t('profile.edit') }}</button>
            </div>
          </div>
          <div>
            <label class="block text-[13px] font-semibold text-gray-700 mb-1.5">{{ t('profile.phone') }}</label>
            <input :value="user?.phone || '—'" readonly dir="ltr" class="pfield" />
          </div>
          <div>
            <label class="block text-[13px] font-semibold text-gray-700 mb-1.5">{{ t('profile.prefLang') }}</label>
            <select :value="locale" @change="setLocale($event.target.value)" class="pfield">
              <option value="en">{{ t('profile.langEn') }}</option>
              <option value="ar">{{ t('profile.langAr') }}</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Security -->
      <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h3 class="text-[16px] font-bold text-gray-900 mb-4">{{ t('profile.security') }}</h3>
        <div class="space-y-3">
          <div class="flex items-center gap-3 border border-gray-200 rounded-xl p-4">
            <div class="w-9 h-9 rounded-lg bg-gray-100 flex items-center justify-center"><span class="material-symbols-outlined text-[18px] text-gray-500">phonelink_lock</span></div>
            <div class="flex-1 min-w-0">
              <p class="text-[14px] font-semibold text-gray-800">{{ t('profile.twoFactor') }}</p>
              <p class="text-[12px] text-gray-400">{{ t('profile.twoFactorHint') }} · {{ t('profile.comingSoon') }}</p>
            </div>
            <span class="w-10 h-6 rounded-full bg-gray-200 relative flex-shrink-0"><span class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow"></span></span>
          </div>

          <div class="border border-gray-200 rounded-xl p-4">
            <div class="flex items-center gap-3 mb-3">
              <div class="w-9 h-9 rounded-lg bg-gray-100 flex items-center justify-center"><span class="material-symbols-outlined text-[18px] text-gray-500">key</span></div>
              <div>
                <p class="text-[14px] font-semibold text-gray-800">{{ t('profile.activeSessions') }}</p>
                <p class="text-[12px] text-gray-400">{{ t('profile.sessions', { n: 1 }) }}</p>
              </div>
            </div>
            <div class="flex items-center justify-between px-1">
              <div>
                <p class="text-[13px] text-gray-700">{{ t('profile.thisDevice') }}</p>
                <p class="text-[11px] text-gray-400 truncate max-w-[220px]">{{ userAgentShort }}</p>
              </div>
              <span class="text-[12px] font-semibold text-emerald-600">{{ t('profile.current') }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Danger zone -->
      <div class="bg-white rounded-2xl border border-red-200 p-6">
        <h3 class="text-[16px] font-bold text-red-600 mb-3">{{ t('profile.dangerZone') }}</h3>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p class="text-[14px] font-semibold text-gray-800">{{ t('profile.signOutAll') }}</p>
            <p class="text-[12px] text-gray-400">{{ t('profile.signOutAllHint') }}</p>
          </div>
          <button class="inline-flex items-center gap-1.5 h-10 px-5 rounded-lg bg-red-600 text-white text-[13px] font-semibold hover:bg-red-700 transition-colors disabled:opacity-60" :disabled="loggingOut" @click="logout">
            <span class="material-symbols-outlined text-[18px]">logout</span>{{ t('profile.logout') }}
          </button>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { useAuthStore } from '@/stores/auth'
import { useAdminI18n } from '@/i18n/admin'
import { useAdminFormat } from '@/composables/useAdminFormat'

const router = useRouter()
const auth = useAuthStore()
const { t, locale, setLocale } = useAdminI18n()
const { date } = useAdminFormat()

const user = computed(() => auth.user)
const loggingOut = ref(false)

const initials = computed(() => (user.value?.name || 'A').split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase())
const verified = computed(() => !!(user.value?.email_verified_at || user.value?.email))
const roleLabel = computed(() => {
  const roles = user.value?.roles || []
  if (roles.includes('SuperAdmin')) return t('brand.subtitle')
  if (roles.includes('Admin')) return t('nav.core')
  return t('brand.subtitle')
})

const stats = computed(() => [
  { key: 'reviews', value: '—', label: t('profile.totalReviews') },
  { key: 'actions', value: '—', label: t('profile.actionsToday') },
  { key: 'since', value: user.value?.created_at ? date(user.value.created_at, { month: 'short', year: 'numeric' }) : '—', label: t('profile.memberSince') },
])

const userAgentShort = computed(() => {
  const ua = navigator.userAgent
  const browser = /Edg/.test(ua) ? 'Edge' : /Chrome/.test(ua) ? 'Chrome' : /Safari/.test(ua) ? 'Safari' : /Firefox/.test(ua) ? 'Firefox' : 'Browser'
  const os = /Windows/.test(ua) ? 'Windows' : /Mac/.test(ua) ? 'macOS' : /Android/.test(ua) ? 'Android' : /iPhone|iPad/.test(ua) ? 'iOS' : /Linux/.test(ua) ? 'Linux' : ''
  return [os, browser].filter(Boolean).join(' — ')
})

async function logout() {
  loggingOut.value = true
  await auth.logout()
  router.replace({ name: 'admin-login' })
}
</script>

<style scoped>
.pfield {
  @apply w-full h-11 px-4 bg-gray-50 border border-gray-200 rounded-xl text-[14px] text-gray-800 outline-none focus:ring-2 focus:ring-primary/15 focus:border-primary/40 transition-all;
}
</style>
