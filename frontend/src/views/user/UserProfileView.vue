<template>
  <div class="min-h-screen bg-[#F7F7F4]" dir="rtl">
    <PublicHeader />

    <div class="max-w-4xl mx-auto px-4 py-8">
      <div class="mb-6">
        <h1 class="font-display-lg text-display-lg text-primary mb-1">حسابي</h1>
        <p class="text-on-surface-variant text-body-md">إدارة بياناتك الشخصية</p>
      </div>

      <AccountNav />

      <div v-if="loading" class="flex items-center justify-center py-20 text-on-surface-variant">
        <span class="material-symbols-outlined animate-spin text-3xl">progress_activity</span>
      </div>

      <form v-else class="max-w-xl space-y-6" @submit.prevent="save">
        <section class="bg-white rounded-2xl border border-outline-variant p-6">
          <div class="flex items-center gap-4 mb-6">
            <div class="w-16 h-16 rounded-full bg-secondary-container flex items-center justify-center text-primary text-2xl font-bold">
              {{ initials }}
            </div>
            <div>
              <p class="font-title-sm text-title-sm text-on-surface">{{ form.name || 'مستخدم' }}</p>
              <span class="inline-block mt-1 px-3 py-0.5 rounded-full bg-surface-container text-on-surface-variant text-[12px] font-bold">مستأجر</span>
            </div>
          </div>

          <div class="space-y-4">
            <div>
              <label class="block text-body-sm font-bold text-on-surface mb-1.5">الاسم الكامل</label>
              <input v-model="form.name" class="field" placeholder="محمد عبدالله" required />
            </div>
            <div>
              <label class="block text-body-sm font-bold text-on-surface mb-1.5">البريد الإلكتروني</label>
              <input v-model="form.email" type="email" class="field" dir="ltr" placeholder="example@email.com" />
            </div>
            <div>
              <label class="block text-body-sm font-bold text-on-surface mb-1.5">رقم الجوال</label>
              <input :value="auth.user?.phone" class="field bg-surface-container cursor-not-allowed" dir="ltr" disabled />
              <p class="text-[11px] text-on-surface-variant mt-1">رقم الجوال مرتبط بحسابك ولا يمكن تغييره</p>
            </div>
          </div>
        </section>

        <div class="flex items-center justify-between">
          <button type="button" class="text-error text-body-sm font-bold hover:underline flex items-center gap-1.5" @click="logout">
            <span class="material-symbols-outlined text-[18px]">logout</span>
            تسجيل الخروج
          </button>
          <button type="submit" class="px-6 py-3 bg-primary text-on-primary rounded-xl font-bold hover:bg-primary-container transition-colors flex items-center gap-2 disabled:opacity-50" :disabled="saving">
            <span v-if="saving" class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span>
            <span v-else class="material-symbols-outlined text-[18px]">save</span>
            حفظ التغييرات
          </button>
        </div>
      </form>
    </div>

    <Transition name="fade">
      <div v-if="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 px-5 py-3 rounded-xl shadow-lg text-white font-bold text-body-sm" :class="toast.type === 'error' ? 'bg-error' : 'bg-primary'">
        {{ toast.msg }}
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import PublicHeader from '@/components/public/PublicHeader.vue'
import AccountNav from '@/components/user/AccountNav.vue'
import { userApi } from '@/api/user'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const loading = ref(true)
const saving = ref(false)
const toast = ref(null)

const form = ref({ name: '', email: '' })

const initials = computed(() => {
  const n = form.value.name || ''
  return n.split(' ').slice(0, 2).map((w) => w[0]).join('') || 'م'
})

function showToast(msg, type = 'success') {
  toast.value = { msg, type }
  setTimeout(() => (toast.value = null), 2800)
}

onMounted(async () => {
  try {
    const { data } = await userApi.getProfile()
    const u = data.data ?? data
    form.value = { name: u.name || '', email: u.email || '' }
  } catch (e) {
    // fall back to store data
    form.value = { name: auth.user?.name || '', email: auth.user?.email || '' }
  } finally {
    loading.value = false
  }
})

async function save() {
  saving.value = true
  try {
    const { data } = await userApi.updateProfile({ name: form.value.name.trim(), email: form.value.email || undefined })
    const u = data.data ?? data
    auth.setUser({ ...auth.user, name: u.name, email: u.email })
    showToast('تم حفظ التغييرات')
  } catch (e) {
    showToast(e.response?.data?.message || 'تعذّر الحفظ', 'error')
  } finally {
    saving.value = false
  }
}

async function logout() {
  await auth.logout()
  router.push({ name: 'home' })
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
