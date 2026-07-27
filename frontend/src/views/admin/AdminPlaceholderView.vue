<template>
  <AdminLayout>
    <div class="mb-6">
      <h1 class="text-[24px] font-bold text-gray-900">{{ title }}</h1>
      <p class="text-gray-500 text-[14px] mt-0.5">{{ t('common.soon') }}</p>
    </div>

    <div class="bg-white rounded-2xl border border-gray-200 py-20 flex flex-col items-center justify-center text-center">
      <div class="w-14 h-14 rounded-2xl bg-gray-50 border border-gray-200 flex items-center justify-center mb-4">
        <span class="material-symbols-outlined text-[28px] text-gray-400">{{ icon }}</span>
      </div>
      <p class="text-[16px] font-semibold text-gray-700">{{ title }}</p>
      <p class="text-[13px] text-gray-400 mt-1 max-w-xs">{{ t('common.comingSoon') }}</p>
    </div>
  </AdminLayout>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { useAdminI18n } from '@/i18n/admin'

const route = useRoute()
const { t } = useAdminI18n()

const map = {
  'admin-cancellations': { key: 'cancellations', icon: 'cancel' },
  'admin-notifications': { key: 'notifications', icon: 'notifications' },
  'admin-profile':       { key: 'profile',       icon: 'person' },
}
const current = computed(() => map[route.name] || { key: 'dashboard', icon: 'grid_view' })
const title = computed(() => t(`nav.${current.value.key}`))
const icon = computed(() => current.value.icon)
</script>
