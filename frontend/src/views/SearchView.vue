<template>
  <div class="min-h-screen bg-[#F7F7F4]" dir="rtl">
    <PublicHeader />

    <div class="max-w-6xl mx-auto px-4 py-8">
      <!-- Results header -->
      <div class="flex items-start justify-between gap-4 mb-6">
        <div class="text-right">
          <h1 class="font-headline-md text-headline-md text-primary">نتائج البحث</h1>
          <p class="text-body-sm text-on-surface-variant mt-1">
            <span v-if="filters.capacity">{{ filters.capacity }} ضيف · </span>
            <span class="font-numeric-data">{{ visible.length }}</span> وحدة متاحة
          </p>
        </div>
        <button
          class="lg:hidden flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white border border-outline-variant text-body-sm font-bold text-on-surface"
          @click="showFilters = !showFilters"
        >
          <span class="material-symbols-outlined text-[20px]">tune</span>
          الفلاتر
        </button>
      </div>

      <div class="grid lg:grid-cols-[300px_1fr] gap-6">
        <!-- Filters sidebar (right in RTL) -->
        <aside :class="showFilters ? 'block' : 'hidden'" class="lg:block">
          <div class="bg-white rounded-2xl border border-outline-variant p-5 lg:sticky lg:top-20">
            <div class="flex items-center justify-between mb-5">
              <h2 class="font-title-sm text-title-sm text-primary">الفلاتر</h2>
              <button class="text-[12px] font-bold text-on-surface-variant hover:text-primary transition-colors" @click="resetFilters">إعادة تعيين</button>
            </div>

            <!-- Price -->
            <div class="pb-5 border-b border-outline-variant/60">
              <label class="block text-body-sm font-bold text-on-surface mb-3">السعر (ر.س / ليلة)</label>
              <!-- Decorative histogram -->
              <div class="flex items-end gap-0.5 h-12 mb-3" aria-hidden="true">
                <span v-for="(h, i) in histogram" :key="i" class="flex-1 rounded-sm bg-primary/20" :style="{ height: h + '%' }"></span>
              </div>
              <div class="grid grid-cols-2 gap-2">
                <div class="relative">
                  <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-[11px] text-on-surface-variant">من</span>
                  <input
                    v-model.number="filters.minPrice"
                    type="number" min="0" placeholder="0"
                    class="w-full h-11 ps-3 pe-9 rounded-lg bg-surface-container-low border border-transparent text-on-surface text-body-sm font-numeric-data text-left outline-none focus:border-primary focus:ring-2 focus:ring-primary/15 transition"
                    dir="ltr"
                  />
                </div>
                <div class="relative">
                  <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-[11px] text-on-surface-variant">إلى</span>
                  <input
                    v-model.number="filters.maxPrice"
                    type="number" min="0" placeholder="∞"
                    class="w-full h-11 ps-3 pe-9 rounded-lg bg-surface-container-low border border-transparent text-on-surface text-body-sm font-numeric-data text-left outline-none focus:border-primary focus:ring-2 focus:ring-primary/15 transition"
                    dir="ltr"
                  />
                </div>
              </div>
            </div>

            <!-- Rating -->
            <div class="py-5 border-b border-outline-variant/60">
              <h3 class="text-body-sm font-bold text-on-surface mb-3">التقييم</h3>
              <div class="space-y-2">
                <label v-for="r in [5, 4, 3, 2, 1]" :key="r" class="flex items-center justify-end gap-2 cursor-pointer group">
                  <span class="text-body-sm text-on-surface-variant group-hover:text-on-surface">فأكثر</span>
                  <span class="flex items-center">
                    <span v-for="s in 5" :key="s" class="material-symbols-outlined text-[16px]" :class="s <= r ? 'text-amber-500' : 'text-outline-variant'" style="font-variation-settings:'FILL' 1">star</span>
                  </span>
                  <input type="radio" name="rating" :value="r" v-model.number="filters.rating" class="accent-primary w-4 h-4" />
                </label>
              </div>
            </div>

            <!-- Unit type -->
            <div class="py-5 border-b border-outline-variant/60">
              <h3 class="text-body-sm font-bold text-on-surface mb-3">نوع الوحدة</h3>
              <div class="space-y-2">
                <label v-for="t in unitTypes" :key="t.value" class="flex items-center justify-end gap-2 cursor-pointer group">
                  <span class="text-body-sm text-on-surface-variant group-hover:text-on-surface">{{ t.label }}</span>
                  <input type="radio" name="utype" :value="t.value" v-model="filters.category" class="accent-primary w-4 h-4" />
                </label>
              </div>
            </div>

            <!-- Amenities -->
            <div class="pt-5">
              <h3 class="text-body-sm font-bold text-on-surface mb-3">المرافق</h3>
              <div class="space-y-2">
                <label v-for="a in amenities" :key="a" class="flex items-center justify-end gap-2 cursor-pointer group">
                  <span class="text-body-sm text-on-surface-variant group-hover:text-on-surface">{{ a }}</span>
                  <input type="checkbox" :value="a" v-model="filters.amenities" class="accent-primary w-4 h-4 rounded" />
                </label>
              </div>
            </div>
          </div>
        </aside>

        <!-- Results list (left in RTL) -->
        <section>
          <!-- Loading -->
          <div v-if="loading" class="space-y-5">
            <div v-for="i in 4" :key="i" class="bg-white rounded-2xl border border-outline-variant overflow-hidden flex animate-pulse h-48">
              <div class="flex-1 p-5 space-y-3">
                <div class="h-4 bg-surface-container rounded w-2/3 ms-auto"></div>
                <div class="h-3 bg-surface-container rounded w-1/2 ms-auto"></div>
                <div class="h-3 bg-surface-container rounded w-1/3 ms-auto"></div>
              </div>
              <div class="w-64 bg-surface-container"></div>
            </div>
          </div>

          <!-- Empty -->
          <div v-else-if="visible.length === 0" class="bg-white rounded-2xl border border-outline-variant text-center py-20 text-on-surface-variant">
            <span class="material-symbols-outlined text-5xl mb-3 block">search_off</span>
            <p class="font-title-sm text-title-sm">لا توجد وحدات مطابقة لبحثك</p>
            <button class="mt-4 px-5 py-2.5 rounded-xl bg-primary text-on-primary text-body-sm font-bold hover:bg-primary-container transition-colors" @click="resetFilters">إعادة ضبط الفلاتر</button>
          </div>

          <!-- Cards -->
          <div v-else class="space-y-5">
            <article
              v-for="unit in visible"
              :key="unit.id"
              class="bg-white rounded-2xl border border-outline-variant overflow-hidden flex flex-col sm:flex-row hover:shadow-card transition-shadow"
            >
              <!-- Details (right in RTL) -->
              <div class="flex-1 p-5 text-right flex flex-col order-2 sm:order-1">
                <div class="flex items-center justify-end gap-1 mb-1.5">
                  <span class="text-[12px] text-on-surface-variant">({{ unit.reviews_count || 0 }})</span>
                  <span class="text-body-sm font-bold text-on-surface">{{ unit.avg_rating || '—' }}</span>
                  <span class="material-symbols-outlined text-[16px] text-amber-500" style="font-variation-settings:'FILL' 1">star</span>
                </div>

                <h3 class="font-title-sm text-title-sm text-on-surface mb-1">{{ unit.name }}</h3>
                <p class="text-body-sm text-on-surface-variant mb-3">{{ unit.city }}، المملكة العربية السعودية</p>

                <div class="flex items-center justify-end flex-wrap gap-x-3 gap-y-1 text-on-surface-variant text-[12px] mb-3">
                  <span class="flex items-center gap-1">{{ unit.bathrooms || 1 }} حمامات<span class="material-symbols-outlined text-[15px]">bathtub</span></span>
                  <span class="text-outline-variant">·</span>
                  <span class="flex items-center gap-1">{{ unit.bedrooms }} غرف نوم<span class="material-symbols-outlined text-[15px]">bed</span></span>
                  <span class="text-outline-variant">·</span>
                  <span class="flex items-center gap-1">{{ unit.capacity }} ضيوف<span class="material-symbols-outlined text-[15px]">group</span></span>
                </div>

                <div class="flex items-center justify-end flex-wrap gap-2 mb-4">
                  <span v-for="f in (unit.features || []).slice(0, 4)" :key="f" class="px-2.5 py-1 rounded-full bg-surface-container text-[11px] font-bold text-on-surface-variant">{{ f }}</span>
                </div>

                <div class="mt-auto flex items-center justify-between gap-3">
                  <RouterLink
                    :to="{ name: 'unit-detail', params: { id: unit.id } }"
                    class="px-5 py-2.5 rounded-xl bg-primary text-on-primary text-body-sm font-bold hover:bg-primary-container transition-colors"
                  >
                    عرض التفاصيل
                  </RouterLink>
                  <div>
                    <span class="font-bold text-primary text-title-sm font-numeric-data">{{ formatMoney(unit.price) }}</span>
                    <span class="text-on-surface-variant text-[12px]"> ر.س / ليلة</span>
                  </div>
                </div>
              </div>

              <!-- Image (left in RTL) -->
              <div class="relative sm:w-64 lg:w-72 h-52 sm:h-auto shrink-0 bg-surface-container order-1 sm:order-2">
                <img v-if="mainImage(unit)" :src="mainImage(unit)" :alt="unit.name" class="w-full h-full object-cover" loading="lazy" />
                <div v-else class="w-full h-full grid place-items-center"><span class="material-symbols-outlined text-4xl text-on-surface-variant">apartment</span></div>
                <button
                  class="absolute top-3 left-3 grid w-8 h-8 place-items-center rounded-full bg-white/90 hover:bg-white transition-colors"
                  :class="favorites.has(unit.id) ? 'text-error' : 'text-on-surface-variant'"
                  @click="toggleFavorite(unit.id)"
                >
                  <span class="material-symbols-outlined text-[18px]" :style="favorites.has(unit.id) ? `font-variation-settings:'FILL' 1` : ''">favorite</span>
                </button>
              </div>
            </article>
          </div>
        </section>
      </div>
    </div>

    <PublicFooter />
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import PublicHeader from '@/components/public/PublicHeader.vue'
import PublicFooter from '@/components/public/PublicFooter.vue'
import { publicApi } from '@/api/public'

