import 'package:flutter/foundation.dart';
import 'package:hive_flutter/hive_flutter.dart';
import 'package:uuid/uuid.dart';
import 'dart:math' as math;

import 'offline_queue_item.dart';
import 'queue_key_store.dart';

class OfflineQueueService {
  static const String _legacyBoxName = 'offline_queue';
  static const String _boxPrefix = 'offline_queue_v2_';
  static const int maxRetries = 3;
  static const Duration defaultBaseBackoff = Duration(seconds: 5);
  static const Duration defaultMaxBackoff = Duration(minutes: 5);
  static const Duration defaultSyncLease = Duration(minutes: 5);

  final QueueKeyStore _keyStore;
  final Uuid _uuid;
  final DateTime Function() _now;
  final Duration _syncLease;
  final Duration _baseBackoff;
  final Duration _maxBackoff;
  final Duration Function(Duration delay, int attempt) _jitter;
  Box<OfflineQueueItem>? _box;
  int? _activeOwnerUserId;
  DateTime? _lastEnqueuedAt;
  Future<void> _lifecycle = Future.value();
  LegacyQueueRecoveryStatus _legacyRecoveryStatus =
      LegacyQueueRecoveryStatus.none;

  OfflineQueueService(
    this._keyStore, {
    Uuid uuid = const Uuid(),
    DateTime Function()? now,
    Duration syncLease = defaultSyncLease,
    Duration baseBackoff = defaultBaseBackoff,
    Duration maxBackoff = defaultMaxBackoff,
    Duration Function(Duration delay, int attempt)? jitter,
  }) : _uuid = uuid,
       _now = now ?? DateTime.now,
       _syncLease = syncLease,
       _baseBackoff = baseBackoff,
       _maxBackoff = maxBackoff,
       _jitter = jitter ?? _randomJitter;

  static Duration _randomJitter(Duration delay, int attempt) {
    final factor = 0.8 + math.Random().nextDouble() * 0.4;
    return Duration(milliseconds: (delay.inMilliseconds * factor).round());
  }

  int? get activeOwnerUserId => _activeOwnerUserId;
  bool get isActive => _box != null && _activeOwnerUserId != null;
  LegacyQueueRecoveryStatus get legacyRecoveryStatus => _legacyRecoveryStatus;

  Future<void> init() async {
    if (!Hive.isAdapterRegistered(0)) {
      Hive.registerAdapter(OfflineQueueItemAdapter());
    }
    if (await Hive.boxExists(_legacyBoxName)) {
      _legacyRecoveryStatus = LegacyQueueRecoveryStatus.quarantined;
    }
  }

  Future<void> activate(int userId) => _serialize(() => _activate(userId));

  Future<void> _activate(int userId) async {
    if (userId <= 0) throw StateError('User queue owner tidak valid');
    if (_activeOwnerUserId == userId && _box?.isOpen == true) return;
    if (_activeOwnerUserId != null) {
      throw StateError('Queue user lain masih aktif');
    }

    final boxName = '$_boxPrefix$userId';
    var key = await _keyStore.read(userId);
    if (key == null && await Hive.boxExists(boxName)) {
      await Hive.deleteBoxFromDisk(boxName);
    }
    key ??= Hive.generateSecureKey();
    if (key.length != 32) throw StateError('Kunci queue tidak valid');
    await _keyStore.write(userId, key);

    final box = await Hive.openBox<OfflineQueueItem>(
      boxName,
      encryptionCipher: HiveAesCipher(key),
    );
    if (box.values.any((item) => item.ownerUserId != userId)) {
      await box.close();
      throw StateError('Queue berisi item dengan owner yang tidak sesuai');
    }
    _box = box;
    _activeOwnerUserId = userId;
    for (final item in box.values) {
      if (_lastEnqueuedAt == null || item.createdAt.isAfter(_lastEnqueuedAt!)) {
        _lastEnqueuedAt = item.createdAt;
      }
    }
    await _recoverStaleSyncs();
  }

  Future<void> purgeForLogout(int userId) =>
      _serialize(() => _purgeForLogout(userId));

  Future<void> _purgeForLogout(int userId) async {
    if (_activeOwnerUserId != userId) {
      throw StateError('Owner logout tidak sesuai queue aktif');
    }
    final boxName = '$_boxPrefix$userId';
    await _box?.close();
    if (await Hive.boxExists(boxName)) {
      await Hive.deleteBoxFromDisk(boxName);
    }
    await _keyStore.delete(userId);
    _box = null;
    _activeOwnerUserId = null;
    _lastEnqueuedAt = null;
  }

