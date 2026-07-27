<template>
  <div class="min-h-screen bg-[#F7F7F4] text-on-surface" :dir="dir">
    <!-- Mobile top bar -->
    <header class="lg:hidden sticky top-0 z-50 bg-primary text-on-primary flex justify-between items-center px-4 py-3.5 shadow-sm">
      <div class="flex items-center gap-3">
        <button class="p-2 hover:bg-white/10 rounded-lg transition-colors" @click="sidebarOpen = true">
          <span class="material-symbols-outlined">menu</span>
        </button>
        <span class="font-semibold text-[16px]">{{ pageTitle }}</span>
      </div>
      <div class="flex items-center gap-2">
        <button class="px-2.5 h-8 rounded-lg hover:bg-white/10 text-[12px] font-bold flex items-center gap-1" @click="toggleLocale">
          <span class="material-symbols-outlined text-[16px]">language</span>{{ otherLang }}
        </button>
        <NotificationBell variant="mobile" />
        <div class="w-8 h-8 rounded-full bg-white/12 flex items-center justify-center font-bold text-sm">{{ adminInitials }}</div>
      </div>
    </header>

    <!-- Sidebar overlay (mobile) -->
    <div v-if="sidebarOpen" class="fixed inset-0 bg-black/50 z-50 lg:hidden" @click="sidebarOpen = false" />

    <AdminSidebar :open="sidebarOpen" @close="sidebarOpen = false" />

    <!-- Main content -->
    <main class="min-h-screen flex flex-col" :class="dir === 'rtl' ? 'lg:mr-[240px]' : 'lg:ml-[240px]'">
      <!-- Desktop top bar -->
      <header class="hidden lg:flex sticky top-0 z-40 bg-white border-b border-gray-200 h-[60px] items-center gap-4 px-6">
        <!-- Breadcrumb -->
        <nav class="flex items-center gap-2 text-[13px] flex-shrink-0">
          <span class="text-gray-400">{{ t('brand.title') }}</span>
          <span class="material-symbols-outlined text-[16px] text-gray-300">{{ dir === 'rtl' ? 'chevron_left' : 'chevron_right' }}</span>
          <span class="text-gray-800 font-semibold">{{ pageTitle }}</span>
        </nav>

        <!-- Search -->
        <div class="flex-1 flex justify-center px-6">
          <div class="relative w-full max-w-md">
            <span class="material-symbols-outlined absolute top-1/2 -translate-y-1/2 text-gray-400 text-[20px]" :class="dir === 'rtl' ? 'right-3' : 'left-3'">search</span>
            <input
              ref="searchInput"
              v-model="search"
              class="w-full h-9 bg-gray-50 border border-gray-200 rounded-lg text-[13px] outline-none focus:ring-2 focus:ring-primary/15 focus:border-primary/40 transition-all"
              :class="dir === 'rtl' ? 'pr-10 pl-14' : 'pl-10 pr-14'"
              :placeholder="t('header.search')"
              type="text"
            />
            <kbd class="absolute top-1/2 -translate-y-1/2 text-[10px] font-sans text-gray-400 bg-white border border-gray-200 rounded px-1.5 py-0.5" :class="dir === 'rtl' ? 'left-2' : 'right-2'">⌘K</kbd>
          </div>
        </div>

        <!-- Actions -->
        <div class="flex items-center gap-1.5 flex-shrink-0">
          <button
            class="h-9 px-3 rounded-lg border border-gray-200 hover:bg-gray-50 text-[12px] font-bold text-gray-600 flex items-center gap-1.5 transition-colors"
            @click="toggleLocale"
          >
            <span class="material-symbols-outlined text-[16px]">language</span>{{ otherLang }}
          </button>

          <NotificationBell variant="desktop" />

          <RouterLink
            :to="{ name: 'admin-settings' }"
            class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-gray-100 transition-colors text-gray-500"
          >
            <span class="material-symbols-outlined text-[20px]">settings</span>
          </RouterLink>

          <div class="w-px h-6 bg-gray-200 mx-1" />

          <!-- Admin dropdown -->
          <div ref="menuRoot" class="relative">
            <button class="flex items-center gap-2 h-9 px-1.5 rounded-lg hover:bg-gray-100 transition-colors" @click="menuOpen = !menuOpen">
              <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold text-[12px]">{{ adminInitials }}</div>
              <span class="text-[13px] font-semibold text-gray-700">{{ shortName }}</span>
              <span class="material-symbols-outlined text-[18px] text-gray-400">expand_more</span>
            </button>
            <transition name="fade">
              <div
                v-if="menuOpen"
                class="absolute top-11 w-52 bg-white rounded-xl border border-gray-200 shadow-lg py-1.5 z-50"
                :class="dir === 'rtl' ? 'left-0' : 'right-0'"
              >
                <div class="px-3 py-2 border-b border-gray-100">
                  <p class="text-[13px] font-semibold text-gray-800 truncate">{{ auth.user?.name || t('header.role') }}</p>
                  <p class="text-[11px] text-gray-400 truncate">{{ auth.user?.email || 'admin@mamsa.sa' }}</p>
                </div>
                <RouterLink :to="{ name: 'admin-profile' }" class="flex items-center gap-2.5 px-3 py-2 text-[13px] text-gray-700 hover:bg-gray-50" @click="menuOpen = false">
                  <span class="material-symbols-outlined text-[18px] text-gray-400">person</span>{{ t('header.profile') }}
                </RouterLink>
                <RouterLink :to="{ name: 'admin-settings' }" class="flex items-center gap-2.5 px-3 py-2 text-[13px] text-gray-700 hover:bg-gray-50" @click="menuOpen = false">
                  <span class="material-symbols-outlined text-[18px] text-gray-400">settings</span>{{ t('header.settings') }}
                </RouterLink>
                <button class="w-full flex items-center gap-2.5 px-3 py-2 text-[13px] text-error hover:bg-error/5 border-t border-gray-100 mt-1" @click="handleLogout">
                  <span class="material-symbols-outlined text-[18px]">logout</span>{{ t('header.logout') }}
                </button>
              </div>
            </transition>
          </div>
        </div>
      </header>

      <div class="flex-1 p-4 lg:p-6">
        <slot />
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useAdminI18n } from '@/i18n/admin'
import AdminSidebar from '@/components/admin/AdminSidebar.vue'
import NotificationBell from '@/components/NotificationBell.vue'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const { t, dir, locale, toggleLocale } = useAdminI18n()

