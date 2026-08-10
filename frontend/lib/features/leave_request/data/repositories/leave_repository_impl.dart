import 'package:dartz/dartz.dart';
import '../../../../core/errors/exceptions.dart';
import '../../../../core/errors/failures.dart';
import '../../../../core/network/network_info.dart';
import '../../domain/entities/leave_entities.dart';
import '../../domain/repositories/leave_repository.dart';
import '../datasources/leave_remote_datasource.dart';

class LeaveRepositoryImpl implements LeaveRepository {
  final LeaveRemoteDataSource _remoteDataSource;
  final NetworkInfo _networkInfo;

  LeaveRepositoryImpl(this._remoteDataSource, this._networkInfo);

  @override
  Future<Either<Failure, List<LeaveRequest>>> getMyLeaves() async {
    if (!await _networkInfo.isConnected) {
      return const Left(NetworkFailure('Tidak ada koneksi internet'));
    }
    try {
      final leaves = await _remoteDataSource.getMyLeaves();
      return Right(leaves);
    } on ServerException catch (e) {
      return Left(ServerFailure(e.message, statusCode: e.statusCode));
    } on NetworkException catch (e) {
      return Left(NetworkFailure(e.message));
    }
  }

  @override
  Future<Either<Failure, LeaveRequest>> submitLeave({
    required String jenis,
    required int? mataKuliahId,
    required String tanggalMulai,
    required String tanggalSelesai,
    required String keterangan,
    String? filePath,
  }) async {
    if (!await _networkInfo.isConnected) {
      return const Left(NetworkFailure('Tidak ada koneksi internet'));
    }
    try {
      final leave = await _remoteDataSource.submitLeave(
        jenis: jenis,
        mataKuliahId: mataKuliahId,
        tanggalMulai: tanggalMulai,
        tanggalSelesai: tanggalSelesai,
        keterangan: keterangan,
        filePath: filePath,
      );
      return Right(leave);
    } on ServerException catch (e) {
      return Left(ServerFailure(e.message, statusCode: e.statusCode));
    } on NetworkException catch (e) {
      return Left(NetworkFailure(e.message));
    }
  }
}