const route = useRoute()

const loading = ref(true)
const results = ref([])
const favorites = ref(new Set())
const showFilters = ref(false)

const unitTypes = [
  { value: '',          label: 'الكل' },
  { value: 'villa',     label: 'فيلا' },
  { value: 'chalet',    label: 'شاليه' },
  { value: 'apartment', label: 'شقة' },
  { value: 'resort',    label: 'منتجع' },
]

// Amenity values must match seeded Feature names — sent verbatim as `features[]`.
const amenities = ['واي فاي', 'مسبح', 'مطبخ', 'موقف سيارات', 'مكيف', 'حديقة']

// Decorative price-distribution bars.
const histogram = [20, 35, 28, 50, 65, 80, 95, 70, 60, 85, 75, 55, 90, 65, 45, 60, 40, 50, 35, 25, 45, 30, 20, 15]

const filters = reactive({
  q: '',
  city: '',
  category: '',     // unit-type radio (maps to backend `category`)
  capacity: null,
  minPrice: null,
  maxPrice: null,
  rating: null,     // server-side: min_rating
  amenities: [],    // server-side: features[]
})

function mainImage(unit) {
  const imgs = unit.images || []
  return (imgs.find((i) => i.is_main) || imgs[0])?.url || null
}
function formatMoney(v) {
  return new Intl.NumberFormat('en-US').format(Number(v) || 0)
}
function toggleFavorite(id) {
  const next = new Set(favorites.value)
  next.has(id) ? next.delete(id) : next.add(id)
  favorites.value = next
}

