import 'package:absensi_mahasiswa/core/errors/exceptions.dart';
import 'package:absensi_mahasiswa/core/errors/failures.dart';
import 'package:absensi_mahasiswa/core/network/network_info.dart';
import 'package:absensi_mahasiswa/features/auth/data/datasources/auth_local_datasource.dart';
import 'package:absensi_mahasiswa/features/auth/data/datasources/auth_remote_datasource.dart';
import 'package:absensi_mahasiswa/features/auth/data/models/user_model.dart';
import 'package:absensi_mahasiswa/features/auth/data/repositories/auth_repository_impl.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  final cached = UserModel.fromJson({
    'id': 7,
    'nama': 'Cached User',
    'roles': ['mahasiswa'],
    'status': 'aktif',
    'enrollment_status': 'approved',
    'must_change_password': false,
  });

  test('401 is terminal and never authenticates cached user', () async {
    final repository = AuthRepositoryImpl(
      ThrowingRemote(AuthException(message: 'expired')),
      FakeLocal(cached),
      const ConnectedNetwork(),
    );

    final result = await repository.getCurrentUser();

    expect(result.isLeft(), isTrue);
    expect(result.fold((failure) => failure, (_) => null), isA<AuthFailure>());
  });

  test('network failure never authenticates cached user', () async {
    final repository = AuthRepositoryImpl(
      ThrowingRemote(NetworkException(message: 'offline')),
      FakeLocal(cached),
      const ConnectedNetwork(),
    );

    final result = await repository.getCurrentUser();

    expect(result.isLeft(), isTrue);
    expect(
      result.fold((failure) => failure, (_) => null),
      isA<NetworkFailure>(),
    );
  });

  test('503 never authenticates cached user and preserves status', () async {
    final repository = AuthRepositoryImpl(
      ThrowingRemote(ServerException(message: 'down', statusCode: 503)),
      FakeLocal(cached),
      const ConnectedNetwork(),
    );

    final result = await repository.getCurrentUser();

    final failure = result.fold((failure) => failure, (_) => null);
    expect(failure, isA<ServerFailure>());
    expect((failure as ServerFailure).statusCode, 503);
  });

  test('malformed response never authenticates cached user', () async {
    final repository = AuthRepositoryImpl(
      ThrowingRemote(const FormatException('bad body')),
      FakeLocal(cached),
      const ConnectedNetwork(),
    );

    final result = await repository.getCurrentUser();

    expect(result.isLeft(), isTrue);
    expect(
      result.fold((failure) => failure, (_) => null),
      isA<ServerFailure>(),
    );
  });
}

class ConnectedNetwork implements NetworkInfo {
  const ConnectedNetwork();

  @override
  Future<bool> get isConnected async => true;
}

class FakeLocal implements AuthLocalDataSource {
  UserModel? user;
  String? token = 'cached-token';

  FakeLocal(this.user);

  @override
  Future<void> clearAll() async {
    user = null;
    token = null;
  }

  @override
  Future<void> clearToken() async => token = null;

  @override
  Future<UserModel?> getSavedUser() async => user;

  @override
  Future<String?> getToken() async => token;

  @override
  Future<void> saveToken(String value) async => token = value;

  @override
  Future<void> saveUser(UserModel value) async => user = value;
}

class ThrowingRemote implements AuthRemoteDataSource {
  final Object error;

  ThrowingRemote(this.error);

  Never _throw() => throw error;

  @override
  Future<void> changePassword(
    String oldPassword,
    String newPassword,
    String confirmPassword,
  ) async => _throw();

  @override
  Future<void> forgotPassword(String email) async => _throw();

  @override
  Future<UserModel> getCurrentUser() async => _throw();

  @override
  Future<Map<String, dynamic>> login(String login, String password) async =>
      _throw();

  @override
  Future<void> logout() async => _throw();

  @override
  Future<void> updateFcmToken(String token) async => _throw();

  @override
  Future<UserModel> updateProfile(Map<String, dynamic> data) async => _throw();
}
