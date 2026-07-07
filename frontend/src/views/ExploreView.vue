<template>
  <div class="min-h-screen bg-[#F7F7F4]" dir="rtl">
    <PublicHeader />

    <!-- Hero (no search — explore-focused) -->
    <section class="relative">
      <div class="absolute inset-0 bg-primary">
        <img
          src="/decor/hero-explore.jpg"
          alt=""
          class="w-full h-full object-cover opacity-40"
          loading="eager"
        />
        <div class="absolute inset-0 bg-gradient-to-t from-primary/95 via-primary/75 to-primary/55"></div>
      </div>

      <div class="relative max-w-6xl mx-auto px-4 py-20 sm:py-28 text-center text-on-primary">
        <h1 class="font-display-lg text-[28px] sm:text-[40px] leading-[1.3] font-bold max-w-3xl mx-auto">
          استكشف الوحدات المميزة في أجمل<br class="hidden sm:block" />
          المدن والوجهات السياحية
        </h1>
        <p class="text-on-primary/85 text-body-md max-w-2xl mx-auto mt-4">
          تصفّح حسب نوع الإقامة، الميزانية، أو الموقع — واعثر على وجهتك القادمة
        </p>
      </div>
    </section>

    <!-- Category grid (في كل مكان لك بيت يناسبك) -->
    <section class="max-w-6xl mx-auto px-4 pt-12">
      <div class="text-center mb-8">
        <h2 class="font-headline-md text-headline-md text-primary">في كل مكان لك بيت يناسبك</h2>
        <p class="text-body-sm text-on-surface-variant mt-2">اختر نوع الإقامة الذي يناسب رحلتك القادمة</p>
      </div>

      <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
        <button
          v-for="d in destinations"
          :key="d.key"
          class="relative h-52 sm:h-56 rounded-2xl overflow-hidden group text-right"
          @click="selectCategory(d)"
        >
          <img :src="d.img" :alt="d.label" class="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" loading="lazy" />
          <div class="absolute inset-0 bg-gradient-to-t from-black/75 via-black/25 to-transparent"></div>
          <span class="absolute top-4 right-4 grid w-10 h-10 place-items-center rounded-xl bg-white/90 text-primary">
            <span class="material-symbols-outlined text-[22px]">{{ d.icon }}</span>
          </span>
          <div class="absolute bottom-0 inset-x-0 p-5 text-white">
            <p class="font-title-sm text-title-sm leading-tight">{{ d.label }}</p>
            <p class="text-[12px] text-white/80 font-numeric-data mt-0.5">{{ formatMoney(d.count) }} وحدة</p>
          </div>
        </button>
      </div>
    </section>

    <!-- Booked-in picks (اختيارات ممسى) -->
    <section v-if="popularLoading || popular.length" class="max-w-6xl mx-auto px-4 pt-14">
      <div class="flex items-start justify-between gap-4 mb-6">
        <div class="text-right">
          <h2 class="font-headline-md text-headline-md text-primary">اختيارات ممسى</h2>
          <p class="text-body-sm text-on-surface-variant mt-1">وحدات راقية موصى بها من فريقنا</p>
        </div>
        <RouterLink :to="{ name: 'search' }" class="flex items-center gap-1.5 px-4 py-2 rounded-full border border-outline-variant text-body-sm font-bold text-on-surface hover:bg-surface-container transition-colors">
          عرض الكل
          <span class="material-symbols-outlined text-[18px]">chevron_left</span>
        </RouterLink>
      </div>

      <div v-if="popularLoading" class="flex gap-4 overflow-hidden">
        <div v-for="i in 4" :key="i" class="w-[260px] shrink-0 bg-white rounded-2xl border border-outline-variant overflow-hidden animate-pulse">
          <div class="h-40 bg-surface-container"></div>
          <div class="p-4 space-y-3"><div class="h-4 bg-surface-container rounded w-3/4"></div><div class="h-3 bg-surface-container rounded w-1/2"></div></div>
        </div>
      </div>

      <div v-else class="flex gap-4 overflow-x-auto pb-2 snap-x snap-mandatory -mx-4 px-4">
        <UnitRailCard v-for="unit in popular" :key="unit.id" :unit="unit" :favorited="favorites.has(unit.id)" @favorite="toggleFavorite(unit.id)" />
      </div>
    </section>

    <!-- By budget (حسب الميزانية) -->
    <section v-if="budgets.length" class="max-w-6xl mx-auto px-4 pt-14">
      <div class="flex items-start justify-between gap-4 mb-6">
        <div class="text-right">
          <h2 class="font-headline-md text-headline-md text-primary">حسب الميزانية</h2>
          <p class="text-body-sm text-on-surface-variant mt-1">أسعار تناسب احتياجاتك</p>
        </div>
        <RouterLink :to="{ name: 'search' }" class="flex items-center gap-1.5 px-4 py-2 rounded-full border border-outline-variant text-body-sm font-bold text-on-surface hover:bg-surface-container transition-colors">
          عرض الكل
          <span class="material-symbols-outlined text-[18px]">chevron_left</span>
        </RouterLink>
      </div>

      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <button
          v-for="b in budgets"
          :key="b.key"
          class="relative h-64 rounded-2xl overflow-hidden group text-right"
          @click="selectBudget(b)"
        >
          <img :src="b.image_url" :alt="b.label" class="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" loading="lazy" />
          <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/25 to-transparent"></div>
          <div class="absolute bottom-0 inset-x-0 p-5 text-white">
            <p class="font-title-sm text-title-sm mb-0.5">{{ b.label }}</p>
            <p class="text-[12px] text-white/80 font-numeric-data">{{ formatMoney(b.count) }} وحدة متاحة</p>
          </div>
        </button>
      </div>
    </section>

    <!-- Picks for you (مختارات لك) -->
    <section class="max-w-6xl mx-auto px-4 pt-14">
      <div class="flex items-start justify-between gap-4 mb-5">
        <div class="text-right">
          <h2 class="font-headline-md text-headline-md text-primary">مختارات لك</h2>
          <p class="text-body-sm text-on-surface-variant mt-1">وحدات مختارة بعناية حسب اهتماماتك</p>
        </div>
        <RouterLink :to="{ name: 'search' }" class="flex items-center gap-1.5 px-4 py-2 rounded-full border border-outline-variant text-body-sm font-bold text-on-surface hover:bg-surface-container transition-colors">
          عرض الكل
          <span class="material-symbols-outlined text-[18px]">chevron_left</span>
        </RouterLink>
      </div>

      <div class="flex items-center flex-wrap gap-2 mb-5">
        <button
          v-for="cat in categories"
          :key="cat.value"
          class="px-4 py-2 rounded-full text-body-sm font-bold border transition-colors"
          :class="picksCategory === cat.value
            ? 'bg-primary text-on-primary border-primary'
            : 'bg-white text-on-surface-variant border-outline-variant hover:border-primary hover:text-primary'"
          @click="setPicksCategory(cat.value)"
        >
          {{ cat.label }}
        </button>
      </div>

      <div v-if="picksLoading" class="flex gap-4 overflow-hidden">
        <div v-for="i in 4" :key="i" class="w-[260px] shrink-0 bg-white rounded-2xl border border-outline-variant overflow-hidden animate-pulse">
          <div class="h-40 bg-surface-container"></div>
          <div class="p-4 space-y-3"><div class="h-4 bg-surface-container rounded w-3/4"></div><div class="h-3 bg-surface-container rounded w-1/2"></div></div>
        </div>
      </div>
      <div v-else-if="picks.length" class="flex gap-4 overflow-x-auto pb-2 snap-x snap-mandatory -mx-4 px-4">
        <UnitRailCard v-for="unit in picks" :key="unit.id" :unit="unit" :favorited="favorites.has(unit.id)" @favorite="toggleFavorite(unit.id)" />
      </div>
      <div v-else class="text-center py-10 text-on-surface-variant text-body-sm">لا توجد وحدات في هذه الفئة حالياً</div>
    </section>

    <!-- Search by location (البحث حسب الموقع) — map + side list -->
    <section v-if="cities.length || mapList.length" class="max-w-6xl mx-auto px-4 pt-14 pb-12">
      <div class="text-right mb-6">
        <h2 class="font-headline-md text-headline-md text-primary">البحث حسب الموقع</h2>
        <p class="text-body-sm text-on-surface-variant mt-1">استكشف الوحدات على الخريطة واختر أقرب وجهة إليك</p>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <!-- Map (right, wide) -->
        <div class="lg:col-span-2 relative rounded-2xl overflow-hidden border border-outline-variant min-h-[380px] order-1">
          <img
            src="/decor/location.jpg"
            alt="خريطة الوحدات"
            class="absolute inset-0 w-full h-full object-cover"
            loading="lazy"
          />
          <div class="absolute inset-0 bg-primary/10"></div>
          <!-- Price pins -->
          <span
            v-for="(pin, i) in mapPins"
            :key="i"
            class="absolute -translate-x-1/2 -translate-y-1/2 px-2.5 py-1 rounded-full bg-white text-primary text-[12px] font-bold font-numeric-data shadow-card whitespace-nowrap hover:bg-primary hover:text-on-primary transition-colors cursor-default"
            :style="{ top: pin.top, left: pin.left }"
          >
            {{ pin.label }}
          </span>
        </div>

        <!-- Side list (left, narrow) -->
        <div class="space-y-3 order-2">
          <RouterLink
            v-for="unit in mapList"
            :key="unit.id"
            :to="{ name: 'unit-detail', params: { id: unit.id } }"
            class="flex items-center gap-3 bg-white rounded-xl border border-outline-variant p-2.5 hover:shadow-card hover:border-primary/30 transition-all group"
          >
            <div class="w-20 h-16 shrink-0 rounded-lg overflow-hidden bg-surface-container">
              <img v-if="mainImage(unit)" :src="mainImage(unit)" :alt="unit.name" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" loading="lazy" />
              <div v-else class="w-full h-full grid place-items-center"><span class="material-symbols-outlined text-on-surface-variant">apartment</span></div>
            </div>
            <div class="min-w-0 flex-1 text-right">
              <p class="text-body-sm font-bold text-on-surface truncate">{{ unit.name }}</p>
              <p class="flex items-center justify-end gap-1 text-[12px] text-on-surface-variant mt-0.5">
                <span class="truncate">{{ unit.city }}</span>
                <span class="material-symbols-outlined text-[14px]">location_on</span>
              </p>
              <p class="mt-1">
                <span class="font-bold text-primary text-body-sm font-numeric-data">{{ formatMoney(unit.price) }}</span>
                <span class="text-[11px] text-on-surface-variant"> ر.س / ليلة</span>
              </p>
            </div>
          </RouterLink>
        </div>
      </div>
    </section>

    <PublicFooter />
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import PublicHeader from '@/components/public/PublicHeader.vue'
import PublicFooter from '@/components/public/PublicFooter.vue'
import UnitRailCard from '@/components/public/UnitRailCard.vue'
import { publicApi } from '@/api/public'
import { useFavorites } from '@/composables/useFavorites'

