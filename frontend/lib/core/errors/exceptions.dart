/// Exception domain aplikasi.
///
/// Setiap kelas di sini WAJIB meng-override [toString].
///
/// Alasannya bukan kosmetik: kode UI banyak yang menulis pesan dengan
/// interpolasi, mis. `'Tidak dapat memperoleh permit absensi: $e'`. Tanpa
/// [toString], Dart mencetak `Instance of 'ServerException'` — user melihat
/// kalimat yang tidak berarti apa-apa, dan penyebab aslinya (yang sudah ada
/// di dalam objek) tidak pernah sampai ke layar.
library;

class ServerException implements Exception {
  final String message;
  final int? statusCode;
  final String? code;
  final Map<String, dynamic>? errors;
  final Map<String, dynamic>? details;

  ServerException({
    required this.message,
    this.statusCode,
    this.code,
    this.errors,
    this.details,
  });

  @override
  String toString() {
    final detail = errors?.entries
        .map((entry) => '${entry.key}: ${entry.value}')
        .join('; ');
    return [
      message,
      if (statusCode != null) '(HTTP $statusCode)',
      if (detail != null && detail.isNotEmpty) detail,
    ].join(' ');
  }
}

class CacheException implements Exception {
  final String message;
  CacheException({required this.message});

  @override
  String toString() => message;
}

class AuthException implements Exception {
  final String message;
  AuthException({required this.message});

  @override
  String toString() => message;
}

class GeofenceException implements Exception {
  final String message;
  final String code;
  GeofenceException({required this.message, required this.code});

  @override
  String toString() => message;
}

class FaceRecognitionException implements Exception {
  final String message;
  final String code;
  FaceRecognitionException({required this.message, required this.code});

  @override
  String toString() => message;
}

class LocationException implements Exception {
  final String message;
  LocationException({required this.message});

  @override
  String toString() => message;
}

class MockLocationException implements Exception {
  final String message;
  MockLocationException({required this.message});

  @override
  String toString() => message;
}

class NetworkException implements Exception {
  final String message;
  NetworkException({required this.message});

  @override
  String toString() => message;
}
