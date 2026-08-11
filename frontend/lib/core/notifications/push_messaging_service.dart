import 'dart:async';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';

import '../logging/app_logger.dart';

/// Callback yang mendaftarkan (atau mencabut) FCM token ke backend.
///
/// Dipisah dari service agar service ini tidak bergantung pada layer auth/data
/// dan tetap dapat diuji tanpa jaringan. `token` bernilai null saat revoke.
typedef FcmTokenSink = Future<void> Function(String? token);

/// Handler pesan latar belakang FCM.
///
/// WAJIB berupa top-level/`static` function beranotasi `@pragma('vm:entry-point')`
/// karena dijalankan pada isolate terpisah oleh plugin. Fungsi ini sengaja
/// minimal: aplikasi memakai notifikasi `notification` (bukan data-only), jadi
/// sistem yang menampilkan banner. Handler cukup memastikan Firebase siap.
@pragma('vm:entry-point')
Future<void> firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  try {
    await Firebase.initializeApp();
  } catch (_) {
    // Tidak ada yang bisa dilakukan di background tanpa Firebase; jangan crash.
  }
}

/// Lifecycle Firebase Cloud Messaging end-to-end (L-02).
///
/// Desain **fail-closed terhadap crash, bukan terhadap fitur**: bila Firebase
/// belum dikonfigurasi pada platform (mis. `google-services.json` /
/// `firebase_options.dart` belum tersedia di lingkungan build), seluruh operasi
/// menjadi no-op yang tercatat di log — aplikasi TIDAK boleh gagal boot hanya
/// karena push belum disiapkan. Begitu infra Firebase tersedia, service ini
/// otomatis aktif tanpa perubahan kode pemanggil.
///
/// Tanggung jawab:
/// * inisialisasi Firebase + minta izin notifikasi;
/// * ambil token, daftarkan ke backend, dan ikuti `onTokenRefresh`;
/// * pasang handler foreground/background/opened;
/// * cabut token (server + lokal) saat logout/sesi invalid.
class PushMessagingService {
  PushMessagingService({
    FirebaseMessaging? messaging,
    Future<void> Function()? initializeFirebase,
    this.enabled = const bool.fromEnvironment(
      'ENABLE_FCM_PUSH',
      defaultValue: false,
    ),
    this.onMessageOpenedApp,
    AppLogger? logger,
  }) : _injectedMessaging = messaging,
       _initializeFirebase = initializeFirebase,
       _log = logger ?? AppLogger.tag('Fcm');

  final FirebaseMessaging? _injectedMessaging;
  final Future<void> Function()? _initializeFirebase;
  final AppLogger _log;
  final bool enabled;

  /// Dipanggil saat user membuka aplikasi lewat tap notifikasi (background→open
  /// atau terminated→open). Dipakai untuk deep-link/navigasi.
  final void Function(RemoteMessage message)? onMessageOpenedApp;

  FirebaseMessaging? _messaging;
  FcmTokenSink? _tokenSink;
  StreamSubscription<String>? _tokenRefreshSub;
  StreamSubscription<RemoteMessage>? _foregroundSub;
  StreamSubscription<RemoteMessage>? _openedSub;

  bool _available = false;
  bool _initialized = false;

  /// True bila Firebase berhasil diinisialisasi pada platform ini.
  bool get isAvailable => _available;

  /// Inisialisasi Firebase + pasang handler global. Aman dipanggil sekali di
  /// startup, sebelum login. Mengembalikan `false` (bukan melempar) bila
  /// Firebase belum dikonfigurasi.
  Future<bool> initialize() async {
    if (_initialized) return _available;
    _initialized = true;
    if (!enabled) {
      _log.info('Firebase Messaging dinonaktifkan oleh release config');
      return false;
    }
    try {
      if (_initializeFirebase != null) {
        await _initializeFirebase();
      } else {
        await Firebase.initializeApp();
      }
      _messaging = _injectedMessaging ?? FirebaseMessaging.instance;

      // Handler background harus didaftarkan sebelum runApp menyelesaikan boot.
      FirebaseMessaging.onBackgroundMessage(firebaseMessagingBackgroundHandler);

      _foregroundSub = FirebaseMessaging.onMessage.listen(_onForegroundMessage);
      _openedSub = FirebaseMessaging.onMessageOpenedApp.listen(
        _onMessageOpenedApp,
      );

      final initialMessage = await _messaging?.getInitialMessage();
      if (initialMessage != null) {
        _onMessageOpenedApp(initialMessage);
      }

      _available = true;
      _log.info('Firebase Messaging aktif');
      return true;
    } catch (error, stack) {
      // Penyebab paling umum: google-services.json / firebase_options.dart
      // belum tersedia. Ini kondisi konfigurasi, bukan bug — turunkan ke warn
      // dan biarkan aplikasi jalan tanpa push.
      _available = false;
      _log.warn(
        'Firebase Messaging tidak tersedia; push dinonaktifkan',
        error: error,
        stackTrace: stack,
      );
      return false;
    }
  }

