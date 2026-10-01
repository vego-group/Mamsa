<template>
  <PartnerLayout>
    <!-- Breadcrumb -->
    <nav class="flex items-center gap-2 text-body-sm text-on-surface-variant mb-6">
      <RouterLink :to="{ name: 'partner-units' }" class="hover:text-primary transition-colors">وحداتي</RouterLink>
      <span class="material-symbols-outlined text-[16px]">chevron_left</span>
      <span class="text-primary font-bold">{{ isEdit ? 'تعديل وحدة' : 'وحدة جديدة' }}</span>
    </nav>

    <div class="mb-6">
      <h1 class="font-display-lg text-display-lg text-primary mb-1">{{ isEdit ? 'تعديل وحدة' : 'إضافة وحدة جديدة' }}</h1>
      <p class="text-on-surface-variant text-body-md">
        {{ isEdit ? 'حدّث بيانات وحدتك' : 'أدخل بيانات وحدتك. ستُحفظ كمسودة حتى تقدّمها للموافقة.' }}
      </p>
    </div>

    <!-- Initial load (edit) -->
    <div v-if="loading" class="flex items-center justify-center py-20 text-on-surface-variant">
      <span class="material-symbols-outlined animate-spin text-3xl">progress_activity</span>
    </div>

    <form v-else class="max-w-3xl space-y-6" @submit.prevent="save">
      <!-- Basic info -->
      <section class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6">
        <h2 class="font-title-sm text-title-sm text-primary mb-5 pb-3 border-b border-outline-variant">البيانات الأساسية</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="sm:col-span-2">
            <label class="block text-body-sm font-bold text-on-surface mb-1.5">اسم الوحدة *</label>
            <input v-model="form.unit_name" class="field" :class="{ 'border-error': errors.unit_name }" placeholder="شقة مودرن في حي الملقا" required />
            <p v-if="errors.unit_name" class="err">{{ errors.unit_name }}</p>
          </div>

          <div>
            <label class="block text-body-sm font-bold text-on-surface mb-1.5">نوع الوحدة *</label>
            <select v-model="form.unit_type" class="field" required>
              <option value="apartment">شقة</option>
              <option value="studio">استوديو</option>
              <option value="villa">فيلا</option>
            </select>
          </div>

          <div>
            <label class="block text-body-sm font-bold text-on-surface mb-1.5">السعر / ليلة (ر.س) *</label>
            <input v-model.number="form.price" type="number" min="1" step="0.01" class="field" :class="{ 'border-error': errors.price }" dir="ltr" required />
            <p v-if="errors.price" class="err">{{ errors.price }}</p>
          </div>

          <div>
            <label class="block text-body-sm font-bold text-on-surface mb-1.5">السعة (أشخاص) *</label>
            <input v-model.number="form.capacity" type="number" min="1" class="field" dir="ltr" required />
          </div>

          <div>
            <label class="block text-body-sm font-bold text-on-surface mb-1.5">عدد الغرف *</label>
            <input v-model.number="form.bedrooms" type="number" min="0" class="field" dir="ltr" required />
          </div>

          <!-- Multi-unit buildings: one listing, many identical apartments.
               Each becomes a separately bookable unit; the site shows ONE card
               with the number still free beside it. -->
          <div>
            <label class="block text-body-sm font-bold text-on-surface mb-1.5">عدد الوحدات المتطابقة</label>
            <input v-model.number="apartmentCount" type="number" min="1" max="100" class="field" dir="ltr" />
            <p class="text-body-sm text-on-surface-variant mt-1">
              لو عندك أكثر من وحدة بنفس المواصفات في نفس المبنى، اكتب العدد هنا.
              هتظهر في الموقع كوحدة واحدة ومعاها عدد المتاح.
            </p>
            <p v-if="unitCountNotice" class="text-body-sm text-primary mt-1">{{ unitCountNotice }}</p>
          </div>
        </div>
      </section>

      <!-- Location -->
      <section class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6">
        <h2 class="font-title-sm text-title-sm text-primary mb-5 pb-3 border-b border-outline-variant">الموقع</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-body-sm font-bold text-on-surface mb-1.5">المدينة *</label>
            <input v-model="form.city" class="field" :class="{ 'border-error': errors.city }" placeholder="الرياض" required />
            <p v-if="errors.city" class="err">{{ errors.city }}</p>
          </div>
          <div>
            <label class="block text-body-sm font-bold text-on-surface mb-1.5">الحي</label>
            <input v-model="form.district" class="field" placeholder="حي الملقا" />
          </div>
        </div>
      </section>

      <!-- Details -->
      <section class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6">
        <h2 class="font-title-sm text-title-sm text-primary mb-5 pb-3 border-b border-outline-variant">تفاصيل إضافية</h2>
        <div class="space-y-4">
          <div>
            <label class="block text-body-sm font-bold text-on-surface mb-1.5">الوصف</label>
            <textarea v-model="form.description" rows="4" class="field resize-none" placeholder="صف وحدتك ومميزاتها..."></textarea>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-body-sm font-bold text-on-surface mb-1.5">وقت الدخول</label>
              <input v-model="form.checkin_time" type="time" class="field" dir="ltr" />
            </div>
            <div>
              <label class="block text-body-sm font-bold text-on-surface mb-1.5">وقت الخروج</label>
              <input v-model="form.checkout_time" type="time" class="field" dir="ltr" />
            </div>
          </div>

          <div>
            <label class="block text-body-sm font-bold text-on-surface mb-1.5">سياسة الإلغاء</label>
            <select v-model="form.cancellation_policy" class="field">
              <option value="no_cancel">غير قابل للإلغاء</option>
              <option value="48_hours">إلغاء مجاني قبل 48 ساعة</option>
            </select>
          </div>

            <!-- Fields the API has always accepted and this form never sent:
                 beds, bathrooms, the map position and the two licence numbers.
                 Without lat/lng a partner could not place their unit on the map
                 at all. -->
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-body-sm font-bold text-on-surface mb-1.5">عدد الأسرّة</label>
                <input v-model.number="form.beds" type="number" min="1" max="20" class="field" dir="rtl" />
              </div>
              <div>
                <label class="block text-body-sm font-bold text-on-surface mb-1.5">دورات المياه</label>
                <input v-model.number="form.bathrooms" type="number" min="1" max="10" class="field" dir="rtl" />
              </div>
            </div>

            <div>
              <label class="block text-body-sm font-bold text-on-surface mb-1.5">العنوان</label>
              <input v-model="form.address" class="field" dir="rtl" placeholder="حي الملقا، طريق الملك عبدالعزيز" />
            </div>

            <LocationPicker v-model:model-lat="form.lat" v-model:model-lng="form.lng" />

            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-body-sm font-bold text-on-surface mb-1.5">رقم رخصة السياحة</label>
                <input v-model="form.tourism_permit_no" class="field" dir="ltr" placeholder="TL-0000000" />
              </div>
              <div>
                <label class="block text-body-sm font-bold text-on-surface mb-1.5">رقم السجل التجاري</label>
                <input v-model="form.company_license_no" class="field" dir="ltr" placeholder="1010000000" />
              </div>
            </div>


          <!-- Features -->
          <div>
            <label class="block text-body-sm font-bold text-on-surface mb-2">المميزات</label>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="feat in availableFeatures"
                :key="feat"
                type="button"
                class="px-3 py-1.5 rounded-lg text-body-sm font-medium border transition-all flex items-center gap-1.5"
                :class="form.features.includes(feat)
                  ? 'bg-primary text-on-primary border-primary'
                  : 'bg-white text-on-surface border-outline-variant hover:bg-surface-container-low'"
                @click="toggleFeature(feat)"
              >
                <span v-if="form.features.includes(feat)" class="material-symbols-outlined text-[14px]">check</span>
                {{ feat }}
              </button>
            </div>
          </div>
        </div>
      </section>

      <!-- Images (edit only — uploads need a saved unit id) -->
      <section v-if="isEdit" class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6">
        <h2 class="font-title-sm text-title-sm text-primary mb-5 pb-3 border-b border-outline-variant">صور الوحدة</h2>

        <div v-if="images.length" class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-4">
          <div
            v-for="img in images"
            :key="img.id"
            class="relative group aspect-[4/3] rounded-xl overflow-hidden border border-outline-variant bg-surface-container-low"
          >
            <img :src="img.url" alt="" class="w-full h-full object-cover" loading="lazy" />
            <span v-if="img.is_main" class="absolute top-2 right-2 bg-primary text-on-primary text-[11px] font-bold px-2 py-0.5 rounded-md">رئيسية</span>
            <div class="absolute inset-x-0 bottom-0 flex opacity-0 group-hover:opacity-100 transition-opacity bg-black/50">
              <button v-if="!img.is_main" type="button" class="flex-1 py-1.5 text-white text-body-sm hover:bg-primary/80" @click="makeMain(img.id)">تعيين كرئيسية</button>
              <button type="button" class="flex-1 py-1.5 text-white text-body-sm hover:bg-error/80" @click="removeImage(img.id)">حذف</button>
            </div>
          </div>
        </div>
        <p v-else class="text-body-sm text-on-surface-variant mb-4">لا توجد صور بعد. يمكنك إضافة حتى 10 صور (jpg, png, webp, avif — حتى 5 ميجابايت للصورة).</p>

        <label class="inline-flex items-center gap-2 px-4 py-2.5 border border-dashed border-outline-variant rounded-xl cursor-pointer hover:bg-surface-container-low transition-colors text-body-sm font-bold text-on-surface" :class="{ 'opacity-50 pointer-events-none': uploading }">
          <span v-if="uploading" class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span>
          <span v-else class="material-symbols-outlined text-[18px]">add_photo_alternate</span>
          {{ uploading ? 'جارٍ الرفع...' : 'إضافة صور' }}
          <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp,image/avif" multiple class="hidden" :disabled="uploading" @change="onFilesSelected" />
        </label>
        <p v-if="imgError" class="err">{{ imgError }}</p>
      </section>

        <!-- Documents (edit only — uploads need a saved unit id, same as photos) -->
        <section v-if="isEdit" class="bg-white rounded-2xl border border-outline-variant p-5 space-y-4">
          <h2 class="font-title-sm text-title-sm text-on-surface">المستندات</h2>

          <div v-for="d in documentTypes" :key="d.type" class="flex items-center justify-between gap-3 py-2 border-b border-outline-variant last:border-0">
            <div class="min-w-0">
              <div class="text-body-sm font-bold text-on-surface">{{ d.label }}</div>
              <div class="text-[11px] text-on-surface-variant">{{ d.hint }}</div>
              <a v-if="docs[d.type]" :href="docs[d.type]" target="_blank" rel="noopener"
                 class="text-[12px] text-primary font-bold hover:underline inline-flex items-center gap-1 mt-1">
                <span class="material-symbols-outlined text-[14px]">description</span>
                عرض المستند الحالي
              </a>
            </div>
            <div class="flex items-center gap-2 shrink-0">
              <label class="inline-flex items-center gap-1.5 px-3 py-2 border border-dashed border-outline-variant rounded-xl cursor-pointer hover:bg-surface-container-low text-body-sm font-bold"
                     :class="{ 'opacity-50 pointer-events-none': docBusy === d.type }">
                <span v-if="docBusy === d.type" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                <span v-else class="material-symbols-outlined text-[16px]">upload_file</span>
                {{ docs[d.type] ? 'استبدال' : 'رفع' }}
                <input type="file" accept="application/pdf,image/jpeg,image/png,image/webp" class="hidden"
                       :disabled="docBusy === d.type" @change="onDocSelected($event, d.type)" />
              </label>
              <button v-if="docs[d.type]" type="button" class="text-error text-[13px] font-bold hover:underline"
                      :disabled="docBusy === d.type" @click="removeDoc(d.type)">حذف</button>
            </div>
          </div>

          <p class="text-[11px] text-on-surface-variant">
            الصيغ المسموحة: PDF أو صورة (jpg / png / webp)، بحد أقصى ١٠ ميجابايت.
          </p>
        </section>


      <!-- New unit: images come after the draft is saved -->
      <section v-else class="bg-surface-container-low rounded-2xl border border-dashed border-outline-variant p-6 text-center text-body-sm text-on-surface-variant">
        احفظ الوحدة كمسودة أولاً، ثم عُد لتعديلها لإضافة الصور.
      </section>

      <!-- Availability calendar (edit only — needs a saved unit id) -->
      <UnitCalendarSection v-if="isEdit" :unit-id="route.params.id" />

      <!-- Actions -->
      <div class="flex flex-col sm:flex-row gap-3">
        <button type="submit" class="flex-1 py-3 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors flex items-center justify-center gap-2 disabled:opacity-50" :disabled="saving">
          <span v-if="saving" class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span>
          <span v-else class="material-symbols-outlined text-[18px]">save</span>
          {{ isEdit ? 'حفظ التعديلات' : 'حفظ كمسودة' }}
        </button>
        <RouterLink :to="{ name: 'partner-units' }" class="flex-1 py-3 border border-outline-variant rounded-xl font-bold text-on-surface text-center hover:bg-surface-container transition-colors">
          إلغاء
        </RouterLink>
      </div>

      <p v-if="errors.general" class="text-error text-body-sm text-center">{{ errors.general }}</p>
    </form>

    <!-- Toast -->
    <Transition name="fade">
      <div v-if="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 px-5 py-3 rounded-xl shadow-lg text-white font-bold text-body-sm" :class="toast.type === 'error' ? 'bg-error' : 'bg-primary'">
        {{ toast.msg }}
      </div>
    </Transition>
  </PartnerLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PartnerLayout from '@/layouts/PartnerLayout.vue'
