// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'offline_queue_item.dart';

// **************************************************************************
// TypeAdapterGenerator
// **************************************************************************

class OfflineQueueItemAdapter extends TypeAdapter<OfflineQueueItem> {
  @override
  final int typeId = 0;

  @override
  OfflineQueueItem read(BinaryReader reader) {
    final numOfFields = reader.readByte();
    final fields = <int, dynamic>{
      for (int i = 0; i < numOfFields; i++) reader.readByte(): reader.read(),
    };
    return OfflineQueueItem(
      id: fields[0] as String,
      type: fields[1] as String,
      data: (fields[2] as Map).cast<String, dynamic>(),
      createdAt: fields[3] as DateTime,
      retryCount: fields[4] as int? ?? 0,
      lastError: fields[5] as String?,
      status: fields[6] as String? ?? OfflineQueueItem.statusPending,
      ownerUserId: fields[7] as int?,
      syncStartedAt: fields[8] as DateTime?,
      failureKind: fields[9] as String?,
      failureCode: fields[10] as String?,
      failureStatus: fields[11] as int?,
      lastAttemptAt: fields[12] as DateTime?,
      nextAttemptAt: fields[13] as DateTime?,
    );
  }

  @override
  void write(BinaryWriter writer, OfflineQueueItem obj) {
    writer
      ..writeByte(14)
      ..writeByte(0)
      ..write(obj.id)
      ..writeByte(1)
      ..write(obj.type)
      ..writeByte(2)
      ..write(obj.data)
      ..writeByte(3)
      ..write(obj.createdAt)
      ..writeByte(4)
      ..write(obj.retryCount)
      ..writeByte(5)
      ..write(obj.lastError)
      ..writeByte(6)
      ..write(obj.status)
      ..writeByte(7)
      ..write(obj.ownerUserId)
      ..writeByte(8)
      ..write(obj.syncStartedAt)
      ..writeByte(9)
      ..write(obj.failureKind)
      ..writeByte(10)
      ..write(obj.failureCode)
      ..writeByte(11)
      ..write(obj.failureStatus)
      ..writeByte(12)
      ..write(obj.lastAttemptAt)
      ..writeByte(13)
      ..write(obj.nextAttemptAt);
  }

  @override
  int get hashCode => typeId.hashCode;

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is OfflineQueueItemAdapter &&
          runtimeType == other.runtimeType &&
          typeId == other.typeId;
}
