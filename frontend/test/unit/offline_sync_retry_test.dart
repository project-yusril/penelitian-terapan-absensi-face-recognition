import 'dart:io';
import 'dart:async';

import 'package:absensi_mahasiswa/core/network/connectivity_service.dart';
import 'package:absensi_mahasiswa/core/offline/offline_queue_item.dart';
import 'package:absensi_mahasiswa/core/offline/offline_queue_service.dart';
import 'package:absensi_mahasiswa/core/offline/queue_key_store.dart';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:dio/dio.dart';
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

class PausingQueueService extends OfflineQueueService {
  final claimed = Completer<void>();
  final releaseClaim = Completer<void>();
  bool _pausedOnce = false;

  PausingQueueService(super.keyStore, {required super.now});

  @override
  Future<void> markSyncing(String id) async {
    await super.markSyncing(id);
    if (!_pausedOnce) {
      _pausedOnce = true;
      claimed.complete();
      await releaseClaim.future;
    }
  }
}

Map<String, dynamic> payload(int id) => {
  'client_uuid': '123e4567-e89b-42d3-a456-${id.toString().padLeft(12, '0')}',
  'jadwal_id': id + 1,
  'type': 'check_in',
  'timestamp': '2026-07-18T01:00:00.000Z',
  'latitude': -0.026,
  'longitude': 109.34,
  'face_distance': 0.2,
  'mock_location_detected': false,
  'liveness_passed': true,
  'gps_accuracy': 5.0,
  'location_age_ms': 0,
  'permit_token': 'a' * 64,
};

Response<dynamic> response(Object? data, {int status = 200}) => Response(
  requestOptions: RequestOptions(path: '/sync'),
  statusCode: status,
  data: data,
);

DioException dioError(
  DioExceptionType type, {
  int? status,
  Map<String, List<String>>? headers,
}) => DioException(
  requestOptions: RequestOptions(path: '/sync'),
  type: type,
  response: status == null
      ? null
      : Response(
          requestOptions: RequestOptions(path: '/sync'),
          statusCode: status,
          headers: Headers.fromMap(headers ?? const {}),
          data: {'message': 'failed'},
        ),
);

