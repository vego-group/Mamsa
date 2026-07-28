import { reactive, computed } from 'vue'

/**
 * Lightweight i18n scoped to the admin back-office.
 *
 * The public/partner SPA is Arabic-RTL; the admin dashboard is designed
 * English-LTR with a working AR toggle. Rather than pull in vue-i18n globally
 * (and risk flipping the rest of the app), this is a tiny reactive dictionary
 * lookup shared across all admin components via a module-level singleton.
 *
 * - locale persists to localStorage ('admin_locale'), default 'en'
 * - t('a.b.c', { name }) resolves dotted keys with {var} interpolation
 * - dir → 'ltr' | 'rtl' for the admin root container only
 */
const messages = {
  en: {
    nav: {
      core: 'Core', operations: 'Operations', insights: 'Insights', account: 'Account',
      dashboard: 'Dashboard', users: 'Users', partners: 'Partners', approvals: 'Approvals',
      units: 'Units', bookings: 'Bookings', cancellations: 'Cancellations',
      reports: 'Reports', notifications: 'Notifications', profile: 'Profile',
    },
    brand: { title: 'Mamsa', subtitle: 'Super Admin' },
    header: {
      search: 'Search anything...', noResults: 'No matching pages', role: 'Super Admin', logout: 'Sign Out',
      settings: 'Settings', profile: 'Profile', notifications: 'Notifications',
    },
    dashboard: {
      title: 'Dashboard', subtitle: 'Platform overview & key performance metrics',
      live: 'Live', exportReport: 'Export Report',
    },
    kpi: {
      totalUsers: 'Total Users', platformCommission: 'Platform Commission',
      totalBookings: 'Total Bookings', activePartners: 'Active Partners',
      pendingRequests: 'Pending Requests', monthlyGrowth: 'Monthly Growth',
      avgBookingValue: 'Avg Booking Value', vsLastMonth: 'vs last month',
    },
    charts: {
      revenueCommission: 'Revenue & Commission', monthlyPerformance: 'Monthly performance overview',
      revenue: 'Revenue', commission: 'Commission',
      bookingStatus: 'Booking Status', distributionOverview: 'Distribution overview',
      completed: 'Completed', approved: 'Approved', pending: 'Pending', cancelled: 'Cancelled',
      revenueByCity: 'Revenue by City', topLocations: 'Top performing locations',
      weeklyBookings: 'Weekly Bookings', dayByDay: 'Day-by-day booking volume',
      noData: 'No data yet',
    },
    table: {
      latestRequests: 'Latest Pending Requests', awaitingReview: 'Properties awaiting review',
      viewAll: 'View All', requestId: 'Request ID', partner: 'Partner',
      property: 'Property', type: 'Type', submitted: 'Submitted', empty: 'No pending requests',
    },
    users: {
      title: 'Users Management', subtitle: '{count} registered users on the platform', exportCsv: 'Export CSV',
      activeUsers: 'Active Users', inactiveUsers: 'Inactive Users', avgSpend: 'Avg Spend/User',
      tabAll: 'All Users', tabActive: 'Active', tabInactive: 'Inactive', tabDisabled: 'Disabled',
      search: 'Search users...',
      colUser: 'User', colMobile: 'Mobile', colCity: 'City', colBookings: 'Bookings',
      colSpent: 'Total Spent', colJoined: 'Joined', colStatus: 'Status',
      statusActive: 'Active', statusInactive: 'Inactive', statusDisabled: 'Disabled',
      showing: 'Showing {from}–{to} of {total}', empty: 'No matching users',
      view: 'View', disable: 'Disable', enable: 'Enable', delete: 'Delete',
      contactInfo: 'Contact Information', bookingStats: 'Booking Statistics', recentActivity: 'Recent Activity',
      totalBookings: 'Total Bookings', totalSpent: 'Total Spent', avgBookingValue: 'Avg. Booking Value',
      joinedOn: 'Joined {date}', disableAccount: 'Disable Account', enableAccount: 'Enable Account', deleteUser: 'Delete User',
      deleteTitle: 'Delete account', deleteMsg: 'Delete {name}’s account? This cannot be undone.', cancel: 'Cancel',
      actAccountCreated: 'Account created', actEmailVerified: 'Email verified',
      actFirstBooking: 'First booking made', actLastBooking: 'Latest booking',
      statusUpdated: 'Status updated', userDeleted: 'User deleted', loadError: 'Failed to load users', actionError: 'Action failed',
      invite: 'Invite User', inviteTitle: 'Invite a new user', created: 'User invited',
      fName: 'Full name', fMobile: 'Mobile number', fEmail: 'Email (optional)', fRole: 'Role',
      roleUser: 'User', rolePartnerInd: 'Partner (individual)', rolePartnerCo: 'Partner (company)', roleAdmin: 'Admin', roleSuperAdmin: 'Super Admin',
      save: 'Invite', saving: 'Saving...',
    },
    partners: {
      title: 'Partners Management', subtitle: '{total} partners · {verified} verified · {warnings} warnings',
      export: 'Export', addPartner: 'Add Partner',
      activePartners: 'Active Partners', totalRevenue: 'Total Revenue', highRisk: 'High-Risk Partners',
      tabAll: 'All', tabIndividuals: 'Individuals', tabCompanies: 'Companies', search: 'Search partners...',
      colPartner: 'Partner', colType: 'Type', colCity: 'City', colUnits: 'Units', colBookings: 'Bookings',
      colRevenue: 'Revenue', colRating: 'Rating', colVerified: 'Verified', colStatus: 'Status',
      typeIndividual: 'Individual', typeCompany: 'Company',
      verified: 'Verified', unverified: 'Unverified',
      statusActive: 'Active', statusPending: 'Pending', statusInactive: 'Inactive',
      showing: 'Showing {from}–{to} of {total}', empty: 'No matching partners',
      profileTitle: 'Partner Profile', contact: 'Contact', joinedOn: 'Joined {date}',
      financial: 'Financial Summary', finTotalRevenue: 'Total Revenue', commissionPaid: 'Commission Paid',
      partnerEarning: 'Partner Earning', avgBooking: 'Avg/Booking',
      performance: 'Performance', totalUnits: 'Total Units', totalBookings: 'Total Bookings',
      cancellations: 'Cancellations', cancellationRate: 'Cancellation Rate',
      documents: 'Documents', docIdentity: 'National ID / CR Document', docBank: 'Bank Account Verification', docOwnership: 'Property Ownership Docs',
      docVerified: 'Verified', docPending: 'Pending', docMissing: 'Missing',
      approve: 'Approve', reject: 'Reject', revokeVerification: 'Revoke Verification',
      disablePartner: 'Disable Partner', enablePartner: 'Enable Partner',
      rejectTitle: 'Reject application', rejectReason: 'Reason', rejectPlaceholder: 'Why is this application rejected?',
      cancel: 'Cancel',
      approved: 'Partner approved', rejected: 'Application rejected', revoked: 'Verification revoked',
      statusUpdated: 'Partner updated', loadError: 'Failed to load partners', actionError: 'Action failed',
    },
    bookings: {
      title: 'Bookings', subtitle: '{count} bookings · {revenue} total revenue', exportCsv: 'Export CSV', exportPdf: 'Export PDF',
      totalRevenue: 'Total Revenue', platformCommission: 'Platform Commission', avgBookingValue: 'Avg Booking Value',
      search: 'Search bookings...', allStatuses: 'All Statuses',
      colId: 'Booking ID', colGuest: 'Guest', colProperty: 'Property', colDates: 'Dates', colAmount: 'Amount',
      colCommission: 'Commission', colPayment: 'Payment', colPayStatus: 'Payment Status', colStatus: 'Status',
      statusCompleted: 'Completed', statusPending: 'Pending', statusCancelled: 'Cancelled',
      payPaid: 'paid', payPending: 'pending', payRefunded: 'refunded', payUnpaid: 'unpaid',
      showing: 'Showing {from}–{to} of {total}', empty: 'No bookings found', loadError: 'Failed to load bookings',
    },
    units: {
      title: 'Units Management', subtitle: '{total} properties · {published} published', export: 'Export', addUnit: 'Add Unit',
      published: 'Published', avgOccupancy: 'Avg Occupancy', totalRevenue: 'Total Revenue',
      search: 'Search units...', allStatuses: 'All Statuses', allTypes: 'All Type',
      pubPublished: 'Published', pubUnderReview: 'Under Review', pubUnpublished: 'Unpublished',
      apprApproved: 'Approved', apprPending: 'Pending', apprRejected: 'Rejected',
      occupancy: 'Occupancy', revenue: 'Revenue', pricePerNight: 'Price/night',
      typeApartment: 'Apartment', typeVilla: 'Villa', typeStudio: 'Studio',
      empty: 'No matching units', loadError: 'Failed to load units',
      featured: 'Featured', featureOn: 'Marked as featured', featureOff: 'Removed from featured',
      showing: 'Showing {from}–{to} of {total}',
    },
    approvals: {
      title: 'Approval Requests', subtitle: 'Review and approve property listings submitted by partners',
      filters: 'Filters', batchReview: 'Batch Review',
      pendingReview: 'Pending Review', approvedToday: 'Approved Today', rejectedToday: 'Rejected Today', avgReviewTime: 'Avg Review Time',
      prioHigh: 'High Priority', prioNormal: 'Normal', prioLow: 'Low Priority',
      prioHighShort: 'High', prioNormalShort: 'Normal', prioLowShort: 'Low',
      review: 'Review', empty: 'No pending requests', loadError: 'Failed to load requests',
      back: 'Back to Approvals', pricing: 'Pricing', perNight: 'SAR / night', basedOnGuests: 'Based on {n} guests',
      partner: 'Partner', verified: 'Verified', submitted: 'Submitted', priority: 'Priority',
      individual: 'Individual', company: 'Company',
      checklist: 'Review Checklist', chkPhotos: 'Photos reviewed', chkDocuments: 'Documents verified',
      chkPricing: 'Pricing reasonable', chkLocation: 'Location confirmed', chkAmenities: 'Amenities checked',
      tabAmenities: 'Amenities', tabDocuments: 'Documents', tabTimeline: 'Timeline', tabProperty: 'Property Info',
      bedrooms: 'Bedrooms', bathrooms: 'Bathrooms', capacity: 'Capacity', size: 'Size', guests: '{n} guests',
      mapPreview: 'Map preview (integration required)',
      docIdentity: 'National ID / CR Document', docBank: 'Bank Account Verification', docOwnership: 'Property Ownership Docs',
      docVerified: 'Verified', docPending: 'Pending', docMissing: 'Missing',
      noAmenities: 'No amenities listed', noDocuments: 'No documents on file',
      tlSubmitted: 'Submitted for review', tlApproved: 'Approved', tlRejected: 'Rejected',
      reviewNote: 'Review all sections before making a decision.', backToList: 'Back to List',
      reject: 'Reject', approveListing: 'Approve Listing',
      rejectTitle: 'Reject listing', rejectReason: 'Reason', rejectPlaceholder: 'Why is this listing rejected?', cancel: 'Cancel',
      approved: 'Listing approved', rejected: 'Listing rejected', actionError: 'Action failed', notFound: 'Request not found',
    },
    cancellations: {
      title: 'Cancellations', subtitle: 'Track and manage booking cancellations and refunds', exportReport: 'Export Report',
      totalRefunds: 'Total Refunds Issued', financialImpact: 'Financial Impact', hostCancellations: 'Host Cancellations',
      trend: 'Cancellation Trend', trendSub: 'Guest vs Host cancellations per month', guestLegend: 'Guest cancellations', hostLegend: 'Host cancellations',
      refundStatus: 'Refund Status', fullyRefunded: 'Fully Refunded', partialRefund: 'Partial Refund', noRefund: 'No Refund', pending: 'Pending',
      highRisk: 'High-Risk Partners', highRiskSub: 'Partners with elevated cancellation rates requiring attention', atRisk: '{n} at risk',
      nCancellations: '{n} cancellations', nRate: '{n}% rate',
      tabAll: 'All', tabGuest: 'Guest', tabHost: 'Host', search: 'Search...',
      colId: 'ID', colBooking: 'Booking', colGuest: 'Guest', colBy: 'Cancelled By', colProperty: 'Property', colDate: 'Date', colRefund: 'Refund', colImpact: 'Impact', colStatus: 'Refund Status',
      byGuest: 'Guest', byHost: 'Host',
      stRefunded: 'Refunded', stPartial: 'Partial Refund', stNoRefund: 'No Refund', stPending: 'Pending',
      individual: 'Individual', company: 'Company', empty: 'No cancellations', loadError: 'Failed to load', showing: 'Showing {from}–{to} of {total}',
    },
    reports: {
      title: 'Reports & Analytics', subtitle: 'Comprehensive business intelligence and performance analytics',
      exportCsv: 'Export CSV', exportPdf: 'Export PDF', thisYear: 'This year',
      tabRevenue: 'Revenue', tabBookings: 'Bookings', tabPartners: 'Partners', tabOccupancy: 'Occupancy',
      totalRevenue: 'Total Revenue', totalCommission: 'Total Commission', totalBookings: 'Total Bookings', avgMonthly: 'Avg Monthly Revenue',
      revCommTime: 'Revenue & Commission Over Time', monthlyBreakdown: 'Monthly breakdown', revenue: 'Revenue', commission: 'Commission',
      revenueByCity: 'Revenue by City', bookingStatus: 'Booking Status Distribution', bookingsByCity: 'Bookings by City',
      completed: 'Completed', approved: 'Approved', pending: 'Pending', cancelled: 'Cancelled',
      topUnits: 'Top Performing Units', unitName: 'Unit', city: 'City', bookings: 'Bookings', unitRevenue: 'Revenue',
      occupancyRate: 'Occupancy Rate', avgNights: 'Avg Nights', avgRating: 'Avg Rating', reviewsCount: 'Reviews', unitsByStatus: 'Units by Status',
      draft: 'Draft', rejected: 'Rejected', noData: 'No data yet', loadError: 'Failed to load reports',
    },
    notifs: {
      title: 'Notifications', unread: '{n} unread notifications', markAll: 'Mark all as read',
      tabAll: 'All', tabUnread: 'Unread', empty: 'No notifications', older: 'Older', loadError: 'Failed to load notifications',
      catApproval: 'Approval', catBooking: 'Booking', catCancellation: 'Cancellation', catPartner: 'Partner', catSystem: 'System', catPayment: 'Payment',
    },
    profile: {
      title: 'Profile', subtitle: 'Manage your account settings and security preferences', verified: 'Verified',
      totalReviews: 'Total Reviews', actionsToday: 'Actions Today', memberSince: 'Member Since',
      personalInfo: 'Personal Information', fullName: 'Full Name', email: 'Email Address', edit: 'Edit', phone: 'Phone Number',
      prefLang: 'Preferred Language', langEn: 'English', langAr: 'العربية',
      security: 'Security', twoFactor: 'Two-Factor Authentication', twoFactorHint: 'Not configured yet', comingSoon: 'Coming soon',
      activeSessions: 'Active Sessions', sessions: '{n} active session', current: 'Current', revoke: 'Revoke',
      dangerZone: 'Danger Zone', signOutAll: 'Sign out of all sessions', signOutAllHint: 'You will be logged out from all devices', logout: 'Logout',
      thisDevice: 'This device',
    },
    login: {
      portal: 'Admin Portal', welcome: 'Welcome back', subtitle: 'Sign in to manage the platform',
      brandSub: 'Super Admin', email: 'Email', password: 'Password', signIn: 'Sign In', signingIn: 'Signing in...',
      otpLink: 'User login with OTP', privacy: 'Privacy', terms: 'Terms', error: 'Sign-in failed, please try again',
    },
    days: { sun: 'Sun', mon: 'Mon', tue: 'Tue', wed: 'Wed', thu: 'Thu', fri: 'Fri', sat: 'Sat' },
    common: { sar: 'SAR', soon: 'This section is being redesigned', comingSoon: 'Coming soon' },
  },
  ar: {
    nav: {
      core: 'الأساسية', operations: 'العمليات', insights: 'التحليلات', account: 'الحساب',
      dashboard: 'لوحة التحكم', users: 'المستخدمون', partners: 'الشركاء', approvals: 'الموافقات',
      units: 'الوحدات', bookings: 'الحجوزات', cancellations: 'الإلغاءات',
      reports: 'التقارير', notifications: 'الإشعارات', profile: 'الملف الشخصي',
    },
    brand: { title: 'ممسى', subtitle: 'المدير العام' },
    header: {
      search: 'ابحث عن أي شيء...', noResults: 'لا توجد صفحات مطابقة', role: 'المدير العام', logout: 'تسجيل الخروج',
      settings: 'الإعدادات', profile: 'الملف الشخصي', notifications: 'الإشعارات',
    },
    dashboard: {
      title: 'لوحة التحكم', subtitle: 'نظرة عامة على المنصة ومؤشرات الأداء الرئيسية',
      live: 'مباشر', exportReport: 'تصدير التقرير',
    },
    kpi: {
      totalUsers: 'إجمالي المستخدمين', platformCommission: 'عمولة المنصة',
      totalBookings: 'إجمالي الحجوزات', activePartners: 'الشركاء النشطون',
      pendingRequests: 'الطلبات المعلقة', monthlyGrowth: 'النمو الشهري',
      avgBookingValue: 'متوسط قيمة الحجز', vsLastMonth: 'عن الشهر الماضي',
    },
    charts: {
      revenueCommission: 'الإيرادات والعمولة', monthlyPerformance: 'نظرة على الأداء الشهري',
      revenue: 'الإيرادات', commission: 'العمولة',
      bookingStatus: 'حالة الحجوزات', distributionOverview: 'توزيع الحالات',
      completed: 'مكتمل', approved: 'مؤكد', pending: 'قيد الانتظار', cancelled: 'ملغي',
      revenueByCity: 'الإيرادات حسب المدينة', topLocations: 'أعلى المدن أداءً',
      weeklyBookings: 'الحجوزات الأسبوعية', dayByDay: 'حجم الحجوزات يوماً بيوم',
      noData: 'لا توجد بيانات بعد',
    },
    table: {
      latestRequests: 'أحدث الطلبات المعلقة', awaitingReview: 'عقارات بانتظار المراجعة',
      viewAll: 'عرض الكل', requestId: 'رقم الطلب', partner: 'الشريك',
      property: 'العقار', type: 'النوع', submitted: 'تاريخ التقديم', empty: 'لا توجد طلبات معلقة',
    },
    users: {
      title: 'إدارة المستخدمين', subtitle: '{count} مستخدم مسجّل في المنصة', exportCsv: 'تصدير CSV',
      activeUsers: 'مستخدمون نشطون', inactiveUsers: 'مستخدمون غير نشطين', avgSpend: 'متوسط الإنفاق/مستخدم',
      tabAll: 'كل المستخدمين', tabActive: 'نشط', tabInactive: 'غير نشط', tabDisabled: 'موقوف',
      search: 'ابحث عن مستخدم...',
      colUser: 'المستخدم', colMobile: 'الجوال', colCity: 'المدينة', colBookings: 'الحجوزات',
      colSpent: 'إجمالي الإنفاق', colJoined: 'تاريخ الانضمام', colStatus: 'الحالة',
      statusActive: 'نشط', statusInactive: 'غير نشط', statusDisabled: 'موقوف',
      showing: 'عرض {from}–{to} من {total}', empty: 'لا يوجد مستخدمون مطابقون',
      view: 'عرض', disable: 'إيقاف', enable: 'تفعيل', delete: 'حذف',
      contactInfo: 'معلومات التواصل', bookingStats: 'إحصائيات الحجوزات', recentActivity: 'النشاط الأخير',
      totalBookings: 'إجمالي الحجوزات', totalSpent: 'إجمالي الإنفاق', avgBookingValue: 'متوسط قيمة الحجز',
      joinedOn: 'انضم في {date}', disableAccount: 'إيقاف الحساب', enableAccount: 'تفعيل الحساب', deleteUser: 'حذف المستخدم',
      deleteTitle: 'حذف الحساب', deleteMsg: 'حذف حساب {name}؟ لا يمكن التراجع عن هذا.', cancel: 'إلغاء',
      actAccountCreated: 'تم إنشاء الحساب', actEmailVerified: 'تم توثيق البريد',
      actFirstBooking: 'أول حجز', actLastBooking: 'آخر حجز',
      statusUpdated: 'تم تحديث الحالة', userDeleted: 'تم حذف المستخدم', loadError: 'تعذّر تحميل المستخدمين', actionError: 'فشل تنفيذ الإجراء',
      invite: 'دعوة مستخدم', inviteTitle: 'دعوة مستخدم جديد', created: 'تمت دعوة المستخدم',
      fName: 'الاسم الكامل', fMobile: 'رقم الجوال', fEmail: 'البريد الإلكتروني (اختياري)', fRole: 'الدور',
      roleUser: 'مستخدم', rolePartnerInd: 'شريك فرد', rolePartnerCo: 'شريك شركة', roleAdmin: 'مدير', roleSuperAdmin: 'مدير عام',
      save: 'دعوة', saving: 'جارٍ الحفظ...',
    },
    partners: {
      title: 'إدارة الشركاء', subtitle: '{total} شريك · {verified} موثّق · {warnings} تنبيهات',
      export: 'تصدير', addPartner: 'إضافة شريك',
      activePartners: 'شركاء نشطون', totalRevenue: 'إجمالي الإيرادات', highRisk: 'شركاء عالو المخاطر',
      tabAll: 'الكل', tabIndividuals: 'أفراد', tabCompanies: 'شركات', search: 'ابحث عن شريك...',
      colPartner: 'الشريك', colType: 'النوع', colCity: 'المدينة', colUnits: 'الوحدات', colBookings: 'الحجوزات',
      colRevenue: 'الإيرادات', colRating: 'التقييم', colVerified: 'التوثيق', colStatus: 'الحالة',
      typeIndividual: 'فرد', typeCompany: 'شركة',
      verified: 'موثّق', unverified: 'غير موثّق',
      statusActive: 'نشط', statusPending: 'قيد المراجعة', statusInactive: 'غير نشط',
      showing: 'عرض {from}–{to} من {total}', empty: 'لا يوجد شركاء مطابقون',
      profileTitle: 'ملف الشريك', contact: 'التواصل', joinedOn: 'انضم في {date}',
      financial: 'الملخص المالي', finTotalRevenue: 'إجمالي الإيرادات', commissionPaid: 'العمولة المدفوعة',
      partnerEarning: 'صافي الشريك', avgBooking: 'متوسط/حجز',
      performance: 'الأداء', totalUnits: 'إجمالي الوحدات', totalBookings: 'إجمالي الحجوزات',
      cancellations: 'الإلغاءات', cancellationRate: 'نسبة الإلغاء',
      documents: 'المستندات', docIdentity: 'الهوية / السجل التجاري', docBank: 'توثيق الحساب البنكي', docOwnership: 'مستندات ملكية العقار',
      docVerified: 'موثّق', docPending: 'قيد المراجعة', docMissing: 'غير متوفر',
      approve: 'موافقة', reject: 'رفض', revokeVerification: 'إلغاء التوثيق',
      disablePartner: 'إيقاف الشريك', enablePartner: 'تفعيل الشريك',
      rejectTitle: 'رفض الطلب', rejectReason: 'السبب', rejectPlaceholder: 'ما سبب رفض هذا الطلب؟',
      cancel: 'إلغاء',
      approved: 'تمت الموافقة على الشريك', rejected: 'تم رفض الطلب', revoked: 'تم إلغاء التوثيق',
      statusUpdated: 'تم تحديث الشريك', loadError: 'تعذّر تحميل الشركاء', actionError: 'فشل تنفيذ الإجراء',
    },
    bookings: {
      title: 'الحجوزات', subtitle: '{count} حجز · {revenue} إجمالي الإيرادات', exportCsv: 'تصدير CSV', exportPdf: 'تصدير PDF',
      totalRevenue: 'إجمالي الإيرادات', platformCommission: 'عمولة المنصة', avgBookingValue: 'متوسط قيمة الحجز',
      search: 'ابحث في الحجوزات...', allStatuses: 'كل الحالات',
      colId: 'رقم الحجز', colGuest: 'الضيف', colProperty: 'العقار', colDates: 'التواريخ', colAmount: 'المبلغ',
      colCommission: 'العمولة', colPayment: 'الدفع', colPayStatus: 'حالة الدفع', colStatus: 'الحالة',
      statusCompleted: 'مكتمل', statusPending: 'قيد الانتظار', statusCancelled: 'ملغي',
      payPaid: 'مدفوع', payPending: 'معلّق', payRefunded: 'مسترد', payUnpaid: 'غير مدفوع',
      showing: 'عرض {from}–{to} من {total}', empty: 'لا توجد حجوزات', loadError: 'تعذّر تحميل الحجوزات',
    },
    units: {
      title: 'إدارة الوحدات', subtitle: '{total} عقار · {published} منشور', export: 'تصدير', addUnit: 'إضافة وحدة',
      published: 'منشورة', avgOccupancy: 'متوسط الإشغال', totalRevenue: 'إجمالي الإيرادات',
      search: 'ابحث عن وحدة...', allStatuses: 'كل الحالات', allTypes: 'كل الأنواع',
      pubPublished: 'منشورة', pubUnderReview: 'قيد المراجعة', pubUnpublished: 'غير منشورة',
      apprApproved: 'موافَق عليها', apprPending: 'قيد الانتظار', apprRejected: 'مرفوضة',
      occupancy: 'الإشغال', revenue: 'الإيرادات', pricePerNight: 'السعر/ليلة',
      typeApartment: 'شقة', typeVilla: 'فيلا', typeStudio: 'استوديو',
      empty: 'لا توجد وحدات مطابقة', loadError: 'تعذّر تحميل الوحدات',
      featured: 'مميّزة', featureOn: 'تم التمييز', featureOff: 'تم إلغاء التمييز',
      showing: 'عرض {from}–{to} من {total}',
    },
    approvals: {
      title: 'طلبات الموافقة', subtitle: 'مراجعة العقارات المقدّمة من الشركاء والموافقة عليها',
      filters: 'تصفية', batchReview: 'مراجعة جماعية',
      pendingReview: 'بانتظار المراجعة', approvedToday: 'موافقات اليوم', rejectedToday: 'مرفوضات اليوم', avgReviewTime: 'متوسط وقت المراجعة',
      prioHigh: 'أولوية عالية', prioNormal: 'عادية', prioLow: 'أولوية منخفضة',
      prioHighShort: 'عالية', prioNormalShort: 'عادية', prioLowShort: 'منخفضة',
      review: 'مراجعة', empty: 'لا توجد طلبات معلقة', loadError: 'تعذّر تحميل الطلبات',
      back: 'العودة للطلبات', pricing: 'التسعير', perNight: 'ر.س / ليلة', basedOnGuests: 'بناءً على {n} ضيوف',
      partner: 'الشريك', verified: 'موثّق', submitted: 'تاريخ التقديم', priority: 'الأولوية',
      individual: 'فرد', company: 'شركة',
      checklist: 'قائمة المراجعة', chkPhotos: 'مراجعة الصور', chkDocuments: 'توثيق المستندات',
      chkPricing: 'التسعير معقول', chkLocation: 'تأكيد الموقع', chkAmenities: 'مراجعة المرافق',
      tabAmenities: 'المرافق', tabDocuments: 'المستندات', tabTimeline: 'المسار الزمني', tabProperty: 'معلومات العقار',
      bedrooms: 'غرف النوم', bathrooms: 'دورات المياه', capacity: 'السعة', size: 'المساحة', guests: '{n} ضيوف',
      mapPreview: 'معاينة الخريطة (تتطلب تكاملاً)',
      docIdentity: 'الهوية / السجل التجاري', docBank: 'توثيق الحساب البنكي', docOwnership: 'مستندات ملكية العقار',
      docVerified: 'موثّق', docPending: 'قيد المراجعة', docMissing: 'غير متوفر',
      noAmenities: 'لا توجد مرافق مدرجة', noDocuments: 'لا توجد مستندات',
      tlSubmitted: 'تم التقديم للمراجعة', tlApproved: 'تمت الموافقة', tlRejected: 'تم الرفض',
      reviewNote: 'راجع جميع الأقسام قبل اتخاذ القرار.', backToList: 'العودة للقائمة',
      reject: 'رفض', approveListing: 'الموافقة على العقار',
      rejectTitle: 'رفض العقار', rejectReason: 'السبب', rejectPlaceholder: 'ما سبب رفض هذا العقار؟', cancel: 'إلغاء',
      approved: 'تمت الموافقة على العقار', rejected: 'تم رفض العقار', actionError: 'فشل تنفيذ الإجراء', notFound: 'الطلب غير موجود',
    },
    cancellations: {
      title: 'الإلغاءات', subtitle: 'تتبّع وإدارة إلغاءات الحجوزات والاستردادات', exportReport: 'تصدير التقرير',
      totalRefunds: 'إجمالي الاستردادات', financialImpact: 'الأثر المالي', hostCancellations: 'إلغاءات المضيف',
      trend: 'اتجاه الإلغاءات', trendSub: 'إلغاءات الضيف مقابل المضيف شهرياً', guestLegend: 'إلغاءات الضيف', hostLegend: 'إلغاءات المضيف',
      refundStatus: 'حالة الاسترداد', fullyRefunded: 'مسترد بالكامل', partialRefund: 'استرداد جزئي', noRefund: 'بدون استرداد', pending: 'قيد الانتظار',
      highRisk: 'شركاء عالو المخاطر', highRiskSub: 'شركاء بمعدل إلغاء مرتفع يحتاجون للانتباه', atRisk: '{n} بمخاطر',
      nCancellations: '{n} إلغاءات', nRate: 'معدل {n}%',
      tabAll: 'الكل', tabGuest: 'الضيف', tabHost: 'المضيف', search: 'بحث...',
      colId: 'المعرّف', colBooking: 'الحجز', colGuest: 'الضيف', colBy: 'أُلغي بواسطة', colProperty: 'العقار', colDate: 'التاريخ', colRefund: 'الاسترداد', colImpact: 'الأثر', colStatus: 'حالة الاسترداد',
      byGuest: 'الضيف', byHost: 'المضيف',
      stRefunded: 'مسترد', stPartial: 'استرداد جزئي', stNoRefund: 'بدون استرداد', stPending: 'قيد الانتظار',
      individual: 'فرد', company: 'شركة', empty: 'لا توجد إلغاءات', loadError: 'تعذّر التحميل', showing: 'عرض {from}–{to} من {total}',
    },
    reports: {
      title: 'التقارير والتحليلات', subtitle: 'ذكاء أعمال شامل وتحليلات للأداء',
      exportCsv: 'تصدير CSV', exportPdf: 'تصدير PDF', thisYear: 'هذا العام',
      tabRevenue: 'الإيرادات', tabBookings: 'الحجوزات', tabPartners: 'الشركاء', tabOccupancy: 'الإشغال',
      totalRevenue: 'إجمالي الإيرادات', totalCommission: 'إجمالي العمولة', totalBookings: 'إجمالي الحجوزات', avgMonthly: 'متوسط الإيراد الشهري',
      revCommTime: 'الإيرادات والعمولة عبر الوقت', monthlyBreakdown: 'تفصيل شهري', revenue: 'الإيرادات', commission: 'العمولة',
      revenueByCity: 'الإيرادات حسب المدينة', bookingStatus: 'توزيع حالات الحجوزات', bookingsByCity: 'الحجوزات حسب المدينة',
      completed: 'مكتمل', approved: 'مؤكد', pending: 'قيد الانتظار', cancelled: 'ملغي',
      topUnits: 'أفضل الوحدات أداءً', unitName: 'الوحدة', city: 'المدينة', bookings: 'الحجوزات', unitRevenue: 'الإيراد',
      occupancyRate: 'نسبة الإشغال', avgNights: 'متوسط الليالي', avgRating: 'متوسط التقييم', reviewsCount: 'المراجعات', unitsByStatus: 'الوحدات حسب الحالة',
      draft: 'مسودة', rejected: 'مرفوضة', noData: 'لا توجد بيانات', loadError: 'تعذّر تحميل التقارير',
    },
    notifs: {
      title: 'الإشعارات', unread: '{n} إشعارات غير مقروءة', markAll: 'تعليم الكل كمقروء',
      tabAll: 'الكل', tabUnread: 'غير المقروءة', empty: 'لا توجد إشعارات', older: 'الأقدم', loadError: 'تعذّر تحميل الإشعارات',
      catApproval: 'موافقة', catBooking: 'حجز', catCancellation: 'إلغاء', catPartner: 'شريك', catSystem: 'النظام', catPayment: 'دفع',
    },
    profile: {
      title: 'الملف الشخصي', subtitle: 'إدارة إعدادات حسابك وتفضيلات الأمان', verified: 'موثّق',
      totalReviews: 'إجمالي المراجعات', actionsToday: 'إجراءات اليوم', memberSince: 'عضو منذ',
      personalInfo: 'المعلومات الشخصية', fullName: 'الاسم الكامل', email: 'البريد الإلكتروني', edit: 'تعديل', phone: 'رقم الجوال',
      prefLang: 'اللغة المفضّلة', langEn: 'English', langAr: 'العربية',
      security: 'الأمان', twoFactor: 'المصادقة الثنائية', twoFactorHint: 'غير مُفعّلة بعد', comingSoon: 'قريباً',
      activeSessions: 'الجلسات النشطة', sessions: '{n} جلسة نشطة', current: 'الحالية', revoke: 'إنهاء',
      dangerZone: 'منطقة الخطر', signOutAll: 'تسجيل الخروج من كل الجلسات', signOutAllHint: 'سيتم تسجيل خروجك من جميع الأجهزة', logout: 'تسجيل الخروج',
      thisDevice: 'هذا الجهاز',
    },
    login: {
      portal: 'بوابة الإدارة', welcome: 'مرحباً بعودتك', subtitle: 'سجّل الدخول لإدارة المنصة',
      brandSub: 'المدير العام', email: 'البريد الإلكتروني', password: 'كلمة المرور', signIn: 'تسجيل الدخول', signingIn: 'جارٍ الدخول...',
      otpLink: 'دخول المستخدمين برمز التحقق', privacy: 'الخصوصية', terms: 'الشروط', error: 'تعذّر تسجيل الدخول، حاول مجدداً',
    },
    days: { sun: 'أحد', mon: 'إثن', tue: 'ثلا', wed: 'أرب', thu: 'خمي', fri: 'جمع', sat: 'سبت' },
    common: { sar: 'ر.س', soon: 'يجري إعادة تصميم هذا القسم', comingSoon: 'قريباً' },
  },
}

const state = reactive({ locale: localStorage.getItem('admin_locale') || 'en' })

function resolve(dict, key) {
  return key.split('.').reduce((node, p) => (node == null ? node : node[p]), dict)
}

export function useAdminI18n() {
  const locale = computed(() => state.locale)
  const dir = computed(() => (state.locale === 'ar' ? 'rtl' : 'ltr'))

  function setLocale(l) {
    state.locale = l
    localStorage.setItem('admin_locale', l)
  }
  function toggleLocale() {
    setLocale(state.locale === 'en' ? 'ar' : 'en')
  }

  function t(key, vars) {
    let val = resolve(messages[state.locale], key)
    if (val == null) val = resolve(messages.en, key) // fallback to English
    if (val == null) return key
    if (vars && typeof val === 'string') {
      return val.replace(/\{(\w+)\}/g, (_, k) => (vars[k] ?? ''))
    }
    return val
  }

  return { t, locale, dir, setLocale, toggleLocale }
}
