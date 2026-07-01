<script setup>
/**
 * StatusBadge — delivery-order status pill (ywsel).
 * Status is never color-only: every state carries a dot + text label (a11y).
 */
import { computed } from 'vue'

const props = defineProps({
  status: { type: String, required: true }, // delivered|in_transit|pending|cancelled|returned
})

const map = {
  delivered:  { label: 'تم التوصيل', cls: 'bg-ok/12 text-ok',        dot: 'bg-ok' },
  in_transit: { label: 'في الطريق',  cls: 'bg-info/12 text-info',    dot: 'bg-info' },
  pending:    { label: 'قيد الانتظار', cls: 'bg-warn/12 text-warn',  dot: 'bg-warn' },
  cancelled:  { label: 'ملغي',       cls: 'bg-danger/12 text-danger',dot: 'bg-danger' },
  returned:   { label: 'مرتجع',      cls: 'bg-ink-800 text-fg-muted',dot: 'bg-fg-subtle' },
}
const s = computed(() => map[props.status] ?? map.returned)
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 ps-2 pe-2.5 h-6 rounded-full text-[12px] font-medium font-arabic"
    :class="s.cls">
    <span class="size-1.5 rounded-full" :class="s.dot" aria-hidden="true" />
    {{ s.label }}
  </span>
</template>
