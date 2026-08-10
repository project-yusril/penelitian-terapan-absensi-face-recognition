import 'package:dartz/dartz.dart';
import '../../../../core/errors/exceptions.dart';
import '../../../../core/errors/failures.dart';
import '../../../../core/network/network_info.dart';
import '../../domain/entities/home_entities.dart';
import '../../domain/repositories/home_repository.dart';
import '../datasources/home_remote_datasource.dart';

class HomeRepositoryImpl implements HomeRepository {
  final HomeRemoteDataSource _remoteDataSource;
  final NetworkInfo _networkInfo;

  HomeRepositoryImpl(this._remoteDataSource, this._networkInfo);

  @override
  Future<Either<Failure, List<JadwalHariIni>>> getTodaySchedule() async {
    if (!await _networkInfo.isConnected) {
      return const Left(NetworkFailure('Tidak ada koneksi internet'));
    }
    try {
      final result = await _remoteDataSource.getTodaySchedule();
      return Right(result);
    } on ServerException catch (e) {
      return Left(ServerFailure(e.message));
    }
  }

  @override
  Future<Either<Failure, AttendanceSummary>> getAttendanceSummary() async {
    if (!await _networkInfo.isConnected) {
      return const Left(NetworkFailure('Tidak ada koneksi internet'));
    }
    try {
      final result = await _remoteDataSource.getAttendanceSummary();
      return Right(result);
    } on ServerException catch (e) {
      return Left(ServerFailure(e.message));
    }
  }

  @override
  Future<Either<Failure, List<NotificationItem>>>
  getRecentNotifications() async {
    try {
      final result = await _remoteDataSource.getRecentNotifications();
      return Right(result);
    } catch (_) {
      return const Right([]);
    }
  }
}
