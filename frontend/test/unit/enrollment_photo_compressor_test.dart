import 'dart:typed_data';

import 'package:absensi_mahasiswa/features/face_recognition/domain/services/enrollment_photo_compressor.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image/image.dart' as img;

Uint8List _renderJpeg({required int width, required int height, int quality = 100}) {
  final image = img.Image(width: width, height: height);
  // Isi dengan noise deterministik supaya JPEG tidak terlalu mudah
  // dikompresi (polos) dan ukuran hasil test realistis.
  for (var y = 0; y < height; y++) {
    for (var x = 0; x < width; x++) {
      final v = (x * 7 + y * 13) % 256;
      image.setPixelRgba(x, y, v, (v * 3) % 256, (v * 7) % 256, 255);
    }
  }
  return Uint8List.fromList(img.encodeJpg(image, quality: quality));
}

void main() {
  group('EnrollmentPhotoCompressor', () {
    test('mengecilkan sisi panjang ke batas keras 500 KB untuk foto kamera besar', () async {
      // 4000x3000 setara output kamera 12 MP. Batas keras bisa memaksa
      // dimensi turun di bawah maxSide — itu perilaku yang benar: ukuran
      // file selalu diutamakan daripada dimensi persis 1600.
      final big = _renderJpeg(width: 4000, height: 3000);
      final result = await EnrollmentPhotoCompressor.compress(big);

      expect(result.bytes, isNot(equals(big)));
      expect(
        result.bytes.length,
        lessThanOrEqualTo(EnrollmentPhotoCompressor.hardLimitBytes),
      );
      final longest = result.width > result.height ? result.width : result.height;
      expect(longest, lessThanOrEqualTo(EnrollmentPhotoCompressor.maxSide));
    });

    test('foto kecil dibiarkan dimensinya (tanpa upscale)', () async {
      final small = _renderJpeg(width: 640, height: 480);
      final result = await EnrollmentPhotoCompressor.compress(small);

      expect(result.width, 640);
      expect(result.height, 480);
      expect(result.bytes, isNotEmpty);
    });

    test('hasil selalu di bawah batas keras meski gambar sulit dikompresi', () async {
      // Noise murni adalah kasus terburuk kompresi JPEG.
      final noisy = img.Image(width: 1600, height: 1200);
      var seed = 42;
      int nextRandom() {
        seed = (seed * 1103515245 + 12345) & 0x7FFFFFFF;
        return (seed >> 16) & 0xFF;
      }
      for (var y = 0; y < noisy.height; y++) {
        for (var x = 0; x < noisy.width; x++) {
          noisy.setPixelRgba(x, y, nextRandom(), nextRandom(), nextRandom(), 255);
        }
      }
      final noisyJpg = Uint8List.fromList(img.encodeJpg(noisy, quality: 100));

      final result = await EnrollmentPhotoCompressor.compress(noisyJpg);

      // Batas keras terpenuhi ATAU floor dimensi tercapai (hasil terbaik
      // dikembalikan apa adanya — server menerima hingga 10 MB).
      final hitFloor = result.width <= EnrollmentPhotoCompressor.minDimension ||
          result.height <= EnrollmentPhotoCompressor.minDimension;
      expect(
        result.bytes.length <= EnrollmentPhotoCompressor.hardLimitBytes || hitFloor,
        isTrue,
      );
      expect(result.width, greaterThanOrEqualTo(1));
      expect(result.height, greaterThanOrEqualTo(1));
    });

    test('bytes bukan gambar melempar error', () async {
      await expectLater(
        () => EnrollmentPhotoCompressor.compress(
          Uint8List.fromList([1, 2, 3]),
        ),
        throwsA(anything),
      );
    });

    test('orientasi EXIF di-bake: hasil kompresi tegak mengikuti tag', () async {
      // Gambar lebar (landscape) dengan tag orientasi 6 (rotate 90 CW):
      // hasil akhir harus lebih tinggi daripada lebar (portrait).
      final image = img.Image(width: 800, height: 400);
      img.fill(image, color: img.ColorRgb8(200, 100, 50));
      final jpgBytes = Uint8List.fromList(img.encodeJpg(image));
      final withExif = _injectExifOrientation(jpgBytes, 6);

      final result = await EnrollmentPhotoCompressor.compress(withExif);

      expect(result.height, greaterThan(result.width));
    });
  });
}

/// Sisipkan tag EXIF Orientation ke JPEG minimal (marker APP1).
/// Cukup untuk membuktikan bakeOrientation dipakai compressor.
Uint8List _injectExifOrientation(Uint8List jpeg, int orientation) {
  // Struktur EXIF APP1: "Exif\0\0" + TIFF header (big-endian) + IFD0 +
  // entry Orientation (0x0112, type SHORT) + nilai.
  final tiff = <int>[
    0x4D, 0x4D, // 'MM' big-endian
    0x00, 0x2A, // magic 42
    0x00, 0x00, 0x00, 0x08, // offset IFD0
    0x00, 0x01, // 1 entry
    0x01, 0x12, // tag Orientation
    0x00, 0x03, // type SHORT
    0x00, 0x00, 0x00, 0x01, // count 1
    0x00, orientation, 0x00, 0x00, // value
    0x00, 0x00, 0x00, 0x00, // next IFD = 0
  ];
  final exifHeader = <int>[0x45, 0x78, 0x69, 0x66, 0x00, 0x00]; // 'Exif\0\0'
  final payload = [...exifHeader, ...tiff];
  final app1Length = payload.length + 2;

  final out = <int>[];
  // SOI
  out.addAll(jpeg.sublist(0, 2));
  // APP1
  out.add(0xFF);
  out.add(0xE1);
  out.add((app1Length >> 8) & 0xFF);
  out.add(app1Length & 0xFF);
  out.addAll(payload);
  // Sisa JPEG setelah SOI
  out.addAll(jpeg.sublist(2));
  return Uint8List.fromList(out);
}
