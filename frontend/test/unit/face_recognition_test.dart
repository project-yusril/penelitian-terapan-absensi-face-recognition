import 'dart:math';

import 'package:absensi_mahasiswa/features/face_recognition/domain/services/enrollment_identity_continuity.dart';
import 'package:absensi_mahasiswa/features/face_recognition/domain/services/face_recognition_service.dart';
import 'package:absensi_mahasiswa/features/face_recognition/domain/services/liveness_detection_service.dart';
import 'package:flutter_test/flutter_test.dart';

/// L-06: test ini menguji KODE PRODUCTION, bukan menyalin ulang rumus lokal.
///
/// - Euclidean distance memanggil `FaceRecognitionService.calculateEuclideanDistance`.
/// - Comparator match diverifikasi via `EnrollmentIdentityContinuity.matches`
///   yang memakai comparator produksi (L-08/R-04: `<=`), termasuk boundary
///   distance == threshold.
/// - Challenge liveness memakai `LivenessDetectionService` produksi.
void main() {
  // `FaceRecognitionService()` tidak memuat interpreter TFLite sampai
  // initialize() dipanggil, sehingga method murni aman diuji di unit test.
  final service = FaceRecognitionService();

  group('FaceRecognitionService.calculateEuclideanDistance (produksi)', () {
    test('mengembalikan 0 untuk embedding identik', () {
      final embedding = List.generate(192, (i) => 0.5);
      expect(service.calculateEuclideanDistance(embedding, embedding), 0.0);
    });

    test('menghitung jarak yang benar untuk embedding berbeda', () {
      final e1 = List.generate(192, (i) => 0.0);
      final e2 = List.generate(192, (i) => 1.0);
      expect(
        service.calculateEuclideanDistance(e1, e2),
        closeTo(sqrt(192), 0.001),
      );
    });

    test('simetris', () {
      final e1 = [0.1, 0.2, 0.3, 0.4, 0.5];
      final e2 = [0.5, 0.4, 0.3, 0.2, 0.1];
      expect(
        service.calculateEuclideanDistance(e1, e2),
        service.calculateEuclideanDistance(e2, e1),
      );
    });

    test('menolak dimensi embedding yang tidak cocok', () {
      expect(
        () => service.calculateEuclideanDistance([0.1, 0.2], [0.1]),
        throwsException,
      );
    });
  });

  group('Comparator match produksi (L-08/R-04: <=)', () {
    // Bind sekali dan uji melalui comparator produksi. Jarak dihitung
    // sebagai euclidean pada continuity, sama dengan backend/analisis.
    EnrollmentIdentityContinuity bound(List<double> embedding) {
      final c = EnrollmentIdentityContinuity();
      c.bind(attemptId: 1, embedding: embedding);
      return c;
    }

    test('match ketika distance < threshold', () {
      final c = bound(const [0.0, 0.0]);
      // distance = 0.6 < 1.0
      expect(
        c.matches(attemptId: 1, candidate: const [0.6, 0.0], threshold: 1.0),
        isTrue,
      );
    });

    test('match ketika distance == threshold (boundary <=)', () {
      final c = bound(const [0.0, 0.0]);
      // distance = 1.0 == threshold 1.0 => harus match dengan comparator `<=`.
      // Test ini akan gagal bila kode kembali ke `<`.
      expect(
        c.matches(attemptId: 1, candidate: const [1.0, 0.0], threshold: 1.0),
        isTrue,
      );
    });

    test('tidak match ketika distance > threshold', () {
      final c = bound(const [0.0, 0.0]);
      // distance = 1.5 > 1.0
      expect(
        c.matches(attemptId: 1, candidate: const [1.5, 0.0], threshold: 1.0),
        isFalse,
      );
    });
  });

  group('LivenessDetectionService (produksi)', () {
    test('getRandomChallenge mengembalikan challenge yang valid dan reset state',
        () {
      final svc = LivenessDetectionService();
      const valid = {'smile', 'turn_left', 'turn_right', 'blink', 'nod'};

      for (var i = 0; i < 20; i++) {
        final challenge = svc.getRandomChallenge();
        expect(valid.contains(challenge), isTrue);
        // reset() dipanggil di dalam getRandomChallenge.
        expect(svc.consecutivePass, 0);
        expect(svc.hasNeutral, isFalse);
      }
    });

    test('progress ter-clamp pada rentang 0..1', () {
      final svc = LivenessDetectionService();
      expect(svc.progress, inInclusiveRange(0.0, 1.0));
    });
  });
}