import { partnerApi } from '@/api/partner'
import LocationPicker from '@/components/partner/LocationPicker.vue'
import UnitCalendarSection from '@/components/partner/UnitCalendarSection.vue'

const route = useRoute()
const router = useRouter()

const isEdit = computed(() => !!route.params.id)
const loading = ref(false)
const saving = ref(false)
const errors = ref({})
const toast = ref(null)

// Unit gallery
const images = ref([])
const uploading = ref(false)
const imgError = ref('')
const fileInput = ref(null)

const availableFeatures = ['واي فاي', 'مسبح', 'موقف سيارات', 'مطبخ', 'مكيف', 'غسالة', 'شاشة ذكية', 'مصعد', 'حديقة', 'شواء']

const form = ref({
  unit_name: '',
  unit_type: 'apartment',
  price: null,
  capacity: 1,
  bedrooms: 1,
  city: '',
  district: '',
  description: '',
  checkin_time: '15:00',
  checkout_time: '12:00',
  beds: null,
  bathrooms: null,
  address: '',
  lat: null,
  lng: null,
  tourism_permit_no: '',
  company_license_no: '',
  cancellation_policy: 'no_cancel',
  features: [],
})

const documentTypes = [
  { type: 'tourism_permit', label: 'رخصة السياحة', hint: 'مطلوبة لإرسال الوحدة للمراجعة' },
  { type: 'ownership_doc', label: 'مستند ملكية العقار', hint: 'صك الملكية أو عقد الإيجار — اختياري حالياً' },
  // Stored on the PARTNER, not this unit: one bank account serves every listing
  // they own. Uploading it here updates it for all of them.
  { type: 'bank_certificate', label: 'توثيق الحساب البنكي', hint: 'خطاب الآيبان من البنك — يخص حسابك، ويسري على كل وحداتك' },
]
const docs = ref({ tourism_permit: null, ownership_doc: null, bank_certificate: null })

