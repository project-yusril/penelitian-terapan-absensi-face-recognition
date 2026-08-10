import 'package:geolocator/geolocator.dart';
import 'package:safe_device/safe_device.dart';

import '../../domain/services/attendance_location_service.dart';

class GeolocatorAttendancePositionProvider
    implements AttendancePositionProvider {
  @override
  Future<AttendancePosition> getHighAccuracyPosition({
    required Duration timeout,
  }) async {
    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied ||
        permission == LocationPermission.deniedForever) {
      throw const AttendanceLocationException('location_permission_denied');
    }
    final position = await Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(accuracy: LocationAccuracy.high),
    ).timeout(timeout);
    return AttendancePosition(
      latitude: position.latitude,
      longitude: position.longitude,
      accuracy: position.accuracy,
      isMocked: position.isMocked,
      timestamp: position.timestamp,
    );
  }

  @override
  Future<bool> isDeviceMockLocation() => SafeDevice.isMockLocation;
}
