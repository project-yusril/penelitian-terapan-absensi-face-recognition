import 'dart:io';

import 'package:absensi_mahasiswa/core/offline/offline_queue_item.dart';
import 'package:absensi_mahasiswa/core/offline/offline_queue_service.dart';
import 'package:absensi_mahasiswa/core/offline/queue_key_store.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hive/hive.dart';
import 'package:hive/src/hive_impl.dart';

class _LegacyItem {
  final String id;

  const _LegacyItem(this.id);
}

class _LegacyAdapter extends TypeAdapter<_LegacyItem> {
  @override
  int get typeId => 0;

  @override
  _LegacyItem read(BinaryReader reader) => throw UnsupportedError('write only');

  @override
  void write(BinaryWriter writer, _LegacyItem obj) {
    writer
      ..writeByte(7)
      ..writeByte(0)
      ..write(obj.id)
      ..writeByte(1)
      ..write('check_in')
      ..writeByte(2)
      ..write(<String, dynamic>{'client_uuid': 'legacy'})
      ..writeByte(3)
      ..write(DateTime.utc(2026))
      ..writeByte(4)
      ..write(0)
      ..writeByte(5)
      ..write(null)
      ..writeByte(6)
      ..write(OfflineQueueItem.statusPending);
  }
}

class _Keys implements QueueKeyStore {
  @override
  Future<void> delete(int userId) async {}
  @override
  Future<List<int>?> read(int userId) async => null;
  @override
  Future<void> write(int userId, List<int> key) async {}
}

void main() {
  test('current adapter reads legacy record with no owner field', () async {
    final directory = await Directory.systemTemp.createTemp(
      'offline_schema_test',
    );
    final legacyHive = HiveImpl()
      ..init(directory.path)
      ..registerAdapter(_LegacyAdapter());
    final legacy = await legacyHive.openBox<_LegacyItem>('offline_queue');
    await legacy.put('legacy', const _LegacyItem('legacy'));
    await legacyHive.close();

    Hive.init(directory.path);
    final queue = OfflineQueueService(_Keys());
    await queue.init();
    final reopened = await Hive.openBox<OfflineQueueItem>('offline_queue');

    expect(reopened.get('legacy')?.ownerUserId, isNull);
    expect(queue.legacyRecoveryStatus, LegacyQueueRecoveryStatus.quarantined);
    await Hive.close();
    await directory.delete(recursive: true);
  });
}
