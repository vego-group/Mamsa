<template>
  <div class="min-h-screen bg-[#F7F7F4]" dir="rtl">
    <PublicHeader />

    <!-- Hero -->
    <section class="bg-primary text-on-primary">
      <div class="max-w-6xl mx-auto px-4 py-14 text-center">
        <h1 class="font-display-lg text-[36px] sm:text-[44px] leading-tight mb-3">
          احجز وحدتك المثالية في السعودية
        </h1>
        <p class="text-on-primary/80 text-body-md mb-8 max-w-xl mx-auto">
          شقق، استوديوهات، وفلل فاخرة في أبرز المدن — احجز بسهولة وأمان
        </p>

        <!-- Search bar -->
        <div class="bg-white rounded-2xl p-3 flex flex-col sm:flex-row gap-2 max-w-3xl mx-auto shadow-lg">
          <div class="relative flex-1">
            <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 text-on-surface-variant">location_on</span>
            <select v-model="filters.city" class="w-full h-12 pr-10 pl-3 rounded-xl bg-surface-container-low border-none text-on-surface text-body-md focus:ring-2 focus:ring-primary/20 outline-none">
              <option value="">كل المدن</option>
              <option v-for="c in cities" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>
          <div class="relative flex-1">
            <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 text-on-surface-variant">home_work</span>
            <select v-model="filters.type" class="w-full h-12 pr-10 pl-3 rounded-xl bg-surface-container-low border-none text-on-surface text-body-md focus:ring-2 focus:ring-primary/20 outline-none">
              <option value="">كل الأنواع</option>
              <option value="apartment">شقة</option>
              <option value="studio">استوديو</option>
              <option value="villa">فيلا</option>
            </select>
          </div>
          <button class="h-12 px-8 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors flex items-center justify-center gap-2" @click="search">
            <span class="material-symbols-outlined text-[20px]">search</span>
            بحث
          </button>
        </div>
      </div>
    </section>

    <!-- Listing -->
    <section class="max-w-6xl mx-auto px-4 py-10">
      <div class="flex items-center justify-between mb-6">
        <h2 class="font-headline-md text-headline-md text-primary">الوحدات المتاحة</h2>
        <span v-if="!loading" class="text-body-sm text-on-surface-variant">{{ units.length }} وحدة</span>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
        <div v-for="i in 6" :key="i" class="bg-white rounded-2xl border border-outline-variant overflow-hidden animate-pulse">
          <div class="h-48 bg-surface-container"></div>
          <div class="p-4 space-y-3">
            <div class="h-4 bg-surface-container rounded w-3/4"></div>
            <div class="h-3 bg-surface-container rounded w-1/2"></div>
          </div>
        </div>
      </div>

      <!-- Empty -->
      <div v-else-if="units.length === 0" class="text-center py-16 text-on-surface-variant">
        <span class="material-symbols-outlined text-5xl mb-3 block">search_off</span>
        <p class="font-title-sm text-title-sm">لا توجد وحدات مطابقة لبحثك</p>
      </div>

      <!-- Grid -->
      <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
        <RouterLink
          v-for="unit in units"
          :key="unit.id"
          :to="{ name: 'unit-detail', params: { id: unit.id } }"
          class="bg-white rounded-2xl border border-outline-variant overflow-hidden hover:shadow-card transition-all group"
        >
          <div class="relative h-48 bg-surface-container overflow-hidden">
            <img
              v-if="mainImage(unit)"
              :src="mainImage(unit)"
              :alt="unit.name"
              class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
              loading="lazy"
            />
            <div v-else class="w-full h-full flex items-center justify-center">
              <span class="material-symbols-outlined text-4xl text-on-surface-variant">apartment</span>
            </div>
            <span class="absolute top-3 right-3 px-3 py-1 rounded-full bg-white/90 text-primary text-[12px] font-bold">
              {{ typeLabel(unit.type) }}
            </span>
          </div>
          <div class="p-4">
            <h3 class="font-title-sm text-title-sm text-on-surface mb-1 truncate">{{ unit.name }}</h3>
            <div class="flex items-center gap-1.5 text-on-surface-variant mb-3">
              <span class="material-symbols-outlined text-[16px]">location_on</span>
              <span class="text-body-sm">{{ unit.city }}{{ unit.district ? ` - ${unit.district}` : '' }}</span>
            </div>
            <div class="flex items-center justify-between">
              <div>
                <span class="font-bold text-primary text-title-sm">{{ formatMoney(unit.price) }}</span>
                <span class="text-on-surface-variant text-body-sm"> ر.س / ليلة</span>
              </div>
              <div v-if="unit.avg_rating" class="flex items-center gap-1 text-amber-500">
                <span class="material-symbols-outlined text-[16px]" style="font-variation-settings:'FILL' 1">star</span>
                <span class="text-body-sm font-bold text-on-surface">{{ unit.avg_rating }}</span>
              </div>
            </div>
            <div class="flex items-center gap-4 mt-3 pt-3 border-t border-outline-variant/50 text-on-surface-variant text-body-sm">
              <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">group</span>{{ unit.capacity }}</span>
              <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">bed</span>{{ unit.bedrooms }}</span>
            </div>
          </div>
        </RouterLink>
      </div>
    </section>

    <footer class="border-t border-outline-variant py-8 text-center text-on-surface-variant text-body-sm">
      © 2026 ممسى — جميع الحقوق محفوظة
    </footer>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import PublicHeader from '@/components/public/PublicHeader.vue'
import { publicApi } from '@/api/public'

const loading = ref(true)
const units = ref([])
const cities = ['الرياض', 'جدة', 'مكة المكرمة', 'الدمام']

const filters = reactive({ city: '', type: '' })

function mainImage(unit) {
  const imgs = unit.images || []
  return (imgs.find((i) => i.is_main) || imgs[0])?.url || null
}
function formatMoney(v) {
  return new Intl.NumberFormat('en-US').format(Number(v) || 0)
}
function typeLabel(t) {
  return { apartment: 'شقة', studio: 'استوديو', villa: 'فيلا' }[t] || t
}

async function load() {
  loading.value = true
  try {
    const params = {}
    if (filters.city) params.city = filters.city
    if (filters.type) params.type = filters.type
    const { data } = await publicApi.listUnits(params)
    units.value = data.data ?? data ?? []
  } catch (e) {
    units.value = []
  } finally {
    loading.value = false
  }
}

function search() {
  load()
}

onMounted(load)
</script>