const router = useRouter()

const destinations = ref([])
const popular = ref([])
const popularLoading = ref(true)
const budgets = ref([])
const cities = ref([])
// Shared, API-backed favorites (persisted for logged-in users).
const { favoriteIds: favorites, load: loadFavorites, toggle: toggleFav } = useFavorites()

// مختارات لك — chip-filtered curated rail.
const picks = ref([])
const picksLoading = ref(true)
const picksCategory = ref('chalet')

// Side list shown next to the location map.
const mapList = ref([])

// Decorative price pins positioned over the static world map.
const mapPins = [
  { label: '1,250', top: '32%', left: '54%' },
  { label: '980',   top: '46%', left: '38%' },
  { label: '2,400', top: '60%', left: '62%' },
  { label: '650',   top: '38%', left: '72%' },
  { label: '1,800', top: '66%', left: '30%' },
]

// Hero quick-filter chips → backend category keys (shared with home).
const categories = [
  { value: 'chalet',    label: 'شاليهات' },
  { value: 'apartment', label: 'شقق فندقية' },
  { value: 'resort',    label: 'منتجعات صحية' },
  { value: 'rest',      label: 'إستراحات' },
]

// Category + budget imagery comes from the API (image_url) — the same bundled
// default asset served from storage; no external/hardcoded images.

