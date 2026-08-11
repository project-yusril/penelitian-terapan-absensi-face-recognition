import 'dart:async';
import 'dart:ui' show PlatformDispatcher;

import 'package:flutter/foundation.dart' show kReleaseMode;
import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:hive_flutter/hive_flutter.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:intl/date_symbol_data_local.dart';

import 'core/logging/app_bloc_observer.dart';
import 'core/logging/app_logger.dart';
import 'core/logging/logging_navigator_observer.dart';
import 'core/network/api_client.dart';
import 'core/network/network_info.dart';
import 'core/network/connectivity_service.dart';
import 'core/theme/app_theme.dart';
import 'core/offline/offline_queue_service.dart';
import 'core/offline/queue_key_store.dart';
import 'core/config/app_config.dart';
import 'core/security/secure_session_store.dart';
import 'core/security/session_coordinator.dart';
import 'core/notifications/push_messaging_service.dart';
import 'core/time/server_time_anchor.dart';
import 'features/attendance/data/services/geolocator_attendance_position_provider.dart';
import 'features/attendance/domain/services/attendance_location_service.dart';
import 'features/face_recognition/domain/services/temporary_capture_processor.dart';

import 'features/auth/data/datasources/auth_local_datasource.dart';
import 'features/auth/data/datasources/auth_remote_datasource.dart';
import 'features/auth/data/repositories/auth_repository_impl.dart';
import 'features/auth/domain/repositories/auth_repository.dart';
import 'features/auth/domain/usecases/login.dart';
import 'features/auth/domain/usecases/logout.dart';
import 'features/auth/domain/usecases/get_current_user.dart';
import 'features/auth/domain/usecases/change_password.dart';
import 'features/auth/presentation/bloc/auth_bloc.dart';
import 'features/auth/presentation/bloc/auth_event.dart';
import 'features/auth/presentation/bloc/auth_state.dart';
import 'features/auth/presentation/pages/login_page.dart';
import 'features/auth/presentation/pages/change_password_page.dart';
import 'features/auth/presentation/pages/forgot_password_page.dart';
import 'features/notifications/presentation/pages/notifications_page.dart';

import 'features/home/data/datasources/home_remote_datasource.dart';
import 'features/home/data/repositories/home_repository_impl.dart';
import 'features/home/domain/repositories/home_repository.dart';
import 'features/home/domain/entities/home_entities.dart';
import 'features/home/presentation/bloc/home_bloc.dart';

import 'features/attendance/presentation/pages/attendance_page.dart';
import 'features/face_recognition/presentation/bloc/face_bloc.dart';
import 'features/face_recognition/presentation/pages/enrollment_page.dart';

import 'features/history/presentation/pages/history_page.dart';
import 'features/shell/presentation/pages/main_shell.dart';

final AppLogger _bootLog = AppLogger.tag('Boot');

/// Bangun [AttendancePage] dari sebuah jadwal, memetakan argumen route ke
/// mode check-in/check-out yang benar.
///
/// H-13: diekstrak agar kontrak navigasi (mode checkout + `attendanceId`) dapat
/// diuji tanpa membangun seluruh state halaman yang butuh kamera/GPS.
/// `isCheckout` true bila jadwal sudah check-in namun belum check-out.
AttendancePage buildAttendancePageFor(JadwalHariIni jadwal) {
  return AttendancePage(
    jadwalId: jadwal.jadwalId,
    mataKuliahId: jadwal.mataKuliahId,
    mataKuliahName: jadwal.mataKuliah,
    geofenceLat: jadwal.geofenceLat,
    geofenceLon: jadwal.geofenceLon,
    geofenceRadius: jadwal.geofenceRadius,
    attendanceId: jadwal.attendanceId,
    isCheckout: jadwal.isCheckedIn && !jadwal.isCheckedOut,
  );
}

