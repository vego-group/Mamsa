<template>
  <RouterLink
    :to="{ name: 'unit-detail', params: { id: unit.id } }"
    class="bg-white rounded-2xl border border-outline-variant overflow-hidden hover:shadow-card transition-all group"
    :class="fluid ? 'w-full' : 'w-[260px] shrink-0 snap-start'"
  >
    <div class="relative h-40 bg-surface-container overflow-hidden">
      <img
        v-if="image"
        :src="image"
        :alt="unit.name"
        class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
        loading="lazy"
      />
      <div v-else class="w-full h-full flex items-center justify-center">
        <span class="material-symbols-outlined text-4xl text-on-surface-variant">apartment</span>
      </div>

      <span v-if="badge" class="absolute top-3 left-3 px-2.5 py-1 rounded-full text-[11px] font-bold" :class="badge.class">
        {{ badge.label }}
      </span>

      <button
        class="absolute top-3 right-3 grid w-8 h-8 place-items-center rounded-full bg-white/90 hover:bg-white transition-colors"
        :class="favorited ? 'text-error' : 'text-on-surface-variant'"
        @click.prevent="$emit('favorite')"
      >
        <span class="material-symbols-outlined text-[18px]" :style="favorited ? `font-variation-settings:'FILL' 1` : ''">favorite</span>
      </button>
    </div>

    <div class="p-4">
      <h3 class="font-title-sm text-[15px] text-on-surface mb-2 leading-snug line-clamp-2 h-[44px]">{{ unit.name }}</h3>

      <div class="flex items-center flex-wrap gap-x-3 gap-y-1.5 text-on-surface-variant text-[12px] mb-3 pb-3 border-b border-outline-variant/50">
        <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[15px]">bed</span>{{ unit.bedrooms }} غرف</span>
        <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[15px]">group</span>{{ unit.capacity }} ضيوف</span>
      </div>

      <div class="flex items-end justify-between">
        <div>
          <span class="font-bold text-primary text-title-sm font-numeric-data">{{ formatMoney(unit.price) }}</span>
          <span class="text-on-surface-variant text-[12px]"> ريال / ليلة</span>
        </div>
        <div v-if="unit.avg_rating" class="flex items-center gap-1 text-on-surface">
          <span class="material-symbols-outlined text-[15px] text-amber-500" style="font-variation-settings:'FILL' 1">star</span>
          <span class="text-[12px] font-bold">{{ unit.avg_rating }}</span>
        </div>
      </div>
    </div>
  </RouterLink>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  unit: { type: Object, required: true },
  favorited: { type: Boolean, default: false },
  badge: { type: Object, default: null }, // optional { label, class }
  // Fill the parent cell (grid layouts) instead of the fixed rail width.
  fluid: { type: Boolean, default: false },
})
defineEmits(['favorite'])

const image = computed(() => {
  const imgs = props.unit.images || []
  return (imgs.find((i) => i.is_main) || imgs[0])?.url || null
})

function formatMoney(v) {
  return new Intl.NumberFormat('en-US').format(Number(v) || 0)
}
</script>