// How many identical apartments this listing stands for. 1 = an ordinary unit.
const apartmentCount = ref(1)
const unitCountNotice = ref('')
const docBusy = ref(null)

async function onDocSelected(e, type) {
  const file = e.target.files?.[0]
  e.target.value = '' // allow re-selecting the same file after a failure
  if (!file) return

  docBusy.value = type
  try {
    const { data } = await partnerApi.uploadUnitDocument(route.params.id, type, file)
    docs.value[type] = data.data?.url ?? null
    showToast('تم رفع المستند')
  } catch (err) {
    showToast(err.response?.data?.message || 'تعذّر رفع المستند', 'error')
  } finally {
    docBusy.value = null
  }
}

async function removeDoc(type) {
  docBusy.value = type
  try {
    await partnerApi.deleteUnitDocument(route.params.id, type)
    docs.value[type] = null
    showToast('تم حذف المستند')
  } catch (err) {
    showToast(err.response?.data?.message || 'تعذّر حذف المستند', 'error')
  } finally {
    docBusy.value = null
  }
}


function showToast(msg, type = 'success') {
  toast.value = { msg, type }
  setTimeout(() => (toast.value = null), 2800)
}

function toggleFeature(feat) {
  const i = form.value.features.indexOf(feat)
  if (i === -1) form.value.features.push(feat)
  else form.value.features.splice(i, 1)
}

