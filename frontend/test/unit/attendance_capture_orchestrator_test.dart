import 'package:absensi_mahasiswa/core/time/server_time_anchor.dart';
import 'package:absensi_mahasiswa/features/attendance/domain/services/attendance_capture_orchestrator.dart';
import 'package:absensi_mahasiswa/features/attendance/domain/services/attendance_location_service.dart';
import 'package:flutter_test/flutter_test.dart';

class _SequentialProvider implements AttendancePositionProvider {
  int calls = 0;

  @override
  Future<AttendancePosition> getHighAccuracyPosition({
    required Duration timeout,
  }) async {
    calls++;
    return AttendancePosition(
      latitude: -0.0263,
      longitude: 109.3425,
      accuracy: calls == 1 ? 10 : 4,
      isMocked: false,
      timestamp: DateTime.utc(2026, 7, 18, 1, 0, calls == 1 ? 0 : 3),
    );
  }

  @override
  Future<bool> isDeviceMockLocation() async => false;
}

void main() {
  test(
    'submission acquires a second fix and uses anchored capture time',
    () async {
      var ticks = Duration.zero;
      final anchor = ServerTimeAnchor(ticks: () => ticks)
        ..anchorFromIso('2026-07-18T01:00:00Z');
      final provider = _SequentialProvider();
      final locations = AttendanceLocationService(
        provider,
        ticks: () => ticks,
        authoritativeNow: () => anchor.now,
      );
      const policy = AttendanceLocationPolicy(
        maxAccuracyMeters: 20,
        maxAgeSeconds: 10,
      );

      await locations.acquire(
        policy: policy,
        geofenceLat: -0.0263,
        geofenceLon: 109.3425,
        geofenceRadius: 100,
      );
      ticks = const Duration(seconds: 3);
      final evidence = await AttendanceCaptureOrchestrator(anchor, locations)
          .capture(
            policy: policy,
            geofenceLat: -0.0263,
            geofenceLon: 109.3425,
            geofenceRadius: 100,
          );

      expect(provider.calls, 2);
      expect(evidence.location.position.accuracy, 4);
      expect(evidence.capturedAt, DateTime.utc(2026, 7, 18, 1, 0, 3));
    },
  );
}
