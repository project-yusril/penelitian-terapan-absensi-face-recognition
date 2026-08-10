import 'dart:async';

import 'package:absensi_mahasiswa/core/errors/failures.dart';
import 'package:absensi_mahasiswa/core/network/connectivity_service.dart';
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

class _SessionStore implements SessionStore {
  String? token = 'token-a';
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
  final currentUsers = <Completer<Either<Failure, User>>>[];
  final _SessionStore session;

  _Repository(this.session);

  @override
  Future<Either<Failure, User>> getCurrentUser() {
    final completer = Completer<Either<Failure, User>>();
    currentUsers.add(completer);
    return completer.future;
  }

  @override
  Future<String?> getToken() async => session.token;

  @override
  Future<Either<Failure, User>> login(String login, String password) async =>
      Right(_user(2, 'B'));

  @override
  Future<Either<Failure, void>> logout() async {
    await session.clear();
    return const Right(null);
  }

  @override
  Future<Either<Failure, void>> changePassword(
    String oldPassword,
    String newPassword,
    String confirmPassword,
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
  Future<Either<Failure, void>> updateFcmToken(String token) async =>
      const Right(null);
  @override
  Future<Either<Failure, User>> updateProfile(
    Map<String, dynamic> data,
  ) async => Right(_user(1, 'A'));
}

User _user(int id, String name) => User(
  id: id,
  nama: name,
  email: '$name@example.test',
  status: 'aktif',
  mustChangePassword: false,
  enrollmentStatus: 'approved',
  roles: const ['mahasiswa'],
);

void main() {
  late _SessionStore store;
  late _Repository repository;
  late OfflineQueueService queue;
  late ConnectivityService connectivity;
  late SessionCoordinator coordinator;
  late AuthBloc bloc;

  setUp(() {
    store = _SessionStore();
    repository = _Repository(store);
    queue = _Queue();
    connectivity = _Connectivity(queue);
    coordinator = SessionCoordinator(store);
    bloc = AuthBloc(
      login: Login(repository),
      logout: Logout(repository),
      getCurrentUser: GetCurrentUser(repository),
      changePassword: ChangePassword(repository),
      authRepository: repository,
      offlineQueueService: queue,
      connectivityService: connectivity,
      sessionCoordinator: coordinator,
    );
  });

  tearDown(() async {
    await bloc.close();
    await connectivity.shutdown();
    await coordinator.close();
  });

  test(
    'refresh result cannot re-authenticate after session invalidation',
    () async {
      bloc.add(GetCurrentUserData());
      await Future<void>.delayed(Duration.zero);
      expect(repository.currentUsers, hasLength(1));

      final snapshot = store.snapshot;
      await coordinator.invalidateIfCurrent(snapshot);
      repository.currentUsers.single.complete(Right(_user(1, 'A')));
      await Future<void>.delayed(Duration.zero);
      await Future<void>.delayed(Duration.zero);

      expect(bloc.state, isNot(isA<Authenticated>()));
    },
  );

  test('generation A refresh cannot overwrite a newer login B', () async {
    bloc.add(GetCurrentUserData());
    await Future<void>.delayed(Duration.zero);
    bloc.add(CheckAuthStatus());
    await Future<void>.delayed(Duration.zero);
    expect(repository.currentUsers, hasLength(2));
    repository.currentUsers[1].complete(Right(_user(2, 'B')));
    repository.currentUsers[0].complete(Right(_user(1, 'A')));
    await Future<void>.delayed(Duration.zero);
    await Future<void>.delayed(Duration.zero);

    expect(bloc.state, isA<Authenticated>());
    expect((bloc.state as Authenticated).user.id, 2);
  });
}
