class ServerException implements Exception {
  final String message;
  final int? statusCode;
  final String? code;
  final Map<String, dynamic>? errors;

  ServerException({
    required this.message,
    this.statusCode,
    this.code,
    this.errors,
  });
}

class CacheException implements Exception {
  final String message;
  CacheException({required this.message});
}

class AuthException implements Exception {
  final String message;
  AuthException({required this.message});
}

class GeofenceException implements Exception {
  final String message;
  final String code;
  GeofenceException({required this.message, required this.code});
}

class FaceRecognitionException implements Exception {
  final String message;
  final String code;
  FaceRecognitionException({required this.message, required this.code});
}

class LocationException implements Exception {
  final String message;
  LocationException({required this.message});
}

class MockLocationException implements Exception {
  final String message;
  MockLocationException({required this.message});
}

class NetworkException implements Exception {
  final String message;
  NetworkException({required this.message});
}
