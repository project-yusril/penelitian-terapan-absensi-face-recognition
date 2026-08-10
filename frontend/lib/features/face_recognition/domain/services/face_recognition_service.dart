import 'dart:math';
import 'package:camera/camera.dart';
import 'package:flutter/foundation.dart';
import 'package:google_mlkit_face_detection/google_mlkit_face_detection.dart';
import 'package:image/image.dart' as img;
import 'package:tflite_flutter/tflite_flutter.dart';
import 'camera_image_converter.dart';
import 'camera_frame_snapshot.dart';

/// MobileFaceNet wrapper.
///
/// PERBAIKAN C-01
/// --------------
/// Versi sebelumnya menerima `Uint8List(0)` (kosong) di `verifyFace`,
/// sehingga embedding kandidat selalu di-generate dari image kosong dan
/// hasil verifikasi tidak valid. Sekarang service menerima `CameraImage`
/// langsung dari stream lalu dikonversi ke `img.Image` 112×112 RGB sebelum
/// diumpankan ke model.
class FaceRecognitionService {
  Interpreter? _interpreter;
  bool _isInitialized = false;

  /// MobileFaceNet menghasilkan embedding 192-dimensi (PRD-01).
  static const int embeddingSize = 192;
  static const int inputSize = 112;

  Future<void> initialize() async {
    if (_isInitialized) return;
    try {
      _interpreter = await Interpreter.fromAsset(
        'assets/mobile_face_net.tflite',
      );
      _isInitialized = true;
    } catch (e) {
      debugPrint('Failed to load MobileFaceNet model: $e');
      rethrow;
    }
  }

  /// Embedding 192-dim dari [imageBytes] (JPEG/PNG) + bounding box wajah.
  Future<List<double>> generateEmbeddingFromBytes(
    Uint8List imageBytes,
    Face face,
  ) async {
    if (!_isInitialized) await initialize();

    final decoded = img.decodeImage(imageBytes);
    if (decoded == null) {
      throw Exception('Failed to decode image bytes');
    }
    return _runInference(decoded, face);
  }

  /// Embedding 192-dim langsung dari [CameraImage] (YUV420 atau BGRA8888).
  Future<List<double>> generateEmbeddingFromCameraImage(
    CameraImage cameraImage,
    Face face,
  ) async {
    if (!_isInitialized) await initialize();

    final decoded = CameraImageConverter.convert(cameraImage);
    return _runInference(decoded, face);
  }

  /// ML Kit applies the same canonical rotation metadata while detecting the
  /// face; rotating pixels here therefore keeps its upright box coordinates
  /// aligned with the embedding crop. Analysis pixels are never mirrored.
  Future<List<double>> generateEmbeddingFromSnapshot(
    CameraFrameSnapshot snapshot,
    Face face,
  ) async {
    if (!_isInitialized) await initialize();
    return _runInference(CameraImageConverter.convertSnapshot(snapshot), face);
  }

  List<double> _runInference(img.Image image, Face face) {
    final box = face.boundingBox;
    final x = box.left.toInt().clamp(0, image.width - 1);
    final y = box.top.toInt().clamp(0, image.height - 1);
    final w = box.width.toInt().clamp(1, image.width - x);
    final h = box.height.toInt().clamp(1, image.height - y);

    final cropped = img.copyCrop(image, x: x, y: y, width: w, height: h);
    final resized = img.copyResize(
      cropped,
      width: inputSize,
      height: inputSize,
    );

    final input = _imageToFloatList(resized);
    final output = List.filled(embeddingSize, 0.0).reshape([1, embeddingSize]);
    _interpreter!.run(input.reshape([1, inputSize, inputSize, 3]), output);
    // M-07: L2-normalize agar euclidean distance berada di rentang wajar (~0..2)
    // dan konsisten dengan threshold yang dipakai backend/evaluasi.
    return _l2Normalize(output[0].cast<double>());
  }

  /// M-07: normalisasi L2 sehingga ||v|| = 1.
  List<double> _l2Normalize(List<double> v) {
    double sumSq = 0.0;
    for (final x in v) {
      sumSq += x * x;
    }
    final norm = sqrt(sumSq);
    if (norm == 0) return v;
    return v.map((x) => x / norm).toList();
  }

