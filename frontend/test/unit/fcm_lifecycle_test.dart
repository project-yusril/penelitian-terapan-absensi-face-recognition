import 'package:absensi_mahasiswa/core/errors/failures.dart';
import 'package:absensi_mahasiswa/core/network/connectivity_service.dart';
import 'package:absensi_mahasiswa/core/notifications/push_messaging_service.dart';
import 'package:absensi_mahasiswa/core/offline/offline_queue_service.dart';
import 'package:absensi_mahasiswa/core/offline/queue_key_store.dart';
import 'package:absensi_mahasiswa/core/security/secure_session_store.dart';
import 'package:absensi_mahasiswa/core/security/session_coordinator.dart';
import 'package:absensi_mahasiswa/features/auth/domain/entities/user.dart';
import 'package:absensi_mahasiswa/features/auth/domain/repositories/auth_repository.dart';
import 'package:absensi_mahasiswa/features/auth/domain/usecases/change_password.dart';
import 'package:absensi_mahasiswa/features/auth/domain/usecases/get_current_user.dart';
import 'package:absensi_mahasiswa/features/auth/domain/usecases/login.dart';
import 'package:absensi_mahasiswa/features/auth/domain/usecases/logout.dart';
import 'package:absensi_mahasiswa/features/auth/presentation/bloc/auth_bloc.dart';
import 'package:absensi_mahasiswa/features/auth/presentation/bloc/auth_event.dart';
import 'package:absensi_mahasiswa/features/auth/presentation/bloc/auth_state.dart';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:dartz/dartz.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

/// Push service palsu: mencatat register/revoke dan token yang dikirim ke
/// backend, tanpa menyentuh platform Firebase.
class _FakePush extends PushMessagingService {
  _FakePush() : super(enabled: true, initializeFirebase: () async {});

  final tokenCalls = <String?>[];
  int registerCount = 0;
  int revokeCount = 0;

  @override
  Future<void> registerForUser(FcmTokenSink sink) async {
    registerCount++;
    await sink('fake-token');
  }

  @override
  Future<void> revokeForUser() async {
    revokeCount++;
  }
}

class _SessionStore implements SessionStore {
  String? token;
  int generation = 1;

  @override
  SessionSnapshot get snapshot => SessionSnapshot(token, generation);

  @override
  Future<void> clear() async {
    token = null;
    generation++;
  }

  @override
  Future<bool> clearIfMatches(SessionSnapshot expected) async {
    if (expected.token != token || expected.generation != generation) {
      return false;
    }
    await clear();
    return true;
  }

