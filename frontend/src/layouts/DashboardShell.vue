<script setup>
/**
 * DashboardShell — ywsel enterprise dashboard layout (navy + amber, dark).
 * Mobile-first, RTL:
 *   < lg : sticky top bar + hamburger → slide-in sidebar over scrim + bottom tab bar.
 *   ≥ lg : fixed sidebar pinned to the end (right, RTL) + persistent top bar.
 * Accessibility: skip link, focus rings, aria on toggles, 44px touch targets.
 */
import { ref, computed } from 'vue'

const props = defineProps({
  title: { type: String, default: 'لوحة التحكم' },
  user:  { type: Object, default: () => ({ name: 'مدير العمليات', role: 'يوصل' }) },
})

const open = ref(false)

const nav = [
  { label: 'نظرة عامة', icon: 'dashboard',        to: '/',          active: true },
  { label: 'الطلبات',   icon: 'package_2',         to: '/orders' },
  { label: 'المندوبون', icon: 'two_wheeler',       to: '/couriers' },
  { label: 'المناطق',   icon: 'map',               to: '/zones' },
  { label: 'العملاء',   icon: 'group',             to: '/customers' },
  { label: 'التقارير',  icon: 'monitoring',        to: '/reports' },
]
const initials = computed(() =>
  (props.user?.name || '؟').trim().split(/\s+/).map(w => w[0]).slice(0, 2).join(''))
</script>

