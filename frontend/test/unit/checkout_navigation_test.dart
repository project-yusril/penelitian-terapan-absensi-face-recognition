import 'package:absensi_mahasiswa/features/home/domain/entities/home_entities.dart';
import 'package:absensi_mahasiswa/features/home/presentation/widgets/jadwal_card.dart';
import 'package:absensi_mahasiswa/main.dart' show buildAttendancePageFor;
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// H-13: kontrak navigasi checkout.
///
/// Menutup gap "action checkout dapat dicapai + test navigasi" pada level
/// widget: tap kartu jadwal yang sudah check-in benar-benar memicu navigasi,
/// dan pemetaan argumen route menghasilkan halaman dalam mode checkout dengan
/// `attendanceId` yang benar. Integration test end-to-end penuh (kamera/GPS)
/// tetap memerlukan perangkat/emulator.
JadwalHariIni _checkedInJadwal({int? attendanceId = 30}) {
  final now = DateTime(2026, 8, 11, 10, 0);
  return JadwalHariIni(
    jadwalId: 12,
    mataKuliahId: 5,
    mataKuliah: 'Sistem Digital',
    dosen: 'Dr. Rani',
    hari: 'senin',
    jamMulai: '09:00:00',
    jamSelesai: '11:00:00',
    ruangan: 'Lab 2',
    geofenceLat: -0.05,
    geofenceLon: 109.3,
    geofenceRadius: 40,
    attendanceStatus: 'hadir',
    attendanceId: attendanceId,
    checkinTime: '2026-08-11T09:05:00',
    // belum checkout
    notBefore: now.subtract(const Duration(hours: 1)),
    expiresAt: now.add(const Duration(hours: 1)),
    backendCanCheckOut: true,
    hasTimeAnchor: true,
    anchoredNow: () => now,
  );
}

void main() {
  group('JadwalCard navigation (H-13)', () {
    testWidgets('checked-in card is tappable and fires onTap', (tester) async {
      final jadwal = _checkedInJadwal();
      var tapped = 0;

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: JadwalCard(jadwal: jadwal, onTap: () => tapped++),
          ),
        ),
      );

      // Kartu harus terekspos sebagai tombol checkout ke a11y. Semantics
      // digabung dengan teks anak, jadi cocokkan sebagai substring.
      expect(
        find.bySemanticsLabel(RegExp('Check-out ${jadwal.mataKuliah}')),
        findsOneWidget,
      );

      await tester.tap(find.byKey(ValueKey('attendance-action-${jadwal.jadwalId}')));
      await tester.pump();

      expect(tapped, 1);
    });

    testWidgets('tap navigates to /attendance with the jadwal argument', (
      tester,
    ) async {
      final jadwal = _checkedInJadwal();
      Object? capturedArgs;

      await tester.pumpWidget(
        MaterialApp(
          onGenerateRoute: (settings) {
            if (settings.name == '/attendance') {
              capturedArgs = settings.arguments;
              // Halaman tujuan digantikan placeholder agar test tidak perlu
              // membangun state kamera/GPS AttendancePage.
              return MaterialPageRoute(
                builder: (_) => const Scaffold(body: Text('attendance-route')),
              );
            }
            return null;
          },
          home: Builder(
            builder: (context) => Scaffold(
              body: JadwalCard(
                jadwal: jadwal,
                onTap: () => Navigator.pushNamed(
                  context,
                  '/attendance',
                  arguments: jadwal,
                ),
              ),
            ),
          ),
        ),
      );

      await tester.tap(find.byKey(ValueKey('attendance-action-${jadwal.jadwalId}')));
      await tester.pumpAndSettle();

      expect(find.text('attendance-route'), findsOneWidget);
      expect(capturedArgs, isA<JadwalHariIni>());
      expect((capturedArgs as JadwalHariIni).jadwalId, jadwal.jadwalId);
      expect((capturedArgs as JadwalHariIni).attendanceId, 30);
    });
  });

  group('route contract maps to checkout mode (H-13)', () {
    test('checked-in schedule builds AttendancePage in checkout mode', () {
      final page = buildAttendancePageFor(_checkedInJadwal(attendanceId: 42));

      expect(page.isCheckout, isTrue);
      expect(page.attendanceId, 42);
      expect(page.jadwalId, 12);
      expect(page.mataKuliahName, 'Sistem Digital');
    });

    test('not-yet-checked-in schedule builds check-in mode', () {
      final now = DateTime(2026, 8, 11, 10, 0);
      final jadwal = JadwalHariIni(
        jadwalId: 12,
        mataKuliahId: 5,
        mataKuliah: 'Sistem Digital',
        dosen: 'Dr. Rani',
        hari: 'senin',
        jamMulai: '09:00:00',
        jamSelesai: '11:00:00',
        ruangan: 'Lab 2',
        geofenceLat: -0.05,
        geofenceLon: 109.3,
        geofenceRadius: 40,
        attendanceStatus: 'belum',
        notBefore: now.subtract(const Duration(hours: 1)),
        expiresAt: now.add(const Duration(hours: 1)),
        backendCanCheckIn: true,
        hasTimeAnchor: true,
        anchoredNow: () => now,
      );

      final page = buildAttendancePageFor(jadwal);

      expect(page.isCheckout, isFalse);
      expect(page.attendanceId, isNull);
    });
  });
}
