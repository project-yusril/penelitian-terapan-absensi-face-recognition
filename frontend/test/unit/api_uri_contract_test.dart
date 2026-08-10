import 'package:absensi_mahasiswa/core/config/app_config.dart';
import 'package:absensi_mahasiswa/core/constants/api_constants.dart';
import 'package:absensi_mahasiswa/core/network/api_client.dart';
import 'package:absensi_mahasiswa/core/security/secure_session_store.dart';
import 'package:absensi_mahasiswa/core/security/session_coordinator.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

class _MemorySessionStore implements SessionStore {
  @override
  SessionSnapshot snapshot = const SessionSnapshot(null, 0);

  @override
  Future<void> clear() async {}

  @override
  Future<bool> clearIfMatches(SessionSnapshot expected) async => false;

  @override
  Future<void> saveToken(String value) async {}
}

class _CaptureAdapter implements HttpClientAdapter {
  final uris = <Uri>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    uris.add(options.uri);
    return ResponseBody.fromString('{}', 200);
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  test(
    'login, me, and attendance resolve below production /api base',
    () async {
      final config = AppConfig.fromEnvironment(
        rawUrl: 'https://host.example/api',
        debug: false,
      );
      final client = ApiClient(
        config,
        SessionCoordinator(_MemorySessionStore()),
      );
      final adapter = _CaptureAdapter();
      client.dio.httpClientAdapter = adapter;

      await client.dio.post(ApiConstants.loginEndpoint);
      await client.dio.get(ApiConstants.meEndpoint);
      await client.dio.post(ApiConstants.checkInEndpoint);

      expect(adapter.uris, [
        Uri.parse('https://host.example/api/auth/login'),
        Uri.parse('https://host.example/api/auth/me'),
        Uri.parse('https://host.example/api/mahasiswa/attendance/check-in'),
      ]);
    },
  );
}
