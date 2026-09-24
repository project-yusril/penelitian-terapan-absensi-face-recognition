import 'dart:isolate';
import 'dart:typed_data';

import 'package:image/image.dart' as img;

import '../../../../core/logging/app_logger.dart';
import 'camera_frame_snapshot.dart';
import 'camera_image_converter.dart';

/// Hasil kompresi foto attempt siap upload (multipart atau base64).
class CompressedAttemptFoto {
  final Uint8List bytes;

  const CompressedAttemptFoto({required this.bytes});
}

/// FOTO ATTEMPT BERISIKO — kompres frame kamera check-in/checkout sebelum
/// dikirim ke server.
///
/// Sumber frame adalah `CameraFrameSnapshot` (YUV420/NV21/BGRA) yang sudah
/// ada di pipeline verifikasi; tidak ada `takePicture()` tambahan. Frame
/// dikonversi ke RGB, diperkecil ke sisi panjang [maxSide] (640 px cukup
/// untuk audit visual impostor), lalu di-encode JPEG kualitas [quality] —
/// payload tipikal < 100 KB. Maksimum keras [hardLimitBytes] menyesuaikan
/// validasi server (PhotoUploadPolicy::MAX_FOTO_KB).
class AttemptFotoCompressor {
  static final AppLogger _log = AppLogger.tag('AttemptFotoCompressor');

  /// Sisi panjang maksimum foto attempt. 640 px cukup untuk membandingkan
  /// wajah secara visual di dashboard tanpa membebani payload sync offline.
  static const int maxSide = 640;

  /// Kualitas JPEG hasil encode.
  static const int quality = 70;

  /// Batas keras hasil kompresi (server menerima maks 10 MB, ini jauh
  /// di bawahnya supaya aman untuk batch offline sync).
  static const int hardLimitBytes = 500 * 1024;

  /// Kompres frame snapshot menjadi JPEG siap upload.
  /// Melempar [Exception] bila frame tidak dapat dikonversi.
  static Future<CompressedAttemptFoto> compressSnapshot(
    CameraFrameSnapshot snapshot,
  ) {
    return Isolate.run(() => _compressSync(snapshot));
  }

  /// Inti kompresi sinkron. HANYA dipanggil dari isolate via [compressSnapshot].
  static CompressedAttemptFoto _compressSync(CameraFrameSnapshot snapshot) {
    final stopwatch = Stopwatch()..start();

    final upright = CameraImageConverter.convertSnapshot(snapshot);
    var work = _fitToMaxSide(upright);
    var effectiveQuality = quality;
    var encoded = img.encodeJpg(work, quality: effectiveQuality);
    while (encoded.length > hardLimitBytes && effectiveQuality > 30) {
      effectiveQuality -= 10;
      encoded = img.encodeJpg(work, quality: effectiveQuality);
    }

    stopwatch.stop();
    _log.info(
      'foto attempt dikompres',
      data: {
        'hasil': '${work.width}x${work.height}',
        'bytes': encoded.length,
        'kualitas': effectiveQuality,
        'ms': stopwatch.elapsedMilliseconds,
      },
    );
    return CompressedAttemptFoto(bytes: Uint8List.fromList(encoded));
  }

  /// Kembalikan gambar dengan sisi panjang maksimum [maxSide]; bila sudah
  /// lebih kecil, dikembalikan tanpa perubahan (tanpa upscale).
  static img.Image _fitToMaxSide(img.Image decoded) {
    final longest = decoded.width > decoded.height
        ? decoded.width
        : decoded.height;
    if (longest <= maxSide) return decoded;
    final scale = maxSide / longest;
    return img.copyResize(
      decoded,
      width: (decoded.width * scale).round(),
      height: (decoded.height * scale).round(),
      interpolation: img.Interpolation.average,
    );
  }
}
