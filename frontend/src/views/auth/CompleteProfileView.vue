<template>
  <div class="min-h-screen bg-surface flex items-center justify-center p-4" dir="rtl">
    <div class="w-full max-w-md">
      <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-16 h-16 bg-primary-container rounded-2xl mb-4">
          <span class="material-symbols-outlined text-on-primary-fixed text-3xl" style="font-variation-settings:'FILL' 1">person</span>
        </div>
        <h1 class="font-arabic text-display-lg text-primary">ممسى</h1>
      </div>

      <div class="bg-white rounded-2xl border border-outline-variant shadow-card p-8">
        <h2 class="font-arabic text-headline-md text-primary mb-2">أكمل بياناتك</h2>
        <p class="text-on-surface-variant text-body-sm mb-6">أدخل اسمك لإكمال إنشاء الحساب</p>

        <form @submit.prevent="submit" class="space-y-5">
          <div>
            <label class="block text-body-sm font-arabic font-bold text-on-surface mb-2">الاسم الكامل</label>
            <input
              v-model="name"
              type="text"
              placeholder="محمد عبدالله"
              class="input-field w-full"
              :class="{ 'border-error': error }"
              required
            />
            <p v-if="error" class="text-error text-body-sm mt-1">{{ error }}</p>
          </div>

          <div>
            <label class="block text-body-sm font-arabic font-bold text-on-surface mb-2">البريد الإلكتروني (اختياري)</label>
            <input
              v-model="email"
              type="email"
              placeholder="example@email.com"
              dir="ltr"
              class="input-field w-full"
            />
          </div>

          <button type="submit" class="btn-primary w-full" :disabled="loading">
            <span v-if="loading" class="flex items-center justify-center gap-2">
              <span class="material-symbols-outlined animate-spin text-lg">progress_activity</span>
              جارٍ الحفظ...
            </span>
            <span v-else>حفظ والمتابعة</span>
          </button>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { authApi } from '@/api/auth'

const router = useRouter()
const auth   = useAuthStore()

const name    = ref('')
const email   = ref('')
const error   = ref('')
const loading = ref(false)

async function submit() {
  error.value = ''
  if (!name.value.trim()) {
    error.value = 'الاسم مطلوب'
    return
  }

  loading.value = true
  try {
    const { data } = await authApi.completeProfile({ name: name.value.trim(), email: email.value || undefined })
    auth.setUser(data.data)
    router.replace({ name: 'dashboard' })
  } catch (err) {
    error.value = err.response?.data?.message || 'حدث خطأ، حاول مجدداً'
  } finally {
    loading.value = false
  }
}
</script>
