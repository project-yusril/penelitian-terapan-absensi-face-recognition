import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../constants/app_constants.dart';

class SessionSnapshot {
  final String? token;
  final int generation;

  const SessionSnapshot(this.token, this.generation);

  bool get hasToken => token != null && token!.isNotEmpty;
}

abstract class SessionStore {
  SessionSnapshot get snapshot;
  Future<void> saveToken(String value);
  Future<void> clear();
  Future<bool> clearIfMatches(SessionSnapshot expected);
}

class SecureSessionStore implements SessionStore {
  static const _tokenKey = 'auth_token_v2';
  static const _profileKey = 'auth_profile_v2';

  final FlutterSecureStorage _storage;
  final SharedPreferences _legacy;
  String? _token;
  int _generation = 0;
  Future<void> _mutation = Future.value();

  SecureSessionStore(this._storage, this._legacy);

  String? get token => _token;
  int get generation => _generation;

  @override
  SessionSnapshot get snapshot => SessionSnapshot(_token, _generation);

  Future<void> initialize() async {
    _token = await _storage.read(key: _tokenKey);
    final legacyToken = _legacy.getString(AppConstants.tokenKey);
    if (_token == null && legacyToken != null && legacyToken.isNotEmpty) {
      await _storage.write(key: _tokenKey, value: legacyToken);
      if (await _storage.read(key: _tokenKey) != legacyToken) {
        await _legacy.remove(AppConstants.tokenKey);
        throw StateError('Migrasi credential ke secure storage gagal');
      }
      _token = legacyToken;
    }
    await _legacy.remove(AppConstants.tokenKey);

    final legacyProfile = _legacy.getString(AppConstants.userKey);
    if (await _storage.read(key: _profileKey) == null &&
        legacyProfile != null) {
      try {
        final json = jsonDecode(legacyProfile) as Map<String, dynamic>;
        final minimal = jsonEncode(_minimalProfile(json));
        await _storage.write(key: _profileKey, value: minimal);
        if (await _storage.read(key: _profileKey) != minimal) {
          throw StateError('Migrasi profil ke secure storage gagal');
        }
        await _legacy.remove(AppConstants.userKey);
      } catch (_) {
        rethrow;
      }
    } else {
      await _legacy.remove(AppConstants.userKey);
    }
  }

  @override
  Future<void> saveToken(String value) => _serialize(() async {
    await _storage.write(key: _tokenKey, value: value);
    if (await _storage.read(key: _tokenKey) != value) {
      throw StateError('Credential tidak tersimpan');
    }
    _token = value;
    _generation++;
  });

  Future<void> saveProfile(Map<String, dynamic> profile) => _storage.write(
    key: _profileKey,
    value: jsonEncode(_minimalProfile(profile)),
  );

  Future<Map<String, dynamic>?> readProfile() async {
    final value = await _storage.read(key: _profileKey);
    return value == null ? null : jsonDecode(value) as Map<String, dynamic>;
  }

  @override
  Future<void> clear() => _serialize(_clear);

  @override
  Future<bool> clearIfMatches(SessionSnapshot expected) => _serialize(() async {
    if (_generation != expected.generation || _token != expected.token) {
      return false;
    }
    await _clear();
    return true;
  });

  Future<void> _clear() async {
    _token = null;
    _generation++;
    await _storage.delete(key: _tokenKey);
    await _storage.delete(key: _profileKey);
    await _legacy.remove(AppConstants.tokenKey);
    await _legacy.remove(AppConstants.userKey);
  }

  Future<T> _serialize<T>(Future<T> Function() operation) {
    final result = _mutation.then((_) => operation());
    _mutation = result.then<void>((_) {}, onError: (_, _) {});
    return result;
  }

  Map<String, dynamic> _minimalProfile(Map<String, dynamic> json) => {
    'id': json['id'],
    'nama': json['nama'],
    'roles': json['roles'] ?? [],
    'status': json['status'] ?? 'aktif',
    'enrollment_status': json['enrollment_status'] ?? 'belum',
    'must_change_password': json['must_change_password'] ?? false,
  };
}