/// Pasang penangkap error tingkat aplikasi.
///
/// Tiga jalur berbeda harus dipasang terpisah, karena masing-masing menangkap
/// hal yang berbeda dan tidak saling menggantikan:
/// * [FlutterError.onError] → error dari framework (build/layout/paint).
///   Inilah yang mencetak "RenderFlex overflowed".
/// * [PlatformDispatcher.instance.onError] → error async yang lolos ke engine.
/// * `runZonedGuarded` → sisanya, mis. Future tanpa `await` yang gagal.
void _installGlobalErrorHandlers() {
  final flutterLog = AppLogger.tag('FlutterError');
  final previousOnError = FlutterError.onError;
  FlutterError.onError = (FlutterErrorDetails details) {
    flutterLog.error(
      details.summary.toString(),
      data: {
        'library': details.library,
        if (details.context != null) 'konteks': details.context.toString(),
      },
      error: details.exception,
      stackTrace: details.stack,
    );
    // Tetap teruskan ke handler bawaan agar overlay merah di debug tidak hilang.
    previousOnError?.call(details);
  };

  final platformLog = AppLogger.tag('PlatformDispatcher');
  PlatformDispatcher.instance.onError = (error, stack) {
    platformLog.error(
      'error async tidak tertangani',
      error: error,
      stackTrace: stack,
    );
    return true;
  };
}

void main() {
  runZonedGuarded(
    () async {
      WidgetsFlutterBinding.ensureInitialized();
      _installGlobalErrorHandlers();

      // Satu baris ini memberi log ke SELURUH bloc/cubit aplikasi sekaligus.
      Bloc.observer = AppBlocObserver();

      _bootLog.info('aplikasi mulai', data: {'mode': kReleaseMode ? 'release' : 'debug'});

      // Formatters memakai DateFormat dengan locale 'id_ID' (mis. nama bulan
      // pada `formatDate`). Tanpa inisialisasi ini, pemanggilnya melempar
      // LocaleDataException — bug laten yang belum terlihat hanya karena
      // formatter tanggal belum dipakai di layar mana pun.
      await _bootLog.timed(
        'initializeDateFormatting(id_ID)',
        () => initializeDateFormatting('id_ID'),
      );

      await _bootLog.timed('Hive.initFlutter', Hive.initFlutter);

      final sharedPreferences = await _bootLog.timed(
        'SharedPreferences.getInstance',
        SharedPreferences.getInstance,
      );
      const secureStorage = FlutterSecureStorage();
      final secureSession = SecureSessionStore(secureStorage, sharedPreferences);
      await _bootLog.timed('SecureSessionStore.initialize', secureSession.initialize);
      final sessionCoordinator = SessionCoordinator(secureSession);

      // AppConfig melempar StateError kalau API_BASE_URL kosong/bukan HTTPS
      // maupun loopback. Tanpa log ini, kegagalannya hanya tampak sebagai
      // layar putih tanpa penjelasan apa pun.
      late final AppConfig appConfig;
      try {
        appConfig = AppConfig.fromEnvironment();
        _bootLog.info(
          'AppConfig terbaca',
          data: {'apiBaseUri': appConfig.apiBaseUri.toString()},
        );
      } catch (error, stack) {
        _bootLog.error(
          'AppConfig gagal dibaca — pastikan --dart-define=API_BASE_URL diisi',
          error: error,
          stackTrace: stack,
        );
        rethrow;
      }

      final offlineQueueService = OfflineQueueService(
        const SecureQueueKeyStore(secureStorage),
      );
      await _bootLog.timed('OfflineQueueService.init', offlineQueueService.init);
      final captureCleanupRegistry = TemporaryCaptureCleanupRegistry(
        sharedPreferences,
      );
      await _bootLog.timed(
        'TemporaryCaptureCleanupRegistry.retryCleanup',
        captureCleanupRegistry.retryCleanup,
      );

      // L-02: lifecycle FCM. initialize() fail-safe — bila Firebase belum
      // dikonfigurasi (google-services.json / firebase_options.dart belum ada),
      // push menjadi no-op dan aplikasi tetap boot normal.
      final pushMessaging = PushMessagingService();
      await _bootLog.timed(
        'PushMessagingService.initialize',
        pushMessaging.initialize,
      );

      _bootLog.info('inisialisasi selesai, menjalankan UI');

      runApp(
        MyApp(
          sharedPreferences: sharedPreferences,
          offlineQueueService: offlineQueueService,
          appConfig: appConfig,
          secureSession: secureSession,
          sessionCoordinator: sessionCoordinator,
          captureCleanupRegistry: captureCleanupRegistry,
          pushMessaging: pushMessaging,
        ),
      );
    },
    (error, stack) {
      AppLogger.tag('Zone').error(
        'error tidak tertangkap di zona aplikasi',
        error: error,
        stackTrace: stack,
      );
    },
  );
}

