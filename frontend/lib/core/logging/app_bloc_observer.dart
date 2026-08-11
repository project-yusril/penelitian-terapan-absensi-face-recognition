import 'package:flutter_bloc/flutter_bloc.dart';

import 'app_logger.dart';

/// Mencatat siklus hidup SEMUA bloc/cubit di aplikasi.
///
/// Dipasang sekali di `main()` lewat `Bloc.observer`, sehingga setiap bloc —
/// Auth, Home, Face, Attendance, dan yang ditambahkan nanti — otomatis
/// ikut tercatat tanpa perlu menyentuh file bloc-nya satu per satu.
///
/// [onError] adalah yang paling penting: sebelum ini, kegagalan di dalam
/// handler bloc hanya berakhir sebagai state `FaceError(e.toString())` di UI
/// tanpa jejak stack trace ke mana pun.
class AppBlocObserver extends BlocObserver {
  AppBlocObserver();

  final AppLogger _log = AppLogger.tag('Bloc');

  @override
  void onCreate(BlocBase<dynamic> bloc) {
    super.onCreate(bloc);
    _log.debug('dibuat', data: {'bloc': bloc.runtimeType.toString()});
  }

  @override
  void onEvent(Bloc<dynamic, dynamic> bloc, Object? event) {
    super.onEvent(bloc, event);
    _log.info(
      'event',
      data: {
        'bloc': bloc.runtimeType.toString(),
        'event': event.runtimeType.toString(),
      },
    );
  }

  @override
  void onTransition(
    Bloc<dynamic, dynamic> bloc,
    Transition<dynamic, dynamic> transition,
  ) {
    super.onTransition(bloc, transition);
    _log.debug(
      'transisi',
      data: {
        'bloc': bloc.runtimeType.toString(),
        'event': transition.event.runtimeType.toString(),
        'dari': transition.currentState.runtimeType.toString(),
        'ke': transition.nextState.runtimeType.toString(),
      },
    );
  }

  @override
  void onChange(BlocBase<dynamic> bloc, Change<dynamic> change) {
    super.onChange(bloc, change);
    _log.trace(
      'perubahan state',
      data: {
        'bloc': bloc.runtimeType.toString(),
        'dari': change.currentState.runtimeType.toString(),
        'ke': change.nextState.runtimeType.toString(),
      },
    );
  }

  @override
  void onError(BlocBase<dynamic> bloc, Object error, StackTrace stackTrace) {
    _log.error(
      'error tidak tertangani di bloc',
      data: {'bloc': bloc.runtimeType.toString()},
      error: error,
      stackTrace: stackTrace,
    );
    super.onError(bloc, error, stackTrace);
  }

  @override
  void onClose(BlocBase<dynamic> bloc) {
    super.onClose(bloc);
    _log.debug('ditutup', data: {'bloc': bloc.runtimeType.toString()});
  }
}
