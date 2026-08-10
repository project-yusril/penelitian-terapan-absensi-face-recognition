import 'package:absensi_mahasiswa/core/time/server_time_anchor.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('advances only by injected monotonic ticks', () {
    var ticks = Duration.zero;
    final anchor = ServerTimeAnchor(ticks: () => ticks);

    expect(anchor.anchorFromIso('2026-07-18T01:00:00Z'), isTrue);
    ticks = const Duration(seconds: 12);

    expect(anchor.now, DateTime.utc(2026, 7, 18, 1, 0, 12));
  });

  test('reanchor replaces server baseline at current tick', () {
    var ticks = const Duration(seconds: 20);
    final anchor = ServerTimeAnchor(ticks: () => ticks);
    anchor.anchorFromIso('2026-07-18T01:00:00Z');
    ticks = const Duration(seconds: 25);
    anchor.anchorFromIso('2026-07-18T02:00:00Z');
    ticks = const Duration(seconds: 27);

    expect(anchor.now, DateTime.utc(2026, 7, 18, 2, 0, 2));
  });

  test('fails closed before anchor and after monotonic rollback', () {
    var ticks = const Duration(seconds: 5);
    final anchor = ServerTimeAnchor(ticks: () => ticks);
    expect(anchor.now, isNull);
    anchor.anchorFromIso('2026-07-18T01:00:00Z');
    ticks = Duration.zero;
    expect(anchor.now, isNull);
  });
}