async function loadUnit() {
  loading.value = true
  try {
    const { data } = await partnerApi.getUnit(route.params.id)
    const u = data.data ?? data
    // Owner-gated on the API, so these only arrive for the partner who owns
    // the unit — which is exactly who is on this screen.
    docs.value = {
      tourism_permit: u.tourism_permit_url ?? null,
      ownership_doc: u.ownership_doc_url ?? null,
      bank_certificate: u.bank_certificate_url ?? null,
    }
    form.value = {
      unit_name: u.name,
      unit_type: u.type,
      price: Number(u.price),
      capacity: u.capacity,
      bedrooms: u.bedrooms,
      city: u.city || '',
      district: u.district || '',
      description: u.description || '',
      checkin_time: (u.checkin_time || '15:00').slice(0, 5),
      checkout_time: (u.checkout_time || '12:00').slice(0, 5),
      beds: u.beds ?? null,
      bathrooms: u.bathrooms ?? null,
      address: u.address || '',
      lat: u.lat ?? null,
      lng: u.lng ?? null,
      tourism_permit_no: u.tourism_permit_no || '',
      company_license_no: u.company_license_no || '',
      cancellation_policy: u.cancellation_policy || 'no_cancel',
      features: Array.isArray(u.features) ? [...u.features] : [],
    }
    // Drop the synthesized default placeholder (id 0) — show real photos only.
    images.value = Array.isArray(u.images) ? u.images.filter((i) => i.id !== 0) : []
  } catch (e) {
    showToast('تعذّر تحميل بيانات الوحدة', 'error')
    router.replace({ name: 'partner-units' })
  } finally {
    loading.value = false
  }
}

