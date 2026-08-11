import 'package:absensi_mahasiswa/features/face_recognition/domain/services/camera_image_converter.dart';
import 'package:absensi_mahasiswa/features/face_recognition/domain/services/camera_frame_snapshot.dart';
import 'package:camera/camera.dart';
import 'package:camera_platform_interface/camera_platform_interface.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('converts one-plane NV21 without accessing missing planes', () {
    final image = CameraImage.fromPlatformInterface(
      CameraImageData(
        format: const CameraImageFormat(ImageFormatGroup.nv21, raw: 17),
        planes: [
          CameraImagePlane(
            bytes: Uint8List.fromList([128, 128, 128, 128, 128, 128]),
            bytesPerRow: 2,
            bytesPerPixel: 1,
          ),
        ],
        height: 2,
        width: 2,
      ),
    );

    final converted = CameraImageConverter.convert(image);

    expect(converted.width, 2);
    expect(converted.height, 2);
    expect(converted.getPixel(0, 0).r, closeTo(128, 1));
  });

  test('converts one-plane BGRA reading BGRA byte order', () {
    // Satu piksel merah penuh: B=0, G=0, R=255, A=255.
    final image = CameraImage.fromPlatformInterface(
      CameraImageData(
        format: const CameraImageFormat(ImageFormatGroup.bgra8888, raw: 1),
        planes: [
          CameraImagePlane(
            bytes: Uint8List.fromList([0, 0, 255, 255]),
            bytesPerRow: 4,
            bytesPerPixel: 4,
          ),
        ],
        height: 1,
        width: 1,
      ),
    );

    final converted = CameraImageConverter.convert(image);

    expect(converted.width, 1);
    expect(converted.height, 1);
    final pixel = converted.getPixel(0, 0);
    expect(pixel.r, 255);
    expect(pixel.g, 0);
    expect(pixel.b, 0);
  });

  test('throws UnsupportedError for unknown format/plane combination', () {
    // YUV420 dengan hanya satu plane bukan kombinasi yang didukung; harus
    // gagal eksplisit (fail-closed), bukan mengakses plane yang tidak ada.
    final image = CameraImage.fromPlatformInterface(
      CameraImageData(
        format: const CameraImageFormat(ImageFormatGroup.yuv420, raw: 35),
        planes: [
          CameraImagePlane(
            bytes: Uint8List.fromList([128, 128, 128, 128]),
            bytesPerRow: 2,
          ),
        ],
        height: 2,
        width: 2,
      ),
    );

    expect(
      () => CameraImageConverter.convert(image),
      throwsA(isA<UnsupportedError>()),
    );
  });

  test('converts three-plane YUV420 with independent strides', () {
    final image = CameraImage.fromPlatformInterface(
      CameraImageData(
        format: const CameraImageFormat(ImageFormatGroup.yuv420, raw: 35),
        planes: [
          CameraImagePlane(
            bytes: Uint8List.fromList([128, 128, 128, 128]),
            bytesPerRow: 2,
          ),
          CameraImagePlane(
            bytes: Uint8List.fromList([128, 0]),
            bytesPerRow: 2,
            bytesPerPixel: 2,
          ),
          CameraImagePlane(
            bytes: Uint8List.fromList([128, 0]),
            bytesPerRow: 2,
            bytesPerPixel: 2,
          ),
        ],
        height: 2,
        width: 2,
      ),
    );

    expect(() => CameraImageConverter.convert(image), returnsNormally);
  });

  test('rotates colored quadrants into canonical upright coordinates', () {
    final snapshot = CameraFrameSnapshot(
      planes: [
        CameraPlaneSnapshot(
          // BGRA: red, green / blue, yellow.
          bytes: const [
            0,
            0,
            255,
            255,
            0,
            255,
            0,
            255,
            255,
            0,
            0,
            255,
            0,
            255,
            255,
            255,
          ],
          bytesPerRow: 8,
          bytesPerPixel: 4,
        ),
      ],
      width: 2,
      height: 2,
      format: ImageFormatGroup.bgra8888,
      sensorOrientation: 90,
      deviceOrientation: DeviceOrientation.portraitUp,
      lensDirection: CameraLensDirection.back,
      platform: CameraPlatformContract.android,
      attemptId: 1,
      frameId: 1,
    );

    final upright = CameraImageConverter.convertSnapshot(snapshot);

    expect(upright.getPixel(0, 0).b, 255); // source bottom-left
    expect(upright.getPixel(1, 0).r, 255); // source top-left
    expect(upright.getPixel(0, 1).g, 255); // source bottom-right yellow
    expect(upright.getPixel(1, 1).g, 255); // source top-right green
  });
}
