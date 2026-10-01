<template>
  <AdminLayout>
    <!-- Header -->
    <div class="flex items-center gap-3 mb-6">
      <RouterLink :to="{ name: 'admin-requests' }" class="inline-flex items-center gap-1 text-[14px] font-semibold text-gray-500 hover:text-gray-800">
        <span class="material-symbols-outlined text-[20px]">{{ dir === 'rtl' ? 'arrow_forward' : 'arrow_back' }}</span>{{ t('approvals.back') }}
      </RouterLink>
      <span class="text-gray-300">/</span>
      <span class="text-[14px] font-bold text-gray-900 tabular-nums">{{ unit?.code || `APR-${routeId}` }}</span>
      <span v-if="unit" class="inline-flex items-center gap-1 text-[13px] font-semibold" :class="priorityMeta.text">
        <span class="w-1.5 h-1.5 rounded-full" :class="priorityMeta.dot"></span>{{ priorityMeta.label }}
      </span>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-24 text-gray-400">
      <span class="material-symbols-outlined animate-spin text-3xl">progress_activity</span>
    </div>

    <div v-else-if="!unit" class="py-16 text-center text-gray-400 text-[13px] bg-white rounded-2xl border border-gray-200">{{ t('approvals.notFound') }}</div>

    <div v-else class="grid grid-cols-1 lg:grid-cols-[340px_1fr] gap-4 pb-24">
      <!-- Left column -->
      <div class="space-y-4">
        <!-- Pricing -->
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
          <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400 mb-2">{{ t('approvals.pricing') }}</p>
          <p class="text-[26px] font-bold text-gray-900 leading-none tabular-nums">{{ int(unit.price) }} <span class="text-[13px] font-normal text-gray-400">{{ t('approvals.perNight') }}</span></p>
          <p class="text-[12px] text-gray-400 mt-1">{{ t('approvals.basedOnGuests', { n: unit.capacity }) }}</p>
        </div>

        <!-- Partner -->
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
          <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400 mb-3">{{ t('approvals.partner') }}</p>
          <div class="flex items-center gap-3 mb-3">
            <div class="w-10 h-10 rounded-full bg-primary/85 flex items-center justify-center text-white font-bold text-[13px]">{{ partnerInitials }}</div>
            <div class="min-w-0">
              <p class="text-[14px] font-bold text-gray-900 truncate">{{ partner.name }}</p>
              <p class="text-[12px] text-gray-400">{{ partner.type === 'company' ? t('approvals.company') : t('approvals.individual') }} · {{ partner.city }}</p>
            </div>
          </div>
          <div class="flex items-center gap-3 text-[12px] mb-3">
            <span v-if="partner.is_verified" class="inline-flex items-center gap-1 text-emerald-600 font-semibold"><span class="material-symbols-outlined text-[15px]">verified</span>{{ t('approvals.verified') }}</span>
            <span v-if="partner.rating != null" class="inline-flex items-center gap-0.5 text-amber-500 font-semibold"><span class="material-symbols-outlined text-[15px]" style="font-variation-settings:'FILL' 1">star</span>{{ partner.rating }}</span>
          </div>
          <div class="space-y-2 text-[13px] border-t border-gray-100 pt-3">
            <div class="flex items-center justify-between"><span class="text-gray-400">{{ t('approvals.submitted') }}</span><span class="font-semibold text-gray-800 tabular-nums">{{ date(submittedAt) }}</span></div>
            <div class="flex items-center justify-between"><span class="text-gray-400">{{ t('approvals.priority') }}</span><span class="font-semibold" :class="priorityMeta.text">{{ priorityMeta.short }}</span></div>
          </div>
        </div>

        <!-- Review checklist -->
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
          <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400 mb-3">{{ t('approvals.checklist') }}</p>
          <ul class="space-y-2.5">
            <li v-for="c in checklist" :key="c.key">
              <button class="flex items-center gap-2.5 text-[13px] w-full text-start" @click="c.done = !c.done">
                <span class="material-symbols-outlined text-[20px]" :class="c.done ? 'text-emerald-500' : 'text-gray-300'" :style="c.done ? `font-variation-settings:'FILL' 1` : ''">{{ c.done ? 'check_circle' : 'radio_button_unchecked' }}</span>
                <span :class="c.done ? 'text-gray-400 line-through' : 'text-gray-700'">{{ t(`approvals.${c.key}`) }}</span>
              </button>
            </li>
          </ul>
        </div>
      </div>

      <!-- Right column -->
      <div class="space-y-4">
        <!-- Gallery -->
        <div class="bg-white rounded-2xl border border-gray-200 p-3">
          <div class="relative h-[360px] rounded-xl overflow-hidden bg-gray-100">
            <img :src="images[gallery]?.url" class="w-full h-full object-cover" :alt="unit.name" />
            <span class="absolute top-3 left-3 flex items-center gap-1 px-2 py-1 rounded-md bg-black/50 text-white text-[12px] font-semibold tabular-nums"><span class="material-symbols-outlined text-[14px]">photo_library</span>{{ gallery + 1 }} / {{ images.length }}</span>
            <button v-if="images.length > 1" class="absolute top-1/2 -translate-y-1/2 left-3 w-9 h-9 grid place-items-center rounded-full bg-white/85 hover:bg-white text-gray-700 shadow" @click="prevImg"><span class="material-symbols-outlined text-[20px]">chevron_left</span></button>
            <button v-if="images.length > 1" class="absolute top-1/2 -translate-y-1/2 right-3 w-9 h-9 grid place-items-center rounded-full bg-white/85 hover:bg-white text-gray-700 shadow" @click="nextImg"><span class="material-symbols-outlined text-[20px]">chevron_right</span></button>
          </div>
          <div v-if="images.length > 1" class="flex gap-2 mt-3 overflow-x-auto pb-1">
            <button v-for="(img, i) in images" :key="img.id ?? i" class="w-20 h-16 rounded-lg overflow-hidden flex-shrink-0 border-2 transition-colors" :class="i === gallery ? 'border-primary' : 'border-transparent opacity-70 hover:opacity-100'" @click="gallery = i">
              <img :src="img.url" class="w-full h-full object-cover" />
            </button>
          </div>
        </div>

        <!-- Tabbed info -->
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
          <div class="flex items-center gap-1 bg-gray-50 rounded-lg p-1 mb-5 w-fit">
            <button v-for="tb in tabs" :key="tb.key" class="px-3 py-1.5 rounded-md text-[13px] font-semibold transition-colors" :class="tab === tb.key ? 'bg-white text-primary shadow-sm' : 'text-gray-500 hover:text-gray-700'" @click="tab = tb.key">{{ tb.label }}</button>
          </div>

          <!-- Property Info -->
          <div v-if="tab === 'property'">
            <h3 class="text-[18px] font-bold text-gray-900 text-end">{{ unit.name }}</h3>
            <p class="text-[13px] text-gray-400 flex items-center gap-1 justify-end mb-3"><span class="material-symbols-outlined text-[16px]">location_on</span>{{ [unit.district, unit.city].filter(Boolean).join(', ') }}</p>
            <p v-if="unit.description" class="text-[14px] text-gray-600 leading-relaxed text-end mb-5">{{ unit.description }}</p>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
              <div v-for="tile in tiles" :key="tile.key" class="bg-gray-50 rounded-xl p-3 text-center">
                <span class="material-symbols-outlined text-[20px] text-gray-400 mb-1 inline-block">{{ tile.icon }}</span>
                <p class="text-[15px] font-bold text-gray-900 tabular-nums">{{ tile.value }}</p>
                <p class="text-[11px] text-gray-400">{{ tile.label }}</p>
              </div>
            </div>
            <!-- Was a static placeholder reading "معاينة الخريطة (تتطلب تكاملاً)".
                 A reviewer approving a listing has to see WHERE it is; a pin
                 icon and a district name is not a location. -->
            <LocationMap
              :lat="unit.lat"
              :lng="unit.lng"
              :place="[unit.district, unit.city].filter(Boolean).join(', ')"
              height-class="h-64"
            />
          </div>

          <!-- Amenities -->
          <div v-else-if="tab === 'amenities'">
            <div v-if="amenities.length" class="flex flex-wrap gap-2">
              <span v-for="(a, i) in amenities" :key="i" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-50 border border-gray-100 text-[13px] text-gray-700">
                <span class="material-symbols-outlined text-[16px] text-primary">check</span>{{ a }}
              </span>
            </div>
            <p v-else class="text-[13px] text-gray-400 py-6 text-center">{{ t('approvals.noAmenities') }}</p>
          </div>

          <!-- Documents -->
          <div v-else-if="tab === 'documents'">
            <ul v-if="documents.length" class="space-y-2">
              <li v-for="doc in documents" :key="doc.key" class="flex items-center gap-3 bg-gray-50 rounded-xl px-3 py-3">
                <span class="material-symbols-outlined text-[18px] text-gray-400">description</span>
                <span class="flex-1 text-[13px] text-gray-700">{{ docLabel(doc.key) }}</span>
                  <!-- A reviewer approving a listing needs to OPEN the document,
                       not read a badge that stands in for one. -->
                  <a v-if="doc.fileUrl" :href="doc.fileUrl" target="_blank" rel="noopener"
                     class="text-[12px] font-semibold text-primary hover:underline inline-flex items-center gap-1">
                    <span class="material-symbols-outlined text-[14px]">open_in_new</span>
                    فتح
                  </a>
                  <span class="text-[12px] font-semibold" :class="docMeta(doc.status).cls">{{ docMeta(doc.status).label }}</span>
              </li>
            </ul>
            <p v-else class="text-[13px] text-gray-400 py-6 text-center">{{ t('approvals.noDocuments') }}</p>
          </div>

          <!-- Timeline -->
          <div v-else-if="tab === 'timeline'">
            <ul class="space-y-4">
              <li v-for="(ev, i) in timeline" :key="i" class="flex gap-3">
                <div class="flex flex-col items-center">
                  <span class="w-2.5 h-2.5 rounded-full bg-primary mt-1"></span>
                  <span v-if="i < timeline.length - 1" class="w-px flex-1 bg-gray-200 my-1"></span>
                </div>
                <div class="pb-2">
                  <p class="text-[13px] font-semibold text-gray-800">{{ timelineLabel(ev.type) }}</p>
                  <p class="text-[11px] text-gray-400 tabular-nums">{{ date(ev.date, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) }}</p>
                </div>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- Sticky decision bar -->
    <div v-if="unit && unit.approval_status === 'pending'" class="fixed bottom-0 inset-x-0 lg:pl-[240px] bg-white border-t border-gray-200 z-30" :class="dir === 'rtl' ? 'lg:pr-[240px] lg:pl-0' : ''">
      <div class="px-6 py-3 flex flex-wrap items-center justify-between gap-3">
        <p class="text-[13px] text-gray-400 flex items-center gap-1.5"><span class="material-symbols-outlined text-[18px]">info</span>{{ t('approvals.reviewNote') }}</p>
        <div class="flex items-center gap-2">
          <RouterLink :to="{ name: 'admin-requests' }" class="h-10 px-4 grid place-items-center rounded-lg text-[13px] font-semibold text-gray-600 hover:bg-gray-100">{{ t('approvals.backToList') }}</RouterLink>
          <button class="inline-flex items-center gap-1.5 h-10 px-5 rounded-lg bg-red-600 text-white text-[13px] font-semibold hover:bg-red-700 disabled:opacity-50" :disabled="busy" @click="showReject = true">
            <span class="material-symbols-outlined text-[18px]">cancel</span>{{ t('approvals.reject') }}
          </button>
          <button class="inline-flex items-center gap-1.5 h-10 px-5 rounded-lg bg-emerald-600 text-white text-[13px] font-semibold hover:bg-emerald-700 disabled:opacity-50" :disabled="busy" @click="approve">
            <span class="material-symbols-outlined text-[18px]">check_circle</span>{{ t('approvals.approveListing') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Reject modal -->
    <Teleport to="body">
      <div v-if="showReject" class="fixed inset-0 bg-black/40 z-[80] flex items-center justify-center p-4" :dir="dir">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-6">
          <h3 class="text-[17px] font-bold text-gray-900 mb-3">{{ t('approvals.rejectTitle') }}</h3>
          <label class="block text-[13px] font-semibold text-gray-700 mb-1.5">{{ t('approvals.rejectReason') }}</label>
          <textarea v-model="rejectReason" rows="3" class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl text-[14px] outline-none focus:ring-2 focus:ring-primary/15 focus:border-primary/40" :placeholder="t('approvals.rejectPlaceholder')"></textarea>
          <div class="flex gap-3 mt-4">
            <button class="flex-1 h-11 rounded-xl border border-gray-200 text-[13px] font-semibold text-gray-700 hover:bg-gray-50" @click="showReject = false">{{ t('approvals.cancel') }}</button>
            <button class="flex-1 h-11 rounded-xl bg-red-600 text-white text-[13px] font-semibold hover:bg-red-700 disabled:opacity-50" :disabled="busy || !rejectReason.trim()" @click="reject">{{ t('approvals.reject') }}</button>
          </div>
        </div>
      </div>
    </Teleport>

    <transition name="fade">
      <div v-if="toast" class="fixed bottom-20 left-1/2 -translate-x-1/2 z-[90] px-5 py-3 rounded-xl shadow-lg text-white font-semibold text-[13px]" :class="toast.type === 'error' ? 'bg-red-600' : 'bg-primary'">{{ toast.msg }}</div>
    </transition>
  </AdminLayout>
</template>

<script setup>
import LocationMap from '@/components/LocationMap.vue'
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { adminApi } from '@/api/admin'
import { useAdminI18n } from '@/i18n/admin'
import { useAdminFormat } from '@/composables/useAdminFormat'
import { useAdminBadges } from '@/composables/useAdminBadges'

const route = useRoute()
const router = useRouter()
const { t, dir } = useAdminI18n()
const { int, date } = useAdminFormat()
const { load: reloadBadges } = useAdminBadges()

const routeId = route.params.id
const loading = ref(true)
const busy = ref(false)
const unit = ref(null)
const partner = ref({ name: '—', type: 'individual', city: '', is_verified: false, rating: null, documents: [] })
const submittedAt = ref(null)
const timeline = ref([])
const gallery = ref(0)
const tab = ref('property')
const showReject = ref(false)
const rejectReason = ref('')
const toast = ref(null)

const checklist = ref([
  { key: 'chkPhotos', done: false },
  { key: 'chkDocuments', done: false },
  { key: 'chkPricing', done: false },
  { key: 'chkLocation', done: false },
  { key: 'chkAmenities', done: false },
])

const tabs = computed(() => [
  { key: 'amenities', label: t('approvals.tabAmenities') },
  { key: 'documents', label: t('approvals.tabDocuments') },
  { key: 'timeline', label: t('approvals.tabTimeline') },
  { key: 'property', label: t('approvals.tabProperty') },
])

const images = computed(() => unit.value?.images ?? [])
const documents = computed(() => partner.value?.documents ?? [])
const amenities = computed(() => (unit.value?.amenities ?? unit.value?.features ?? []).map((a) => a?.label || a?.name || a).filter(Boolean))

const tiles = computed(() => [
  { key: 'bed', icon: 'bed', value: unit.value?.bedrooms ?? unit.value?.beds ?? '—', label: t('approvals.bedrooms') },
  { key: 'bath', icon: 'bathtub', value: unit.value?.bathrooms ?? '—', label: t('approvals.bathrooms') },
  { key: 'cap', icon: 'group', value: t('approvals.guests', { n: unit.value?.capacity ?? 0 }), label: t('approvals.capacity') },
  { key: 'area', icon: 'straighten', value: unit.value?.area ? `${unit.value.area} m²` : '—', label: t('approvals.size') },
])

const partnerInitials = computed(() => (partner.value?.name || '?').split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase())

const priorityMeta = computed(() => {
  const days = submittedAt.value ? (Date.now() - new Date(submittedAt.value).getTime()) / 86400000 : 0
  if (days >= 5) return { label: t('approvals.prioHigh'), short: t('approvals.prioHighShort'), text: 'text-red-500', dot: 'bg-red-500' }
  if (days >= 2) return { label: t('approvals.prioNormal'), short: t('approvals.prioNormalShort'), text: 'text-emerald-600', dot: 'bg-emerald-500' }
  return { label: t('approvals.prioLow'), short: t('approvals.prioLowShort'), text: 'text-gray-400', dot: 'bg-gray-400' }
})

const docLabels = { identity: 'docIdentity', bank: 'docBank', ownership: 'docOwnership' }
const docLabel = (k) => t(`approvals.${docLabels[k] || 'docIdentity'}`)
function docMeta(status) {
  if (status === 'verified') return { label: t('approvals.docVerified'), cls: 'text-emerald-600' }
  if (status === 'pending') return { label: t('approvals.docPending'), cls: 'text-amber-600' }
  return { label: t('approvals.docMissing'), cls: 'text-gray-400' }
}
const tlLabels = { submitted: 'tlSubmitted', approved: 'tlApproved', rejected: 'tlRejected' }
const timelineLabel = (ty) => t(`approvals.${tlLabels[ty] || 'tlSubmitted'}`)

function prevImg() { gallery.value = (gallery.value - 1 + images.value.length) % images.value.length }
function nextImg() { gallery.value = (gallery.value + 1) % images.value.length }
function showToast(msg, type = 'success') { toast.value = { msg, type }; setTimeout(() => (toast.value = null), 2400) }

async function load() {
  loading.value = true
  try {
    const { data } = await adminApi.getRequest(routeId)
    const d = data.data ?? data
    unit.value = d.unit?.data ?? d.unit
    partner.value = d.partner ?? partner.value
    submittedAt.value = d.submitted_at
    timeline.value = d.timeline ?? []
  } catch {
    unit.value = null
  } finally {
    loading.value = false
  }
}

async function approve() {
  busy.value = true
  try {
    await adminApi.approveRequest(routeId)
    showToast(t('approvals.approved'))
    reloadBadges(true)
    setTimeout(() => router.push({ name: 'admin-requests' }), 800)
  } catch {
    showToast(t('approvals.actionError'), 'error')
  } finally {
    busy.value = false
  }
}
async function reject() {
  if (!rejectReason.value.trim()) return
  busy.value = true
  try {
    await adminApi.rejectRequest(routeId, rejectReason.value.trim())
    showReject.value = false
    showToast(t('approvals.rejected'))
    reloadBadges(true)
    setTimeout(() => router.push({ name: 'admin-requests' }), 800)
  } catch {
    showToast(t('approvals.actionError'), 'error')
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.25s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
