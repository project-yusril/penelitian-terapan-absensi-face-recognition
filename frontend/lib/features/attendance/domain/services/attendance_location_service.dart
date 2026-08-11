import '../../../../core/utils/location_utils.dart';

class AttendanceLocationPolicy {
  final double maxAccuracyMeters;
  final int maxAgeSeconds;

  const AttendanceLocationPolicy({
    required this.maxAccuracyMeters,
    required this.maxAgeSeconds,
  });

  factory AttendanceLocationPolicy.fromJson(Map<dynamic, dynamic>? json) {
    final accuracy = json?['max_accuracy_meters'];
    final age = json?['max_age_seconds'];
    return AttendanceLocationPolicy(
      maxAccuracyMeters: accuracy is num ? accuracy.toDouble() : 0,
      maxAgeSeconds: age is num ? age.toInt() : 0,
    );
  }

  bool accepts({required double accuracy, required int ageMs}) =>
      accuracy.isFinite &&
      accuracy >= 0 &&
      accuracy <= maxAccuracyMeters &&
      ageMs >= 0 &&
      ageMs <= maxAgeSeconds * 1000;
}

class AttendancePosition {
  final double latitude;
  final double longitude;
  final double accuracy;
  final bool isMocked;
  final DateTime? timestamp;

  const AttendancePosition({
    required this.latitude,
    required this.longitude,
    required this.accuracy,
    required this.isMocked,
    required this.timestamp,
  });
}

class AttendanceLocationFix {
  final AttendancePosition position;
  final double distanceMeters;
  final bool mockDetected;
  final Duration capturedAtTicks;

  const AttendanceLocationFix({
    required this.position,
    required this.distanceMeters,
    required this.mockDetected,
    required this.capturedAtTicks,
  });

  int ageMs(Duration nowTicks) => (nowTicks - capturedAtTicks).inMilliseconds;
}

abstract class AttendancePositionProvider {
  Future<AttendancePosition> getHighAccuracyPosition({
    required Duration timeout,
  });
  Future<bool> isDeviceMockLocation();
}

class AttendanceLocationException implements Exception {
  final String code;
  const AttendanceLocationException(this.code);
  @override
  String toString() => code;
}

class AttendanceLocationService {
  final AttendancePositionProvider _provider;
  final Duration Function() _ticks;
  final DateTime? Function() _authoritativeNow;
  final DateTime Function()? _deviceNow;

  /// Kelonggaran kecil untuk stempel yang tampak sedikit di depan jam
  /// perangkat akibat pembulatan di layer lokasi. Bukan toleransi skew jam:
  /// stempel dan pembandingnya kini berasal dari jam yang sama.
  static const Duration clockDriftTolerance = Duration(seconds: 2);

  const AttendanceLocationService(
    this._provider, {
    required Duration Function() ticks,
    required DateTime? Function() authoritativeNow,
    DateTime Function()? deviceNow,
  }) : _ticks = ticks,
       _authoritativeNow = authoritativeNow,
       _deviceNow = deviceNow;

  /// Jam perangkat — jam yang sama dengan yang menstempel `position.timestamp`.
  DateTime _nowOnDevice() => (_deviceNow ?? DateTime.now)().toUtc();

  Future<AttendanceLocationFix> acquire({
    required AttendanceLocationPolicy policy,
    required double geofenceLat,
    required double geofenceLon,
    required double geofenceRadius,
    Duration timeout = const Duration(seconds: 12),
  }) async {
    final requestedAt = _authoritativeNow();
    if (requestedAt == null) {
      throw const AttendanceLocationException('server_time_anchor_unavailable');
    }
    final position = await _provider
        .getHighAccuracyPosition(timeout: timeout)
        .timeout(timeout);
    final receivedAtTicks = _ticks();
    final receivedAt = _authoritativeNow();
    final sourceTimestamp = position.timestamp?.toUtc();
    if (receivedAt == null) {
      throw const AttendanceLocationException('server_time_anchor_unavailable');
    }

    // `position.timestamp` distempel oleh jam PERANGKAT, sedangkan
    // `requestedAt`/`receivedAt` berjangkar jam SERVER. Versi sebelumnya
    // membandingkan keduanya secara langsung, sehingga selisih jam sekecil
    // beberapa detik saja membuat setiap fix yang sah ditolak — dan itu bukan
    // kasus teoretis: perangkat uji tercatat 2,9 detik lebih cepat dari
    // server, dan absensi menjadi mustahil dilakukan.
    //
    // Perbandingan kini dilakukan sepenuhnya di domain jam perangkat.
    // Kesegaran fix tetap ditegakkan, tetapi oleh `policy.maxAgeSeconds` di
    // bawah — satu-satunya aturan yang memang dirancang untuk itu, dan yang
    // juga diperiksa ulang saat submit lewat [validatedAgeMs].
    final deviceReceivedAt = _nowOnDevice();
    if (sourceTimestamp == null ||
        sourceTimestamp.isAfter(deviceReceivedAt.add(clockDriftTolerance))) {
      throw const AttendanceLocationException('location_timestamp_rejected');
    }

    // Stempel yang sedikit "di depan" (dalam toleransi) dianggap berumur nol
    // alih-alih negatif, supaya tidak lolos lewat celah umur negatif.
    final rawAge = deviceReceivedAt.difference(sourceTimestamp);
    final sourceAge = rawAge.isNegative ? Duration.zero : rawAge;
    final capturedAt = receivedAtTicks - sourceAge;
    final deviceMock = await _provider.isDeviceMockLocation();
    if (position.isMocked || deviceMock) {
      throw const AttendanceLocationException('mock_location_detected');
    }
    if (!policy.accepts(
      accuracy: position.accuracy,
      ageMs: sourceAge.inMilliseconds,
    )) {
      throw const AttendanceLocationException('location_policy_rejected');
    }
    final distance = LocationUtils.haversineDistance(
      position.latitude,
      position.longitude,
      geofenceLat,
      geofenceLon,
    );
    if (!distance.isFinite || distance > geofenceRadius) {
      throw const AttendanceLocationException('outside_geofence');
    }
    return AttendanceLocationFix(
      position: position,
      distanceMeters: distance,
      mockDetected: false,
      capturedAtTicks: capturedAt,
    );
  }

  int validatedAgeMs(
    AttendanceLocationFix fix,
    AttendanceLocationPolicy policy,
  ) {
    final age = fix.ageMs(_ticks());
    if (!policy.accepts(accuracy: fix.position.accuracy, ageMs: age)) {
      throw const AttendanceLocationException('location_policy_rejected');
    }
    return age;
  }
}