<template>
  <div class="min-h-screen bg-ink-950 text-fg font-arabic" dir="rtl">
    <a href="#main"
       class="sr-only focus:not-sr-only focus:absolute focus:z-[60] focus:top-3 focus:start-3
              focus:bg-brand focus:text-on-brand focus:px-4 focus:py-2 focus:rounded-lg">
      تخطٍّ إلى المحتوى
    </a>

    <!-- ── Mobile top bar ─────────────────────────────── -->
    <header class="lg:hidden sticky top-0 z-40 flex items-center justify-between px-4 h-14
                   bg-ink-900/80 backdrop-blur border-b border-ink-700">
      <div class="flex items-center gap-1.5">
        <button class="grid place-items-center size-10 rounded-lg text-fg-muted hover:bg-ink-850 hover:text-fg
                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55"
                @click="open = true" aria-label="فتح القائمة" :aria-expanded="open">
          <span class="material-symbols-outlined text-[22px]">menu</span>
        </button>
        <span class="text-[17px] font-semibold">{{ title }}</span>
      </div>
      <div class="size-9 grid place-items-center rounded-full bg-brand/15 text-brand-300 font-data text-sm font-bold">
        {{ initials }}
      </div>
    </header>

    <!-- ── Scrim ──────────────────────────────────────── -->
    <transition enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
                leave-active-class="transition-opacity duration-200" leave-to-class="opacity-0">
      <div v-if="open" class="fixed inset-0 z-40 bg-black/70 backdrop-blur-sm lg:hidden" @click="open = false" />
    </transition>

    <!-- ── Sidebar ────────────────────────────────────── -->
    <aside
      class="fixed inset-y-0 end-0 z-50 w-[264px] flex flex-col bg-ink-900 border-s border-ink-700
             transition-transform duration-200 ease-out lg:translate-x-0"
      :class="open ? 'translate-x-0' : 'translate-x-full lg:translate-x-0'">
      <!-- Brand lockup -->
      <div class="flex items-center justify-between gap-2 px-5 h-14 border-b border-ink-700">
        <div class="flex items-center gap-2.5">
          <span class="grid place-items-center size-9 rounded-xl bg-brand text-on-brand">
            <span class="material-symbols-outlined text-[20px]">local_shipping</span>
          </span>
          <div class="leading-none">
            <p class="text-base font-bold">يوصل</p>
            <p class="text-[10px] tracking-[0.2em] text-fg-subtle font-data">YWSEL</p>
          </div>
        </div>
        <button class="lg:hidden grid place-items-center size-9 rounded-lg text-fg-muted hover:bg-ink-850"
                @click="open = false" aria-label="إغلاق القائمة">
          <span class="material-symbols-outlined text-[20px]">close</span>
        </button>
      </div>

      <!-- Nav -->
      <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1" aria-label="التنقل الرئيسي">
        <a v-for="item in nav" :key="item.to" :href="item.to"
           :aria-current="item.active ? 'page' : undefined"
           class="relative flex items-center gap-3 h-11 px-3 rounded-lg text-sm transition-colors
                  focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55"
           :class="item.active
             ? 'bg-brand/10 text-brand-300 font-semibold before:absolute before:inset-y-2 before:end-0 before:w-0.5 before:rounded-full before:bg-brand'
             : 'text-fg-muted hover:bg-ink-850 hover:text-fg'">
          <span class="material-symbols-outlined text-[20px]">{{ item.icon }}</span>
          {{ item.label }}
        </a>
      </nav>

      <!-- User -->
      <div class="p-3 border-t border-ink-700">
        <div class="flex items-center gap-3 p-2 rounded-lg hover:bg-ink-850 transition-colors">
          <div class="size-9 grid place-items-center rounded-full bg-brand/15 text-brand-300 font-data text-sm font-bold">
            {{ initials }}
          </div>
          <div class="min-w-0 leading-tight">
            <p class="text-sm font-medium truncate">{{ user.name }}</p>
            <p class="text-[11px] text-fg-subtle truncate">{{ user.role }}</p>
          </div>
          <span class="material-symbols-outlined text-[18px] text-fg-subtle ms-auto">unfold_more</span>
        </div>
      </div>
    </aside>

    <!-- ── Main column ────────────────────────────────── -->
    <div class="lg:me-[264px] flex flex-col min-h-screen">
      <!-- Desktop top bar -->
      <header class="hidden lg:flex sticky top-0 z-30 items-center justify-between gap-4 h-14 px-6
                     bg-ink-950/80 backdrop-blur border-b border-ink-700">
        <div class="relative w-full max-w-sm">
          <span class="material-symbols-outlined absolute end-3 top-1/2 -translate-y-1/2 text-fg-subtle text-[20px]">search</span>
          <input type="search" placeholder="ابحث عن طلب، مندوب، عميل…"
                 class="w-full h-10 ps-4 pe-10 rounded-lg bg-ink-900 border border-ink-700 text-sm text-fg
                        placeholder:text-fg-subtle outline-none transition-all
                        focus:border-brand/55 focus:ring-2 focus:ring-brand/20" />
        </div>
        <div class="flex items-center gap-1.5">
          <button class="relative grid place-items-center size-10 rounded-lg text-fg-muted hover:bg-ink-850 hover:text-fg
                         focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55" aria-label="الإشعارات">
            <span class="material-symbols-outlined text-[20px]">notifications</span>
            <span class="absolute top-2 end-2 size-2 rounded-full bg-brand ring-2 ring-ink-950" />
          </button>
          <button class="grid place-items-center size-10 rounded-lg text-fg-muted hover:bg-ink-850 hover:text-fg
                         focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55" aria-label="الإعدادات">
            <span class="material-symbols-outlined text-[20px]">settings</span>
          </button>
        </div>
      </header>

      <!-- Page content -->
      <main id="main" class="flex-1 p-4 md:p-6 lg:p-8 pb-24 lg:pb-8">
        <slot />
      </main>
    </div>

    <!-- ── Mobile bottom tab bar ──────────────────────── -->
    <nav class="lg:hidden fixed bottom-0 inset-x-0 z-30 grid grid-cols-5 h-16
                bg-ink-900/90 backdrop-blur border-t border-ink-700" aria-label="تنقل سريع">
      <a v-for="item in nav.slice(0, 5)" :key="item.to" :href="item.to"
         :aria-current="item.active ? 'page' : undefined"
         class="flex flex-col items-center justify-center gap-1 text-[11px]"
         :class="item.active ? 'text-brand-300' : 'text-fg-subtle'">
        <span class="material-symbols-outlined text-[22px]">{{ item.icon }}</span>
        {{ item.label }}
      </a>
    </nav>
  </div>
</template>
