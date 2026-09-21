import 'package:absensi_mahasiswa/features/face_recognition/domain/services/liveness_detection_service.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_mlkit_face_detection/google_mlkit_face_detection.dart';

/// N-01: gate frame netral pasca-liveness di AttendancePage.
///
/// Gate produksi adalah komposisi dua metode LivenessDetectionService yang
/// sama dengan syarat capture final enrollment:
///   isFaceFacingFront(face) && areEyesOpen(face)
/// Test ini mengunci kontrak metode produksi tersebut dengan Face sungguhan
/// (bukan menyalin rumus), sehingga perubahan ambang di service langsung
/// terdeteksi oleh test.
Face _face({
  double? eulerX = 0,
  double? eulerY = 0,
  double? leftEye = 1,
  double? rightEye = 1,
}) {
  return Face(
    boundingBox: const Rect.fromLTWH(0, 0, 100, 100),
    landmarks: const {},
    contours: const {},
    headEulerAngleX: eulerX,
    headEulerAngleY: eulerY,
    leftEyeOpenProbability: leftEye,
    rightEyeOpenProbability: rightEye,
  );
}

bool _passesNeutralGate(Face face) {
  final service = LivenessDetectionService();
  return service.isFaceFacingFront(face) && service.areEyesOpen(face);
}

void main() {
  group('N-01 gate frame netral pasca-liveness (metode produksi)', () {
    test('wajah lurus & mata terbuka lolos gate', () {
      expect(_passesNeutralGate(_face()), isTrue);
    });

    test('wajah masih menoleh dari challenge turn (eulerY 30) ditolak', () {
      expect(_passesNeutralGate(_face(eulerY: 30)), isFalse);
    });

    test('wajah masih angguk dalam dari challenge nod (eulerX 25) lolos gate '
        '(ambang sama dengan syarat capture final enrollment)', () {
      // Ambang produksi isFaceFacingFront adalah |eulerX| < 30, sama persis
      // dengan gate capture final enrollment. N-01 tidak mengubah ambang —
      // hanya menunda verifikasi sampai pose netral tercapai.
      expect(_passesNeutralGate(_face(eulerX: 25)), isTrue);
    });

    test('wajah angguk melewati ambang frontal (eulerX 35) ditolak', () {
      expect(_passesNeutralGate(_face(eulerX: 35)), isFalse);
    });

    test('mata masih terpejam dari challenge blink ditolak', () {
      expect(_passesNeutralGate(_face(leftEye: 0.1, rightEye: 0.1)), isFalse);
    });

    test('satu mata terpejam saja ditolak', () {
      expect(_passesNeutralGate(_face(leftEye: 0.2)), isFalse);
    });

    test('probabilitas null dianggap gagal (fail-closed)', () {
      // areEyesOpen memakai default 0 ketika probabilitas null, jadi wajah
      // tanpa data mata tidak boleh lolos gate.
      expect(_passesNeutralGate(_face(leftEye: null, rightEye: null)), isFalse);
    });
  });
}
