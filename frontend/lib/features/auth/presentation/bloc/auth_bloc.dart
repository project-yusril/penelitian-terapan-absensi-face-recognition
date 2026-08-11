import 'dart:async';

import 'package:flutter_bloc/flutter_bloc.dart';
import '../../domain/usecases/login.dart';
import '../../domain/usecases/logout.dart';
import '../../domain/usecases/get_current_user.dart';
import '../../domain/usecases/change_password.dart';
import '../../domain/repositories/auth_repository.dart';
import 'auth_event.dart';
import 'auth_state.dart';
import '../../../../core/offline/offline_queue_service.dart';
import '../../../../core/network/connectivity_service.dart';
import '../../../../core/errors/failures.dart';
import '../../../../core/security/session_coordinator.dart';
import '../../../../core/notifications/push_messaging_service.dart';

class AuthBloc extends Bloc<AuthEvent, AuthState> {
  final Login _login;
  final Logout _logout;
  final GetCurrentUser _getCurrentUser;
  final ChangePassword _changePassword;
  final AuthRepository _authRepository;
  final OfflineQueueService _offlineQueueService;
  final ConnectivityService _connectivityService;
  final SessionCoordinator _sessionCoordinator;
  final PushMessagingService? _pushMessaging;
  late final StreamSubscription<SessionInvalidation> _invalidationSubscription;
  Future<void> _sessionOperation = Future.value();
  int _authGeneration = 0;

  AuthBloc({
    required Login login,
    required Logout logout,
    required GetCurrentUser getCurrentUser,
    required ChangePassword changePassword,
    required AuthRepository authRepository,
    required OfflineQueueService offlineQueueService,
    required ConnectivityService connectivityService,
    required SessionCoordinator sessionCoordinator,
    PushMessagingService? pushMessaging,
  }) : _login = login,
       _logout = logout,
       _getCurrentUser = getCurrentUser,
       _changePassword = changePassword,
       _authRepository = authRepository,
       _offlineQueueService = offlineQueueService,
       _connectivityService = connectivityService,
       _sessionCoordinator = sessionCoordinator,
       _pushMessaging = pushMessaging,
       super(AuthInitial()) {
    on<LoginRequested>(_onLoginRequested);
    on<LogoutRequested>(_onLogoutRequested);
    on<CheckAuthStatus>(_onCheckAuthStatus);
    on<GetCurrentUserData>(_onGetCurrentUserData);
    on<ChangePasswordRequested>(_onChangePasswordRequested);
    on<UpdateProfileRequested>(_onUpdateProfileRequested);
    on<SessionInvalidated>(_onSessionInvalidated);
    _invalidationSubscription = _sessionCoordinator.invalidations.listen((_) {
      if (!isClosed) add(const SessionInvalidated());
    });
  }

  Future<void> _onLoginRequested(
    LoginRequested event,
    Emitter<AuthState> emit,
  ) async {
    _authGeneration++;
    await _serializeSession(() async {
      emit(AuthLoading());
      final result = await _login(
        LoginParams(login: event.login, password: event.password),
      );
      await result.fold((failure) async => emit(AuthError(failure.message)), (
        user,
      ) async {
        await _offlineQueueService.activate(user.id);
        _connectivityService.resume();
        await _registerPushToken();
        emit(Authenticated(user));
      });
    });
  }

  Future<void> _onLogoutRequested(
    LogoutRequested event,
    Emitter<AuthState> emit,
  ) async {
    _authGeneration++;
    await _serializeSession(() async {
      emit(AuthLoading());
      final owner = _offlineQueueService.activeOwnerUserId;
      // Cabut FCM token selagi sesi masih valid agar backend benar-benar
      // mengosongkan target push milik akun ini (perangkat bersama, C-06).
      await _revokePushToken();
      await _connectivityService.pauseAndWait();
      await _logout();
      if (owner != null) await _offlineQueueService.purgeForLogout(owner);
      emit(const Unauthenticated());
    });
  }

