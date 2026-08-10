import 'package:absensi_mahasiswa/core/security/secure_session_store.dart';
import 'package:absensi_mahasiswa/core/security/session_coordinator.dart';
import 'package:flutter_test/flutter_test.dart';

class MemorySessionStore implements SessionStore {
  String? token;
  int generation = 0;
  int clearCount = 0;

  MemorySessionStore(this.token);

  @override
  SessionSnapshot get snapshot => SessionSnapshot(token, generation);

  @override
  Future<void> saveToken(String value) async {
    token = value;
    generation++;
  }

  @override
  Future<void> clear() async {
    token = null;
    generation++;
    clearCount++;
  }

  @override
  Future<bool> clearIfMatches(SessionSnapshot expected) async {
    await Future<void>.delayed(Duration.zero);
    if (token != expected.token || generation != expected.generation) {
      return false;
    }
    await clear();
    return true;
  }
}

void main() {
  test('twenty concurrent invalidations clear and emit once', () async {
    final store = MemorySessionStore('token-a');
    final coordinator = SessionCoordinator(store);
    final events = <SessionInvalidation>[];
    final subscription = coordinator.invalidations.listen(events.add);
    final snapshot = coordinator.snapshot;

    final results = await Future.wait(
      List.generate(20, (_) => coordinator.invalidateIfCurrent(snapshot)),
    );
    await Future<void>.delayed(Duration.zero);

    expect(results.every((result) => result), isTrue);
    expect(store.clearCount, 1);
    expect(events, hasLength(1));
    await subscription.cancel();
    await coordinator.close();
  });

  test('stale snapshot cannot clear a newly saved token', () async {
    final store = MemorySessionStore('token-a');
    final coordinator = SessionCoordinator(store);
    final stale = coordinator.snapshot;
    await store.saveToken('token-b');

    final invalidated = await coordinator.invalidateIfCurrent(stale);

    expect(invalidated, isFalse);
    expect(store.token, 'token-b');
    expect(store.clearCount, 0);
    await coordinator.close();
  });
}
