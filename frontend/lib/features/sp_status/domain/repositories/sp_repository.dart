import 'package:dartz/dartz.dart';
import '../../../../core/errors/failures.dart';
import '../entities/sp_entity.dart';

abstract class SpRepository {
  Future<Either<Failure, List<SpRecord>>> getMySpRecords();
}
