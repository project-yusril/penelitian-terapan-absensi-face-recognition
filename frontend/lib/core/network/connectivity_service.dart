import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../constants/api_constants.dart';
import '../offline/offline_attendance_validator.dart';
import '../offline/offline_queue_item.dart';
import '../offline/offline_queue_service.dart';

typedef OfflineBatchSender =
    Future<Response<dynamic>> Function(List<Map<String, dynamic>> payloads);
typedef ConnectivityChecker = Future<List<ConnectivityResult>> Function();

/// Auto-syncs offline attendance in backend-compatible batches.
class ConnectivityService {
  static const int batchSize = 20;

  final Connectivity _connectivity;
  final OfflineQueueService _queueService;
  final Dio _dio;
  final OfflineBatchSender? _sender;
  final OfflineAttendanceValidator _validator;
  final DateTime Function() _now;
  final bool _enableRetryTimer;
  final ConnectivityChecker _connectivityChecker;

  StreamSubscription<List<ConnectivityResult>>? _subscription;
  final _syncStateController = StreamController<SyncState>.broadcast();
  bool _isSyncing = false;
  bool _paused = true;
  bool _isOnline = false;
  Future<void>? _activeSync;
  Timer? _retryTimer;
  bool _disposed = false;
  Future<void>? _shutdown;

  ConnectivityService(
    this._connectivity,
    this._queueService,
    this._dio, {
    OfflineBatchSender? sender,
    OfflineAttendanceValidator validator = const OfflineAttendanceValidator(),
    DateTime Function()? now,
    bool enableRetryTimer = true,
    bool initiallyPaused = true,
    ConnectivityChecker? connectivityChecker,
  }) : _sender = sender,
       _validator = validator,
       _now = now ?? DateTime.now,
       _enableRetryTimer = enableRetryTimer,
       _paused = initiallyPaused,
       _connectivityChecker =
           connectivityChecker ?? _connectivity.checkConnectivity;

  Stream<SyncState> get syncStateStream => _syncStateController.stream;
  bool get isSyncing => _isSyncing;
  int get pendingCount => _queueService.pendingCount;

  void initialize() {
    if (_disposed || _subscription != null) return;
    _subscription = _connectivity.onConnectivityChanged.listen((results) {
      _isOnline = results.any((result) => result != ConnectivityResult.none);
      if (!_paused && _isOnline && !_isSyncing) {
        unawaited(syncPendingItems());
      }
    });
    unawaited(_refreshConnectivityAndSync());
  }

  Future<void> _refreshConnectivityAndSync() async {
    final results = await _connectivityChecker();
    _isOnline = results.any((result) => result != ConnectivityResult.none);
    if (!_paused && _isOnline) await syncPendingItems();
  }

  Future<void> syncPendingItems() async {
    if (_paused || _isSyncing) return;
    final operation = _syncPendingItems();
    _activeSync = operation;
    try {
      await operation;
    } finally {
      if (identical(_activeSync, operation)) _activeSync = null;
    }
  }

  Future<void> _syncPendingItems() async {
    final owner = _queueService.activeOwnerUserId;
    if (owner == null) return;
    _retryTimer?.cancel();
    _isSyncing = true;
    if (!_syncStateController.isClosed) {
      _syncStateController.add(SyncState.syncing(_queueService.pendingCount));
    }
    var successCount = 0;
    var failCount = 0;
    final claimedIds = <String>{};

    try {
      final pendingItems = _queueService.getPendingItems();
      if (pendingItems.any((item) => item.ownerUserId != owner)) {
        throw StateError('Queue memiliki owner yang tidak sesuai');
      }

      final validItems = <OfflineQueueItem>[];
      for (final item in pendingItems) {
        final validation = _validator.validate(item.data);
        if (validation.isValid) {
          validItems.add(item);
        } else {
          await _queueService.markPermanentFailure(
            item.id,
            validation.message!,
            code: validation.code!,
          );
          failCount++;
        }
      }

      for (var offset = 0; offset < validItems.length; offset += batchSize) {
        if (_paused) break;
        _queueService.assertOwner(owner);
        final end = (offset + batchSize).clamp(0, validItems.length);
        final batch = validItems.sublist(offset, end);
        for (final item in batch) {
          if (_paused) break;
          await _queueService.markSyncing(item.id);
          claimedIds.add(item.id);
        }
        if (_paused) break;
        final outcome = await _processBatch(batch, owner);
        successCount += outcome.success;
        failCount += outcome.failed;
        if (outcome.authBlocked) break;
      }
    } finally {
      await _queueService.restoreSyncing(claimedIds);
      _isSyncing = false;
      _scheduleEarliestRetry();
    }

    if (!_syncStateController.isClosed) {
      _syncStateController.add(SyncState.completed(successCount, failCount));
    }
    debugPrint(
      '[ConnectivityService] Sync done: $successCount ok, $failCount failed',
    );
  }

