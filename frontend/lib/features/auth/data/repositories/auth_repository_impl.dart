import 'package:dartz/dartz.dart';
import '../../../../core/errors/exceptions.dart';
import '../../../../core/errors/failures.dart';
import '../../../../core/network/network_info.dart';
import '../../domain/entities/user.dart';
import '../../domain/repositories/auth_repository.dart';
import '../datasources/auth_local_datasource.dart';
import '../datasources/auth_remote_datasource.dart';
import '../models/user_model.dart';

class AuthRepositoryImpl implements AuthRepository {
  final AuthRemoteDataSource _remoteDataSource;
  final AuthLocalDataSource _localDataSource;
  final NetworkInfo _networkInfo;

  AuthRepositoryImpl(
    this._remoteDataSource,
    this._localDataSource,
    this._networkInfo,
  );

  @override
  Future<Either<Failure, User>> login(String login, String password) async {
    if (!await _networkInfo.isConnected) {
      return const Left(NetworkFailure('Tidak ada koneksi internet'));
    }
    try {
      final result = await _remoteDataSource.login(login, password);
      final user = result['user'] as UserModel;
      final token = result['token'] as String;

      await _localDataSource.saveToken(token);
      await _localDataSource.saveUser(user);

      return Right(user);
    } on ServerException catch (e) {
      return Left(ServerFailure(e.message, statusCode: e.statusCode));
    } on AuthException catch (e) {
      return Left(AuthFailure(e.message));
    } on NetworkException catch (e) {
      return Left(NetworkFailure(e.message));
    }
  }

  @override
  Future<Either<Failure, void>> logout() async {
    try {
      if (await _networkInfo.isConnected) {
        await _remoteDataSource.logout();
      }
    } catch (_) {}
    await _localDataSource.clearAll();
    return const Right(null);
  }

  @override
  Future<Either<Failure, User>> getCurrentUser() async {
    try {
      final user = await _remoteDataSource.getCurrentUser();
      await _localDataSource.saveUser(user);
      return Right(user);
    } on AuthException catch (e) {
      return Left(AuthFailure(e.message));
    } on ServerException catch (e) {
      return Left(ServerFailure(e.message, statusCode: e.statusCode));
    } on NetworkException catch (e) {
      return Left(NetworkFailure(e.message));
    } catch (_) {
      return const Left(ServerFailure('Respons verifikasi sesi tidak valid'));
    }
  }

  @override
  Future<Either<Failure, void>> changePassword(
    String oldPassword,
    String newPassword,
    String confirmPassword,
  ) async {
    try {
      await _remoteDataSource.changePassword(
        oldPassword,
        newPassword,
        confirmPassword,
      );
      return const Right(null);
    } on ServerException catch (e) {
      return Left(ServerFailure(e.message, statusCode: e.statusCode));
    }
  }

  @override
  Future<Either<Failure, void>> forgotPassword(String email) async {
    try {
      await _remoteDataSource.forgotPassword(email);
      return const Right(null);
    } on ServerException catch (e) {
      return Left(ServerFailure(e.message));
    }
  }

  @override
  Future<Either<Failure, void>> updateFcmToken(String token) async {
    try {
      await _remoteDataSource.updateFcmToken(token);
      return const Right(null);
    } catch (_) {
      return const Right(null);
    }
  }

  @override
  Future<Either<Failure, User>> updateProfile(Map<String, dynamic> data) async {
    try {
      final user = await _remoteDataSource.updateProfile(data);
      await _localDataSource.saveUser(user);
      return Right(user);
    } on ServerException catch (e) {
      return Left(ServerFailure(e.message));
    }
  }

  @override
  Future<String?> getToken() => _localDataSource.getToken();

  @override
  Future<void> saveToken(String token) => _localDataSource.saveToken(token);

  @override
  Future<void> clearToken() => _localDataSource.clearToken();

  @override
  Future<void> saveUser(User user) =>
      _localDataSource.saveUser(user as UserModel);

  @override
  Future<User?> getSavedUser() => _localDataSource.getSavedUser();
}
