import { computed, toValue } from 'vue'

/**
 * How a multi-unit building talks about what is left.
 *
 * Scarcity, not inventory. Printing the raw count on every card is noise — and
 * a HIGH number reads as "nobody wants this", which is the opposite of what a
 * listing wants to say. So the badge stays silent until the number is small
 * enough to matter, and a standalone listing (which always reports 1) says
 * nothing at all: "1 متاحة" is true of the entire catalogue.
 */
export const SCARCE_AT = 3

export function useScarcity(unitRef) {
  const count = computed(() => Number(toValue(unitRef)?.available_count ?? 0))

  // A building of one is an ordinary listing. Only a real building — one that
  // HAS siblings — can be running low.
  const isBuilding = computed(() => {
    const u = toValue(unitRef)
    return typeof u?.listing_id === 'string' && !u.listing_id.startsWith('u')
  })

  const scarce = computed(() =>
    isBuilding.value && count.value > 0 && count.value <= SCARCE_AT)

  const scarceLabel = computed(() =>
    count.value === 1 ? 'وحدة واحدة متبقية' : `متبقي ${count.value} وحدات فقط`)

  return { count, isBuilding, scarce, scarceLabel }
}