class MyApp extends StatefulWidget {
  final SharedPreferences sharedPreferences;
  final OfflineQueueService offlineQueueService;
  final AppConfig appConfig;
  final SecureSessionStore secureSession;
  final SessionCoordinator sessionCoordinator;
  final TemporaryCaptureCleanupRegistry captureCleanupRegistry;
  final PushMessagingService pushMessaging;

  const MyApp({
    super.key,
    required this.sharedPreferences,
    required this.offlineQueueService,
    required this.appConfig,
    required this.secureSession,
    required this.sessionCoordinator,
    required this.captureCleanupRegistry,
    required this.pushMessaging,
  });

  @override
  State<MyApp> createState() => _MyAppState();
}

class _MyAppState extends State<MyApp> {
  late final Connectivity _connectivity;
  late final NetworkInfoImpl _networkInfo;
  late final ApiClient _apiClient;
  late final ConnectivityService _connectivityService;
  late final ServerTimeAnchor _serverTimeAnchor;
  late final AttendanceLocationService _attendanceLocationService;

  @override
  void initState() {
    super.initState();
    _connectivity = Connectivity();
    _serverTimeAnchor = ServerTimeAnchor();
    _attendanceLocationService = AttendanceLocationService(
      GeolocatorAttendancePositionProvider(),
      ticks: () => _serverTimeAnchor.monotonicNow,
      authoritativeNow: () => _serverTimeAnchor.now,
    );
    _networkInfo = NetworkInfoImpl(_connectivity);
    _apiClient = ApiClient(widget.appConfig, widget.sessionCoordinator);
    _connectivityService = ConnectivityService(
      _connectivity,
      widget.offlineQueueService,
      _apiClient.dio,
    )..initialize();
  }

