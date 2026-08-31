import { ref } from 'vue'
import { userApi } from '@/api/user'
import { useAuthStore } from '@/stores/auth'

// Module-level state: every view shares one favorites set, loaded once per
// session, so hearts stay consistent across Home / Explore / Favorites.
//
// Keyed on `listing_id`, NOT `id`. For a multi-unit building the card shows
// whichever apartment is free, so its `id` changes once one is booked — and a
// favourited building would come back reading as unfavourited. `listing_id` is
// the same for every apartment of a building.
const favoriteIds = ref(new Set())

/** The stable key for a listing; falls back for anything the API predates. */
export function listingKey(unit) {
  return unit?.listing_id ?? `u${unit?.id}`
}
let loaded = false

export function useFavorites() {
  const auth = useAuthStore()

  async function load() {
    if (loaded || !auth.isAuthenticated) return
    loaded = true
    try {
      const { data } = await userApi.favorites()
      favoriteIds.value = new Set((data.data ?? data ?? []).map(listingKey))
    } catch {
      loaded = false // transient failure — allow a retry on next view
    }
  }

  /** Is this listing favourited? Pass the unit object, not its id. */
  function isFavorite(unit) {
    return favoriteIds.value.has(listingKey(unit))
  }

  /**
   * Optimistic toggle synced to the API.
   *
   * The SET is keyed on the listing; the CALL still sends a unit id, which the
   * server resolves to the building — so removing works from whichever
   * apartment the card happens to be showing.
   *
   * @returns {boolean} false when the user must log in first (caller redirects).
   */
  function toggle(unit) {
    if (!auth.isAuthenticated) return false

    const key = listingKey(unit)
    const next = new Set(favoriteIds.value)
    const adding = !next.has(key)
    adding ? next.add(key) : next.delete(key)
    favoriteIds.value = next

    // Fire-and-forget with rollback — the endpoints are idempotent.
    const call = adding ? userApi.addFavorite(unit.id) : userApi.removeFavorite(unit.id)
    call.catch(() => {
      const rollback = new Set(favoriteIds.value)
      adding ? rollback.delete(key) : rollback.add(key)
      favoriteIds.value = rollback
    })

    return true
  }

  return { favoriteIds, load, toggle, isFavorite }
}
