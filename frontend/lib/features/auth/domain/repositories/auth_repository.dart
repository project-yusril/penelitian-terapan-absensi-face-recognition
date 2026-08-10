import 'package:dartz/dartz.dart';
import '../../../../core/errors/failures.dart';
import '../entities/user.dart';

abstract class AuthRepository {
  Future<Either<Failure, User>> login(String login, String password);
  Future<Either<Failure, void>> logout();
  Future<Either<Failure, User>> getCurrentUser();
  Future<Either<Failure, void>> changePassword(
    String oldPassword,
    String newPassword,
    String confirmPassword,
  );
  Future<Either<Failure, void>> forgotPassword(String email);
  Future<Either<Failure, void>> updateFcmToken(String token);
  Future<Either<Failure, User>> updateProfile(Map<String, dynamic> data);
  Future<String?> getToken();
  Future<void> saveToken(String token);
  Future<void> clearToken();
  Future<void> saveUser(User user);
  Future<User?> getSavedUser();
}
