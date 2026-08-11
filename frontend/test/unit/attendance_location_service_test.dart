import 'dart:async';

import 'package:absensi_mahasiswa/features/attendance/domain/services/attendance_location_service.dart';
import 'package:flutter_test/flutter_test.dart';

class _Provider implements AttendancePositionProvider {
  AttendancePosition position = AttendancePosition(
    latitude: -0.0263,
    longitude: 109.3425,
    accuracy: 20,
    isMocked: false,
    timestamp: DateTime.utc(2026, 7, 18, 1),
  );
  Future<AttendancePosition> Function(Duration timeout)? response;
  bool deviceMock = false;
  int calls = 0;

  @override
  Future<AttendancePosition> getHighAccuracyPosition({
    required Duration timeout,
  }) async {
    calls++;
    return response?.call(timeout) ?? position;
  }

  @override
  Future<bool> isDeviceMockLocation() async => deviceMock;
}

void main() {
  const policy = AttendanceLocationPolicy(
    maxAccuracyMeters: 20,
    maxAgeSeconds: 10,
  );

  final serverBase = DateTime.utc(2026, 7, 18, 1);

  /// Bangun service dengan jam perangkat yang sengaja meleset dari jam server.
  ///
  /// Jam perangkat WAJIB disuntikkan di test: kalau tidak, service memakai
  /// `DateTime.now()` sungguhan dan fixture waktu palsu akan tampak berumur
  /// berbulan-bulan.
  ///
  /// [deviceSkew] positif berarti jam HP lebih cepat dari server.
  AttendanceLocationService buildService(
    _Provider provider, {
    Duration deviceSkew = Duration.zero,
    Duration Function()? ticks,
  }) {
    final readTicks = ticks ?? () => Duration.zero;
    return AttendanceLocationService(
      provider,
      ticks: readTicks,
      authoritativeNow: () => serverBase.add(readTicks()),
      deviceNow: () => serverBase.add(readTicks()).add(deviceSkew),
    );
  }

  AttendancePosition positionAt(DateTime? timestamp) => AttendancePosition(
    latitude: -0.0263,
    longitude: 109.3425,
    accuracy: 5,
    isMocked: false,
    timestamp: timestamp,
  );

  Matcher throwsCode(String code) => throwsA(
    isA<AttendanceLocationException>().having((e) => e.code, 'code', code),
  );

  test('policy boundaries are inclusive and reject non-finite values', () {
    expect(policy.accepts(accuracy: 20, ageMs: 10000), isTrue);
    expect(policy.accepts(accuracy: 20.001, ageMs: 10000), isFalse);
    expect(policy.accepts(accuracy: 20, ageMs: 10001), isFalse);
    expect(policy.accepts(accuracy: double.nan, ageMs: 0), isFalse);
  });

  test('service uses injectable provider and monotonic age', () async {
    var ticks = Duration.zero;
    final provider = _Provider();
    final service = buildService(provider, ticks: () => ticks);

    final fix = await service.acquire(
      policy: policy,
      geofenceLat: -0.0263,
      geofenceLon: 109.3425,
      geofenceRadius: 100,
    );
    ticks = const Duration(seconds: 10);

    expect(provider.calls, 1);
    expect(service.validatedAgeMs(fix, policy), 10000);
  });

  test('combines position and device mock signals', () async {
    final provider = _Provider()..deviceMock = true;
    final service = buildService(provider);

    expect(
      () => service.acquire(
        policy: policy,
        geofenceLat: -0.0263,
        geofenceLon: 109.3425,
        geofenceRadius: 100,
      ),
      throwsA(isA<AttendanceLocationException>()),
    );
  });

  test('rejects null and future source timestamps', () async {
    final provider = _Provider();
    final service = buildService(provider);

    for (final timestamp in <DateTime?>[
      null,
      // Jauh di depan jam perangkat, di luar toleransi drift.
      DateTime.utc(2026, 7, 18, 1, 0, 30),
    ]) {
      provider.position = positionAt(timestamp);
      await expectLater(
        service.acquire(
          policy: policy,
          geofenceLat: -0.0263,
          geofenceLon: 109.3425,
          geofenceRadius: 100,
        ),
        throwsCode('location_timestamp_rejected'),
      );
    }
  });

  // Regresi: jam perangkat yang meleset beberapa detik dari server dulu
  // membuat SETIAP fix yang sah ditolak, karena stempel jam perangkat
  // dibandingkan dengan batas jam server.
  test('accepts a valid fix even when the device clock is skewed', () async {
    for (final skew in [
      const Duration(seconds: 3), // HP lebih cepat — kasus nyata di perangkat uji
      const Duration(seconds: -3), // HP lebih lambat
    ]) {
      final provider = _Provider()
        ..position = positionAt(DateTime.utc(2026, 7, 18, 1).add(skew));
      final service = buildService(provider, deviceSkew: skew);

      final fix = await service.acquire(
        policy: policy,
        geofenceLat: -0.0263,
        geofenceLon: 109.3425,
        geofenceRadius: 100,
      );

      expect(fix.ageMs(Duration.zero), 0, reason: 'skew $skew');
    }
  });

  test('accepts a slightly stale fix within the age policy', () async {
    // Android lazim menyajikan fix beberapa detik lampau; itu sah selama
    // masih di dalam maxAgeSeconds.
    final provider = _Provider()
      ..position = positionAt(DateTime.utc(2026, 7, 18, 0, 59, 56));
    final service = buildService(provider);

    final fix = await service.acquire(
      policy: policy,
      geofenceLat: -0.0263,
      geofenceLon: 109.3425,
      geofenceRadius: 100,
    );

    expect(fix.ageMs(Duration.zero), 4000);
  });

  test('rejects a fix older than the age policy', () async {
    // Kesegaran tetap ditegakkan — sekarang oleh aturan yang memang untuk itu.
    final provider = _Provider()
      ..position = positionAt(DateTime.utc(2026, 7, 18, 0, 59, 45));
    final service = buildService(provider);

    await expectLater(
      service.acquire(
        policy: policy,
        geofenceLat: -0.0263,
        geofenceLon: 109.3425,
        geofenceRadius: 100,
      ),
      throwsCode('location_policy_rejected'),
    );
  });

  test('times out a position request', () async {
    final provider = _Provider()
      ..response = (_) => Completer<AttendancePosition>().future;
    final service = buildService(provider);

    await expectLater(
      service.acquire(
        policy: policy,
        geofenceLat: -0.0263,
        geofenceLon: 109.3425,
        geofenceRadius: 100,
        timeout: const Duration(milliseconds: 1),
      ),
      throwsA(isA<TimeoutException>()),
    );
  });
}
