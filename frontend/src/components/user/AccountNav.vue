<template>
  <div class="flex gap-1 border-b border-outline-variant mb-6">
    <RouterLink
      v-for="tab in tabs"
      :key="tab.name"
      :to="{ name: tab.name }"
      class="px-5 py-3 font-title-sm text-[15px] transition-all border-b-4 -mb-px flex items-center gap-2"
      :class="route.name === tab.name
        ? 'text-primary border-primary'
        : 'text-on-surface-variant border-transparent hover:text-primary'"
    >
      <span class="material-symbols-outlined text-[18px]">{{ tab.icon }}</span>
      {{ tab.label }}
    </RouterLink>
  </div>
</template>

<script setup>
import { useRoute } from 'vue-router'

const route = useRoute()

const tabs = [
  { name: 'account',           icon: 'calendar_today',         label: 'حجوزاتي' },
  { name: 'account-favorites', icon: 'favorite',               label: 'المفضلة' },
  { name: 'account-wallet',    icon: 'account_balance_wallet', label: 'المحفظة' },
  { name: 'account-profile',   icon: 'person',                 label: 'الملف الشخصي' },
]
</script>
