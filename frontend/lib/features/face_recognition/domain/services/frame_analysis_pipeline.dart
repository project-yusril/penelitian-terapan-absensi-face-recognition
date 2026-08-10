import 'dart:async';

import 'camera_frame_snapshot.dart';

typedef FrameAnalyzer<T> = Future<T> Function(CameraFrameSnapshot snapshot);

/// Admits one frame at a time. Incoming frames are dropped rather than queued,
/// which prevents stale camera buffers from overtaking the active analysis.
final class SingleInflightFramePipeline<T> {
  final FrameAnalyzer<T> _analyze;
  bool _busy = false;
  int _admittedSequence = 0;

  SingleInflightFramePipeline(this._analyze);

  bool get isBusy => _busy;

  Future<T?> submit(CameraFrameSnapshot snapshot) async {
    if (_busy) return null;
    _busy = true;
    try {
      return await _analyze(snapshot);
    } finally {
      _busy = false;
    }
  }

  Future<T?> admit(CameraFrameSnapshot Function(int sequence) snapshot) {
    if (_busy) return Future<T?>.value();
    return submit(snapshot(++_admittedSequence));
  }
}

final class AttemptGeneration {
  int _generation = 0;

  int begin() => ++_generation;
  int get current => _generation;
  bool isCurrent(int attemptId) => attemptId == _generation;
  void cancel() => _generation++;
}

enum LivenessDiscontinuity {
  none,
  zeroFaces,
  multipleFaces,
  trackingChanged,
  trackingUnavailable,
  frameGap,
  orientationChanged,
}

final class LivenessContinuity {
  final int maximumFrameGap;
  int? _trackingId;
  int? _frameId;
  int? _rotation;

  LivenessContinuity({this.maximumFrameGap = 1});

  LivenessDiscontinuity observe({
    required int faceCount,
    required int? trackingId,
    required int frameId,
    required int rotation,
  }) {
    LivenessDiscontinuity reason = LivenessDiscontinuity.none;
    if (faceCount == 0) {
      reason = LivenessDiscontinuity.zeroFaces;
    } else if (faceCount > 1) {
      reason = LivenessDiscontinuity.multipleFaces;
    } else if (_trackingId != null && trackingId == null) {
      reason = LivenessDiscontinuity.trackingUnavailable;
    } else if (_trackingId != null && trackingId != _trackingId) {
      reason = LivenessDiscontinuity.trackingChanged;
    } else if (_frameId != null && frameId - _frameId! > maximumFrameGap) {
      reason = LivenessDiscontinuity.frameGap;
    } else if (_rotation != null && rotation != _rotation) {
      reason = LivenessDiscontinuity.orientationChanged;
    }

    if (reason != LivenessDiscontinuity.none) reset();
    if (faceCount == 1) {
      _trackingId = trackingId;
      _frameId = frameId;
      _rotation = rotation;
    }
    return reason;
  }

  void reset() {
    _trackingId = null;
    _frameId = null;
    _rotation = null;
  }
}

final class AsyncCommandSerializer {
  Future<void> _tail = Future.value();

  Future<T> run<T>(Future<T> Function() command) {
    final completer = Completer<T>();
    _tail = _tail.then((_) async {
      try {
        completer.complete(await command());
      } catch (error, stackTrace) {
        completer.completeError(error, stackTrace);
      }
    });
    return completer.future;
  }
}
