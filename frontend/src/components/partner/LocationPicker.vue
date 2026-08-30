<template>
  <div>
    <div class="flex items-center justify-between mb-2">
      <label class="block text-body-sm font-bold text-on-surface">موقع الوحدة على الخريطة</label>
      <button
        v-if="modelLat && modelLng"
        type="button"
        class="text-[12px] font-bold text-error hover:underline"
        @click="clear"
      >
        مسح الموقع
      </button>
    </div>

    <div ref="mapEl" class="h-72 rounded-2xl overflow-hidden border border-outline-variant bg-surface-container" />

    <p class="text-[11px] text-on-surface-variant mt-1.5">
      اضغط على الخريطة أو اسحب العلامة لتحديد الموقع بدقة.
    </p>

    <!-- The numbers stay visible and editable: a partner who already has exact
         coordinates should not have to hunt for the spot on a map. -->
    <div class="grid grid-cols-2 gap-3 mt-3">
      <div>
        <label class="block text-[12px] font-bold text-on-surface-variant mb-1">خط العرض</label>
        <input :value="modelLat" type="number" step="any" dir="ltr" class="field" placeholder="24.7136"
               @input="onNumber('lat', $event.target.value)" />
      </div>
      <div>
        <label class="block text-[12px] font-bold text-on-surface-variant mb-1">خط الطول</label>
        <input :value="modelLng" type="number" step="any" dir="ltr" class="field" placeholder="46.6753"
               @input="onNumber('lng', $event.target.value)" />
      </div>
    </div>

    <p v-if="outsideSaudi" class="text-[12px] text-error font-bold mt-2 flex items-center gap-1">
      <span class="material-symbols-outlined text-[15px]">error</span>
      الموقع خارج حدود المملكة — سيُرفض عند الإرسال للمراجعة.
    </p>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

const props = defineProps({
  modelLat: { type: [Number, String, null], default: null },
  modelLng: { type: [Number, String, null], default: null },
})
const emit = defineEmits(['update:modelLat', 'update:modelLng'])

// Riyadh — only ever the STARTING view for a unit with no location yet. It is
// never emitted, so an untouched map cannot silently place a unit here.
const FALLBACK = [24.7136, 46.6753]

// The same box the backend enforces (Maps::insideSaudi). Warning only: the
// server is the authority, and a client-side block would be a second rule to
// keep in sync.
const BOUNDS = { latMin: 16.0, latMax: 32.2, lngMin: 34.4, lngMax: 55.7 }

const mapEl = ref(null)
let map = null
let marker = null

const hasPoint = computed(() => props.modelLat != null && props.modelLng != null
  && props.modelLat !== '' && props.modelLng !== '')

const outsideSaudi = computed(() => {
  if (!hasPoint.value) return false
  const lat = Number(props.modelLat)
  const lng = Number(props.modelLng)
  if (Number.isNaN(lat) || Number.isNaN(lng)) return false
  return lat < BOUNDS.latMin || lat > BOUNDS.latMax || lng < BOUNDS.lngMin || lng > BOUNDS.lngMax
})

function setPoint(lat, lng) {
  // 6 decimals ≈ 0.11 m. Beyond that is noise, and the raw float from a click
  // makes the number fields unreadable.
  emit('update:modelLat', Number(lat.toFixed(6)))
  emit('update:modelLng', Number(lng.toFixed(6)))
}

function onNumber(which, value) {
  const n = value === '' ? null : Number(value)
  emit(which === 'lat' ? 'update:modelLat' : 'update:modelLng', Number.isNaN(n) ? null : n)
}

function clear() {
  emit('update:modelLat', null)
  emit('update:modelLng', null)
}

function drawMarker() {
  if (!map) return

  if (!hasPoint.value) {
    if (marker) { marker.remove(); marker = null }
    return
  }

  const pos = [Number(props.modelLat), Number(props.modelLng)]
  if (Number.isNaN(pos[0]) || Number.isNaN(pos[1])) return

  if (marker) {
    marker.setLatLng(pos)
  } else {
    marker = L.marker(pos, { draggable: true }).addTo(map)
    marker.on('dragend', () => {
      const { lat, lng } = marker.getLatLng()
      setPoint(lat, lng)
    })
  }
}

onMounted(async () => {
  await nextTick()

  const start = hasPoint.value ? [Number(props.modelLat), Number(props.modelLng)] : FALLBACK

  map = L.map(mapEl.value).setView(start, hasPoint.value ? 15 : 5)

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap',
    maxZoom: 19,
  }).addTo(map)

  map.on('click', (e) => setPoint(e.latlng.lat, e.latlng.lng))

  drawMarker()

  // The container is often still 0×0 on mount (inside a section that has just
  // rendered), which leaves Leaflet showing a single grey tile until a resize.
  setTimeout(() => map && map.invalidateSize(), 200)
})

onBeforeUnmount(() => {
  if (map) { map.remove(); map = null; marker = null }
})

watch(() => [props.modelLat, props.modelLng], () => {
  drawMarker()
  if (map && hasPoint.value && marker) {
    const pos = marker.getLatLng()
    if (!map.getBounds().contains(pos)) map.panTo(pos)
  }
})
</script>
