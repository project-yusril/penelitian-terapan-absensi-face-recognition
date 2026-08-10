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

  test('policy boundaries are inclusive and reject non-finite values', () {
    expect(policy.accepts(accuracy: 20, ageMs: 10000), isTrue);
    expect(policy.accepts(accuracy: 20.001, ageMs: 10000), isFalse);
    expect(policy.accepts(accuracy: 20, ageMs: 10001), isFalse);
    expect(policy.accepts(accuracy: double.nan, ageMs: 0), isFalse);
  });

  test('service uses injectable provider and monotonic age', () async {
    var ticks = Duration.zero;
    final provider = _Provider();
    final service = AttendanceLocationService(
      provider,
      ticks: () => ticks,
      authoritativeNow: () => DateTime.utc(2026, 7, 18, 1).add(ticks),
    );

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
    final service = AttendanceLocationService(
      provider,
      ticks: () => Duration.zero,
      authoritativeNow: () => DateTime.utc(2026, 7, 18, 1),
    );

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

  test('rejects cached, future, and null source timestamps', () async {
    var ticks = Duration.zero;
    final provider = _Provider();
    final service = AttendanceLocationService(
      provider,
      ticks: () => ticks,
      authoritativeNow: () => DateTime.utc(2026, 7, 18, 1).add(ticks),
    );

    for (final timestamp in <DateTime?>[
      DateTime.utc(2026, 7, 18, 0, 59, 59),
      DateTime.utc(2026, 7, 18, 1, 0, 1),
      null,
    ]) {
      provider.position = AttendancePosition(
        latitude: -0.0263,
        longitude: 109.3425,
        accuracy: 5,
        isMocked: false,
        timestamp: timestamp,
      );
      await expectLater(
        service.acquire(
          policy: policy,
          geofenceLat: -0.0263,
          geofenceLon: 109.3425,
          geofenceRadius: 100,
        ),
        throwsA(
          isA<AttendanceLocationException>().having(
            (error) => error.code,
            'code',
            'location_timestamp_rejected',
          ),
        ),
      );
    }
  });

  test('times out a position request', () async {
    final provider = _Provider()
      ..response = (_) => Completer<AttendancePosition>().future;
    final service = AttendanceLocationService(
      provider,
      ticks: () => Duration.zero,
      authoritativeNow: () => DateTime.utc(2026, 7, 18, 1),
    );

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
