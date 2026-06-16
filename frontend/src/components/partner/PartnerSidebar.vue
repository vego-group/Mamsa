<template>
  <aside
    class="fixed right-0 top-0 h-full w-[260px] bg-primary flex flex-col z-[60] transition-transform duration-300"
    :class="open ? 'translate-x-0' : 'translate-x-full lg:translate-x-0'"
  >
    <!-- Brand -->
    <div class="p-6 flex items-center gap-3 border-b border-white/10">
      <div class="w-10 h-10 bg-white/10 rounded-xl flex items-center justify-center flex-shrink-0">
        <span class="material-symbols-outlined text-on-primary-fixed text-2xl">apartment</span>
      </div>
      <div>
        <div class="font-display-lg text-[20px] text-on-primary-fixed leading-tight">ممسى</div>
        <div class="text-[11px] text-secondary-fixed-dim opacity-70">لوحة الشريك</div>
      </div>
      <button class="lg:hidden mr-auto p-1 hover:bg-white/10 rounded" @click="$emit('close')">
        <span class="material-symbols-outlined text-on-primary-fixed">close</span>
      </button>
    </div>

    <!-- Nav -->
    <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-1">
      <RouterLink
        v-for="item in navItems"
        :key="item.name"
        :to="{ name: item.name }"
        class="flex items-center gap-3 px-4 py-3 rounded-lg font-title-sm text-title-sm transition-all"
        :class="isActive(item.name)
          ? 'bg-white/10 text-on-primary font-bold border-r-4 border-secondary-fixed-dim'
          : 'text-secondary-fixed-dim hover:text-on-primary hover:bg-white/5'"
        @click="$emit('close')"
      >
        <span
          class="material-symbols-outlined"
          :style="isActive(item.name) ? `font-variation-settings: 'FILL' 1` : ''"
        >{{ item.icon }}</span>
        <span>{{ item.label }}</span>
      </RouterLink>
    </nav>

    <!-- Footer -->
    <div class="p-4 border-t border-white/10">
      <div class="flex items-center gap-3 px-4 py-2 mb-2">
        <span class="material-symbols-outlined text-secondary-fixed-dim">
          {{ isCompany ? 'business' : 'person' }}
        </span>
        <div class="min-w-0">
          <p class="text-body-sm text-on-primary font-bold leading-none truncate">{{ auth.user?.name || 'شريك' }}</p>
          <p class="text-[11px] text-secondary-fixed-dim opacity-70">{{ isCompany ? 'شركة' : 'فرد' }}</p>
        </div>
      </div>
      <button
        class="w-full flex items-center justify-center gap-2 py-2.5 px-4 rounded-xl border border-white/20 hover:bg-white/10 text-on-primary transition-all font-bold text-body-sm"
        @click="handleLogout"
      >
        <span class="material-symbols-outlined text-[18px]">logout</span>
        تسجيل الخروج
      </button>
    </div>
  </aside>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

defineProps({ open: Boolean })
defineEmits(['close'])

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const isCompany = computed(() => auth.user?.roles?.some((r) => r === 'Company'))

const navItems = [
  { name: 'partner-dashboard', icon: 'home',           label: 'الرئيسية' },
  { name: 'partner-units',     icon: 'apartment',      label: 'وحداتي' },
  { name: 'partner-bookings',  icon: 'calendar_today', label: 'الحجوزات' },
  { name: 'partner-profile',   icon: 'person',         label: 'الملف الشخصي' },
]

function isActive(name) {
  if (route.name === name) return true
  // Keep "وحداتي" highlighted while creating/editing a unit
  if (name === 'partner-units') return route.name?.startsWith('partner-unit')
  return false
}

async function handleLogout() {
  await auth.logout()
  router.replace({ name: 'login' })
}
</script>