  Future<T> _serialize<T>(Future<T> Function() operation) {
    final result = _lifecycle.then((_) => operation());
    _lifecycle = result.then<void>((_) {}, onError: (_, _) {});
    return result;
  }

  Box<OfflineQueueItem> get _activeBox {
    final box = _box;
    if (box == null || _activeOwnerUserId == null) {
      throw StateError('Queue belum diaktifkan untuk user');
    }
    return box;
  }

  void assertOwner(int userId) {
    if (_activeOwnerUserId != userId) {
      throw StateError('Owner queue tidak sesuai sesi');
    }
  }

  Future<OfflineQueueItem> enqueue({
    required String type,
    required Map<String, dynamic> data,
  }) async {
    final owner = _activeOwnerUserId;
    if (owner == null) throw StateError('Queue belum diaktifkan');
    var createdAt = _now();
    if (_lastEnqueuedAt != null && !createdAt.isAfter(_lastEnqueuedAt!)) {
      createdAt = _lastEnqueuedAt!.add(const Duration(microseconds: 1));
    }
    _lastEnqueuedAt = createdAt;
    final item = OfflineQueueItem(
      id: _uuid.v4(),
      type: type,
      data: data,
      createdAt: createdAt,
      ownerUserId: owner,
    );
    assertOwner(owner);
    await _activeBox.put(item.id, item);
    debugPrint('[OfflineQueue] Enqueued: ${item.type} (id: ${item.id})');
    return item;
  }

  Future<void> markSyncing(String id) => _mutate(id, (item) {
    item.status = OfflineQueueItem.statusSyncing;
    item.syncStartedAt = _now();
  });

  Future<void> restoreSyncing(Iterable<String> ids) async {
    if (!isActive) return;
    for (final id in ids) {
      await _mutate(id, (item) {
        if (item.status == OfflineQueueItem.statusSyncing) {
          item.status = OfflineQueueItem.statusPending;
          item.syncStartedAt = null;
        }
      });
    }
  }

  Future<void> markCompleted(String id) async {
    final item = _activeBox.get(id);
    if (item != null) {
      _assertItemOwner(item);
      await _activeBox.delete(id);
    }
  }

  Future<void> markPermanentFailure(
    String id,
    String error, {
    String code = 'permanent_failure',
    int? status,
  }) => _mutate(id, (item) {
    item.lastError = error;
    item.failureKind = OfflineQueueItem.failurePermanent;
    item.failureCode = code;
    item.failureStatus = status;
    item.lastAttemptAt = _now();
    item.nextAttemptAt = null;
    item.syncStartedAt = null;
    item.status = OfflineQueueItem.statusFailed;
  });

  Future<void> markTransientFailure(
    String id,
    String error, {
    String code = 'transient_failure',
    int? status,
    Duration? retryAfter,
  }) => _mutate(id, (item) {
    final now = _now();
    item.retryCount++;
    item.lastError = error;
    item.failureKind = OfflineQueueItem.failureTransient;
    item.failureCode = code;
    item.failureStatus = status;
    item.lastAttemptAt = now;
    item.syncStartedAt = null;
    if (item.retryCount >= maxRetries) {
      item.status = OfflineQueueItem.statusFailed;
      item.nextAttemptAt = null;
      return;
    }
    final exponentialMs =
        _baseBackoff.inMilliseconds * math.pow(2, item.retryCount - 1).toInt();
    final capped = Duration(
      milliseconds: math.min(exponentialMs, _maxBackoff.inMilliseconds),
    );
    final jittered = _jitter(capped, item.retryCount);
    final boundedJitter = Duration(
      milliseconds: jittered.inMilliseconds.clamp(
        0,
        _maxBackoff.inMilliseconds,
      ),
    );
    final delay = retryAfter != null && retryAfter > boundedJitter
        ? retryAfter
        : boundedJitter;
    item.status = OfflineQueueItem.statusPending;
    item.nextAttemptAt = now.add(delay);
  });

