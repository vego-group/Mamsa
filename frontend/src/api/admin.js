
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

    // المستخدمون
    listUsers: (params) => http.get('/admin/users', { params }),
    createUser: (payload) => http.post('/admin/users', payload),
    updateUserStatus: (id, is_active) => http.patch(`/admin/users/${id}/status`, { is_active }),
    deleteUser: (id) => http.delete(`/admin/users/${id}`),

    // الوحدات والحجوزات
    listUnits: (params) => http.get('/admin/units', { params }),
    listBookings: (params) => http.get('/admin/bookings', { params }),

    // التقارير
    reports: () => http.get('/admin/reports'),

    // الإشعارات (داخل التطبيق)
    notifications: () => http.get('/admin/notifications'),
    notificationsUnread: () => http.get('/admin/notifications/unread-count'),
    markNotificationRead: (id) => http.post(`/admin/notifications/${id}/read`),
    markAllNotificationsRead: () => http.post('/admin/notifications/read-all'),
}