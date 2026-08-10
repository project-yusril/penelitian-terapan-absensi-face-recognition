import 'dart:math';

final class EnrollmentIdentityContinuity {
  static const Duration maximumWindow = Duration(seconds: 10);

  final Duration Function() _ticks;
  int? _attemptId;
  List<double>? _embedding;
  Duration? _boundAt;

  EnrollmentIdentityContinuity({Duration Function()? ticks})
    : _ticks = ticks ?? _monotonicClock();

  void bind({required int attemptId, required List<double> embedding}) {
    _attemptId = attemptId;
    _embedding = List<double>.unmodifiable(embedding);
    _boundAt = _ticks();
  }

  bool matches({
    required int attemptId,
    required List<double> candidate,
    required double threshold,
  }) {
    final reference = _embedding;
    final boundAt = _boundAt;
    if (_attemptId != attemptId ||
        reference == null ||
        boundAt == null ||
        candidate.length != reference.length ||
        threshold <= 0) {
      return false;
    }
    final age = _ticks() - boundAt;
    if (age.isNegative || age > maximumWindow) return false;

    var squaredDistance = 0.0;
    for (var i = 0; i < reference.length; i++) {
      final difference = reference[i] - candidate[i];
      squaredDistance += difference * difference;
    }
    // L-08/R-04: comparator canonical `<=` (selaras backend/analisis).
    return sqrt(squaredDistance) <= threshold;
  }

  void reset() {
    _attemptId = null;
    _embedding = null;
    _boundAt = null;
  }

  static Duration Function() _monotonicClock() {
    final stopwatch = Stopwatch()..start();
    return () => stopwatch.elapsed;
  }
}
