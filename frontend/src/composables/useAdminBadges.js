import { reactive, computed } from 'vue'
import http from '@/api/http'

/**
 * Shared sidebar badge counts (pending approvals + unread notifications).
 * Module-level singleton so the sidebar fetches once per session; any screen
 * that mutates these counts (e.g. approving a request) can call setApprovals().
 */
const state = reactive({ approvals: 0, notifications: 0, loaded: false })

async function fetchApprovals() {
  try {
    const res = await http.get('/admin/requests', { params: { per_page: 1 } })
    state.approvals = res.data.stats?.pending ?? res.data.meta?.total ?? 0
  } catch { /* keep last value */ }
}

async function fetchNotifications() {
  try {
    const res = await http.get('/admin/notifications/unread-count')
    state.notifications = (res.data.data ?? res.data).unread_count ?? 0
  } catch { /* keep last value */ }
}

export function useAdminBadges() {
  async function load(force = false) {
    if (state.loaded && !force) return
    state.loaded = true
    await Promise.all([fetchApprovals(), fetchNotifications()])
  }
  return {
    approvals: computed(() => state.approvals),
    notifications: computed(() => state.notifications),
    load,
    setApprovals: (n) => { state.approvals = Math.max(0, n) },
    setNotifications: (n) => { state.notifications = Math.max(0, n) },
  }
}
