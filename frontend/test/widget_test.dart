import 'package:flutter_test/flutter_test.dart';
import 'package:absensi_mahasiswa/core/utils/location_utils.dart';
import 'package:absensi_mahasiswa/core/utils/validators.dart';
import 'package:absensi_mahasiswa/features/home/domain/entities/home_entities.dart';

void main() {
  testWidgets('App smoke test - core utils work', (WidgetTester tester) async {
    final distance = LocationUtils.haversineDistance(
      -0.0234,
      109.3456,
      -0.0234,
      109.3456,
    );
    expect(distance, equals(0.0));

    expect(Validators.validateEmail('test@example.com'), isNull);
    expect(Validators.validatePassword('password123'), isNull);
  });

  test('checked-in schedule exposes checkout navigation action', () {
    final schedule = JadwalHariIni(
      jadwalId: 10,
      mataKuliahId: 20,
      mataKuliah: 'Keamanan',
      dosen: 'Dosen',
      hari: 'Senin',
      jamMulai: '09:00',
      jamSelesai: '11:00',
      ruangan: 'Lab',
      geofenceLat: 0,
      geofenceLon: 0,
      geofenceRadius: 100,
      attendanceStatus: 'hadir',
      attendanceId: 30,
      checkinTime: '09:00',
      notBefore: DateTime.utc(2026, 7, 18, 8, 45),
      expiresAt: DateTime.utc(2026, 7, 18, 11, 15),
      backendCanCheckOut: true,
      hasTimeAnchor: true,
      anchoredNow: () => DateTime.utc(2026, 7, 18, 10),
    );

    expect(schedule.canCheckOut, isTrue);
    expect(schedule.canOpenAttendance, isTrue);
    expect(schedule.attendanceId, 30);
  });
}
