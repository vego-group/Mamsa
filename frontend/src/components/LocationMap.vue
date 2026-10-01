<template>
  <div>
    <div v-if="hasPoint" ref="mapEl" :class="heightClass" class="rounded-xl overflow-hidden border border-outline-variant bg-surface-container" />

    <!-- No coordinates is not an empty state to hide — a listing cannot be
         submitted without a location, so a reviewer seeing this is looking at
         something that should not have reached them. Say so. -->
    <div v-else :class="heightClass" class="bg-gray-50 rounded-xl flex flex-col items-center justify-center text-center px-4">
      <span class="material-symbols-outlined text-[28px] text-gray-400">location_off</span>
      <p v-if="place" class="text-[13px] font-semibold text-gray-600 mt-1">{{ place }}</p>
      <p class="text-[11px] text-amber-700 font-semibold mt-1">لم يحدّد الشريك موقعاً على الخريطة</p>
    </div>

    <div v-if="hasPoint" class="flex items-center justify-between mt-1.5">
      <p class="text-[11px] text-gray-400">{{ place }}</p>
      <a :href="externalUrl" target="_blank" rel="noopener"
         class="text-[11px] font-bold text-primary hover:underline inline-flex items-center gap-1">
        <span class="material-symbols-outlined text-[13px]">open_in_new</span>
        فتح في خرائط
      </a>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

const props = defineProps({
  lat: { type: [Number, String, null], default: null },
  lng: { type: [Number, String, null], default: null },
  place: { type: String, default: '' },
  heightClass: { type: String, default: 'h-56' },
})

const mapEl = ref(null)
let map = null
let marker = null

const hasPoint = computed(() => {
  const a = Number(props.lat)
  const b = Number(props.lng)
  return props.lat != null && props.lng != null && props.lat !== '' && props.lng !== ''
    && !Number.isNaN(a) && !Number.isNaN(b)
})

const externalUrl = computed(
  () => `https://www.openstreetmap.org/?mlat=${Number(props.lat)}&mlon=${Number(props.lng)}#map=16/${Number(props.lat)}/${Number(props.lng)}`,
)

async function build() {
  if (!hasPoint.value) return
  await nextTick()
  if (!mapEl.value || map) return

  const pos = [Number(props.lat), Number(props.lng)]
  map = L.map(mapEl.value, {
    // Read-only: this is a review surface, and a reviewer scrolling the page
    // should not have the map swallow the wheel.
    scrollWheelZoom: false,
  }).setView(pos, 15)

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap',
    maxZoom: 19,
  }).addTo(map)

  marker = L.marker(pos).addTo(map)

  // The container is commonly 0×0 on mount inside a tab that has just become
  // visible, which leaves Leaflet painting one grey tile until something resizes.
  setTimeout(() => map && map.invalidateSize(), 200)
}

onMounted(build)

onBeforeUnmount(() => {
  if (map) { map.remove(); map = null; marker = null }
})

watch(() => [props.lat, props.lng], async () => {
  if (!hasPoint.value) {
    if (map) { map.remove(); map = null; marker = null }
    return
  }
  if (!map) { await build(); return }
  const pos = [Number(props.lat), Number(props.lng)]
  marker.setLatLng(pos)
  map.setView(pos, 15)
})
</script>
