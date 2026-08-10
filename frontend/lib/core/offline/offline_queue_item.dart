import 'package:hive/hive.dart';

part 'offline_queue_item.g.dart';

@HiveType(typeId: 0)
class OfflineQueueItem extends HiveObject {
  @HiveField(0)
  final String id;

  @HiveField(1)
  final String type;

  @HiveField(2)
  final Map<String, dynamic> data;

  @HiveField(3)
  final DateTime createdAt;

  @HiveField(4)
  int retryCount;

  @HiveField(5)
  String? lastError;

  @HiveField(6)
  String status;

  @HiveField(7)
  final int? ownerUserId;

  @HiveField(8)
  DateTime? syncStartedAt;

  @HiveField(9)
  String? failureKind;

  @HiveField(10)
  String? failureCode;

  @HiveField(11)
  int? failureStatus;

  @HiveField(12)
  DateTime? lastAttemptAt;

  @HiveField(13)
  DateTime? nextAttemptAt;

  OfflineQueueItem({
    required this.id,
    required this.type,
    required this.data,
    required this.createdAt,
    this.retryCount = 0,
    this.lastError,
    this.status = 'pending',
    this.ownerUserId,
    this.syncStartedAt,
    this.failureKind,
    this.failureCode,
    this.failureStatus,
    this.lastAttemptAt,
    this.nextAttemptAt,
  });

  static const String checkInType = 'check_in';
  static const String checkOutType = 'check_out';
  static const String syncOfflineType = 'sync_offline';

  static const String statusPending = 'pending';
  static const String statusSyncing = 'syncing';
  static const String statusFailed = 'failed';
  static const String statusCompleted = 'completed';

  static const String failureTransient = 'transient';
  static const String failurePermanent = 'permanent';
  static const String failureAuthBlocked = 'auth_blocked';
}
