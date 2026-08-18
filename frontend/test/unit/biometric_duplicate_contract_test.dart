import 'dart:convert';
import 'dart:io';

import 'package:absensi_mahasiswa/core/config/app_config.dart';
import 'package:absensi_mahasiswa/core/errors/exceptions.dart';
import 'package:absensi_mahasiswa/core/network/api_client.dart';
import 'package:absensi_mahasiswa/core/security/secure_session_store.dart';
import 'package:absensi_mahasiswa/core/security/session_coordinator.dart';
import 'package:absensi_mahasiswa/features/face_recognition/presentation/bloc/face_bloc.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

class _SessionStore implements SessionStore {
  @override
  SessionSnapshot snapshot = const SessionSnapshot(null, 0);
  @override
  Future<void> clear() async {}
  @override
  Future<bool> clearIfMatches(SessionSnapshot expected) async => false;
  @override
  Future<void> saveToken(String value) async {}
}

class _ResponseAdapter implements HttpClientAdapter {
  _ResponseAdapter(this.status, this.body) : headers = const {};
  final int status;
  final Map<String, dynamic> body;
  final Map<String, List<String>> headers;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    return ResponseBody.fromString(
      jsonEncode(body),
      status,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
        ...headers,
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

FaceBloc _bloc(HttpClientAdapter adapter) {
  final client = ApiClient(
    AppConfig.fromEnvironment(rawUrl: 'https://host.example/api', debug: false),
    SessionCoordinator(_SessionStore()),
  );
  client.dio.httpClientAdapter = adapter;
  return FaceBloc(client);
}

void main() {
  final embedding = List<double>.filled(192, 0.1);

  test('200 minimal false response parses as clear', () async {
    final bloc = _bloc(_ResponseAdapter(200, {'is_duplicate': false}));
    expect((await bloc.checkDuplicate(embedding)).isDuplicate, isFalse);
    await bloc.close();
  });

  test('409 biometric conflict parses duplicate owner name', () async {
    final bloc = _bloc(
      _ResponseAdapter(409, {
        'code': 'BIOMETRIC_CONFLICT',
        'message': 'Data biometrik tidak dapat digunakan untuk pendaftaran.',
        'matched_name': 'Yusril',
        'logout_required': true,
      }),
    );
    final result = await bloc.checkDuplicate(embedding);
    expect(result.isDuplicate, isTrue);
    expect(result.matchedName, 'Yusril');
    expect(result.logoutRequired, isTrue);
    await bloc.close();
  });

  test('429 remains a cooldown error', () async {
    final bloc = _bloc(_ResponseAdapter(429, {}));
    await expectLater(
      bloc.checkDuplicate(embedding),
      throwsA(
        isA<ServerException>().having(
          (error) => error.statusCode,
          'statusCode',
          429,
        ),
      ),
    );
    await bloc.close();
  });

  test('enrollment camera stack renders matched and expected names at top', () {
    final source = File(
      'lib/features/face_recognition/presentation/pages/enrollment_page.dart',
    ).readAsStringSync();

    expect(source, contains("top: 16"));
    expect(source, contains(r"'Kamu adalah $_matchedName'"));
    expect(source, contains(r"'Silakan daftarkan wajah $_expectedName'"));
    expect(
      source,
      contains('Data biometrik tidak dapat digunakan untuk pendaftaran.'),
    );
  });
}