function mainImage(unit) {
  const imgs = unit.images || []
  return (imgs.find((i) => i.is_main) || imgs[0])?.url || null
}
function formatMoney(v) {
  return new Intl.NumberFormat('en-US').format(Number(v) || 0)
}
function toggleFavorite(id) {
  // Guests are sent to login — hearts only persist for authenticated users.
  if (!toggleFav(id)) router.push({ name: 'login' })
}

// Catalogue cards lead to the filtered search-results page.
function selectCategory(d) {
  router.push({ name: 'search', query: { category: d.key } })
}
function selectBudget(b) {
  const query = {}
  if (b.min != null) query.min_price = b.min
  if (b.max != null) query.max_price = b.max
  router.push({ name: 'search', query })
}

async function loadCategories() {
  try {
    const { data } = await publicApi.categories()
    destinations.value = (data.data ?? data ?? []).map((c) => ({
      ...c,
      img: c.image_url, // bundled default served from storage (API-provided)
    }))
  } catch (e) {
    destinations.value = []
  }
}

async function loadPopular() {
  popularLoading.value = true
  try {
    const { data } = await publicApi.popularUnits()
    popular.value = data.data ?? data ?? []
  } catch (e) {
    popular.value = []
  } finally {
    popularLoading.value = false
  }
}

async function loadBudgets() {
  try {
    const { data } = await publicApi.budgets()
    budgets.value = data.data ?? data ?? []
  } catch (e) {
    budgets.value = []
  }
}

async function loadCities() {
  try {
    const { data } = await publicApi.cities()
    cities.value = data.data ?? data ?? []
  } catch (e) {
    cities.value = []
  }
}

async function loadPicks() {
  picksLoading.value = true
  try {
    const { data } = await publicApi.listUnits({ category: picksCategory.value, capacity: 2 })
    picks.value = (data.data ?? data ?? []).slice(0, 8)
  } catch (e) {
    picks.value = []
  } finally {
    picksLoading.value = false
  }
}
function setPicksCategory(value) {
  if (picksCategory.value === value) return
  picksCategory.value = value
  loadPicks()
}

async function loadMapList() {
  try {
    const { data } = await publicApi.listUnits({ capacity: 2 })
    mapList.value = (data.data ?? data ?? []).slice(0, 5)
  } catch (e) {
    mapList.value = []
  }
}

onMounted(() => {
  loadFavorites()
  loadCategories()
  loadPopular()
  loadBudgets()
  loadCities()
  loadPicks()
  loadMapList()
})
</script>
