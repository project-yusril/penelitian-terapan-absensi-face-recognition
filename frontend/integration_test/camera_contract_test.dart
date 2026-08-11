import 'dart:async';
import 'dart:io';

import 'package:absensi_mahasiswa/features/face_recognition/domain/services/camera_frame_snapshot.dart';
import 'package:absensi_mahasiswa/features/face_recognition/domain/services/camera_image_converter.dart';
import 'package:camera/camera.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('camera frames satisfy the production converter contract', (
    tester,
  ) async {
    final cameras = await availableCameras();
    expect(cameras, isNotEmpty);

    for (final camera in cameras) {
      final controller = CameraController(
        camera,
        ResolutionPreset.medium,
        enableAudio: false,
        imageFormatGroup: Platform.isIOS
            ? ImageFormatGroup.bgra8888
            : ImageFormatGroup.nv21,
      );

      try {
        await controller.initialize();
        final frame = Completer<CameraImage>();
        await controller.startImageStream((image) {
          if (!frame.isCompleted) frame.complete(image);
        });

        final image = await frame.future.timeout(const Duration(seconds: 15));
        final expectedFormat = Platform.isIOS
            ? ImageFormatGroup.bgra8888
            : ImageFormatGroup.nv21;
        expect(image.format.group, expectedFormat);
        expect(image.planes, hasLength(1));

        final snapshot = CameraFrameSnapshot.copyFrom(
          image: image,
          camera: camera,
          deviceOrientation: controller.value.deviceOrientation,
          platform: Platform.isIOS
              ? CameraPlatformContract.ios
              : CameraPlatformContract.android,
          attemptId: 1,
          frameId: 1,
        );
        final converted = CameraImageConverter.convertSnapshot(snapshot);

        expect(converted.width, greaterThan(0));
        expect(converted.height, greaterThan(0));
      } finally {
        if (controller.value.isStreamingImages) {
          await controller.stopImageStream();
        }
        await controller.dispose();
      }
    }
  });
}
