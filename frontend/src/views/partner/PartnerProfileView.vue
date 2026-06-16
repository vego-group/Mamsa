<template>
  <PartnerLayout>
    <div class="mb-6">
      <h1 class="font-display-lg text-display-lg text-primary mb-1">الملف الشخصي</h1>
      <p class="text-on-surface-variant text-body-md">إدارة بياناتك كشريك في المنصة</p>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-20 text-on-surface-variant">
      <span class="material-symbols-outlined animate-spin text-3xl">progress_activity</span>
    </div>

    <form v-else class="max-w-2xl space-y-6" @submit.prevent="save">
      <!-- Account -->
      <section class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6">
        <h2 class="font-title-sm text-title-sm text-primary mb-5 pb-3 border-b border-outline-variant">بيانات الحساب</h2>
        <div class="flex items-center gap-4 mb-5">
          <div class="w-16 h-16 rounded-full bg-secondary-container flex items-center justify-center text-primary text-2xl font-bold">
            {{ initials }}
          </div>
          <div>
            <p class="font-title-sm text-title-sm text-on-surface">{{ form.name || '—' }}</p>
            <span class="inline-block mt-1 px-3 py-0.5 rounded-full text-[12px] font-bold" :class="form.type === 'company' ? 'bg-blue-100 text-blue-700' : 'bg-secondary-container text-on-secondary-container'">
              {{ form.type === 'company' ? 'شركة' : 'فرد' }}
            </span>
          </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="sm:col-span-2">
            <label class="block text-body-sm font-bold text-on-surface mb-1.5">الاسم *</label>
            <input v-model="form.name" class="field" required />
          </div>
          <div class="sm:col-span-2">
            <label class="block text-body-sm font-bold text-on-surface mb-1.5">البريد الإلكتروني</label>
            <input v-model="form.email" type="email" class="field" dir="ltr" />
          </div>
          <div class="sm:col-span-2">
            <label class="block text-body-sm font-bold text-on-surface mb-1.5">رقم الجوال</label>
            <input :value="auth.user?.phone" class="field bg-surface-container cursor-not-allowed" dir="ltr" disabled />
          </div>
        </div>
      </section>

      <!-- Partner details -->
      <section class="bg-white rounded-2xl border border-outline-variant shadow-sm p-6">
        <h2 class="font-title-sm text-title-sm text-primary mb-5 pb-3 border-b border-outline-variant">بيانات الشريك</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-body-sm font-bold text-on-surface mb-1.5">نوع الشريك</label>
            <select v-model="form.type" class="field">
              <option value="individual">فرد</option>
              <option value="company">شركة</option>
            </select>
          </div>
          <div v-if="form.type === 'individual'">
            <label class="block text-body-sm font-bold text-on-surface mb-1.5">رقم الهوية الوطنية</label>
            <input v-model="form.national_id" class="field" dir="ltr" maxlength="20" />
          </div>
          <div v-else>
            <label class="block text-body-sm font-bold text-on-surface mb-1.5">رقم السجل التجاري</label>
            <input v-model="form.cr_number" class="field" dir="ltr" maxlength="20" />
          </div>
        </div>
      </section>

      <div class="flex justify-end">
        <button type="submit" class="px-6 py-3 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors flex items-center gap-2 disabled:opacity-50" :disabled="saving">
          <span v-if="saving" class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span>
          <span v-else class="material-symbols-outlined text-[18px]">save</span>
          حفظ التغييرات
        </button>
      </div>
    </form>

    <Transition name="fade">
      <div v-if="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 px-5 py-3 rounded-xl shadow-lg text-white font-bold text-body-sm" :class="toast.type === 'error' ? 'bg-error' : 'bg-primary'">
        {{ toast.msg }}
      </div>
    </Transition>
  </PartnerLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import PartnerLayout from '@/layouts/PartnerLayout.vue'
import { partnerApi } from '@/api/partner'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const loading = ref(true)
const saving = ref(false)
const toast = ref(null)

const form = ref({
  name: '',
  email: '',
  type: 'individual',
  national_id: '',
  cr_number: '',
})

const initials = computed(() => {
  const n = form.value.name || ''
  return n.split(' ').slice(0, 2).map((w) => w[0]).join('') || 'ش'
})

function showToast(msg, type = 'success') {
  toast.value = { msg, type }
  setTimeout(() => (toast.value = null), 2800)
}

onMounted(async () => {
  try {
    const { data } = await partnerApi.getProfile()
    const u = data.data ?? data
    const pd = u.partner_detail ?? u.partnerDetail ?? {}
    form.value = {
      name: u.name || '',
      email: u.email || '',
      type: pd.type || 'individual',
      national_id: pd.national_id || '',
      cr_number: pd.cr_number || '',
    }
  } catch (e) {
    showToast('تعذّر تحميل الملف', 'error')
  } finally {
    loading.value = false
  }
})

async function save() {
  saving.value = true
  try {
    const { data } = await partnerApi.updateProfile({ ...form.value })
    const u = data.data ?? data
    // Reflect updated name/email in the shared auth store
    auth.setUser({ ...auth.user, name: u.name, email: u.email })
    showToast('تم حفظ التغييرات')
  } catch (e) {
    showToast(e.response?.data?.message || 'تعذّر الحفظ', 'error')
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.field {
  @apply w-full px-4 py-2.5 bg-surface-container-low border border-outline-variant rounded-xl text-body-md
         focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition-all;
}
.fade-enter-active, .fade-leave-active { transition: opacity 0.25s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