  Future<void> markAuthBlocked(String id, String error, {int? status}) =>
      _mutate(id, (item) {
        item.lastError = error;
        item.failureKind = OfflineQueueItem.failureAuthBlocked;
        item.failureCode = 'auth_blocked';
        item.failureStatus = status;
        item.lastAttemptAt = _now();
        item.nextAttemptAt = null;
        item.syncStartedAt = null;
        item.status = OfflineQueueItem.statusPending;
      });

  /// Compatibility wrapper. New sync code should classify failures explicitly.
  Future<void> markFailed(String id, String error) =>
      markTransientFailure(id, error);

  Future<void> _recoverStaleSyncs() async {
    final staleBefore = _now().subtract(_syncLease);
    for (final item in _activeBox.values.where(
      (item) =>
          item.status == OfflineQueueItem.statusSyncing &&
          (item.syncStartedAt == null ||
              item.syncStartedAt!.isBefore(staleBefore)),
    )) {
      _assertItemOwner(item);
      item.status = OfflineQueueItem.statusPending;
      item.syncStartedAt = null;
      await item.save();
    }
  }

  Future<void> _mutate(
    String id,
    void Function(OfflineQueueItem) change,
  ) async {
    final item = _activeBox.get(id);
    if (item == null) return;
    _assertItemOwner(item);
    change(item);
    await item.save();
  }

  void _assertItemOwner(OfflineQueueItem item) {
    if (item.ownerUserId != _activeOwnerUserId) {
      throw StateError('Item queue bukan milik user aktif');
    }
  }

  List<OfflineQueueItem> getPendingItems({bool eligibleOnly = true}) {
    if (!isActive) return [];
    final now = _now();
    final items = _activeBox.values.where((item) {
      _assertItemOwner(item);
      if (item.status != OfflineQueueItem.statusPending) return false;
      if (!eligibleOnly) return true;
      if (item.failureKind == OfflineQueueItem.failureAuthBlocked) return false;
      return item.nextAttemptAt == null || !item.nextAttemptAt!.isAfter(now);
    }).toList();
    return items..sort((a, b) {
      final byCreatedAt = a.createdAt.compareTo(b.createdAt);
      return byCreatedAt != 0 ? byCreatedAt : a.id.compareTo(b.id);
    });
  }

  List<OfflineQueueItem> getAllItems() {
    if (!isActive) return [];
    final items = _activeBox.values.map((item) {
      _assertItemOwner(item);
      return item;
    }).toList();
    return items..sort((a, b) {
      final byCreatedAt = b.createdAt.compareTo(a.createdAt);
      return byCreatedAt != 0 ? byCreatedAt : b.id.compareTo(a.id);
    });
  }

  int get pendingCount => getPendingItems(eligibleOnly: false).length;
  int get failedCount => getAllItems()
      .where((item) => item.status == OfflineQueueItem.statusFailed)
      .length;
  bool get hasPendingItems => getPendingItems().isNotEmpty;

  DateTime? get earliestNextAttemptAt {
    final dates =
        getPendingItems(eligibleOnly: false)
            .where(
              (item) =>
                  item.failureKind != OfflineQueueItem.failureAuthBlocked &&
                  item.nextAttemptAt != null,
            )
            .map((item) => item.nextAttemptAt!)
            .toList()
          ..sort();
    return dates.isEmpty ? null : dates.first;
  }

  Future<void> retryFailed() async {
    for (final item in getAllItems().where(
      (item) =>
          item.status == OfflineQueueItem.statusFailed &&
          item.failureKind == OfflineQueueItem.failureTransient,
    )) {
      item.status = OfflineQueueItem.statusPending;
      item.retryCount = 0;
      item.syncStartedAt = null;
      item.nextAttemptAt = null;
      await item.save();
    }
  }

  Future<void> unblockAuthFailures() async {
    for (final item in getPendingItems(eligibleOnly: false).where(
      (item) => item.failureKind == OfflineQueueItem.failureAuthBlocked,
    )) {
      item.failureKind = null;
      item.failureCode = null;
      item.failureStatus = null;
      item.nextAttemptAt = null;
      await item.save();
    }
  }

  Future<void> clearCompleted() async {
    for (final item in getAllItems().where(
      (item) => item.status == OfflineQueueItem.statusCompleted,
    )) {
      await _activeBox.delete(item.id);
    }
  }

  Future<void> clearAll() => _activeBox.clear();
  Stream<BoxEvent> watch() => _activeBox.watch();
}

enum LegacyQueueRecoveryStatus { none, quarantined }
