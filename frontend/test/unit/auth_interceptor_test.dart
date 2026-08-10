import 'package:absensi_mahasiswa/core/network/interceptors/auth_interceptor.dart';
import 'package:absensi_mahasiswa/core/security/secure_session_store.dart';
import 'package:absensi_mahasiswa/core/security/session_coordinator.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

class MemorySessionStore implements SessionStore {
  String? token;
  int generation = 0;
  int clears = 0;

  MemorySessionStore(this.token);

  @override
  SessionSnapshot get snapshot => SessionSnapshot(token, generation);

  @override
  Future<void> saveToken(String value) async {
    token = value;
    generation++;
  }

  @override
  Future<void> clear() async {
    token = null;
    generation++;
    clears++;
  }

  @override
  Future<bool> clearIfMatches(SessionSnapshot expected) async {
    if (snapshot.token != expected.token ||
        snapshot.generation != expected.generation) {
      return false;
    }
    await clear();
    return true;
  }
}

void main() {
  late MemorySessionStore store;
  late SessionCoordinator coordinator;
  late Dio dio;
  late List<RequestOptions> requests;

  setUp(() {
    store = MemorySessionStore('active-token');
    coordinator = SessionCoordinator(store);
    requests = [];
    dio = Dio(BaseOptions(baseUrl: 'https://api.example.test'));
    dio.interceptors.add(
      AuthInterceptor(coordinator, Uri.parse('https://api.example.test')),
    );
    dio.httpClientAdapter = _Adapter((options) async {
      requests.add(options);
      return ResponseBody.fromString(
        '{}',
        401,
        headers: {
          Headers.contentTypeHeader: ['application/json'],
        },
      );
    });
  });

  tearDown(() async {
    dio.close(force: true);
    await coordinator.close();
  });

  test(
    'protected bearer 401 invalidates and original 401 reaches caller',
    () async {
      await expectLater(
        dio.get('/auth/me'),
        throwsA(
          isA<DioException>().having(
            (error) => error.response?.statusCode,
            'status',
            401,
          ),
        ),
      );

      expect(requests.single.headers['Authorization'], 'Bearer active-token');
      expect(store.clears, 1);
    },
  );

  test(
    'public auth endpoints omit bearer and business 401 does not invalidate',
    () async {
      for (final path in [
        '/auth/login',
        '/auth/forgot-password',
        '/auth/reset-password',
      ]) {
        try {
          await dio.post(path);
        } on DioException catch (error) {
          expect(error.response?.statusCode, 401);
        }
      }

      expect(
        requests.every(
          (request) => !request.headers.containsKey('Authorization'),
        ),
        isTrue,
      );
      expect(store.clears, 0);
      expect(store.token, 'active-token');
    },
  );

  test('parallel protected 401 responses invalidate once', () async {
    await Future.wait(
      List.generate(20, (_) async {
        try {
          await dio.get('/auth/me');
        } on DioException {
          return;
        }
      }),
    );

    expect(store.clears, 1);
  });

  test('cross-origin request is rejected before it is sent', () async {
    await expectLater(
      dio.get('https://evil.example/path'),
      throwsA(
        isA<DioException>().having(
          (error) => error.type,
          'type',
          DioExceptionType.cancel,
        ),
      ),
    );

    expect(requests, isEmpty);
    expect(store.clears, 0);
  });
}

class _Adapter implements HttpClientAdapter {
  final Future<ResponseBody> Function(RequestOptions) handler;

  _Adapter(this.handler);

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) => handler(options);

  @override
  void close({bool force = false}) {}
}
