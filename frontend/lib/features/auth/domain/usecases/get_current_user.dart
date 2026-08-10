import 'package:dartz/dartz.dart';
import '../../../../core/errors/failures.dart';
import '../entities/user.dart';
import '../repositories/auth_repository.dart';

class GetCurrentUser {
  final AuthRepository repository;
  GetCurrentUser(this.repository);

  Future<Either<Failure, User>> call() {
    return repository.getCurrentUser();
  }
}
