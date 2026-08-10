import 'package:dartz/dartz.dart';
import '../../../../core/errors/failures.dart';
import '../entities/user.dart';
import '../repositories/auth_repository.dart';

class Login {
  final AuthRepository repository;
  Login(this.repository);

  Future<Either<Failure, User>> call(LoginParams params) {
    return repository.login(params.login, params.password);
  }
}

class LoginParams {
  final String login;
  final String password;
  const LoginParams({required this.login, required this.password});
}
