import 'package:dartz/dartz.dart';
import '../../../../core/errors/failures.dart';
import '../repositories/auth_repository.dart';

class ChangePassword {
  final AuthRepository repository;
  ChangePassword(this.repository);

  Future<Either<Failure, void>> call(ChangePasswordParams params) {
    return repository.changePassword(
      params.oldPassword,
      params.newPassword,
      params.confirmPassword,
    );
  }
}

class ChangePasswordParams {
  final String oldPassword;
  final String newPassword;
  final String confirmPassword;
  const ChangePasswordParams({
    required this.oldPassword,
    required this.newPassword,
    required this.confirmPassword,
  });
}