  Future<_BatchOutcome> _processBatch(
    List<OfflineQueueItem> batch,
    int owner,
  ) async {
    if (batch.isEmpty) return const _BatchOutcome();
    try {
      final response = await _send(batch.map((item) => item.data).toList());
      _queueService.assertOwner(owner);
      return _applyResponse(batch, response);
    } on DioException catch (error) {
      if (_paused &&
          error.response?.statusCode != 401 &&
          error.response?.statusCode != 403) {
        return const _BatchOutcome(authBlocked: true);
      }
      if (_queueService.activeOwnerUserId != owner) rethrow;
      if (error.response?.statusCode == 422) {
        if (batch.length == 1) {
          await _queueService.markPermanentFailure(
            batch.single.id,
            _errorMessage(error, 'Payload ditolak server'),
            code: 'validation_422',
            status: 422,
          );
          return const _BatchOutcome(failed: 1);
        }
        final middle = batch.length ~/ 2;
        final left = await _processBatch(batch.sublist(0, middle), owner);
        if (_paused || left.authBlocked) return left;
        final right = await _processBatch(batch.sublist(middle), owner);
        return left + right;
      }
      return _applyTransportFailure(batch, error);
    } catch (error) {
      if (_queueService.activeOwnerUserId != owner) rethrow;
      for (final item in batch) {
        await _queueService.markTransientFailure(
          item.id,
          error.toString(),
          code: 'unexpected_transport_error',
        );
      }
      return _BatchOutcome(failed: batch.length);
    }
  }

  Future<Response<dynamic>> _send(List<Map<String, dynamic>> payloads) {
    final sender = _sender;
    if (sender != null) return sender(payloads);
    return _dio.post(
      ApiConstants.attendanceSyncOfflineEndpoint,
      data: {'attendances': payloads},
    );
  }

  Future<_BatchOutcome> _applyResponse(
    List<OfflineQueueItem> batch,
    Response<dynamic> response,
  ) async {
    final body = response.data;
    final data = body is Map ? body['data'] : null;
    final rawResults = data is Map ? data['results'] : null;
    if (rawResults is! List) {
      return _markMalformed(batch, 'Respons tidak memiliki data.results');
    }

    final byUuid = <String, Map<dynamic, dynamic>>{};
    final duplicateUuids = <String>{};
    for (final raw in rawResults) {
      if (raw is! Map) {
        continue;
      }
      final uuid = raw['client_uuid'];
      if (uuid is! String || uuid.isEmpty) {
        continue;
      }
      if (byUuid.containsKey(uuid)) duplicateUuids.add(uuid);
      byUuid[uuid] = raw;
    }

    var success = 0;
    var failed = 0;
    for (final item in batch) {
      final uuid = item.data['client_uuid'] as String;
      final result = byUuid[uuid];
      if (duplicateUuids.contains(uuid) || result == null) {
        await _queueService.markTransientFailure(
          item.id,
          duplicateUuids.contains(uuid)
              ? 'Respons berisi hasil UUID duplikat'
              : 'Respons tidak memiliki hasil untuk $uuid',
          code: 'malformed_response',
        );
        failed++;
        continue;
      }

      final status = result['status'];
      if (status == 'success' || status == 'duplicate') {
        await _queueService.markCompleted(item.id);
        success++;
        continue;
      }
      if (status != 'failed' && status != 'skipped') {
        await _queueService.markTransientFailure(
          item.id,
          'Status hasil sinkronisasi tidak dikenal',
          code: 'malformed_response',
        );
        failed++;
        continue;
      }

      final reason = result['reason']?.toString() ?? 'Ditolak server';
      final code = result['code']?.toString() ?? 'item_rejected';
      if (result['retryable'] == true) {
        await _queueService.markTransientFailure(item.id, reason, code: code);
      } else {
        await _queueService.markPermanentFailure(item.id, reason, code: code);
      }
      failed++;
    }
    return _BatchOutcome(success: success, failed: failed);
  }

  Future<_BatchOutcome> _markMalformed(
    List<OfflineQueueItem> batch,
    String message,
  ) async {
    for (final item in batch) {
      await _queueService.markTransientFailure(
        item.id,
        message,
        code: 'malformed_response',
      );
    }
    return _BatchOutcome(failed: batch.length);
  }

