import 'package:camera/camera.dart';
import 'package:image/image.dart' as img;

import 'camera_frame_snapshot.dart';

class CameraImageConverter {
  static img.Image convert(CameraImage image) {
    return switch ((image.format.group, image.planes.length)) {
      (ImageFormatGroup.bgra8888, 1) => _bgra(image),
      (ImageFormatGroup.nv21, 1) => _nv21(image),
      (ImageFormatGroup.yuv420, 3) => _yuv420(image),
      _ => throw UnsupportedError(
        'Format kamera ${image.format.group} dengan ${image.planes.length} plane tidak didukung',
      ),
    };
  }

  static img.Image convertSnapshot(CameraFrameSnapshot snapshot) {
    final decoded = switch ((snapshot.format, snapshot.planes.length)) {
      (ImageFormatGroup.bgra8888, 1) => _bgraSnapshot(snapshot),
      (ImageFormatGroup.nv21, 1) => _nv21Snapshot(snapshot),
      (ImageFormatGroup.yuv420, 3) => _yuv420Snapshot(snapshot),
      _ => throw UnsupportedError(
        'Format kamera ${snapshot.format} dengan ${snapshot.planes.length} plane tidak didukung',
      ),
    };
    final rotation = CanonicalCameraRotation.clockwiseDegrees(snapshot);
    final upright = rotation == 0
        ? decoded
        : img.copyRotate(decoded, angle: rotation);
    return snapshot.mirrorPolicy == AnalysisMirrorPolicy.horizontal
        ? img.flipHorizontal(upright)
        : upright;
  }

  static img.Image _bgraSnapshot(CameraFrameSnapshot source) {
    final plane = source.planes[0];
    final bytes = plane.bytes;
    final result = img.Image(width: source.width, height: source.height);
    for (var y = 0; y < source.height; y++) {
      for (var x = 0; x < source.width; x++) {
        final index = y * plane.bytesPerRow + x * 4;
        _assertSnapshotAvailable(bytes, index + 3);
        result.setPixelRgb(
          x,
          y,
          bytes[index + 2],
          bytes[index + 1],
          bytes[index],
        );
      }
    }
    return result;
  }

  static img.Image _nv21Snapshot(CameraFrameSnapshot source) {
    final plane = source.planes[0];
    final bytes = plane.bytes;
    final yRowStride = plane.bytesPerRow >= source.width
        ? plane.bytesPerRow
        : source.width;
    final chromaOffset = yRowStride * source.height;
    final result = img.Image(width: source.width, height: source.height);
    for (var y = 0; y < source.height; y++) {
      for (var x = 0; x < source.width; x++) {
        final yIndex = y * yRowStride + x;
        final uvIndex = chromaOffset + (y ~/ 2) * yRowStride + (x ~/ 2) * 2;
        _assertSnapshotAvailable(bytes, uvIndex + 1);
        _setYuv(
          result,
          x,
          y,
          bytes[yIndex],
          bytes[uvIndex + 1],
          bytes[uvIndex],
        );
      }
    }
    return result;
  }

  static img.Image _yuv420Snapshot(CameraFrameSnapshot source) {
    final yPlane = source.planes[0];
    final uPlane = source.planes[1];
    final vPlane = source.planes[2];
    final yBytes = yPlane.bytes;
    final uBytes = uPlane.bytes;
    final vBytes = vPlane.bytes;
    final result = img.Image(width: source.width, height: source.height);
    for (var y = 0; y < source.height; y++) {
      for (var x = 0; x < source.width; x++) {
        final yIndex = y * yPlane.bytesPerRow + x * (yPlane.bytesPerPixel ?? 1);
        final uIndex =
            (y ~/ 2) * uPlane.bytesPerRow +
            (x ~/ 2) * (uPlane.bytesPerPixel ?? 1);
        final vIndex =
            (y ~/ 2) * vPlane.bytesPerRow +
            (x ~/ 2) * (vPlane.bytesPerPixel ?? 1);
        _assertSnapshotAvailable(yBytes, yIndex);
        _assertSnapshotAvailable(uBytes, uIndex);
        _assertSnapshotAvailable(vBytes, vIndex);
        _setYuv(result, x, y, yBytes[yIndex], uBytes[uIndex], vBytes[vIndex]);
      }
    }
    return result;
  }

  static img.Image _bgra(CameraImage source) {
    final plane = source.planes[0];
    final result = img.Image(width: source.width, height: source.height);
    for (var y = 0; y < source.height; y++) {
      for (var x = 0; x < source.width; x++) {
        final index = y * plane.bytesPerRow + x * 4;
        _assertAvailable(plane, index + 3);
        result.setPixelRgb(
          x,
          y,
          plane.bytes[index + 2],
          plane.bytes[index + 1],
          plane.bytes[index],
        );
      }
    }
    return result;
  }

  static img.Image _nv21(CameraImage source) {
    final plane = source.planes[0];
    final yRowStride = plane.bytesPerRow >= source.width
        ? plane.bytesPerRow
        : source.width;
    final chromaOffset = yRowStride * source.height;
    final chromaRowStride = yRowStride;
    final result = img.Image(width: source.width, height: source.height);

    for (var y = 0; y < source.height; y++) {
      for (var x = 0; x < source.width; x++) {
        final yIndex = y * yRowStride + x;
        final uvIndex =
            chromaOffset + (y ~/ 2) * chromaRowStride + (x ~/ 2) * 2;
        _assertAvailable(plane, uvIndex + 1);
        _setYuv(
          result,
          x,
          y,
          plane.bytes[yIndex],
          plane.bytes[uvIndex + 1],
          plane.bytes[uvIndex],
        );
      }
    }
    return result;
  }

  static img.Image _yuv420(CameraImage source) {
    final yPlane = source.planes[0];
    final uPlane = source.planes[1];
    final vPlane = source.planes[2];
    final result = img.Image(width: source.width, height: source.height);
    for (var y = 0; y < source.height; y++) {
      for (var x = 0; x < source.width; x++) {
        final yIndex = y * yPlane.bytesPerRow + x * (yPlane.bytesPerPixel ?? 1);
        final uIndex =
            (y ~/ 2) * uPlane.bytesPerRow +
            (x ~/ 2) * (uPlane.bytesPerPixel ?? 1);
        final vIndex =
            (y ~/ 2) * vPlane.bytesPerRow +
            (x ~/ 2) * (vPlane.bytesPerPixel ?? 1);
        _assertAvailable(yPlane, yIndex);
        _assertAvailable(uPlane, uIndex);
        _assertAvailable(vPlane, vIndex);
        _setYuv(
          result,
          x,
          y,
          yPlane.bytes[yIndex],
          uPlane.bytes[uIndex],
          vPlane.bytes[vIndex],
        );
      }
    }
    return result;
  }

  static void _setYuv(img.Image target, int x, int y, int yp, int up, int vp) {
    final r = (yp + 1.402 * (vp - 128)).round().clamp(0, 255);
    final g = (yp - 0.344136 * (up - 128) - 0.714136 * (vp - 128))
        .round()
        .clamp(0, 255);
    final b = (yp + 1.772 * (up - 128)).round().clamp(0, 255);
    target.setPixelRgb(x, y, r, g, b);
  }

  static void _assertAvailable(Plane plane, int index) {
    if (index < 0 || index >= plane.bytes.length) {
      throw FormatException('Buffer plane kamera tidak lengkap');
    }
  }

  static void _assertSnapshotAvailable(List<int> bytes, int index) {
    if (index < 0 || index >= bytes.length) {
      throw FormatException('Buffer plane kamera tidak lengkap');
    }
  }
}
