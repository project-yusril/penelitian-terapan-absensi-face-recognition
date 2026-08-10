import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

abstract class QueueKeyStore {
  Future<List<int>?> read(int userId);
  Future<void> write(int userId, List<int> key);
  Future<void> delete(int userId);
}

class SecureQueueKeyStore implements QueueKeyStore {
  static const _prefix = 'offline_queue_key_v2_';
  final FlutterSecureStorage _storage;

  const SecureQueueKeyStore(this._storage);

  @override
  Future<List<int>?> read(int userId) async {
    final value = await _storage.read(key: '$_prefix$userId');
    return value == null ? null : base64Url.decode(value);
  }

  @override
  Future<void> write(int userId, List<int> key) =>
      _storage.write(key: '$_prefix$userId', value: base64Url.encode(key));

  @override
  Future<void> delete(int userId) => _storage.delete(key: '$_prefix$userId');
}