  @override
  Future<void> saveToken(String value) async {
    token = value;
    generation++;
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

class _Queue extends OfflineQueueService {
  _Queue() : super(_Keys());
  int? owner;
  @override
  int? get activeOwnerUserId => owner;
  @override
  Future<void> activate(int userId) async => owner = userId;
  @override
  Future<void> purgeForLogout(int userId) async => owner = null;
}

class _Connectivity extends ConnectivityService {
  _Connectivity(OfflineQueueService queue)
    : super(
        Connectivity(),
        queue,
        Dio(),
        enableRetryTimer: false,
        connectivityChecker: (() async => [ConnectivityResult.none]),
      );
  @override
  Future<void> pauseAndWait() async {}
  @override
  void resume() {}
}

class _Repository implements AuthRepository {
  _Repository(this.session, this.push);

  final _SessionStore session;
  final _FakePush push;

  @override
  Future<Either<Failure, User>> login(String login, String password) async {
    await session.saveToken('token-b');
    return Right(_user());
  }

  @override
  Future<Either<Failure, User>> getCurrentUser() async => Right(_user());

  @override
  Future<Either<Failure, void>> logout() async {
    await session.clear();
    return const Right(null);
  }

  @override
  Future<String?> getToken() async => session.token;

  @override
  Future<Either<Failure, void>> updateFcmToken(String token) async {
    // Ini yang dipanggil FcmTokenSink; catat token yang benar-benar diteruskan.
    push.tokenCalls.add(token.isEmpty ? null : token);
    return const Right(null);
  }

  @override
  Future<Either<Failure, void>> changePassword(
    String a,
    String b,
    String c,
  ) async => const Right(null);
  @override
  Future<void> clearToken() => session.clear();
  @override
  Future<Either<Failure, void>> forgotPassword(String email) async =>
      const Right(null);
  @override
  Future<User?> getSavedUser() async => null;
  @override
  Future<void> saveToken(String token) => session.saveToken(token);
  @override
  Future<void> saveUser(User user) async {}
  @override
  Future<Either<Failure, User>> updateProfile(
    Map<String, dynamic> data,
  ) async => Right(_user());
}

User _user() => const User(
  id: 7,
  nama: 'Uji',
  email: 'uji@example.test',
  status: 'aktif',
  mustChangePassword: false,
  enrollmentStatus: 'approved',
  roles: ['mahasiswa'],
);

AuthBloc _buildBloc({
  required _SessionStore store,
  required _Repository repository,
  required OfflineQueueService queue,
  required ConnectivityService connectivity,
  required SessionCoordinator coordinator,
  required _FakePush push,
}) {
  return AuthBloc(
    login: Login(repository),
    logout: Logout(repository),
    getCurrentUser: GetCurrentUser(repository),
    changePassword: ChangePassword(repository),
    authRepository: repository,
    offlineQueueService: queue,
    connectivityService: connectivity,
    sessionCoordinator: coordinator,
    pushMessaging: push,
  );
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('PushMessagingService fail-safe', () {
    test('degrades to no-op when Firebase init throws', () async {
      final service = PushMessagingService(
        enabled: true,
        initializeFirebase: () async =>
            throw Exception('no google-services.json'),
      );

      final available = await service.initialize();

      expect(available, isFalse);
      expect(service.isAvailable, isFalse);

      // register/revoke tidak boleh melempar walau Firebase tidak tersedia.
      String? sunk = 'unchanged';
      await service.registerForUser((token) async => sunk = token);
      // Tanpa Firebase, register tidak menghubungi sink token.
      expect(sunk, 'unchanged');

      // revoke tetap mengosongkan backend (kirim null) tanpa crash.
      await service.revokeForUser();
      expect(sunk, isNull);
      await service.dispose();
    });

    test('initialize is idempotent', () async {
      var calls = 0;
      final service = PushMessagingService(
        enabled: true,
        initializeFirebase: () async {
          calls++;
          throw Exception('unconfigured');
        },
      );
      await service.initialize();
      await service.initialize();
      expect(calls, 1);
    });

    test(
      'is disabled by default for release builds without an opt-in',
      () async {
        var calls = 0;
        final service = PushMessagingService(
          initializeFirebase: () async => calls++,
        );

        expect(await service.initialize(), isFalse);
        expect(service.isAvailable, isFalse);
        expect(calls, 0);
      },
    );
  });

  group('AuthBloc FCM lifecycle', () {
    late _SessionStore store;
    late _FakePush push;
    late _Repository repository;
    late OfflineQueueService queue;
    late ConnectivityService connectivity;
    late SessionCoordinator coordinator;
    late AuthBloc bloc;

    setUp(() {
      store = _SessionStore();
      push = _FakePush();
      repository = _Repository(store, push);
      queue = _Queue();
      connectivity = _Connectivity(queue);
      coordinator = SessionCoordinator(store);
      bloc = _buildBloc(
        store: store,
        repository: repository,
        queue: queue,
        connectivity: connectivity,
        coordinator: coordinator,
        push: push,
      );
    });

    tearDown(() async {
      await bloc.close();
      await connectivity.shutdown();
      await coordinator.close();
    });

    test('registers FCM token on login success', () async {
      bloc.add(const LoginRequested(login: 'uji', password: 'rahasia'));
      await bloc.stream.firstWhere((s) => s is Authenticated);

      expect(push.registerCount, 1);
      expect(push.tokenCalls, contains('fake-token'));
    });

    test('registers FCM token on startup auth success', () async {
      await store.saveToken('token-existing');
      bloc.add(CheckAuthStatus());
      await bloc.stream.firstWhere((s) => s is Authenticated);

      expect(push.registerCount, 1);
    });

    test('revokes FCM token on logout', () async {
      bloc.add(const LoginRequested(login: 'uji', password: 'rahasia'));
      await bloc.stream.firstWhere((s) => s is Authenticated);

      bloc.add(LogoutRequested());
      await bloc.stream.firstWhere((s) => s is Unauthenticated);

      expect(push.revokeCount, greaterThanOrEqualTo(1));
    });

    test('revokes FCM token on session invalidation', () async {
      await store.saveToken('token-existing');
      bloc.add(CheckAuthStatus());
      await bloc.stream.firstWhere((s) => s is Authenticated);

      final beforeRevoke = push.revokeCount;
      await coordinator.invalidateIfCurrent(store.snapshot);
      await bloc.stream.firstWhere((s) => s is Unauthenticated);

      expect(push.revokeCount, greaterThan(beforeRevoke));
    });
  });
}
