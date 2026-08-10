import 'package:google_mlkit_face_detection/google_mlkit_face_detection.dart';

/// Liveness Detection (Anti-spoofing) Service.
///
/// PERBAIKAN C-04
/// --------------
/// Versi sebelumnya cuma cek satu frame: kalau wajah kebetulan tersenyum,
/// challenge "smile" lolos hanya karena foto cetak/selfie statis. Sekarang
/// pakai pola temporal: butuh state "NEUTRAL" dulu (mis. mulut tertutup,
/// kepala lurus, mata terbuka) lalu berubah ke state "CHALLENGE" (mis.
/// senyum, kepala menoleh) — semuanya dalam window beberapa frame untuk
/// menghindari false-pass dari foto statis.
class LivenessDetectionService {
  /// Min selisih probabilitas dari netral → challenge sebelum dianggap pass
  static const double _smileDelta = 0.6;
  static const double _blinkDelta = 0.5;
  static const double _angleDelta = 25.0; // derajat
  static const double _eyeOpenBaseline = 0.7;

  /// Apakah kita sudah lihat frame "netral" untuk challenge saat ini?
  bool _hasNeutral = false;
  // Snapshot probability waktu netral
  double _neutralSmileProb = 0;
  double _neutralLeftEye = 1;
  double _neutralRightEye = 1;
  double _neutralEulerX = 0;
  double _neutralEulerY = 0;
  int _consecutivePass = 0;

  /// Hitung frame berturut-turut yang harus pass agar liveness lolos.
  /// Lebih besar = lebih aman dari spoofing tapi lebih lambat.
  static const int _requiredConsecutivePass = 3;

  /// Reset state ketika challenge baru dipilih.
  void reset() {
    _hasNeutral = false;
    _consecutivePass = 0;
  }

  /// Apakah sudah menangkap frame "netral" yang sah (untuk panduan UI).
  bool get hasNeutral => _hasNeutral;

  /// Berapa frame challenge yang sudah konsisten (0.._requiredConsecutivePass).
  int get consecutivePass => _consecutivePass;

  /// Total frame konsisten yang dibutuhkan agar liveness lolos.
  int get requiredConsecutivePass => _requiredConsecutivePass;

  /// Progress liveness 0.0..1.0 untuk progress bar di UI.
  double get progress =>
      (_consecutivePass / _requiredConsecutivePass).clamp(0.0, 1.0);

  String getRandomChallenge() {
    final challenges = ['smile', 'turn_left', 'turn_right', 'blink', 'nod'];
    challenges.shuffle();
    reset();
    return challenges.first;
  }

  /// Periksa challenge berbasis pola temporal.
  /// Return true HANYA bila sudah ada frame netral DAN sekarang frame
  /// "challenge" konsisten beberapa kali berturut-turut.
  Future<bool> checkChallenge(Face face, String challenge) async {
    // Kalau wajah miring banget atau mata tertutup di awal, anggap belum
    // pernah lihat frame "netral" yang sah.
    if (!_hasNeutral) {
      if (_isFaceNeutral(face, challenge)) {
        _hasNeutral = true;
        _neutralSmileProb = face.smilingProbability ?? 0;
        _neutralLeftEye = face.leftEyeOpenProbability ?? 1;
        _neutralRightEye = face.rightEyeOpenProbability ?? 1;
        _neutralEulerX = face.headEulerAngleX ?? 0;
        _neutralEulerY = face.headEulerAngleY ?? 0;
      }
      return false;
    }

    final passed = switch (challenge) {
      'smile' => _checkSmileDelta(face),
      'turn_left' => _checkTurnLeft(face),
      'turn_right' => _checkTurnRight(face),
      'blink' => _checkBlink(face),
      'nod' => _checkNod(face),
      _ => false,
    };

    if (passed) {
      _consecutivePass++;
      if (_consecutivePass >= _requiredConsecutivePass) {
        // jangan auto-reset di sini; caller (attendance page) yang reset
        return true;
      }
    } else {
      _consecutivePass = 0;
    }
    return false;
  }

  /// Wajah dianggap netral untuk semua challenge bila:
  /// - lurus menghadap kamera (eulerX/Y kecil)
  /// - mata terbuka
  /// - tidak sedang tersenyum lebar (untuk smile challenge)
  bool _isFaceNeutral(Face face, String challenge) {
    final eulerX = (face.headEulerAngleX ?? 0).abs();
    final eulerY = (face.headEulerAngleY ?? 0).abs();
    final leftEye = face.leftEyeOpenProbability ?? 1;
    final rightEye = face.rightEyeOpenProbability ?? 1;
    final smile = face.smilingProbability ?? 0;

    if (eulerX > 15 || eulerY > 15) return false;
    if (leftEye < _eyeOpenBaseline || rightEye < _eyeOpenBaseline) return false;
    if (challenge == 'smile' && smile > 0.3) return false;
    return true;
  }

  bool _checkSmileDelta(Face face) {
    final smile = face.smilingProbability ?? 0;
    return (smile - _neutralSmileProb) >= _smileDelta;
  }

  // Catatan: kamera depan menghasilkan citra ter-mirror, sehingga sumbu Y
  // euler dari ML Kit berlawanan dengan arah toleh user. Maka "toleh kanan"
  // (dari perspektif user) = euler Y bertambah positif, dan sebaliknya.
  bool _checkTurnLeft(Face face) {
    final eulerY = face.headEulerAngleY ?? 0;
    return (eulerY - _neutralEulerY) >= _angleDelta;
  }

  bool _checkTurnRight(Face face) {
    final eulerY = face.headEulerAngleY ?? 0;
    return (eulerY - _neutralEulerY) <= -_angleDelta;
  }

  bool _checkBlink(Face face) {
    final leftEye = face.leftEyeOpenProbability ?? 1;
    final rightEye = face.rightEyeOpenProbability ?? 1;
    return (_neutralLeftEye - leftEye) >= _blinkDelta &&
        (_neutralRightEye - rightEye) >= _blinkDelta;
  }

  bool _checkNod(Face face) {
    final eulerX = face.headEulerAngleX ?? 0;
    return (eulerX - _neutralEulerX).abs() >= (_angleDelta - 10);
  }

  bool hasSingleFace(List<Face> faces) => faces.length == 1;

  bool isFaceFacingFront(Face face) {
    final eulerX = face.headEulerAngleX?.abs() ?? 90;
    final eulerY = face.headEulerAngleY?.abs() ?? 90;
    return eulerX < 30 && eulerY < 30;
  }

  bool areEyesOpen(Face face) {
    final leftEye = face.leftEyeOpenProbability ?? 0;
    final rightEye = face.rightEyeOpenProbability ?? 0;
    return leftEye > 0.5 && rightEye > 0.5;
  }

  void dispose() {}
}
