<template>
  <div class="min-h-screen bg-[#F7F7F4]" dir="rtl">
    <PublicHeader />

    <div class="max-w-6xl mx-auto px-4 py-8">
      <div class="mb-6">
        <h1 class="font-display-lg text-display-lg text-primary mb-1">مختاراتك المفضلة</h1>
        <p class="text-on-surface-variant text-body-md">الوحدات التي أضفتها إلى قائمتك المفضلة</p>
      </div>

      <AccountNav />

      <!-- Loading -->
      <div v-if="loading" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div v-for="i in 4" :key="i" class="bg-white rounded-2xl border border-outline-variant overflow-hidden animate-pulse">
          <div class="h-40 bg-surface-container"></div>
          <div class="p-4 space-y-3">
            <div class="h-4 bg-surface-container rounded w-3/4"></div>
            <div class="h-3 bg-surface-container rounded w-1/2"></div>
          </div>
        </div>
      </div>

      <!-- Empty -->
      <div v-else-if="units.length === 0" class="text-center py-20 text-on-surface-variant">
        <span class="material-symbols-outlined text-5xl mb-3 block">favorite</span>
        <p class="font-title-sm text-title-sm mb-2">لا توجد وحدات مفضلة بعد</p>
        <p class="text-body-sm mb-6">اضغط على أيقونة القلب في أي وحدة لإضافتها هنا</p>
        <RouterLink :to="{ name: 'explore' }" class="inline-flex items-center gap-2 px-6 py-3 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors">
          اكتشف الوحدات
        </RouterLink>
      </div>

      <!-- Grid -->
      <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <UnitRailCard
          v-for="unit in units"
          :key="unit.id"
          :unit="unit"
          fluid
          :favorited="favoriteIds.has(unit.id)"
          @favorite="unfavorite(unit.id)"
        />
      </div>
    </div>

    <PublicFooter />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import PublicHeader from '@/components/public/PublicHeader.vue'
import PublicFooter from '@/components/public/PublicFooter.vue'
import AccountNav from '@/components/user/AccountNav.vue'
import UnitRailCard from '@/components/public/UnitRailCard.vue'
import { userApi } from '@/api/user'
import { useFavorites } from '@/composables/useFavorites'

const { favoriteIds, toggle } = useFavorites()

const loading = ref(true)
const units = ref([])

function unfavorite(unitId) {
  toggle(unitId)
  // Remove from this page immediately — the heart has no "off" state here.
  units.value = units.value.filter((u) => u.id !== unitId)
}

onMounted(async () => {
  try {
    const { data } = await userApi.favorites()
    units.value = data.data ?? data ?? []
    favoriteIds.value = new Set(units.value.map((u) => u.id))
  } catch {
    units.value = []
  }
  loading.value = false
})
</script>