  Future<void> _onCheckAuthStatus(
    CheckAuthStatus event,
    Emitter<AuthState> emit,
  ) async {
    _authGeneration++;
    await _serializeSession(() async {
      final token = await _authRepository.getToken();
      if (token == null || token.isEmpty) {
        emit(Unauthenticated());
        return;
      }
      final result = await _getCurrentUser();
      await result.fold(
        (failure) async {
          await _connectivityService.pauseAndWait();
          if (failure is AuthFailure) {
            emit(const Unauthenticated(sessionExpired: true));
          } else {
            emit(AuthVerificationUnavailable(failure.message));
          }
        },
        (user) async {
          await _offlineQueueService.activate(user.id);
          _connectivityService.resume();
          await _registerPushToken();
          emit(Authenticated(user));
        },
      );
    });
  }

  Future<void> _onGetCurrentUserData(
    GetCurrentUserData event,
    Emitter<AuthState> emit,
  ) async {
    final operationGeneration = _authGeneration;
    final sessionSnapshot = _sessionCoordinator.snapshot;
    final result = await _getCurrentUser();
    final currentSnapshot = _sessionCoordinator.snapshot;
    if (operationGeneration != _authGeneration ||
        currentSnapshot.generation != sessionSnapshot.generation ||
        currentSnapshot.token != sessionSnapshot.token ||
        !currentSnapshot.hasToken) {
      return;
    }
    result.fold(
      (failure) => emit(AuthError(failure.message)),
      (user) => emit(Authenticated(user)),
    );
  }

  Future<void> _onChangePasswordRequested(
    ChangePasswordRequested event,
    Emitter<AuthState> emit,
  ) async {
    emit(AuthLoading());
    final result = await _changePassword(
      ChangePasswordParams(
        oldPassword: event.oldPassword,
        newPassword: event.newPassword,
        confirmPassword: event.confirmPassword,
      ),
    );
    result.fold(
      (failure) => emit(AuthError(failure.message)),
      (_) => emit(PasswordChangeSuccess()),
    );
  }

  Future<void> _onUpdateProfileRequested(
    UpdateProfileRequested event,
    Emitter<AuthState> emit,
  ) async {
    emit(AuthLoading());
    final result = await _authRepository.updateProfile(event.data);
    result.fold(
      (failure) => emit(AuthError(failure.message)),
      (user) => emit(ProfileUpdateSuccess(user)),
    );
  }

  /// Daftarkan FCM token milik user aktif ke backend. No-op bila push tidak
  /// dikonfigurasi. Kegagalan tidak menggagalkan auth — service sudah menelan
  /// error internal, tetapi guard tambahan menjaga alur auth tetap kokoh.
  Future<void> _registerPushToken() async {
    final push = _pushMessaging;
    if (push == null) return;
    try {
      await push.registerForUser(
        (token) => _authRepository.updateFcmToken(token ?? ''),
      );
    } catch (_) {
      // Registrasi push tidak boleh memblokir login.
    }
  }

  /// Cabut FCM token dari perangkat dan backend. No-op bila push tidak
  /// dikonfigurasi.
  Future<void> _revokePushToken() async {
    final push = _pushMessaging;
    if (push == null) return;
    try {
      await push.revokeForUser();
    } catch (_) {
      // Kegagalan revoke tidak boleh memblokir logout/invalidation.
    }
  }

  Future<void> _serializeSession(Future<void> Function() operation) {
    final result = _sessionOperation.then((_) => operation());
    _sessionOperation = result.then<void>((_) {}, onError: (_, _) {});
    return result;
  }

  Future<void> _onSessionInvalidated(
    SessionInvalidated event,
    Emitter<AuthState> emit,
  ) async {
    _authGeneration++;
    await _serializeSession(() async {
      if (_sessionCoordinator.snapshot.hasToken) return;
      final owner = _offlineQueueService.activeOwnerUserId;
      if (state is Unauthenticated && owner == null) return;
      await _revokePushToken();
      await _connectivityService.pauseAndWait();
      if (owner != null && _offlineQueueService.activeOwnerUserId == owner) {
        await _offlineQueueService.purgeForLogout(owner);
      }
      emit(const Unauthenticated(sessionExpired: true));
    });
  }

  @override
  Future<void> close() async {
    await _invalidationSubscription.cancel();
    await _sessionOperation;
    return super.close();
  }
}
