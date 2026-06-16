<template>
  <div class="min-h-screen bg-[#F7F7F4] text-on-surface" dir="rtl">
    <!-- Mobile top bar -->
    <header class="lg:hidden sticky top-0 z-50 bg-primary text-on-primary flex flex-row-reverse justify-between items-center px-4 py-4 shadow-sm">
      <div class="flex items-center gap-3">
        <button class="p-2 hover:bg-white/10 rounded-lg transition-colors" @click="sidebarOpen = true">
          <span class="material-symbols-outlined">menu</span>
        </button>
        <span class="font-title-sm text-title-sm">{{ pageTitle }}</span>
      </div>
      <div class="w-8 h-8 rounded-full bg-secondary-container flex items-center justify-center text-primary font-bold text-sm">
        {{ initials }}
      </div>
    </header>

    <!-- Mobile overlay -->
    <div v-if="sidebarOpen" class="fixed inset-0 bg-black/50 z-50 lg:hidden" @click="sidebarOpen = false" />

    <PartnerSidebar :open="sidebarOpen" @close="sidebarOpen = false" />

    <!-- Main -->
    <main class="lg:mr-[260px] min-h-screen flex flex-col">
      <!-- Desktop top bar -->
      <header class="hidden lg:flex sticky top-0 z-40 bg-white border-b border-outline-variant shadow-sm flex-row-reverse justify-between items-center px-gutter py-4">
        <div class="flex items-center gap-3">
          <div class="text-right">
            <p class="text-body-sm font-bold text-on-surface leading-none">{{ auth.user?.name || 'شريك' }}</p>
            <p class="text-[11px] text-on-surface-variant">{{ isCompany ? 'شركة' : 'فرد' }}</p>
          </div>
          <div class="w-9 h-9 rounded-full bg-secondary-container flex items-center justify-center text-primary font-bold text-sm">
            {{ initials }}
          </div>
        </div>
        <h2 class="font-headline-md text-headline-md text-primary">{{ pageTitle }}</h2>
      </header>

      <div class="flex-1 p-4 lg:p-gutter">
        <slot />
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import PartnerSidebar from '@/components/partner/PartnerSidebar.vue'

const auth = useAuthStore()
const route = useRoute()
const sidebarOpen = ref(false)

const isCompany = computed(() => auth.user?.roles?.some((r) => r === 'Company'))

const initials = computed(() => {
  const name = auth.user?.name || ''
  return name.split(' ').slice(0, 2).map((w) => w[0]).join('') || 'ش'
})

const pageTitles = {
  'partner-dashboard': 'لوحة التحكم',
  'partner-units':     'وحداتي',
  'partner-unit-form': 'وحدة جديدة',
  'partner-unit-edit': 'تعديل وحدة',
  'partner-bookings':  'الحجوزات',
  'partner-profile':   'الملف الشخصي',
}

const pageTitle = computed(() => pageTitles[route.name] || 'لوحة التحكم')
</script>
