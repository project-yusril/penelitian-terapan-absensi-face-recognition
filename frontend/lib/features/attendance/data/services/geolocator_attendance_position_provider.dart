import 'package:geolocator/geolocator.dart';

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

  /// N-02: sinyal mock perangkat kini dibaca dari flag `Location.isMock()`
  /// Android pada fix terakhir (`Geolocator.getLastKnownPosition`) — jalur
  /// kanonik yang sama dengan `position.isMocked` di atas. Menggantikan
  /// plugin `safe_device` (tertinggal di KGP lama) dengan zero-dependency
  /// tambahan; hasilnya identik untuk kasus fake GPS yang diteruskan ke
  /// provider lokasi sistem.
  @override
  Future<bool> isDeviceMockLocation() async {
    try {
      final last = await Geolocator.getLastKnownPosition();
      return last?.isMocked ?? false;
    } catch (_) {
      // Tidak ada fix cache / permission berubah di antara panggilan:
      // fail-open di sini karena sinyal ini hanya pelengkap — fix utama
      // yang baru diambil tetap diperiksa lewat `position.isMocked`.
      return false;
    }
  }
}
