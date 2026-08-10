import 'package:dartz/dartz.dart';
import '../../../../core/errors/exceptions.dart';
import '../../../../core/errors/failures.dart';
import '../../../../core/network/network_info.dart';
import '../../domain/entities/sp_entity.dart';
import '../../domain/repositories/sp_repository.dart';
import '../datasources/sp_remote_datasource.dart';

class SpRepositoryImpl implements SpRepository {
  final SpRemoteDataSource _remoteDataSource;
  final NetworkInfo _networkInfo;

  SpRepositoryImpl(this._remoteDataSource, this._networkInfo);

  @override
  Future<Either<Failure, List<SpRecord>>> getMySpRecords() async {
    if (!await _networkInfo.isConnected) {
      return const Left(NetworkFailure('Tidak ada koneksi internet'));
    }
    try {
      final result = await _remoteDataSource.getMySpRecords();
      return Right(result);
    } on ServerException catch (e) {
      return Left(ServerFailure(e.message));
    }
  }
}