// All filtering is server-side now (min_rating + features[]), so the visible
// list is just the API results. Kept as a computed to keep the template stable.
const visible = computed(() => results.value)

async function load() {
  loading.value = true
  try {
    const params = {}
    if (filters.q) params.q = filters.q
    if (filters.city) params.city = filters.city
    if (filters.category) params.category = filters.category
    if (filters.capacity) params.capacity = filters.capacity
    if (filters.minPrice != null && filters.minPrice !== '') params.min_price = filters.minPrice
    if (filters.maxPrice != null && filters.maxPrice !== '') params.max_price = filters.maxPrice
    if (filters.rating) params.min_rating = filters.rating
    if (filters.amenities.length) params.features = filters.amenities
    const { data } = await publicApi.listUnits(params)
    results.value = data.data ?? data ?? []
  } catch (e) {
    results.value = []
  } finally {
    loading.value = false
  }
}

function resetFilters() {
  filters.q = ''
  filters.city = ''
  filters.category = ''
  filters.capacity = null
  filters.minPrice = null
  filters.maxPrice = null
  filters.rating = null
  filters.amenities = []
}

// Every filter is server-side; reload on change (debounced for the price inputs).
let debounce
watch(
  () => [
    filters.q, filters.city, filters.category, filters.capacity,
    filters.minPrice, filters.maxPrice, filters.rating, [...filters.amenities],
  ],
  () => {
    clearTimeout(debounce)
    debounce = setTimeout(load, 300)
  }
)

// Seed filters from the incoming query (e.g. coming from Explore cards).
function applyQuery() {
  const q = route.query
  if (q.q) filters.q = String(q.q)
  if (q.city) filters.city = String(q.city)
  if (q.category) filters.category = String(q.category)
  if (q.capacity) filters.capacity = Number(q.capacity)
  if (q.min_price != null) filters.minPrice = Number(q.min_price)
  if (q.max_price != null) filters.maxPrice = Number(q.max_price)
}

onMounted(() => {
  applyQuery()
  load()
})
</script>