async function onFilesSelected(e) {
  const files = Array.from(e.target.files || [])
  if (!files.length) return
  imgError.value = ''
  uploading.value = true
  try {
    const { data } = await partnerApi.uploadUnitImages(route.params.id, files)
    images.value = data.data ?? []
    showToast('تم رفع الصور')
  } catch (err) {
    imgError.value =
      err.response?.data?.message ||
      err.response?.data?.errors?.['images.0']?.[0] ||
      'تعذّر رفع الصور'
  } finally {
    uploading.value = false
    if (fileInput.value) fileInput.value.value = '' // allow re-selecting the same file
  }
}

async function removeImage(id) {
  try {
    const { data } = await partnerApi.deleteUnitImage(route.params.id, id)
    images.value = data.data ?? []
  } catch {
    showToast('تعذّر حذف الصورة', 'error')
  }
}

async function makeMain(id) {
  try {
    const { data } = await partnerApi.setMainImage(route.params.id, id)
    images.value = data.data ?? []
  } catch {
    showToast('تعذّر تعيين الصورة الرئيسية', 'error')
  }
}

async function save() {
  errors.value = {}
  saving.value = true
  try {
    const payload = { ...form.value }
    let unitId = route.params.id
    if (isEdit.value) {
      await partnerApi.updateUnit(unitId, payload)
      showToast('تم حفظ التعديلات')
    } else {
      const created = await partnerApi.createUnit(payload)
      unitId = created.data?.data?.id ?? created.data?.id
      showToast('تم إنشاء الوحدة كمسودة')
    }

    // Expanded AFTER the save, never before: the copies inherit whatever this
    // unit holds, so running it on a half-filled draft would clone the gaps.
    // Idempotent server-side — re-saving with the same count adds nothing.
    if (Number(apartmentCount.value) > 1 && unitId) {
      const { data } = await partnerApi.setApartmentCount(unitId, Number(apartmentCount.value))
      unitCountNotice.value = data?.message || ''
      showToast(data?.message || 'تم إنشاء الوحدات')
    }
    setTimeout(() => router.push({ name: 'partner-units' }), 700)
  } catch (e) {
    if (e.response?.status === 422 && e.response.data?.errors) {
      // Map Laravel field errors (unit_name, price, city, ...)
      for (const [field, msgs] of Object.entries(e.response.data.errors)) {
        errors.value[field] = msgs[0]
      }
      errors.value.general = 'يرجى تصحيح الحقول المميزة'
    } else {
      errors.value.general = e.response?.data?.message || 'تعذّر الحفظ، حاول مجدداً'
    }
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  if (isEdit.value) loadUnit()
})
</script>

<style scoped>
.field {
  @apply w-full px-4 py-2.5 bg-surface-container-low border border-outline-variant rounded-xl text-body-md
         focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition-all;
}
.err { @apply text-error text-body-sm mt-1; }
.fade-enter-active, .fade-leave-active { transition: opacity 0.25s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