  List<List<List<double>>> _imageToFloatList(img.Image image) {
    return List.generate(
      inputSize,
      (y) => List.generate(inputSize, (x) {
        final pixel = image.getPixel(x, y);
        return [
          (pixel.r / 127.5) - 1.0,
          (pixel.g / 127.5) - 1.0,
          (pixel.b / 127.5) - 1.0,
        ];
      }),
    );
  }

  double calculateEuclideanDistance(List<double> a, List<double> b) {
    if (a.length != b.length) {
      throw Exception(
        'Embedding dimensions mismatch (${a.length} vs ${b.length})',
      );
    }
    double sum = 0;
    for (int i = 0; i < a.length; i++) {
      final d = a[i] - b[i];
      sum += d * d;
    }
    return sqrt(sum);
  }

  /// Verifikasi wajah dari CameraImage (C-01: fix tidak lagi pass bytes kosong).
  Future<FaceVerificationResult> verifyFaceFromCamera(
    CameraImage cameraImage,
    Face face,
    List<double> referenceEmbedding,
    double threshold,
  ) async {
    final stopwatch = Stopwatch()..start();
    final current = await generateEmbeddingFromCameraImage(cameraImage, face);
    final distance = calculateEuclideanDistance(current, referenceEmbedding);
    stopwatch.stop();
    return FaceVerificationResult(
      distance: distance,
      threshold: threshold,
      // L-08/R-04: comparator canonical adalah `<=`, sama dengan backend
      // (`face_distance > threshold` => tolak) dan analisis FAR/FRR.
      isMatch: distance <= threshold,
      inferenceTimeMs: stopwatch.elapsedMilliseconds,
    );
  }

  Future<FaceVerificationResult> verifyFaceFromSnapshot(
    CameraFrameSnapshot snapshot,
    Face face,
    List<double> referenceEmbedding,
    double threshold,
  ) async {
    final stopwatch = Stopwatch()..start();
    final current = await generateEmbeddingFromSnapshot(snapshot, face);
    final distance = calculateEuclideanDistance(current, referenceEmbedding);
    stopwatch.stop();
    return FaceVerificationResult(
      distance: distance,
      threshold: threshold,
      // L-08/R-04: comparator canonical `<=` (selaras backend/analisis).
      isMatch: distance <= threshold,
      inferenceTimeMs: stopwatch.elapsedMilliseconds,
    );
  }

  /// Backward-compat: tetap support API lama untuk pemanggil yang
  /// memang sudah punya bytes JPEG/PNG (mis. flow enrollment dari
  /// `takePicture`). Akan throw kalau bytes-nya kosong.
  Future<FaceVerificationResult> verifyFace(
    Uint8List imageBytes,
    Face face,
    List<double> referenceEmbedding,
    double threshold,
  ) async {
    if (imageBytes.isEmpty) {
      throw Exception(
        'verifyFace: imageBytes kosong. Gunakan verifyFaceFromCamera '
        'untuk verifikasi dari kamera stream.',
      );
    }
    final stopwatch = Stopwatch()..start();
    final current = await generateEmbeddingFromBytes(imageBytes, face);
    final distance = calculateEuclideanDistance(current, referenceEmbedding);
    stopwatch.stop();
    return FaceVerificationResult(
      distance: distance,
      threshold: threshold,
      // L-08/R-04: comparator canonical `<=` (selaras backend/analisis).
      isMatch: distance <= threshold,
      inferenceTimeMs: stopwatch.elapsedMilliseconds,
    );
  }

  /// Alias lama supaya pemanggil yang tidak diupdate tetap jalan.
  Future<List<double>> generateEmbedding(Uint8List imageBytes, Face face) =>
      generateEmbeddingFromBytes(imageBytes, face);

  void dispose() {
    _interpreter?.close();
    _isInitialized = false;
  }
}

class FaceVerificationResult {
  final double distance;
  final double threshold;
  final bool isMatch;
  final int inferenceTimeMs;

  FaceVerificationResult({
    required this.distance,
    required this.threshold,
    required this.isMatch,
    required this.inferenceTimeMs,
  });
}
