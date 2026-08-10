class AppConstants {
  static const String appName = 'Absensi Mahasiswa';
  static const String institutionName = 'Politeknik Negeri Pontianak';
  static const String jurusanName = 'Jurusan Teknik Elektro';

  static const String tokenKey = 'auth_token';
  static const String userKey = 'user_data';
  static const String fcmTokenKey = 'fcm_token';
  static const String embeddingCacheKey = 'embedding_cache';

  static const double defaultFaceThreshold = 1.0;
  static const int livenessTimeoutSeconds = 10;
  static const int livenessChallengeCount = 1;
  static const double defaultGeofenceRadius = 50.0;
  static const double gpsAccuracyMinimum = 20.0;
  static const int offlineSyncTimeoutMinutes = 30;

  static const double sp1Threshold = 16.0;
  static const double sp2Threshold = 32.0;
  static const double sp3Threshold = 38.0;
  static const double doThreshold = 46.0;

  static const int pollingIntervalSeconds = 30;

  static const List<String> livenessChallenges = [
    'smile',
    'turn_left',
    'turn_right',
    'blink',
    'nod',
  ];

  static const Map<String, String> livenessChallengeLabels = {
    'smile': 'Silakan SENYUM',
    'turn_left': 'Silakan tolehkan kepala ke KIRI',
    'turn_right': 'Silakan tolehkan kepala ke KANAN',
    'blink': 'Silakan KEDIPKAN mata',
    'nod': 'Silakan ANGGUKKAN kepala',
  };

  static const Map<String, String> reEnrollmentReasons = {
    'potong_rambut': 'Potong Rambut',
    'pakai_jilbab': 'Pakai Jilbab',
    'lepas_jilbab': 'Lepas Jilbab',
    'perubahan_lain': 'Perubahan Lainnya',
  };
}
