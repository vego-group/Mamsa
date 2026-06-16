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

const route = useRoute()
const router = useRouter()

const isEdit = computed(() => !!route.params.id)
const loading = ref(false)
const saving = ref(false)
const errors = ref({})
const toast = ref(null)

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
  cancellation_policy: 'no_cancel',
  features: [],
})

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
      cancellation_policy: u.cancellation_policy || 'no_cancel',
      features: Array.isArray(u.features) ? [...u.features] : [],
    }
  } catch (e) {
    showToast('تعذّر تحميل بيانات الوحدة', 'error')
    router.replace({ name: 'partner-units' })
  } finally {
    loading.value = false
  }
}

async function save() {
  errors.value = {}
  saving.value = true
  try {
    const payload = { ...form.value }
    if (isEdit.value) {
      await partnerApi.updateUnit(route.params.id, payload)
      showToast('تم حفظ التعديلات')
    } else {
      await partnerApi.createUnit(payload)
      showToast('تم إنشاء الوحدة كمسودة')
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
