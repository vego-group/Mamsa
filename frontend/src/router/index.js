import { createRouter, createWebHistory } from 'vue-router'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      component: () => import('@/views/HomeView.vue'),
      // Public — browsable by guests and authenticated users alike
    },
    {
      path: '/preview/dashboard',
      name: 'ops-preview',
      component: () => import('@/views/DeliveryDashboard.vue'),
      // Design preview of the ywsel ops dashboard — no auth, review only.
    },
    {
      path: '/explore',
      name: 'explore',
      component: () => import('@/views/ExploreView.vue'),
      // Public — catalogue / discover landing
    },
    {
      path: '/search',
      name: 'search',
      component: () => import('@/views/SearchView.vue'),
      // Public — filtered search results
    },
    {
      path: '/contact',
      name: 'contact',
      component: () => import('@/views/ContactView.vue'),
      // Public — contact/support form (POST /contact)
    },
    {
      path: '/units/:id',
      name: 'unit-detail',
      component: () => import('@/views/UnitDetailView.vue'),
      // Public unit detail; booking action gates on auth
    },
    {
      path: '/bookings/:id/payment',
      name: 'payment',
      component: () => import('@/views/PaymentView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/bookings/:id',
      // The booking confirmation email links to /my-reservations/{id}, which is
      // the path the production storefront serves. Same screen, so it is an
      // alias rather than a second route: navigation still generates
      // /bookings/{id} by name, and the auth guard covers both because the meta
      // is shared. A guest opening the invoice link now lands somewhere real
      // instead of on a blank page.
      alias: '/my-reservations/:id',
      name: 'booking-detail',
      component: () => import('@/views/user/UserBookingDetailView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/payment/callback',
      name: 'payment-callback',
      component: () => import('@/views/PaymentCallbackView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/account',
      name: 'account',
      component: () => import('@/views/user/UserBookingsView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/account/profile',
      name: 'account-profile',
      component: () => import('@/views/user/UserProfileView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/account/favorites',
      name: 'account-favorites',
      component: () => import('@/views/user/UserFavoritesView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/account/wallet',
      name: 'account-wallet',
      component: () => import('@/views/user/UserWalletView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/auth/LoginView.vue'),
      meta: { guest: true },
    },
    {
      path: '/admin/login',
      name: 'admin-login',
      component: () => import('@/views/auth/AdminLoginView.vue'),
      meta: { guest: true },
    },
    {
      path: '/partner/register',
      name: 'partner-register',
      component: () => import('@/views/auth/PartnerRegisterView.vue'),
      meta: { guest: true },
    },
    {
      path: '/otp',
      name: 'otp',
      component: () => import('@/views/auth/OtpView.vue'),
      meta: { guest: true },
    },
    {
      path: '/complete-profile',
      name: 'complete-profile',
      component: () => import('@/views/auth/CompleteProfileView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/dashboard',
      name: 'dashboard',
      component: () => import('@/views/DashboardView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/partner',
      redirect: '/partner/dashboard',
      meta: { requiresAuth: true, requiresPartner: true },
      children: [
        {
          path: 'dashboard',
          name: 'partner-dashboard',
          component: () => import('@/views/partner/PartnerDashboardView.vue'),
        },
        {
          path: 'units',
          name: 'partner-units',
          component: () => import('@/views/partner/PartnerUnitsView.vue'),
        },
        {
          path: 'units/new',
          name: 'partner-unit-form',
          component: () => import('@/views/partner/PartnerUnitFormView.vue'),
        },
        {
          path: 'units/:id/edit',
          name: 'partner-unit-edit',
          component: () => import('@/views/partner/PartnerUnitFormView.vue'),
        },
        {
          path: 'bookings',
          name: 'partner-bookings',
          component: () => import('@/views/partner/PartnerBookingsView.vue'),
        },
        {
          path: 'profile',
          name: 'partner-profile',
          component: () => import('@/views/partner/PartnerProfileView.vue'),
        },
      ],
    },
    {
      path: '/admin',
      redirect: '/admin/dashboard',
      meta: { requiresAuth: true, requiresAdmin: true },
      children: [
        {
          path: 'dashboard',
          name: 'admin-dashboard',
          component: () => import('@/views/admin/AdminDashboardView.vue'),
        },
        {
          path: 'users',
          name: 'admin-users',
          component: () => import('@/views/admin/AdminUsersView.vue'),
        },
        {
          path: 'units',
          name: 'admin-units',
          component: () => import('@/views/admin/AdminUnitsView.vue'),
        },
        {
          path: 'bookings',
          name: 'admin-bookings',
          component: () => import('@/views/admin/AdminBookingsView.vue'),
        },
        {
          path: 'requests',
          name: 'admin-requests',
          component: () => import('@/views/admin/AdminRequestsView.vue'),
        },
        {
          path: 'partners',
          name: 'admin-partners',
          component: () => import('@/views/admin/AdminPartnersView.vue'),
        },
        {
          path: 'requests/:id',
          name: 'admin-request-detail',
          component: () => import('@/views/admin/AdminRequestDetailView.vue'),
        },
        {
          path: 'reports',
          name: 'admin-reports',
          component: () => import('@/views/admin/AdminReportsView.vue'),
        },
        {
          path: 'settings',
          name: 'admin-settings',
          component: () => import('@/views/admin/AdminSettingsView.vue'),
        },
        {
          path: 'cancellations',
          name: 'admin-cancellations',
          component: () => import('@/views/admin/AdminCancellationsView.vue'),
        },
        {
          path: 'notifications',
          name: 'admin-notifications',
          component: () => import('@/views/admin/AdminNotificationsView.vue'),
        },
        {
          path: 'profile',
          name: 'admin-profile',
          component: () => import('@/views/admin/AdminProfileView.vue'),
        },
      ],
    },
  ],
})

// Where an authenticated session belongs, derived from stored roles.
function homeForStoredUser() {
  const user = JSON.parse(localStorage.getItem('user') || 'null')
  const roles = user?.roles || []
  if (roles.some((r) => r === 'Admin' || r === 'SuperAdmin')) return { name: 'admin-dashboard' }
  if (roles.some((r) => r === 'Individual' || r === 'Company')) return { name: 'partner-dashboard' }
  return { name: 'account' } // regular renter → their bookings dashboard
}

// A lazily-imported view whose chunk is gone — the usual cause is a deploy
// that replaced assets/ while this tab stayed open. There is nothing to recover
// to in-page: the code for the destination no longer exists in this build, so
// reload once at the intended URL and let the fresh index.html resolve it.
//
// Guarded against a loop: if the reload does not fix it, the flag stops us from
// reloading forever on a genuinely broken chunk.
router.onError((error, to) => {
  const isChunkError = /Failed to fetch dynamically imported module|Importing a module script failed|error loading dynamically imported module/i
    .test(error?.message || '')

  if (!isChunkError) return

  const key = 'chunk-reload-attempted'
  if (sessionStorage.getItem(key) === to.fullPath) return

  try { sessionStorage.setItem(key, to.fullPath) } catch { /* private mode */ }
  window.location.assign(to.fullPath)
})

router.beforeEach((to) => {
  const token = localStorage.getItem('access_token')

  if (to.meta.requiresAuth && !token) {
    return { name: 'login' }
  }

  // Already authenticated and hitting a guest-only page → go to their home.
  if (to.meta.guest && token) {
    return homeForStoredUser()
  }

  // Protected admin area (everything under /admin except the login page itself)
  const isAdminArea = to.path.startsWith('/admin') && to.name !== 'admin-login'
  if (isAdminArea) {
    if (!token) return { name: 'admin-login' }
    const roles = JSON.parse(localStorage.getItem('user') || 'null')?.roles || []
    const isAdmin = roles.some((r) => r === 'Admin' || r === 'SuperAdmin')
    if (!isAdmin) return homeForStoredUser()
  }

  // Protected partner area (registration page is public, excluded here)
  if (to.path.startsWith('/partner') && to.name !== 'partner-register') {
    if (!token) return { name: 'login' }
    const roles = JSON.parse(localStorage.getItem('user') || 'null')?.roles || []
    const isPartner = roles.some((r) => r === 'Individual' || r === 'Company')
    if (!isPartner) return homeForStoredUser()
  }
})

export default router
