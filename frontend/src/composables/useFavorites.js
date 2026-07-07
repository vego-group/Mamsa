import { ref } from 'vue'
import { userApi } from '@/api/user'
import { useAuthStore } from '@/stores/auth'

// Module-level state: every view shares one favorites set, loaded once per
// session, so hearts stay consistent across Home / Explore / Favorites.
const favoriteIds = ref(new Set())
let loaded = false

export function useFavorites() {
  const auth = useAuthStore()

  async function load() {
    if (loaded || !auth.isAuthenticated) return
    loaded = true
    try {
      const { data } = await userApi.favorites()
      favoriteIds.value = new Set((data.data ?? data ?? []).map((u) => u.id))
    } catch {
      loaded = false // transient failure — allow a retry on next view
    }
  }

  /**
   * Optimistic toggle synced to the API.
   * @returns {boolean} false when the user must log in first (caller redirects).
   */
  function toggle(unitId) {
    if (!auth.isAuthenticated) return false

    const next = new Set(favoriteIds.value)
    const adding = !next.has(unitId)
    adding ? next.add(unitId) : next.delete(unitId)
    favoriteIds.value = next

    // Fire-and-forget with rollback — the endpoints are idempotent.
    const call = adding ? userApi.addFavorite(unitId) : userApi.removeFavorite(unitId)
    call.catch(() => {
      const rollback = new Set(favoriteIds.value)
      adding ? rollback.delete(unitId) : rollback.add(unitId)
      favoriteIds.value = rollback
    })

    return true
  }

  return { favoriteIds, load, toggle }
}
