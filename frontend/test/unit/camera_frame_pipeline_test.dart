import 'dart:async';

import 'package:absensi_mahasiswa/features/face_recognition/domain/services/camera_frame_snapshot.dart';
import 'package:absensi_mahasiswa/features/face_recognition/domain/services/frame_analysis_pipeline.dart';
import 'package:camera/camera.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('CanonicalCameraRotation', () {
    final orientations = <DeviceOrientation, int>{
      DeviceOrientation.portraitUp: 0,
      DeviceOrientation.landscapeLeft: 90,
      DeviceOrientation.portraitDown: 180,
      DeviceOrientation.landscapeRight: 270,
    };

    for (final entry in orientations.entries) {
      test('Android back ${entry.key}', () {
        expect(
          CanonicalCameraRotation.degrees(
            platform: CameraPlatformContract.android,
            sensorOrientation: 90,
            deviceOrientation: entry.key,
            lensDirection: CameraLensDirection.back,
          ),
          (90 - entry.value + 360) % 360,
        );
      });

      test('Android front ${entry.key}', () {
        expect(
          CanonicalCameraRotation.degrees(
            platform: CameraPlatformContract.android,
            sensorOrientation: 270,
            deviceOrientation: entry.key,
            lensDirection: CameraLensDirection.front,
          ),
          (270 + entry.value) % 360,
        );
      });

      test('iOS contract ignores device compensation for ${entry.key}', () {
        expect(
          CanonicalCameraRotation.degrees(
            platform: CameraPlatformContract.ios,
            sensorOrientation: 90,
            deviceOrientation: entry.key,
            lensDirection: CameraLensDirection.front,
          ),
          90,
        );
      });
    }

    test('rejects invalid sensor orientation rather than falling back', () {
      expect(
        () => CanonicalCameraRotation.degrees(
          platform: CameraPlatformContract.android,
          sensorOrientation: 45,
          deviceOrientation: DeviceOrientation.portraitUp,
          lensDirection: CameraLensDirection.back,
        ),
        throwsArgumentError,
      );
    });
  });

  test('snapshot owns immutable copies of plugin plane bytes', () {
    final source = Uint8List.fromList([1, 2, 3, 4]);
    final snapshot = CameraFrameSnapshot(
      planes: [
        CameraPlaneSnapshot(bytes: source, bytesPerRow: 4, bytesPerPixel: 4),
      ],
      width: 1,
      height: 1,
      format: ImageFormatGroup.bgra8888,
      sensorOrientation: 90,
      deviceOrientation: DeviceOrientation.portraitUp,
      lensDirection: CameraLensDirection.front,
      platform: CameraPlatformContract.android,
      attemptId: 4,
      frameId: 8,
    );

    source[0] = 99;
    final exposed = snapshot.planes.single.bytes;
    exposed[1] = 88;

    expect(snapshot.planes.single.bytes, [1, 2, 3, 4]);
    expect(snapshot.attemptId, 4);
    expect(snapshot.frameId, 8);
  });

  test('drops frame two while frame one analysis is blocked', () async {
    final blocked = Completer<void>();
    final analyzed = <int>[];
    final pipeline = SingleInflightFramePipeline<void>((snapshot) async {
      analyzed.add(snapshot.frameId);
      await blocked.future;
    });
    final first = _snapshot(frameId: 1);
    final second = _snapshot(frameId: 2);

    final firstResult = pipeline.submit(first);
    await pipeline.submit(second);
    expect(analyzed, [1]);
    blocked.complete();
    await firstResult;

    expect(analyzed, [1]);
  });

  test('dropped callbacks do not consume admitted frame sequence', () async {
    final blocked = Completer<void>();
    final analyzed = <int>[];
    final pipeline = SingleInflightFramePipeline<void>((snapshot) async {
      analyzed.add(snapshot.frameId);
      if (snapshot.frameId == 1) await blocked.future;
    });

    final first = pipeline.admit((sequence) => _snapshot(frameId: sequence));
    await pipeline.admit((sequence) => _snapshot(frameId: sequence));
    blocked.complete();
    await first;
    await pipeline.admit((sequence) => _snapshot(frameId: sequence));

    expect(analyzed, [1, 2]);
  });

  test('stale attempt completion is ignored', () async {
    final attempts = AttemptGeneration();
    final first = attempts.begin();
    final blocked = Completer<void>();
    var applied = false;

    final completion = () async {
      await blocked.future;
      if (attempts.isCurrent(first)) applied = true;
    }();
    attempts.begin();
    blocked.complete();
    await completion;

    expect(applied, isFalse);
  });

  group('LivenessContinuity', () {
    test('resets for zero and multiple faces', () {
      final continuity = LivenessContinuity();
      continuity.observe(faceCount: 1, trackingId: 7, frameId: 1, rotation: 0);
      expect(
        continuity.observe(
          faceCount: 0,
          trackingId: null,
          frameId: 2,
          rotation: 0,
        ),
        LivenessDiscontinuity.zeroFaces,
      );
      expect(
        continuity.observe(
          faceCount: 2,
          trackingId: null,
          frameId: 3,
          rotation: 0,
        ),
        LivenessDiscontinuity.multipleFaces,
      );
    });

    test('resets for tracking, frame gap, and orientation changes', () {
      final tracking = LivenessContinuity();
      tracking.observe(faceCount: 1, trackingId: 7, frameId: 1, rotation: 0);
      expect(
        tracking.observe(faceCount: 1, trackingId: 8, frameId: 2, rotation: 0),
        LivenessDiscontinuity.trackingChanged,
      );

      final gap = LivenessContinuity();
      gap.observe(faceCount: 1, trackingId: 7, frameId: 1, rotation: 0);
      expect(
        gap.observe(faceCount: 1, trackingId: 7, frameId: 3, rotation: 0),
        LivenessDiscontinuity.frameGap,
      );

      final orientation = LivenessContinuity();
      orientation.observe(faceCount: 1, trackingId: 7, frameId: 1, rotation: 0);
      expect(
        orientation.observe(
          faceCount: 1,
          trackingId: 7,
          frameId: 2,
          rotation: 90,
        ),
        LivenessDiscontinuity.orientationChanged,
      );
    });

    test('fails closed when tracking becomes null after challenge begins', () {
      final continuity = LivenessContinuity();
      continuity.observe(faceCount: 1, trackingId: 7, frameId: 1, rotation: 0);

      expect(
        continuity.observe(
          faceCount: 1,
          trackingId: null,
          frameId: 2,
          rotation: 0,
        ),
        LivenessDiscontinuity.trackingUnavailable,
      );
    });
  });
}

CameraFrameSnapshot _snapshot({required int frameId}) {
  return CameraFrameSnapshot(
    planes: [
      CameraPlaneSnapshot(
        bytes: const [0, 0, 0, 255],
        bytesPerRow: 4,
        bytesPerPixel: 4,
      ),
    ],
    width: 1,
    height: 1,
    format: ImageFormatGroup.bgra8888,
    sensorOrientation: 0,
    deviceOrientation: DeviceOrientation.portraitUp,
    lensDirection: CameraLensDirection.back,
    platform: CameraPlatformContract.android,
    attemptId: 1,
    frameId: frameId,
  );
}
