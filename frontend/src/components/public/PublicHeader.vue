<template>
  <header class="sticky top-0 z-40 bg-white/95 backdrop-blur border-b border-outline-variant">
    <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between gap-4" dir="rtl">
      <!-- Brand (right in RTL) -->
      <RouterLink :to="{ name: 'home' }" class="flex items-center gap-2 shrink-0">
        <div class="w-9 h-9 bg-primary rounded-xl flex items-center justify-center">
          <span class="material-symbols-outlined text-on-primary text-xl" style="font-variation-settings:'FILL' 1">apartment</span>
        </div>
        <div class="leading-tight">
          <span class="block font-display-lg text-[20px] text-primary">ممسى</span>
        </div>
      </RouterLink>

      <!-- Center nav (desktop) -->
      <nav class="hidden md:flex items-center gap-7">
        <RouterLink
          v-for="link in navLinks"
          :key="link.label"
          :to="link.to"
          class="text-body-sm font-bold transition-colors"
          :class="isActive(link) ? 'text-primary' : 'text-on-surface-variant hover:text-primary'"
        >
          {{ link.label }}
        </RouterLink>
      </nav>

      <!-- Actions (left in RTL) -->
      <div class="flex items-center gap-2 shrink-0">
        <template v-if="auth.isAuthenticated">
          <!-- Partners' "حسابي" opens their dashboard, so give them a direct
               link to the guest reservations they made as a user. -->
          <RouterLink
            v-if="auth.isPartner"
            :to="{ name: 'account' }"
            class="px-4 py-2 rounded-lg text-body-sm font-bold text-on-surface hover:bg-surface-container transition-colors"
          >
            حجوزاتي
          </RouterLink>
          <RouterLink :to="dashboardRoute" class="px-4 py-2 rounded-lg text-body-sm font-bold text-primary hover:bg-surface-container transition-colors">
            حسابي
          </RouterLink>
          <button class="px-4 py-2 rounded-lg text-body-sm font-bold text-on-surface-variant hover:bg-surface-container transition-colors" @click="logout">
            خروج
          </button>
        </template>
        <template v-else>
          <RouterLink :to="{ name: 'login' }" class="hidden sm:inline-flex px-4 py-2 rounded-lg text-body-sm font-bold text-on-surface hover:bg-surface-container transition-colors">
            سجل دخول
          </RouterLink>
          <RouterLink :to="{ name: 'partner-register' }" class="px-4 py-2 rounded-lg bg-primary text-on-primary text-body-sm font-bold hover:bg-primary-container transition-colors">
            إدراج عقار
          </RouterLink>
        </template>

        <!-- Language -->
        <button class="hidden sm:grid w-9 h-9 place-items-center rounded-lg text-on-surface-variant hover:bg-surface-container transition-colors" title="اللغة">
          <span class="material-symbols-outlined text-[20px]">language</span>
        </button>
        <!-- Mobile menu toggle -->
        <button class="md:hidden grid w-9 h-9 place-items-center rounded-lg text-on-surface-variant hover:bg-surface-container transition-colors" @click="mobileOpen = !mobileOpen" aria-label="القائمة">
          <span class="material-symbols-outlined text-[22px]">{{ mobileOpen ? 'close' : 'menu' }}</span>
        </button>
      </div>
    </div>

    <!-- Mobile nav -->
    <Transition name="slide">
      <nav v-if="mobileOpen" class="md:hidden border-t border-outline-variant bg-white px-4 py-3 flex flex-col gap-1" dir="rtl">
        <RouterLink
          v-for="link in navLinks"
          :key="link.label"
          :to="link.to"
          class="px-3 py-2.5 rounded-lg text-body-md font-bold transition-colors"
          :class="isActive(link) ? 'bg-surface-container text-primary' : 'text-on-surface-variant hover:bg-surface-container'"
          @click="mobileOpen = false"
        >
          {{ link.label }}
        </RouterLink>
      </nav>
    </Transition>
  </header>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const mobileOpen = ref(false)

// Marketing nav. الرئيسية/العقارات resolve to existing routes; the rest are
// placeholders pointing at home until those landing pages ship.
const navLinks = [
  { label: 'الرئيسية',     to: { name: 'home' }, match: 'home' },
  { label: 'إكتشف وجهتك',  to: { name: 'explore' }, match: 'explore' },
  { label: 'العقارات',     to: { name: 'home' } },
  { label: 'المستشارين',   to: { name: 'home' } },
]

const dashboardRoute = computed(() => auth.homeRoute())

function isActive(link) {
  return link.match && route.name === link.match
}

async function logout() {
  await auth.logout()
  router.push({ name: 'home' })
}
</script>

<style scoped>
.slide-enter-active, .slide-leave-active { transition: all 0.2s ease; }
.slide-enter-from, .slide-leave-to { opacity: 0; transform: translateY(-8px); }
</style>
