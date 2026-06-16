<template>
  <header class="sticky top-0 z-40 bg-white/90 backdrop-blur border-b border-outline-variant">
    <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between" dir="rtl">
      <!-- Brand -->
      <RouterLink :to="{ name: 'home' }" class="flex items-center gap-2">
        <div class="w-9 h-9 bg-primary rounded-xl flex items-center justify-center">
          <span class="material-symbols-outlined text-on-primary text-xl" style="font-variation-settings:'FILL' 1">apartment</span>
        </div>
        <span class="font-display-lg text-[22px] text-primary">ممسى</span>
      </RouterLink>

      <!-- Actions -->
      <div class="flex items-center gap-2">
        <template v-if="auth.isAuthenticated">
          <RouterLink :to="dashboardRoute" class="px-4 py-2 rounded-lg text-body-sm font-bold text-primary hover:bg-surface-container transition-colors">
            حسابي
          </RouterLink>
          <button class="px-4 py-2 rounded-lg text-body-sm font-bold text-on-surface-variant hover:bg-surface-container transition-colors" @click="logout">
            خروج
          </button>
        </template>
        <template v-else>
          <RouterLink :to="{ name: 'login' }" class="px-5 py-2 rounded-lg bg-primary text-on-primary text-body-sm font-bold hover:bg-primary-container transition-colors">
            تسجيل الدخول
          </RouterLink>
        </template>
      </div>
    </div>
  </header>
</template>

<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const dashboardRoute = computed(() => auth.homeRoute())

async function logout() {
  await auth.logout()
  router.push({ name: 'home' })
}
</script>
