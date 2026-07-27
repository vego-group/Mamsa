
import http from './http'

export const adminApi = {
    // لوحة التحكم الرئيسية (إحصائيات حقيقية)
    dashboard: () => http.get('/admin/dashboard'),

    // جلب قائمة الطلبات (متطابق مع: Route::get('/'))
    listRequests: (params) => http.get('/admin/requests', { params }),

    // جلب تفاصيل طلب محدد (متطابق مع: Route::get('{unit}'))
    getRequest: (id) => http.get(`/admin/requests/${id}`),

    // الموافقة على الطلب (متطابق مع: Route::post('{unit}/approve'))
    approveRequest: (id) => http.post(`/admin/requests/${id}/approve`),

    // رفض الطلب (متطابق مع: Route::post('{unit}/reject'))
    rejectRequest: (id, reason) => http.post(`/admin/requests/${id}/reject`, { reason }),

    // إدارة الشركاء + مراجعة الطلبات
    listPartners: (params) => http.get('/admin/partners', { params }),
    getPartner: (userId) => http.get(`/admin/partners/${userId}`),
    approvePartner: (userId) => http.post(`/admin/partners/${userId}/approve`),
    rejectPartner: (userId, reason) => http.post(`/admin/partners/${userId}/reject`, { reason }),
    setPartnerActive: (userId, is_active) => http.post(`/admin/partners/${userId}/active`, { is_active }),
    revokePartner: (userId) => http.post(`/admin/partners/${userId}/revoke`),

    // المستخدمون
    listUsers: (params) => http.get('/admin/users', { params }),
    getUser: (id) => http.get(`/admin/users/${id}`),
    createUser: (payload) => http.post('/admin/users', payload),
    updateUserStatus: (id, is_active) => http.patch(`/admin/users/${id}/status`, { is_active }),
    deleteUser: (id) => http.delete(`/admin/users/${id}`),

    // الوحدات والحجوزات
    listUnits: (params) => http.get('/admin/units', { params }),
    setUnitFeatured: (id, is_featured) => http.patch(`/admin/units/${id}/featured`, { is_featured }),
    listBookings: (params) => http.get('/admin/bookings', { params }),
    listCancellations: (params) => http.get('/admin/cancellations', { params }),

    // الإشعارات (مركز الإشعارات الكامل)
    listNotifications: () => http.get('/admin/notifications'),
    markAllNotificationsRead: () => http.post('/admin/notifications/read-all'),
    markNotificationRead: (id) => http.post(`/admin/notifications/${id}/read`),

    // التقارير
    reports: () => http.get('/admin/reports'),
    // ملاحظة: إشعارات لوحة التحكم يتعامل معها NotificationBell عبر basePath
}