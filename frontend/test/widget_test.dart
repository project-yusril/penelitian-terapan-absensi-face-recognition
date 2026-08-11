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

  group('status jadwal', () {
    /// Jadwal 08:00–12:30 dilihat pada pukul 14:30 — jamnya sudah lewat.
    JadwalHariIni buildLewat({String? status}) => JadwalHariIni(
      jadwalId: 4,
      mataKuliahId: 1,
      mataKuliah: 'Pemrograman Mobile',
      dosen: 'Dosen',
      hari: 'Selasa',
      jamMulai: '08:00',
      jamSelesai: '12:30',
      ruangan: 'Lab Komputer 4',
      geofenceLat: 0,
      geofenceLon: 0,
      geofenceRadius: 100,
      attendanceStatus: status,
      notBefore: DateTime.utc(2026, 8, 11, 7, 45),
      expiresAt: DateTime.utc(2026, 8, 11, 12, 45),
      hasTimeAnchor: true,
      anchoredNow: () => DateTime.utc(2026, 8, 11, 14, 30),
    );

    test('jadwal yang jamnya lewat ditandai terlewat, bukan belum dimulai', () {
      final jadwal = buildLewat();

      expect(jadwal.hasEnded, isTrue);
      expect(jadwal.isOngoing, isFalse);
      expect(jadwal.isMissed, isTrue);
      expect(jadwal.isCheckedIn, isFalse);
    });

    test('status alpha tidak dianggap check-in', () {
      // Regresi: isCheckedIn dulu bernilai true untuk SEMUA status selain
      // 'belum', sehingga jadwal yang bolos tampil sebagai "Check-in".
      final jadwal = buildLewat(status: 'alpha');

      expect(jadwal.isCheckedIn, isFalse);
      expect(jadwal.isMissed, isTrue);
      expect(jadwal.canOpenAttendance, isFalse);
    });

    test('izin dan sakit bukan check-in dan bukan alpha', () {
      for (final status in ['izin', 'sakit']) {
        final jadwal = buildLewat(status: status);
        expect(jadwal.isCheckedIn, isFalse, reason: status);
        expect(jadwal.isExcused, isTrue, reason: status);
        expect(jadwal.isMissed, isFalse, reason: status);
      }
    });

    test('hadir_terlambat dan pending tetap dihitung check-in', () {
      for (final status in ['hadir', 'hadir_terlambat', 'pending']) {
        expect(
          buildLewat(status: status).isCheckedIn,
          isTrue,
          reason: status,
        );
      }
    });

    test('jadwal yang belum masuk jendela tidak ditandai terlewat', () {
      final jadwal = JadwalHariIni(
        jadwalId: 5,
        mataKuliahId: 1,
        mataKuliah: 'Pemrograman Mobile',
        dosen: 'Dosen',
        hari: 'Selasa',
        jamMulai: '16:00',
        jamSelesai: '18:00',
        ruangan: 'Lab',
        geofenceLat: 0,
        geofenceLon: 0,
        geofenceRadius: 100,
        notBefore: DateTime.utc(2026, 8, 11, 15, 45),
        expiresAt: DateTime.utc(2026, 8, 11, 18, 15),
        hasTimeAnchor: true,
        anchoredNow: () => DateTime.utc(2026, 8, 11, 14, 30),
      );

      expect(jadwal.hasEnded, isFalse);
      expect(jadwal.isMissed, isFalse);
      expect(jadwal.isOngoing, isFalse);
    });
  });
}
