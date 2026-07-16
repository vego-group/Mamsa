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
      <div class="flex items-center gap-3">
        <NotificationBell variant="mobile" />
        <div class="w-8 h-8 rounded-full bg-secondary-container flex items-center justify-center text-primary font-bold text-sm">
          {{ adminInitials }}
        </div>
      </div>
    </header>

    <!-- Sidebar overlay (mobile) -->
    <div
      v-if="sidebarOpen"
      class="fixed inset-0 bg-black/50 z-50 lg:hidden"
      @click="sidebarOpen = false"
    />

    <!-- Sidebar -->
    <AdminSidebar
      :open="sidebarOpen"
      @close="sidebarOpen = false"
    />

    <!-- Main content -->
    <main class="lg:mr-[260px] min-h-screen flex flex-col">
      <!-- Desktop top bar -->
      <header class="hidden lg:flex sticky top-0 z-40 bg-white border-b border-outline-variant shadow-sm flex-row-reverse justify-between items-center px-gutter py-4">
        <div class="flex items-center gap-4">
          <NotificationBell variant="desktop" />
          <button class="w-10 h-10 flex items-center justify-center rounded-full hover:bg-surface-container transition-colors">
            <span class="material-symbols-outlined text-on-surface-variant">settings</span>
          </button>
          <div class="h-8 w-px bg-outline-variant mx-2" />
          <div class="flex items-center gap-3">
            <div class="text-right">
              <p class="text-body-sm font-bold text-on-surface leading-none">{{ auth.user?.name || 'المدير' }}</p>
              <p class="text-[11px] text-on-surface-variant">مدير النظام</p>
            </div>
            <div class="w-9 h-9 rounded-full bg-secondary-container flex items-center justify-center text-primary font-bold text-sm">
              {{ adminInitials }}
            </div>
          </div>
        </div>
        <div class="flex items-center gap-4 flex-1 max-w-xl pr-12">
          <div class="relative w-full">
            <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-on-surface-variant">search</span>
            <input
              class="w-full pr-12 pl-4 py-2 bg-surface-container-low border border-outline-variant rounded-full text-body-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition-all"
              placeholder="البحث في المنصة..."
              type="text"
            />
          </div>
        </div>
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
import AdminSidebar from '@/components/admin/AdminSidebar.vue'
import NotificationBell from '@/components/NotificationBell.vue'

const auth = useAuthStore()
const route = useRoute()
const sidebarOpen = ref(false)

const adminInitials = computed(() => {
  const name = auth.user?.name || ''
  return name.split(' ').slice(0, 2).map(w => w[0]).join('') || 'م'
})

const pageTitles = {
  'admin-dashboard': 'لوحة التحكم',
  'admin-users':     'إدارة المستخدمين',
  'admin-units':     'إدارة الوحدات',
  'admin-bookings':  'الحجوزات',
  'admin-requests':  'الطلبات',
  'admin-request-detail': 'تفاصيل الطلب',
  'admin-partners':  'طلبات الشركاء',
  'admin-reports':   'التقارير',
  'admin-settings':  'الحساب',
}

const pageTitle = computed(() => pageTitles[route.name] || 'لوحة التحكم')
</script>
