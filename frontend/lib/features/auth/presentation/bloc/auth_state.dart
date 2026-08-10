import 'package:equatable/equatable.dart';
import '../../domain/entities/user.dart';

abstract class AuthState extends Equatable {
  const AuthState();
  @override
  List<Object?> get props => [];
}

class AuthInitial extends AuthState {}

class AuthLoading extends AuthState {}

class Authenticated extends AuthState {
  final User user;
  const Authenticated(this.user);
  @override
  List<Object?> get props => [user];
}

class Unauthenticated extends AuthState {
  final bool sessionExpired;

  const Unauthenticated({this.sessionExpired = false});

  @override
  List<Object?> get props => [sessionExpired];
}

class AuthVerificationUnavailable extends AuthState {
  final String message;

  const AuthVerificationUnavailable(this.message);

  @override
  List<Object?> get props => [message];
}

class AuthError extends AuthState {
  final String message;
  const AuthError(this.message);
  @override
  List<Object?> get props => [message];
}

class PasswordChangeSuccess extends AuthState {}

class ProfileUpdateSuccess extends AuthState {
  final User user;
  const ProfileUpdateSuccess(this.user);
}