  @override
  void dispose() {
    unawaited(_connectivityService.shutdown());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // Core services
    final networkInfo = _networkInfo;
    final apiClient = _apiClient;
    final navigatorKey = GlobalKey<NavigatorState>();

    // Connectivity service with auto-sync
    final connectivityService = _connectivityService;

    // Auth
    final authLocalDataSource = AuthLocalDataSourceImpl(
      widget.sharedPreferences,
      widget.secureSession,
    );
    final authRemoteDataSource = AuthRemoteDataSourceImpl(apiClient);
    final authRepository = AuthRepositoryImpl(
      authRemoteDataSource,
      authLocalDataSource,
      networkInfo,
    );

    // Home
    final homeRemoteDataSource = HomeRemoteDataSourceImpl(
      apiClient,
      _serverTimeAnchor,
    );
    final homeRepository = HomeRepositoryImpl(
      homeRemoteDataSource,
      networkInfo,
    );

    return MultiRepositoryProvider(
      providers: [
        RepositoryProvider<ApiClient>.value(value: apiClient),
        // Dibutuhkan halaman profil: foto enrollment dilayani lewat route yang
        // memakai auth:sanctum DAN signed, jadi Image.network harus ikut
        // mengirim bearer token — Dio tidak terlibat saat memuat gambar.
        RepositoryProvider<SessionCoordinator>.value(
          value: widget.sessionCoordinator,
        ),
        RepositoryProvider<AuthRepository>.value(value: authRepository),
        RepositoryProvider<HomeRepository>.value(value: homeRepository),
        RepositoryProvider<NetworkInfo>.value(value: networkInfo),
        RepositoryProvider<OfflineQueueService>.value(
          value: widget.offlineQueueService,
        ),
        RepositoryProvider<ConnectivityService>.value(
          value: connectivityService,
        ),
        RepositoryProvider<ServerTimeAnchor>.value(value: _serverTimeAnchor),
        RepositoryProvider<AttendanceLocationService>.value(
          value: _attendanceLocationService,
        ),
        RepositoryProvider<TemporaryCaptureCleanupRegistry>.value(
          value: widget.captureCleanupRegistry,
        ),
      ],
      child: MultiBlocProvider(
        providers: [
          BlocProvider<AuthBloc>(
            create: (_) => AuthBloc(
              login: Login(authRepository),
              logout: Logout(authRepository),
              getCurrentUser: GetCurrentUser(authRepository),
              changePassword: ChangePassword(authRepository),
              authRepository: authRepository,
              offlineQueueService: widget.offlineQueueService,
              connectivityService: connectivityService,
              sessionCoordinator: widget.sessionCoordinator,
              pushMessaging: widget.pushMessaging,
            )..add(CheckAuthStatus()),
          ),
          BlocProvider<HomeBloc>(create: (_) => HomeBloc(homeRepository)),
          BlocProvider<FaceBloc>(create: (_) => FaceBloc(apiClient)),
        ],
        child: BlocListener<AuthBloc, AuthState>(
          listenWhen: (previous, current) =>
              current is Unauthenticated && previous is! Unauthenticated,
          listener: (_, state) {
            navigatorKey.currentState?.pushNamedAndRemoveUntil(
              '/login',
              (_) => false,
            );
          },
          child: MaterialApp(
            navigatorKey: navigatorKey,
            navigatorObservers: [LoggingNavigatorObserver()],
            title: 'Absensi Mahasiswa',
            theme: AppTheme.lightTheme,
            debugShowCheckedModeBanner: false,
            routes: {
              '/': (context) => const _AuthGate(),
              '/login': (context) => const LoginPage(),
              '/home': (context) => const _ProtectedRoute(child: MainShell()),
              '/change-password': (context) =>
                  const _ProtectedRoute(child: ChangePasswordPage()),
              '/enrollment': (context) =>
                  const _ProtectedRoute(child: EnrollmentPage()),
              '/history': (context) =>
                  const _ProtectedRoute(child: HistoryPage()),
            },
            onGenerateRoute: (settings) {
              // L-01: halaman notifikasi nyata (butuh apiClient dari scope build).
              if (settings.name == '/notifications') {
                return MaterialPageRoute(
                  builder: (_) => _ProtectedRoute(
                    child: NotificationsPage(apiClient: apiClient),
                  ),
                );
              }

              if (settings.name == '/attendance' &&
                  settings.arguments is JadwalHariIni) {
                final jadwal = settings.arguments as JadwalHariIni;
                return MaterialPageRoute(
                  builder: (_) => _ProtectedRoute(
                    child: buildAttendancePageFor(jadwal),
                  ),
                );
              }
              // L-01: alur lupa password nyata (forgot + reset via endpoint backend).
              if (settings.name == '/forgot-password') {
                return MaterialPageRoute(
                  builder: (_) => ForgotPasswordPage(apiClient: apiClient),
                );
              }
              return null;
            },
          ),
        ),
      ),
    );
  }
}

class _AuthGate extends StatelessWidget {
  const _AuthGate();

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<AuthBloc, AuthState>(
      builder: (context, state) {
        if (state is Authenticated) {
          // Ganti password tidak lagi dipaksa; tersedia di Profil.
          // Hanya arahkan ke enrollment kalau memang BELUM daftar atau DITOLAK.
          // Status 'pending' = sudah daftar & menunggu approval → jangan paksa
          // daftar ulang, langsung ke menu utama.
          if (state.user.isMahasiswa &&
              (state.user.enrollmentStatus == 'belum' ||
                  state.user.enrollmentStatus == 'rejected')) {
            return const EnrollmentPage();
          }
          return const MainShell();
        }

        if (state is AuthLoading || state is AuthInitial) {
          return const Scaffold(
            body: Center(child: CircularProgressIndicator()),
          );
        }
        if (state is AuthVerificationUnavailable) {
          return _VerificationUnavailable(message: state.message);
        }
        return const LoginPage();
      },
    );
  }
}

class _ProtectedRoute extends StatelessWidget {
  final Widget child;

  const _ProtectedRoute({required this.child});

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<AuthBloc, AuthState>(
      builder: (context, state) {
        if (state is Authenticated) return child;
        return const _AuthGate();
      },
    );
  }
}

class _VerificationUnavailable extends StatelessWidget {
  final String message;

  const _VerificationUnavailable({required this.message});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.cloud_off_outlined, size: 48),
              const SizedBox(height: 16),
              const Text(
                'Sesi belum dapat diverifikasi',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 8),
              Text(message, textAlign: TextAlign.center),
              const SizedBox(height: 20),
              FilledButton(
                onPressed: () =>
                    context.read<AuthBloc>().add(CheckAuthStatus()),
                child: const Text('Coba lagi'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
