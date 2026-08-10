import 'dart:io';

import 'package:absensi_mahasiswa/core/offline/offline_queue_service.dart';
import 'package:absensi_mahasiswa/core/offline/queue_key_store.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hive/hive.dart';

class MemoryQueueKeyStore implements QueueKeyStore {
  final keys = <int, List<int>>{};

  @override
  Future<void> delete(int userId) async => keys.remove(userId);

  @override
  Future<List<int>?> read(int userId) async => keys[userId];

  @override
  Future<void> write(int userId, List<int> key) async {
    keys[userId] = List<int>.from(key);
  }
}

void main() {
  late Directory directory;
  late MemoryQueueKeyStore keys;
  late OfflineQueueService queue;

  setUp(() async {
    directory = await Directory.systemTemp.createTemp('offline_queue_test');
    Hive.init(directory.path);
    keys = MemoryQueueKeyStore();
    queue = OfflineQueueService(keys);
    await queue.init();
  });

  tearDown(() async {
    await Hive.close();
    await directory.delete(recursive: true);
  });

  test('queue A is purged and never visible after user B logs in', () async {
    await queue.activate(101);
    final item = await queue.enqueue(
      type: 'check_in',
      data: {'client_uuid': 'queue-a'},
    );

    expect(item.ownerUserId, 101);
    expect(queue.getPendingItems(), hasLength(1));
    expect(keys.keys, contains(101));

    await queue.purgeForLogout(101);
    await queue.activate(202);

    expect(queue.activeOwnerUserId, 202);
    expect(queue.getPendingItems(), isEmpty);
    expect(keys.keys, isNot(contains(101)));
    expect(keys.keys, contains(202));
  });

  test('cannot activate another user while current owner is active', () async {
    await queue.activate(101);

    await expectLater(queue.activate(202), throwsStateError);
    expect(queue.activeOwnerUserId, 101);
  });

  test('logout cannot purge a queue owned by another user', () async {
    await queue.activate(101);

    await expectLater(queue.purgeForLogout(202), throwsStateError);
    expect(queue.activeOwnerUserId, 101);
  });

  test('activation waits for logout purge before switching owner', () async {
    await queue.activate(101);
    await queue.enqueue(type: 'check_in', data: {'client_uuid': 'queue-a'});

    final purge = queue.purgeForLogout(101);
    final activateB = queue.activate(202);
    await Future.wait([purge, activateB]);

    expect(queue.activeOwnerUserId, 202);
    expect(queue.getPendingItems(), isEmpty);
    expect(keys.keys, isNot(contains(101)));
  });

  test('expired syncing lease is recovered after restart', () async {
    var now = DateTime(2026, 7, 20, 10);
    queue = OfflineQueueService(keys, now: () => now);
    await queue.init();
    await queue.activate(101);
    final item = await queue.enqueue(
      type: 'check_in',
      data: {'client_uuid': 'lease'},
    );
    await queue.markSyncing(item.id);

    await Hive.close();
    now = now.add(const Duration(minutes: 6));
    queue = OfflineQueueService(keys, now: () => now);
    await queue.init();
    await queue.activate(101);

    expect(queue.getPendingItems().single.id, item.id);
    expect(queue.getPendingItems().single.syncStartedAt, isNull);
  });

  test('fresh syncing lease is not recovered', () async {
    final now = DateTime(2026, 7, 20, 10);
    queue = OfflineQueueService(keys, now: () => now);
    await queue.init();
    await queue.activate(101);
    final item = await queue.enqueue(
      type: 'check_in',
      data: {'client_uuid': 'lease'},
    );
    await queue.markSyncing(item.id);

    await Hive.close();
    queue = OfflineQueueService(
      keys,
      now: () => now.add(const Duration(minutes: 1)),
    );
    await queue.init();
    await queue.activate(101);

    expect(queue.getPendingItems(), isEmpty);
    expect(queue.getAllItems().single.status, 'syncing');
  });

  test(
    'legacy queue remains quarantined and reports recovery status',
    () async {
      final legacy = await Hive.openBox<dynamic>('offline_queue');
      await legacy.put('legacy', {'client_uuid': 'preserve-me'});
      await legacy.close();

      queue = OfflineQueueService(keys);
      await queue.init();

      expect(await Hive.boxExists('offline_queue'), isTrue);
      expect(queue.legacyRecoveryStatus, LegacyQueueRecoveryStatus.quarantined);
      final reopened = await Hive.openBox<dynamic>('offline_queue');
      expect(reopened.get('legacy'), {'client_uuid': 'preserve-me'});
    },
  );
}