void main() {
  late Directory directory;
  late OfflineQueueService queue;
  late DateTime now;

  setUp(() async {
    directory = await Directory.systemTemp.createTemp('offline_sync_test');
    Hive.init(directory.path);
    now = DateTime.utc(2026, 7, 18, 1);
    queue = OfflineQueueService(
      MemoryQueueKeyStore(),
      now: () => now,
      baseBackoff: const Duration(seconds: 10),
      jitter: (delay, _) => delay,
    );
    await queue.init();
    await queue.activate(7);
  });

  tearDown(() async {
    await Hive.close();
    await directory.delete(recursive: true);
  });

  ConnectivityService service(OfflineBatchSender sender) => ConnectivityService(
    Connectivity(),
    queue,
    Dio(),
    sender: sender,
    now: () => now,
    enableRetryTimer: false,
    initiallyPaused: false,
    connectivityChecker: () async => [ConnectivityResult.wifi],
  );

  test(
    'transient backoff gates eligibility and exhausts deterministically',
    () async {
      final item = await queue.enqueue(type: 'check_in', data: payload(1));

      await queue.markTransientFailure(item.id, 'timeout');
      expect(queue.getPendingItems(), isEmpty);
      expect(item.nextAttemptAt, now.add(const Duration(seconds: 10)));

      now = now.add(const Duration(seconds: 10));
      expect(queue.getPendingItems().single.id, item.id);
      await queue.markTransientFailure(item.id, 'timeout');
      expect(item.nextAttemptAt, now.add(const Duration(seconds: 20)));

      now = now.add(const Duration(seconds: 20));
      await queue.markTransientFailure(item.id, 'timeout');
      expect(item.status, OfflineQueueItem.statusFailed);
      expect(item.failureKind, OfflineQueueItem.failureTransient);
      expect(item.nextAttemptAt, isNull);
    },
  );

  test('manual retry only resets exhausted transient failures', () async {
    final transient = await queue.enqueue(type: 'check_in', data: payload(1));
    final permanent = await queue.enqueue(type: 'check_in', data: payload(2));
    for (var i = 0; i < OfflineQueueService.maxRetries; i++) {
      await queue.markTransientFailure(transient.id, 'network');
    }
    await queue.markPermanentFailure(permanent.id, 'poison');

    await queue.retryFailed();

    expect(transient.status, OfflineQueueItem.statusPending);
    expect(transient.retryCount, 0);
    expect(permanent.status, OfflineQueueItem.statusFailed);
    expect(permanent.failureKind, OfflineQueueItem.failurePermanent);
  });

  test('local-invalid item is never sent and becomes permanent', () async {
    final invalid = payload(1)..['permit_token'] = 'bad';
    await queue.enqueue(type: 'check_in', data: invalid);
    var sends = 0;
    final sync = service((_) async {
      sends++;
      return response(null);
    });

    await sync.syncPendingItems();

    expect(sends, 0);
    expect(queue.getAllItems().single.failureCode, 'invalid_permit_token');
  });

  test(
    '422 poison batch is bisected and valid siblings succeed in order',
    () async {
      for (var i = 0; i < 4; i++) {
        await queue.enqueue(type: 'check_in', data: payload(i));
      }
      final calls = <List<int>>[];
      final sync = service((items) async {
        final ids = items.map((item) => item['jadwal_id'] as int).toList();
        calls.add(ids);
        if (ids.contains(3)) {
          throw dioError(DioExceptionType.badResponse, status: 422);
        }
        return response({
          'data': {
            'results': items
                .map(
                  (item) => {
                    'client_uuid': item['client_uuid'],
                    'status': 'success',
                  },
                )
                .toList(),
          },
        });
      });

      await sync.syncPendingItems();

      expect(calls, [
        [1, 2, 3, 4],
        [1, 2],
        [3, 4],
        [3],
        [4],
      ]);
      expect(queue.getAllItems(), hasLength(1));
      expect(queue.getAllItems().single.data['jadwal_id'], 3);
      expect(
        queue.getAllItems().single.failureKind,
        OfflineQueueItem.failurePermanent,
      );
    },
  );

  test('timeout does not split and applies one transient transition', () async {
    for (var i = 0; i < 3; i++) {
      await queue.enqueue(type: 'check_in', data: payload(i));
    }
    var sends = 0;
    final sync = service((_) async {
      sends++;
      throw dioError(DioExceptionType.receiveTimeout);
    });

    await sync.syncPendingItems();

    expect(sends, 1);
    expect(queue.getAllItems().every((item) => item.retryCount == 1), isTrue);
  });

  test('429 honors Retry-After and does not split', () async {
    final item = await queue.enqueue(type: 'check_in', data: payload(1));
    var sends = 0;
    final sync = service((_) async {
      sends++;
      throw dioError(
        DioExceptionType.badResponse,
        status: 429,
        headers: {
          'retry-after': ['120'],
        },
      );
    });

    await sync.syncPendingItems();

    expect(sends, 1);
    expect(item.failureCode, 'rate_limited');
    expect(item.nextAttemptAt, now.add(const Duration(seconds: 120)));
  });

  test(
    'malformed, missing, and duplicate results are handled per UUID',
    () async {
      for (var i = 0; i < 3; i++) {
        await queue.enqueue(type: 'check_in', data: payload(i));
      }
      final firstUuid = payload(0)['client_uuid'];
      final thirdUuid = payload(2)['client_uuid'];
      final sync = service(
        (_) async => response({
          'data': {
            'results': [
              {'client_uuid': firstUuid, 'status': 'success'},
              {'client_uuid': firstUuid, 'status': 'success'},
              'bad',
              {'client_uuid': thirdUuid, 'status': 'success'},
            ],
          },
        }),
      );

      await sync.syncPendingItems();

      expect(queue.getAllItems(), hasLength(2));
      expect(
        queue.getAllItems().every(
          (item) => item.failureKind == OfflineQueueItem.failureTransient,
        ),
        isTrue,
      );
    },
  );

  test('success and duplicate results remove their queue items', () async {
    for (var i = 0; i < 2; i++) {
      await queue.enqueue(type: 'check_in', data: payload(i));
    }
    final sync = service(
      (items) async => response({
        'data': {
          'results': [
            {'client_uuid': items[0]['client_uuid'], 'status': 'success'},
            {'client_uuid': items[1]['client_uuid'], 'status': 'duplicate'},
          ],
        },
      }),
    );

    await sync.syncPendingItems();

    expect(queue.getAllItems(), isEmpty);
  });

  test('sends at most twenty items and preserves queue order', () async {
    for (var i = 0; i < 25; i++) {
      await queue.enqueue(type: 'check_in', data: payload(i));
    }
    final calls = <List<int>>[];
    final sync = service((items) async {
      calls.add(items.map((item) => item['jadwal_id'] as int).toList());
      return response({
        'data': {
          'results': items
              .map(
                (item) => {
                  'client_uuid': item['client_uuid'],
                  'status': 'success',
                },
              )
              .toList(),
        },
      });
    });

    await sync.syncPendingItems();

    expect(calls.map((call) => call.length), [20, 5]);
    expect(calls.expand((call) => call), List.generate(25, (i) => i + 1));
  });

  test('auth failure is deferred without incrementing retry', () async {
    final item = await queue.enqueue(type: 'check_in', data: payload(1));
    final sync = service((_) async {
      throw dioError(DioExceptionType.badResponse, status: 401);
    });

    await sync.syncPendingItems();

    expect(item.status, OfflineQueueItem.statusPending);
    expect(item.failureKind, OfflineQueueItem.failureAuthBlocked);
    expect(item.retryCount, 0);
    expect(queue.getPendingItems(), isEmpty);
  });

  test('active sync 401 aborts remaining batches without retry', () async {
    for (var i = 0; i < 25; i++) {
      await queue.enqueue(type: 'check_in', data: payload(i));
    }
    var sends = 0;
    final sync = service((_) async {
      sends++;
      throw dioError(DioExceptionType.badResponse, status: 401);
    });

    await sync.syncPendingItems();

    expect(sends, 1);
    expect(queue.getAllItems(), hasLength(25));
    expect(
      queue.getAllItems().where(
        (item) => item.failureKind == OfflineQueueItem.failureAuthBlocked,
      ),
      hasLength(20),
    );
    expect(queue.getAllItems().every((item) => item.retryCount == 0), isTrue);
  });

  test('pause waits for active request and skips following batches', () async {
    for (var i = 0; i < 25; i++) {
      await queue.enqueue(type: 'check_in', data: payload(i));
    }
    final requestStarted = Completer<void>();
    final releaseRequest = Completer<void>();
    var sends = 0;
    late ConnectivityService sync;
    sync = service((items) async {
      sends++;
      if (sends == 1) {
        requestStarted.complete();
        await releaseRequest.future;
      }
      return response({
        'data': {
          'results': items
              .map(
                (item) => {
                  'client_uuid': item['client_uuid'],
                  'status': 'success',
                },
              )
              .toList(),
        },
      });
    });

    final runningSync = sync.syncPendingItems();
    await requestStarted.future;
    final pause = sync.pauseAndWait();
    releaseRequest.complete();
    await Future.wait([runningSync, pause]);

    expect(sends, 1);
    expect(queue.getAllItems(), hasLength(5));
  });

  test('pause during batch preparation restores every claimed item', () async {
    await Hive.close();
    final pausingQueue = PausingQueueService(
      MemoryQueueKeyStore(),
      now: () => now,
    );
    queue = pausingQueue;
    await queue.init();
    await queue.activate(7);
    for (var i = 0; i < 3; i++) {
      await queue.enqueue(type: 'check_in', data: payload(i));
    }
    final sync = ConnectivityService(
      Connectivity(),
      queue,
      Dio(),
      sender: (_) async => throw StateError('must not send'),
      now: () => now,
      enableRetryTimer: false,
      initiallyPaused: false,
      connectivityChecker: () async => [ConnectivityResult.none],
    );

    final running = sync.syncPendingItems();
    await pausingQueue.claimed.future;
    final pause = sync.pauseAndWait();
    pausingQueue.releaseClaim.complete();
    await Future.wait([running, pause]);

    expect(
      queue.getAllItems().every((item) => item.status != 'syncing'),
      isTrue,
    );
    sync.resume();
    await Future<void>.delayed(Duration.zero);
    expect(queue.getPendingItems(), hasLength(3));
    await sync.shutdown();
  });

  test('pause before right 422 split restores unsent right items', () async {
    for (var i = 0; i < 4; i++) {
      await queue.enqueue(type: 'check_in', data: payload(i));
    }
    late ConnectivityService sync;
    var calls = 0;
    sync = service((items) async {
      calls++;
      if (calls == 1) {
        throw dioError(DioExceptionType.badResponse, status: 422);
      }
      unawaited(sync.pauseAndWait());
      return response({
        'data': {
          'results': items
              .map(
                (item) => {
                  'client_uuid': item['client_uuid'],
                  'status': 'success',
                },
              )
              .toList(),
        },
      });
    });

    await sync.syncPendingItems();

    expect(calls, 2);
    expect(
      queue.getAllItems().every((item) => item.status != 'syncing'),
      isTrue,
    );
    expect(queue.getPendingItems(), hasLength(2));
  });

  test(
    'shutdown waits for sender and closes state stream without late add',
    () async {
      await queue.enqueue(type: 'check_in', data: payload(1));
      final started = Completer<void>();
      final release = Completer<void>();
      final sync = service((items) async {
        started.complete();
        await release.future;
        return response({
          'data': {
            'results': [
              {'client_uuid': items.single['client_uuid'], 'status': 'success'},
            ],
          },
        });
      });
      final statesDone = sync.syncStateStream.drain<void>();

      final running = sync.syncPendingItems();
      await started.future;
      final shutdown = sync.shutdown();
      release.complete();
      await Future.wait([running, shutdown, statesDone]);
      await sync.shutdown();

      expect(queue.getAllItems(), isEmpty);
    },
  );
}
