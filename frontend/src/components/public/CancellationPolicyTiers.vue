<template>
  <div>
    <p v-if="policy.name" class="text-body-sm font-bold text-on-surface mb-1.5">
      سياسة الإلغاء: {{ policy.name }}
    </p>
    <ul class="space-y-1">
      <li
        v-for="t in sortedTiers"
        :key="t.min_hours_before_checkin"
        class="flex items-center gap-2 text-body-sm text-on-surface-variant"
      >
        <span
          class="material-symbols-outlined text-[16px]"
          :class="t.refund_percent > 0 ? 'text-primary' : 'text-error'"
        >{{ t.refund_percent > 0 ? 'check_circle' : 'cancel' }}</span>
        <span>{{ t.label }}: {{ refundText(t.refund_percent) }}</span>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { computed } from 'vue'

// Renders a tiered cancellation policy — either a unit's live
// `cancellation_policy_details` (pre-booking, FR-021) or a booking's frozen
// `policy_snapshot` (FR-036). Both share the {name, tiers[]} shape.
const props = defineProps({
  policy: { type: Object, required: true }, // {template?, name, tiers: [{min_hours_before_checkin, refund_percent, label}]}
})

// Most generous window first (furthest from check-in), matching how the tiers read.
const sortedTiers = computed(() =>
  [...(props.policy.tiers ?? [])].sort((a, b) => b.min_hours_before_checkin - a.min_hours_before_checkin),
)

function refundText(percent) {
  if (percent >= 100) return 'استرداد كامل'
  if (percent > 0) return `استرداد ${percent}%`
  return 'بدون استرداد'
}
</script>
