import 'dart:io';

import 'package:absensi_mahasiswa/features/face_recognition/domain/services/liveness_detection_service.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_mlkit_face_detection/google_mlkit_face_detection.dart';

/// Regresi retry permit-bound: challenge yang tampil saat retry liveness
/// HARUS tetap challenge dari permit server.
///
/// Backend memvalidasi `liveness_challenge` payload terhadap
/// `attendance_permits.encrypted_challenge` saat submit; ketidakcocokan
/// dijawab 403. Karena permit tidak diperbarui antar-retry, halaman tidak
/// boleh mengganti challenge ketika liveness diulang (timeout frame netral,
/// verifikasi wajah gagal, atau error verifikasi).
///
/// Kontrak diuji dua lapis:
/// 1. PERILAKU (service): mengulang liveness dengan challenge permit yang
///    sama — persis yang dilakukan jalur retry halaman (`reset()` lalu
///    `checkChallenge` dengan challenge lama) — harus bisa lolos lagi, dan
///    `getRandomChallenge()` terbukti MENGACAK sehingga tidak boleh dipakai.
/// 2. KONTRAK (source halaman): `AttendancePage` sama sekali tidak memanggil
///    `getRandomChallenge()`, `_challenge` hanya diisi dari permit, dan
///    setiap jalur retry me-reset state liveness dengan `reset()` saja.
///    Pola source-contract sama dengan `biometric_duplicate_contract_test`.
Face _face({
  double smile = 0,
  double eulerX = 0,
  double eulerY = 0,
  double leftEye = 0.9,
  double rightEye = 0.9,
}) {
  return Face(
    boundingBox: const Rect.fromLTWH(0, 0, 100, 100),
    landmarks: const {},
    contours: const {},
    headEulerAngleX: eulerX,
    headEulerAngleY: eulerY,
    leftEyeOpenProbability: leftEye,
    rightEyeOpenProbability: rightEye,
    smilingProbability: smile,
  );
}

/// Dorong satu challenge dari nol hingga lolos: 1 frame netral, lalu
/// 3 frame challenge konsisten (ambang produksi `_requiredConsecutivePass`).
Future<void> _passChallenge(
  LivenessDetectionService service,
  String challenge,
) async {
  service.reset();
  expect(await service.checkChallenge(_face(), challenge), isFalse,
      reason: 'frame pertama adalah netral');
  for (var i = 0; i < 3; i++) {
    final passed = switch (challenge) {
      'smile' => await service.checkChallenge(_face(smile: 0.95), challenge),
      'turn_left' => await service.checkChallenge(_face(eulerY: 35), challenge),
      'turn_right' =>
        await service.checkChallenge(_face(eulerY: -35), challenge),
      'blink' =>
        await service.checkChallenge(_face(leftEye: 0.1, rightEye: 0.1), challenge),
      'nod' => await service.checkChallenge(_face(eulerX: 25), challenge),
      _ => throw ArgumentError(challenge),
    };
    expect(passed, i == 2, reason: 'pass ke-${i + 1} dari 3');
  }
}

void main() {
  group('retry liveness mempertahankan challenge permit (anti-403)', () {
    for (final permitChallenge in ['blink', 'smile', 'turn_left']) {
      test(
          'liveness dapat diulang dengan challenge permit "$permitChallenge" '
          'setelah reset() — siklus retry penuh lolos dua kali', () async {
        final service = LivenessDetectionService();

        // Attempt pertama: alur normal sampai liveness lolos.
        await _passChallenge(service, permitChallenge);
        expect(service.consecutivePass, 3);

        // Jalur retry halaman: _verifyFace gagal / timeout frame netral →
        // HANYA reset(), challenge tidak diganti.
        service.reset();
        expect(service.consecutivePass, 0);
        expect(service.hasNeutral, isFalse);

        // Attempt kedua dengan challenge yang sama harus lolos lagi —
        // state pengukuran dipulihkan penuh oleh reset().
        await _passChallenge(service, permitChallenge);
        expect(service.consecutivePass, 3);
      });
    }

    test('getRandomChallenge() MENGACAK challenge — tidak cocok untuk retry',
        () {
      final service = LivenessDetectionService();
      const attempts = 40;
      final seen = <String>{};

      // Dengan 40 undian atas 5 pilihan, peluang hanya melihat satu nilai
      // adalah 5 * (1/5)^39 ≈ 0 — layak dianggap pasti bervariasi.
      for (var i = 0; i < attempts; i++) {
        seen.add(service.getRandomChallenge());
      }
      expect(seen.length, greaterThan(1),
          reason: 'getRandomChallenge mengacak; retry harus pakai reset()');
    });
  });

  group('kontrak source AttendancePage (permit-bound)', () {
    final source = File(
      'lib/features/attendance/presentation/pages/attendance_page.dart',
    ).readAsStringSync();

    test('halaman tidak memanggil getRandomChallenge() sama sekali', () {
      // Challenge sepenuhnya milik permit server; satu-satunya pemanggil
      // sah getRandomChallenge adalah halaman yang menerbitkan permit baru,
      // dan AttendancePage bukan itu.
      expect(source, isNot(contains('getRandomChallenge')));
    });

    test('_challenge hanya diisi dari permit', () {
      // Buang deklarasi field agar yang tersisa hanya penugasan runtime.
      final withoutDeclaration = source.replaceAll(
        "String _challenge = '';",
        '',
      );
      final assignments = RegExp(r'\b_challenge\s*=')
          .allMatches(withoutDeclaration)
          .toList();
      expect(assignments, hasLength(1),
          reason: 'challenge ditetapkan sekali, saat permit diterima');
      expect(source, contains("_challenge = permit['liveness_challenge']"));
    });

    test('setiap jalur retry me-reset state liveness via reset()', () {
      // Retry path yang diwajibkan memulihkan state: timeout frame netral,
      // verifikasi wajah gagal, error verifikasi, gagal submit, mock GPS,
      // dan lifecycle pause. Masing-masing harus memanggil reset().
      for (final entry in [
        'void _abortNeutralWait()',
        '_livenessPassed = false; // C-02: reset',
        'void _failSubmission(',
        'void _blockMockLocation()',
      ]) {
        expect(source, contains(entry), reason: 'jalur $entry harus ada');
      }
      // reset() dipanggil minimal 6 kali (retry paths + lifecycle) dan
      // TIDAK PERNAH berpasangan dengan pemilihan challenge acak.
      final resetCalls = RegExp('_livenessService\\.reset\\(\\)')
          .allMatches(source)
          .length;
      expect(resetCalls, greaterThanOrEqualTo(6),
          reason: 'semua jalur retry wajib reset state liveness');
    });
  });
}
