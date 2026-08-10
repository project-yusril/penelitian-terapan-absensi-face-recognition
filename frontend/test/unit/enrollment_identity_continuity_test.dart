import 'package:absensi_mahasiswa/features/face_recognition/domain/services/enrollment_identity_continuity.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('accepts the same liveness subject within the continuity window', () {
    var ticks = Duration.zero;
    final continuity = EnrollmentIdentityContinuity(ticks: () => ticks)
      ..bind(attemptId: 4, embedding: const [0, 0]);
    ticks = const Duration(seconds: 3);

    expect(
      continuity.matches(
        attemptId: 4,
        candidate: const [0.1, 0.1],
        threshold: 1,
      ),
      isTrue,
    );
  });

  test('rejects subject mismatch and a stale or different attempt', () {
    var ticks = Duration.zero;
    final continuity = EnrollmentIdentityContinuity(ticks: () => ticks)
      ..bind(attemptId: 4, embedding: const [0, 0]);

    expect(
      continuity.matches(attemptId: 4, candidate: const [1, 1], threshold: 1),
      isFalse,
    );
    expect(
      continuity.matches(attemptId: 5, candidate: const [0, 0], threshold: 1),
      isFalse,
    );
    ticks =
        EnrollmentIdentityContinuity.maximumWindow +
        const Duration(milliseconds: 1);
    expect(
      continuity.matches(attemptId: 4, candidate: const [0, 0], threshold: 1),
      isFalse,
    );
  });
}
