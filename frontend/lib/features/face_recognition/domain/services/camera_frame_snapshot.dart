import 'package:camera/camera.dart';
import 'package:flutter/services.dart';

enum CameraPlatformContract { android, ios }

enum AnalysisMirrorPolicy { none, horizontal }

final class CameraPlaneSnapshot {
  final Uint8List _bytes;
  final int bytesPerRow;
  final int? bytesPerPixel;

  CameraPlaneSnapshot({
    required List<int> bytes,
    required this.bytesPerRow,
    required this.bytesPerPixel,
  }) : _bytes = Uint8List.fromList(bytes);

  Uint8List get bytes => Uint8List.fromList(_bytes);
}

/// An owned frame. Plugin buffers may be reused as soon as the callback ends,
/// so every plane is copied synchronously before any asynchronous analysis.
final class CameraFrameSnapshot {
  final List<CameraPlaneSnapshot> planes;
  final int width;
  final int height;
  final ImageFormatGroup format;
  final int sensorOrientation;
  final DeviceOrientation deviceOrientation;
  final CameraLensDirection lensDirection;
  final CameraPlatformContract platform;
  final int attemptId;
  final int frameId;
  final AnalysisMirrorPolicy mirrorPolicy;

  CameraFrameSnapshot({
    required List<CameraPlaneSnapshot> planes,
    required this.width,
    required this.height,
    required this.format,
    required this.sensorOrientation,
    required this.deviceOrientation,
    required this.lensDirection,
    required this.platform,
    required this.attemptId,
    required this.frameId,
    this.mirrorPolicy = AnalysisMirrorPolicy.none,
  }) : planes = List.unmodifiable(planes);

  factory CameraFrameSnapshot.copyFrom({
    required CameraImage image,
    required CameraDescription camera,
    required DeviceOrientation deviceOrientation,
    required CameraPlatformContract platform,
    required int attemptId,
    required int frameId,
  }) {
    return CameraFrameSnapshot(
      planes: image.planes
          .map(
            (plane) => CameraPlaneSnapshot(
              bytes: plane.bytes,
              bytesPerRow: plane.bytesPerRow,
              bytesPerPixel: plane.bytesPerPixel,
            ),
          )
          .toList(growable: false),
      width: image.width,
      height: image.height,
      format: image.format.group,
      sensorOrientation: camera.sensorOrientation,
      deviceOrientation: deviceOrientation,
      lensDirection: camera.lensDirection,
      platform: platform,
      attemptId: attemptId,
      frameId: frameId,
    );
  }
}

abstract final class CanonicalCameraRotation {
  static int clockwiseDegrees(CameraFrameSnapshot frame) => degrees(
    platform: frame.platform,
    sensorOrientation: frame.sensorOrientation,
    deviceOrientation: frame.deviceOrientation,
    lensDirection: frame.lensDirection,
  );

  /// Android follows Camera2/ML Kit compensation. The iOS camera plugin's
  /// sensor orientation is already the clockwise stream-to-upright contract;
  /// device orientation must not be applied a second time.
  static int degrees({
    required CameraPlatformContract platform,
    required int sensorOrientation,
    required DeviceOrientation deviceOrientation,
    required CameraLensDirection lensDirection,
  }) {
    _validateRightAngle(sensorOrientation);
    if (platform == CameraPlatformContract.ios) return sensorOrientation;

    final deviceDegrees = switch (deviceOrientation) {
      DeviceOrientation.portraitUp => 0,
      DeviceOrientation.landscapeLeft => 90,
      DeviceOrientation.portraitDown => 180,
      DeviceOrientation.landscapeRight => 270,
    };
    return lensDirection == CameraLensDirection.front
        ? (sensorOrientation + deviceDegrees) % 360
        : (sensorOrientation - deviceDegrees + 360) % 360;
  }

  static void _validateRightAngle(int degrees) {
    if (degrees != 0 && degrees != 90 && degrees != 180 && degrees != 270) {
      throw ArgumentError.value(degrees, 'sensorOrientation');
    }
  }
}
