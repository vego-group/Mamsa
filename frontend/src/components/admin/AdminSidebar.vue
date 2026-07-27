<template>
  <!-- Desktop sidebar (always visible) + mobile drawer. Dark-green rail, sectioned nav. -->
  <aside
    class="fixed top-0 bottom-0 w-[240px] bg-primary flex flex-col z-[60] transition-transform duration-300"
    :class="[
      dir === 'rtl' ? 'right-0' : 'left-0',
      open ? 'translate-x-0' : (dir === 'rtl' ? 'translate-x-full lg:translate-x-0' : '-translate-x-full lg:translate-x-0'),
    ]"
  >
    <!-- Brand -->
    <div class="h-[60px] px-4 flex items-center gap-3 border-b border-white/10">
      <div class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center flex-shrink-0">
        <span class="text-on-primary font-bold text-[15px] leading-none">M</span>
      </div>
      <div class="min-w-0 flex-1">
        <div class="text-on-primary font-bold text-[15px] leading-tight truncate">{{ t('brand.title') }}</div>
        <div class="text-[10px] uppercase tracking-wider text-white/45 leading-tight">{{ t('brand.subtitle') }}</div>
      </div>
      <button class="lg:hidden p-1 hover:bg-white/10 rounded text-white/70" @click="$emit('close')">
        <span class="material-symbols-outlined text-[20px]">close</span>
      </button>
    </div>

    <!-- Nav -->
    <nav class="flex-1 overflow-y-auto py-4 px-3">
      <div v-for="section in sections" :key="section.key" class="mb-5 last:mb-0">
        <p class="px-3 mb-1.5 text-[10px] font-bold uppercase tracking-[0.08em] text-white/35">
          {{ t(`nav.${section.key}`) }}
        </p>
        <RouterLink
          v-for="item in section.items"
          :key="item.name"
          :to="{ name: item.name }"
          class="group flex items-center gap-3 px-3 h-[38px] rounded-lg text-[13.5px] mb-0.5 transition-colors"
          :class="isActive(item.name)
            ? 'bg-white/12 text-on-primary font-semibold'
            : 'text-white/60 hover:text-on-primary hover:bg-white/[0.06]'"
          @click="$emit('close')"
        >
          <span
            class="material-symbols-outlined text-[20px]"
            :style="isActive(item.name) ? `font-variation-settings: 'FILL' 1` : ''"
          >{{ item.icon }}</span>
          <span class="flex-1 truncate">{{ t(`nav.${item.key}`) }}</span>
          <span
            v-if="item.badge && badgeValue(item.badge) > 0"
            class="min-w-[20px] h-5 px-1.5 flex items-center justify-center rounded-full bg-amber-500 text-[11px] font-bold text-[#1a1206]"
          >{{ badgeValue(item.badge) > 99 ? '99+' : badgeValue(item.badge) }}</span>
        </RouterLink>
      </div>
    </nav>

    <!-- Footer: admin identity + logout -->
    <div class="p-3 border-t border-white/10">
      <div class="flex items-center gap-3 px-2 py-1.5">
        <div class="w-9 h-9 rounded-full bg-white/12 flex items-center justify-center text-on-primary font-bold text-[13px] flex-shrink-0">
          {{ adminInitials }}
        </div>
        <div class="min-w-0 flex-1">
          <p class="text-[13px] text-on-primary font-semibold leading-tight truncate">{{ auth.user?.name || t('header.role') }}</p>
          <p class="text-[11px] text-white/45 leading-tight truncate">{{ auth.user?.email || 'admin@mamsa.sa' }}</p>
        </div>
        <button
          class="p-2 rounded-lg text-white/55 hover:text-on-primary hover:bg-white/10 transition-colors flex-shrink-0"
          :title="t('header.logout')"
          @click="handleLogout"
        >
          <span class="material-symbols-outlined text-[20px]">logout</span>
        </button>
      </div>
    </div>
  </aside>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useAdminI18n } from '@/i18n/admin'
import { useAdminBadges } from '@/composables/useAdminBadges'

defineProps({ open: Boolean })
defineEmits(['close'])

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const { t, dir } = useAdminI18n()
const { approvals, notifications, load } = useAdminBadges()

// Sections mirror the Figma sidebar groups. `key` drives the i18n label + icon.
const sections = [
  { key: 'core', items: [
    { key: 'dashboard', name: 'admin-dashboard', icon: 'grid_view' },
    { key: 'users',     name: 'admin-users',     icon: 'group' },
    { key: 'partners',  name: 'admin-partners',  icon: 'handshake' },
    { key: 'approvals', name: 'admin-requests',  icon: 'fact_check', badge: 'approvals' },
  ] },
  { key: 'operations', items: [
    { key: 'units',         name: 'admin-units',         icon: 'apartment' },
    { key: 'bookings',      name: 'admin-bookings',      icon: 'calendar_month' },
    { key: 'cancellations', name: 'admin-cancellations', icon: 'cancel' },
  ] },
  { key: 'insights', items: [
    { key: 'reports',       name: 'admin-reports',       icon: 'bar_chart' },
    { key: 'notifications', name: 'admin-notifications', icon: 'notifications', badge: 'notifications' },
  ] },
  { key: 'account', items: [
    { key: 'profile', name: 'admin-profile', icon: 'person' },
  ] },
]

const adminInitials = computed(() => {
  const name = auth.user?.name || 'Admin'
  return name.split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase() || 'A'
})

function badgeValue(key) {
  return key === 'approvals' ? approvals.value : notifications.value
}

function isActive(name) {
  if (route.name === name) return true
  // request detail lives under the Approvals item
  if (name === 'admin-requests' && route.name === 'admin-request-detail') return true
  return false
}

async function handleLogout() {
  await auth.logout()
  router.replace({ name: 'admin-login' })
}

onMounted(() => load())
</script>
