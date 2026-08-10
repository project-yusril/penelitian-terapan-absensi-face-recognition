import 'package:camera/camera.dart';
import 'package:flutter/widgets.dart';
import 'package:google_mlkit_face_detection/google_mlkit_face_detection.dart';

import 'camera_frame_snapshot.dart';

abstract final class CameraFrameAnalysis {
  static InputImage toInputImage(CameraFrameSnapshot snapshot) {
    if (snapshot.planes.length != 1) {
      throw UnsupportedError('ML Kit byte input requires one camera plane');
    }
    final rotation = InputImageRotationValue.fromRawValue(
      CanonicalCameraRotation.clockwiseDegrees(snapshot),
    );
    if (rotation == null) {
      throw StateError('Canonical camera rotation is not a right angle');
    }
    final format = switch (snapshot.format) {
      ImageFormatGroup.bgra8888 => InputImageFormat.bgra8888,
      ImageFormatGroup.nv21 => InputImageFormat.nv21,
      _ => throw UnsupportedError(
        'Format ${snapshot.format} is not supported by ML Kit byte input',
      ),
    };
    return InputImage.fromBytes(
      bytes: snapshot.planes.single.bytes,
      metadata: InputImageMetadata(
        size: Size(snapshot.width.toDouble(), snapshot.height.toDouble()),
        rotation: rotation,
        format: format,
        bytesPerRow: snapshot.planes.single.bytesPerRow,
      ),
    );
  }
}