const sidebarOpen = ref(false)
const menuOpen = ref(false)
const menuRoot = ref(null)
const searchInput = ref(null)
const search = ref('')

const otherLang = computed(() => (locale.value === 'en' ? 'AR' : 'EN'))

const adminInitials = computed(() => {
  const name = auth.user?.name || 'Admin'
  return name.split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase() || 'A'
})
const shortName = computed(() => (auth.user?.name || 'Admin').split(' ')[0])

// route name → sidebar nav key, reused for the breadcrumb title
const routeToNavKey = {
  'admin-dashboard': 'dashboard', 'admin-users': 'users', 'admin-partners': 'partners',
  'admin-requests': 'approvals', 'admin-request-detail': 'approvals', 'admin-units': 'units',
  'admin-bookings': 'bookings', 'admin-cancellations': 'cancellations', 'admin-reports': 'reports',
  'admin-notifications': 'notifications', 'admin-profile': 'profile', 'admin-settings': 'profile',
}
const pageTitle = computed(() => t(`nav.${routeToNavKey[route.name] || 'dashboard'}`))

function onDocClick(e) {
  if (menuOpen.value && menuRoot.value && !menuRoot.value.contains(e.target)) menuOpen.value = false
}
function onKeydown(e) {
  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault()
    searchInput.value?.focus()
  }
}

async function handleLogout() {
  menuOpen.value = false
  await auth.logout()
  router.replace({ name: 'admin-login' })
}

onMounted(() => {
  document.addEventListener('click', onDocClick)
  document.addEventListener('keydown', onKeydown)
})
onBeforeUnmount(() => {
  document.removeEventListener('click', onDocClick)
  document.removeEventListener('keydown', onKeydown)
})
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.15s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