  Future<_BatchOutcome> _applyTransportFailure(
    List<OfflineQueueItem> batch,
    DioException error,
  ) async {
    final status = error.response?.statusCode;
    final message = _errorMessage(error, 'Sinkronisasi gagal');
    if (status == 401 || status == 403) {
      for (final item in batch) {
        await _queueService.markAuthBlocked(item.id, message, status: status);
      }
      return _BatchOutcome(failed: batch.length, authBlocked: true);
    }

    final transient =
        status == null ||
        status == 408 ||
        status == 425 ||
        status == 429 ||
        status >= 500 ||
        error.type == DioExceptionType.connectionTimeout ||
        error.type == DioExceptionType.sendTimeout ||
        error.type == DioExceptionType.receiveTimeout ||
        error.type == DioExceptionType.connectionError;
    if (transient) {
      final retryAfter = status == 429
          ? _parseRetryAfter(error.response)
          : null;
      for (final item in batch) {
        await _queueService.markTransientFailure(
          item.id,
          message,
          code: status == 429 ? 'rate_limited' : 'transport_error',
          status: status,
          retryAfter: retryAfter,
        );
      }
    } else {
      for (final item in batch) {
        await _queueService.markPermanentFailure(
          item.id,
          message,
          code: 'http_$status',
          status: status,
        );
      }
    }
    return _BatchOutcome(failed: batch.length);
  }

  String _errorMessage(DioException error, String fallback) {
    final body = error.response?.data;
    if (body is Map && body['message'] != null) {
      return body['message'].toString();
    }
    return error.message ?? fallback;
  }

  Duration? _parseRetryAfter(Response<dynamic>? response) {
    final raw = response?.headers.value('retry-after')?.trim();
    if (raw == null || raw.isEmpty) return null;
    final seconds = int.tryParse(raw);
    if (seconds != null) return Duration(seconds: seconds.clamp(0, 86400));
    final date = DateTime.tryParse(raw)?.toUtc();
    if (date == null) return null;
    final delay = date.difference(_now().toUtc());
    return delay.isNegative ? Duration.zero : delay;
  }

  void _scheduleEarliestRetry() {
    _retryTimer?.cancel();
    if (!_enableRetryTimer || _paused) return;
    final next = _queueService.earliestNextAttemptAt;
    if (next == null) return;
    final delay = next.difference(_now());
    _retryTimer = Timer(delay.isNegative ? Duration.zero : delay, () {
      if (!_paused && _isOnline) unawaited(syncPendingItems());
    });
  }

  Future<void> pauseAndWait() async {
    _paused = true;
    _retryTimer?.cancel();
    await _activeSync;
  }

  void resume() {
    if (_disposed) return;
    _paused = false;
    unawaited(_resumeOnlineSync());
  }

  Future<void> _resumeOnlineSync() async {
    if (!_queueService.isActive) return;
    await _queueService.unblockAuthFailures();
    final results = await _connectivityChecker();
    _isOnline = results.any((result) => result != ConnectivityResult.none);
    if (_isOnline) await syncPendingItems();
    _scheduleEarliestRetry();
  }

  Future<void> shutdown() {
    return _shutdown ??= _performShutdown();
  }

  Future<void> _performShutdown() async {
    _disposed = true;
    await pauseAndWait();
    _retryTimer?.cancel();
    await _subscription?.cancel();
    _subscription = null;
    if (!_syncStateController.isClosed) {
      await _syncStateController.close();
    }
  }

  Future<void> dispose() => shutdown();
}

class _BatchOutcome {
  final int success;
  final int failed;
  final bool authBlocked;

  const _BatchOutcome({
    this.success = 0,
    this.failed = 0,
    this.authBlocked = false,
  });

  _BatchOutcome operator +(_BatchOutcome other) => _BatchOutcome(
    success: success + other.success,
    failed: failed + other.failed,
    authBlocked: authBlocked || other.authBlocked,
  );
}

class SyncState {
  final SyncStatus status;
  final int pendingCount;
  final int successCount;
  final int failCount;

  SyncState._(
    this.status,
    this.pendingCount,
    this.successCount,
    this.failCount,
  );

  factory SyncState.syncing(int pending) =>
      SyncState._(SyncStatus.syncing, pending, 0, 0);

  factory SyncState.completed(int success, int fail) =>
      SyncState._(SyncStatus.completed, 0, success, fail);

  factory SyncState.idle() => SyncState._(SyncStatus.idle, 0, 0, 0);
}

enum SyncStatus { idle, syncing, completed }
