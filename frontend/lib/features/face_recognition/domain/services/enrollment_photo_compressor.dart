import 'dart:isolate';
import 'dart:math' as math;
import 'dart:typed_data';

import 'package:image/image.dart' as img;

import '../../../../core/logging/app_logger.dart';

/// Hasil kompresi foto enrollment siap upload.
class CompressedPhoto {
  final Uint8List bytes;
  final int width;
  final int height;

  const CompressedPhoto({
    required this.bytes,
    required this.width,
    required this.height,
  });
}

/// Menyiapkan foto enrollment sebelum dikirim ke server.
///
/// `takePicture()` menghasilkan JPEG ukuran penuh kamera (2–8 MB pada HP
/// modern) sedangkan backend memvalidasi ukuran foto. Karena itu payload
/// dikompresi di sini SEBELUM submit: sisi panjang maksimum [maxSide] px dan
/// kualitas JPEG [quality]. Orientasi EXIF di-bake (pixel diputar mengikuti
/// tag) supaya hasil kompresi tegak tanpa bergantung pada pembaca EXIF
/// penampil gambar.
///
/// Server tetap memakai batas longgar (10 MB) sebagai sabuk pengaman untuk
/// klien lama; kompresi ini menekan payload tipikal ke < 500 KB.
class EnrollmentPhotoCompressor {
  static final AppLogger _log = AppLogger.tag('PhotoCompressor');

  /// Sisi panjang maksimum foto yang dikirim ke server. 1600 px cukup untuk
  /// arsip verifikasi visual Kaprodi tanpa membebani upload jaringan kampus.
  static const int maxSide = 1600;

  /// Kualitas JPEG hasil encode. 85 adalah titik seimbang ukuran vs ketajaman.
  static const int quality = 85;

  /// Batas keras hasil kompresi. Jika encode pertama masih melampaui
  /// [hardLimitBytes], kualitas diturunkan bertahap hingga lolos.
  static const int hardLimitBytes = 500 * 1024;

  /// Kualitas minimum sebelum berhenti menurunkan.
  static const int minQuality = 25;

  /// Floor dimensi (px) saat siklus pengecilan. Mencegah shrink loop
  /// menghasilkan gambar tidak berguna; bila floor tercapai dan masih
  /// melampaui [hardLimitBytes], hasil terbaik dikembalikan apa adanya
  /// (server masih menerima hingga 10 MB).
  static const int minDimension = 64;

  /// Kompres [bytes] (JPEG/PNG dari `takePicture`) menjadi JPEG siap upload.
  ///
  /// Melempar [Exception] bila [bytes] bukan gambar valid.
  ///
  /// Decode/resize/encode `package:image` adalah kerja CPU-bound sinkron
  /// (bisa 4–10 detik untuk foto 12 MP di HP low-end), sehingga seluruh
  /// pekerjaan dijalankan di background isolate — pemanggil tetap responsif.
  static Future<CompressedPhoto> compress(Uint8List bytes) {
    return Isolate.run(() => _compressSync(bytes));
  }

  /// Inti kompresi sinkron. HANYA boleh dipanggil dari isolate lain via
  /// [compress]; tidak pernah dipanggil langsung dari UI isolate.
  static CompressedPhoto _compressSync(Uint8List bytes) {
    final stopwatch = Stopwatch()..start();
    final decodedRaw = img.decodeImage(bytes);
    if (decodedRaw == null) {
      _log.error(
        'kompresi gagal: bytes bukan JPEG/PNG valid',
        data: {'bytes': bytes.length},
      );
      throw Exception('compressEnrollmentPhoto: gambar tidak dapat di-decode');
    }
    final decoded = img.bakeOrientation(decodedRaw);

    var work = _fitToMaxSide(decoded);

    var effectiveQuality = quality;
    var encoded = img.encodeJpg(work, quality: effectiveQuality);
    // Turunkan kualitas bertahap bila masih di atas batas keras. Bila kualitas
    // sudah mencapai [minQuality] dan masih melampaui [hardLimitBytes],
    // kecilkan dimensi 20% dan mulai turunkan kualitas lagi dari [quality].
    // Loop berhenti di floor [minDimension]; hasil terbaik dikembalikan
    // apa adanya — server menerima hingga 10 MB.
    var hitDimensionFloor = false;
    while (encoded.length > hardLimitBytes && !hitDimensionFloor) {
      if (effectiveQuality > minQuality) {
        effectiveQuality -= 10;
        encoded = img.encodeJpg(work, quality: effectiveQuality);
        continue;
      }
      // Kualitas minimum tercapai tapi masih melampaui batas keras.
      if (work.width <= minDimension || work.height <= minDimension) {
        hitDimensionFloor = true;
        _log.error(
          'kompresi mencapai floor dimensi sebelum memenuhi batas keras — '
          'hasil terbaik dikembalikan apa adanya',
          data: {'bytesHasil': encoded.length, 'floor': minDimension},
        );
        break;
      }
      // Pengecilan 20% dibatasi oleh floor [minDimension].
      final newW = math.max(minDimension, (work.width * 0.8).round());
      final newH = math.max(minDimension, (work.height * 0.8).round());
      work = img.copyResize(
        work,
        width: newW,
        height: newH,
        interpolation: img.Interpolation.average,
      );
      effectiveQuality = quality;
      encoded = img.encodeJpg(work, quality: effectiveQuality);
    }

    stopwatch.stop();
    _log.info(
      'foto enrollment dikompres',
      data: {
        'bytesAsli': bytes.length,
        'bytesHasil': encoded.length,
        'asli': '${decoded.width}x${decoded.height}',
        'hasil': '${work.width}x${work.height}',
        'kualitas': effectiveQuality,
        'diFloorDimensi': hitDimensionFloor,
        'ms': stopwatch.elapsedMilliseconds,
      },
    );
    return CompressedPhoto(
      bytes: Uint8List.fromList(encoded),
      width: work.width,
      height: work.height,
    );
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