  /// Minta izin notifikasi, ambil token, dan daftarkan ke backend lewat [sink].
  /// Dipanggil setelah user terautentikasi. Idempotent dan no-op bila Firebase
  /// tidak tersedia.
  Future<void> registerForUser(FcmTokenSink sink) async {
    _tokenSink = sink;
    final messaging = _messaging;
    if (!_available || messaging == null) {
      _log.debug('registerForUser dilewati (Firebase tidak tersedia)');
      return;
    }
    try {
      final settings = await messaging.requestPermission(
        alert: true,
        badge: true,
        sound: true,
      );
      final status = settings.authorizationStatus;
      if (status == AuthorizationStatus.denied) {
        _log.info('Izin notifikasi ditolak; token tidak didaftarkan');
        return;
      }

      final token = await messaging.getToken();
      if (token == null || token.isEmpty) {
        _log.warn('FCM token kosong; lewati registrasi');
        return;
      }
      await sink(token);
      _log.info('FCM token terdaftar', data: {'len': token.length});

      // Refresh dapat terjadi kapan saja (reinstall, restore, rotasi). Selalu
      // dorong ulang ke backend agar target push tetap valid.
      await _tokenRefreshSub?.cancel();
      _tokenRefreshSub = messaging.onTokenRefresh.listen((refreshed) {
        _log.info('FCM token refresh', data: {'len': refreshed.length});
        unawaited(_safeSink(refreshed));
      });
    } catch (error, stack) {
      _log.warn('Gagal registrasi FCM token', error: error, stackTrace: stack);
    }
  }

  /// Cabut token: hapus dari device dan kosongkan di backend. Dipanggil saat
  /// logout atau sesi invalid agar perangkat tidak lagi menerima push milik
  /// akun sebelumnya (penting untuk perangkat bersama — lihat C-06).
  Future<void> revokeForUser() async {
    await _tokenRefreshSub?.cancel();
    _tokenRefreshSub = null;

    // Selalu coba kosongkan di backend, walau delete lokal gagal.
    await _safeSink(null);

    final messaging = _messaging;
    if (_available && messaging != null) {
      try {
        await messaging.deleteToken();
        _log.info('FCM token dicabut dari perangkat');
      } catch (error, stack) {
        _log.warn(
          'Gagal menghapus FCM token lokal',
          error: error,
          stackTrace: stack,
        );
      }
    }
    _tokenSink = null;
  }

  Future<void> _safeSink(String? token) async {
    final sink = _tokenSink;
    if (sink == null) return;
    try {
      await sink(token);
    } catch (error, stack) {
      _log.warn(
        'Gagal mengirim FCM token ke backend',
        error: error,
        stackTrace: stack,
      );
    }
  }

  void _onForegroundMessage(RemoteMessage message) {
    // Notifikasi foreground tidak otomatis ditampilkan Android. Aplikasi ini
    // sudah punya lonceng notifikasi in-app + badge unread (AppLayout), jadi
    // di sini cukup dicatat; UI membaca daftar via endpoint notifications.
    _log.info(
      'Pesan FCM foreground',
      data: {
        'title': message.notification?.title,
        'messageId': message.messageId,
      },
    );
  }

  void _onMessageOpenedApp(RemoteMessage message) {
    _log.info('Notifikasi FCM dibuka', data: {'messageId': message.messageId});
    onMessageOpenedApp?.call(message);
  }

  Future<void> dispose() async {
    await _tokenRefreshSub?.cancel();
    await _foregroundSub?.cancel();
    await _openedSub?.cancel();
    _tokenRefreshSub = null;
    _foregroundSub = null;
    _openedSub = null;
  }
}
