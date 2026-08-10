import 'package:absensi_mahasiswa/features/home/domain/entities/home_entities.dart';
import 'package:flutter_test/flutter_test.dart';

JadwalHariIni schedule({
  required bool anchored,
  required DateTime? Function() now,
}) => JadwalHariIni(
  jadwalId: 1,
  mataKuliahId: 1,
  mataKuliah: 'Test',
  dosen: 'Test',
  hari: 'Sabtu',
  jamMulai: '00:00',
  jamSelesai: '23:59',
  ruangan: 'Lab',
  geofenceLat: 0,
  geofenceLon: 0,
  geofenceRadius: 50,
  notBefore: DateTime.utc(2026, 7, 18, 1),
  expiresAt: DateTime.utc(2026, 7, 18, 2),
  backendCanCheckIn: true,
  hasTimeAnchor: anchored,
  anchoredNow: now,
);

void main() {
  test('fails closed when no server time anchor exists', () {
    expect(schedule(anchored: false, now: () => null).canCheckIn, isFalse);
  });

  test('uses anchored authoritative window with inclusive boundaries', () {
    var current = DateTime.utc(2026, 7, 18, 1);
    final item = schedule(anchored: true, now: () => current);
    expect(item.canCheckIn, isTrue);
    current = DateTime.utc(2026, 7, 18, 2);
    expect(item.canCheckIn, isTrue);
    current = DateTime.utc(2026, 7, 18, 2, 0, 0, 1);
    expect(item.canCheckIn, isFalse);
  });
}
