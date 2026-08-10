import '../../../../core/time/server_time_anchor.dart';
import 'attendance_location_service.dart';

class AttendanceCaptureEvidence {
  final AttendanceLocationFix location;
  final int locationAgeMs;
  final DateTime capturedAt;

  const AttendanceCaptureEvidence({
    required this.location,
    required this.locationAgeMs,
    required this.capturedAt,
  });
}

class AttendanceCaptureOrchestrator {
  final ServerTimeAnchor _anchor;
  final AttendanceLocationService _locations;

  const AttendanceCaptureOrchestrator(this._anchor, this._locations);

  Future<AttendanceCaptureEvidence> capture({
    required AttendanceLocationPolicy policy,
    required double geofenceLat,
    required double geofenceLon,
    required double geofenceRadius,
  }) async {
    if (_anchor.now == null) {
      throw StateError('server_time_anchor_unavailable');
    }
    final fix = await _locations.acquire(
      policy: policy,
      geofenceLat: geofenceLat,
      geofenceLon: geofenceLon,
      geofenceRadius: geofenceRadius,
    );
    final locationAgeMs = _locations.validatedAgeMs(fix, policy);
    final capturedAt = _anchor.now;
    if (capturedAt == null) {
      throw StateError('server_time_anchor_unavailable');
    }
    return AttendanceCaptureEvidence(
      location: fix,
      locationAgeMs: locationAgeMs,
      capturedAt: capturedAt,
    );
  }
}
