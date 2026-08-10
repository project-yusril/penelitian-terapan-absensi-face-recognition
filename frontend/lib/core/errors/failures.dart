abstract class Failure {
  final String message;
  const Failure(this.message);
}

class ServerFailure extends Failure {
  final int? statusCode;
  const ServerFailure(super.message, {this.statusCode});
}

class CacheFailure extends Failure {
  const CacheFailure(super.message);
}

class AuthFailure extends Failure {
  const AuthFailure(super.message);
}

class GeofenceFailure extends Failure {
  final String code;
  const GeofenceFailure(super.message, {required this.code});
}

class FaceRecognitionFailure extends Failure {
  final String code;
  const FaceRecognitionFailure(super.message, {required this.code});
}

class LocationFailure extends Failure {
  const LocationFailure(super.message);
}

class NetworkFailure extends Failure {
  const NetworkFailure(super.message);
}
