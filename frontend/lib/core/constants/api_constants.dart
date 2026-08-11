class ApiConstants {
  // Auth routes
  static const String loginEndpoint = 'auth/login';
  static const String logoutEndpoint = 'auth/logout';
  static const String meEndpoint = 'auth/me';
  static const String changePasswordEndpoint = 'auth/change-password';
  static const String forgotPasswordEndpoint = 'auth/forgot-password';
  static const String resetPasswordEndpoint = 'auth/reset-password';
  static const String fcmTokenEndpoint = 'fcm-token';

  // Profile routes
  static const String profileEndpoint = 'profile';
  static const String profileFotoEndpoint = 'profile/foto';
  static const String profileSignatureEndpoint = 'profile/signature';

  // Mahasiswa - Enrollment routes
  static const String enrollmentSubmitEndpoint = 'mahasiswa/enrollment';
  static const String enrollmentCheckDuplicateEndpoint =
      'mahasiswa/enrollment/check-duplicate';
  static const String enrollmentStatusEndpoint = 'mahasiswa/enrollment/status';

  static const String enrollmentReRequestEndpoint = 'mahasiswa/re-enrollment';
  static const String enrollmentMyEmbeddingEndpoint =
      'mahasiswa/enrollment/embedding';

  // Mahasiswa - Attendance routes
  static const String checkInEndpoint = 'mahasiswa/attendance/check-in';
  static const String attendancePermitEndpoint = 'mahasiswa/attendance/permits';
  static const String checkOutEndpoint = 'mahasiswa/attendance/check-out';
  static const String attendanceTodayEndpoint = 'mahasiswa/attendance/today';
  static const String attendanceHistoryEndpoint =
      'mahasiswa/attendance/history';
  static const String attendanceSyncOfflineEndpoint =
      'mahasiswa/attendance/sync-offline';

  // Mahasiswa - Jadwal routes
  static const String jadwalEndpoint = 'mahasiswa/jadwal';
  static const String jadwalTodayEndpoint = 'mahasiswa/jadwal/today';
  static const String jadwalActiveEndpoint = 'mahasiswa/jadwal/active';

  // Mahasiswa - Leave Request routes
  static const String leavesEndpoint = 'mahasiswa/leave-requests';

  // Mahasiswa - SP routes
  static const String spMyEndpoint = 'mahasiswa/sp-records';

  // Mahasiswa - Dashboard
  static const String mahasiswaDashboardEndpoint = 'mahasiswa/dashboard';

  // Notification routes (shared)
  static const String notificationsEndpoint = 'notifications';
  static const String notificationsUnreadEndpoint =
      'notifications/unread-count';
  static const String notificationsReadAllEndpoint = 'notifications/read-all';

  // Admin routes
  static const String adminUsersEndpoint = 'admin/users';
  static const String adminTahunAjaranEndpoint = 'admin/tahun-ajaran';
  static const String adminSemesterEndpoint = 'admin/semester';
  static const String adminMataKuliahEndpoint = 'admin/mata-kuliah';
  static const String adminJadwalEndpoint = 'admin/jadwal';
  static const String adminGeofenceEndpoint = 'admin/geofence';
  static const String adminDashboardEndpoint = 'admin/dashboard';
  static const String adminSettingsEndpoint = 'admin/settings';

  // Admin - Enrollment approval
  static const String adminEnrollmentsEndpoint = 'kaprodi/enrollments';

  // Admin - Reports
  static const String adminReportsByMahasiswa = 'admin/reports/by-mahasiswa';
  static const String adminReportsByMataKuliah = 'admin/reports/by-mata-kuliah';
  static const String adminReportsExportExcel = 'admin/reports/export/excel';
  static const String adminReportsExportPdf = 'admin/reports/export/pdf';

  // Admin - Analysis
  static const String analysisGeofence = 'admin/analysis/geofence';
  static const String analysisFaceVerification =
      'admin/analysis/face-verification';
  static const String analysisLatency = 'admin/analysis/latency';
  static const String analysisAttendanceSp = 'admin/analysis/attendance-sp';

  // Dosen routes
  static const String dosenMataKuliahEndpoint = 'dosen/mata-kuliah';
  static const String dosenAttendanceEndpoint = 'dosen/attendance';
  static const String dosenClassTodayEndpoint = 'dosen/attendance/class-today';
  static const String dosenDashboardEndpoint = 'dosen/dashboard';

  // Kaprodi routes
  static const String kaprodiDashboardEndpoint = 'kaprodi/dashboard';
  static const String kaprodiSpRecordsEndpoint = 'kaprodi/sp-records';
  static const String kaprodiLeaveRequestsEndpoint = 'kaprodi/leave-requests';

  // Kajur routes
  static const String kajurDashboardEndpoint = 'kajur/dashboard';
  static const String kajurSpRecordsEndpoint = 'kajur/sp-records';

  // Timeouts
  static const int connectTimeoutMs = 30000;
  static const int receiveTimeoutMs = 30000;
  static const int sendTimeoutMs = 30000;
}
