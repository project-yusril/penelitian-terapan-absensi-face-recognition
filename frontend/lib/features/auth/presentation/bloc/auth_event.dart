import 'package:equatable/equatable.dart';

abstract class AuthEvent extends Equatable {
  const AuthEvent();
  @override
  List<Object?> get props => [];
}

class SessionInvalidated extends AuthEvent {
  const SessionInvalidated();
}

class LoginRequested extends AuthEvent {
  final String login;
  final String password;
  const LoginRequested({required this.login, required this.password});
  @override
  List<Object?> get props => [login, password];
}

class LogoutRequested extends AuthEvent {}

class CheckAuthStatus extends AuthEvent {}

class GetCurrentUserData extends AuthEvent {}

class ChangePasswordRequested extends AuthEvent {
  final String oldPassword;
  final String newPassword;
  final String confirmPassword;
  const ChangePasswordRequested({
    required this.oldPassword,
    required this.newPassword,
    required this.confirmPassword,
  });
}

class UpdateProfileRequested extends AuthEvent {
  final Map<String, dynamic> data;
  const UpdateProfileRequested(this.data);
}
