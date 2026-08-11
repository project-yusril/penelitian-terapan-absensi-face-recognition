import 'package:absensi_mahasiswa/features/home/domain/entities/home_entities.dart';
import 'package:absensi_mahasiswa/features/home/presentation/widgets/jadwal_card.dart';
import 'package:absensi_mahasiswa/main.dart' show buildAttendancePageFor;
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('checked-in schedule opens the checkout route contract', (
    tester,
  ) async {
    final now = DateTime(2026, 8, 11, 10);
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
      attendanceStatus: 'hadir',
      attendanceId: 42,
      checkinTime: '2026-08-11T09:05:00',
      notBefore: now.subtract(const Duration(hours: 1)),
      expiresAt: now.add(const Duration(hours: 1)),
      backendCanCheckOut: true,
      hasTimeAnchor: true,
      anchoredNow: () => now,
    );

    await tester.pumpWidget(
      MaterialApp(
        onGenerateRoute: (settings) {
          if (settings.name != '/attendance') return null;
          final page = buildAttendancePageFor(
            settings.arguments! as JadwalHariIni,
          );
          return MaterialPageRoute<void>(
            builder: (_) => Scaffold(
              body: Text(
                '${page.isCheckout ? 'Check-out' : 'Check-in'}:${page.attendanceId}',
              ),
            ),
          );
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

    await tester.tap(find.byKey(const ValueKey('attendance-action-12')));
    await tester.pumpAndSettle();

    expect(find.text('Check-out:42'), findsOneWidget);
  });
}
