import 'dart:async';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:camera/camera.dart';

import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:google_mlkit_face_detection/google_mlkit_face_detection.dart';
import 'package:device_info_plus/device_info_plus.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/constants/app_constants.dart';
import '../../../../core/errors/exceptions.dart';
import '../../../../core/logging/app_logger.dart';
import '../../../auth/presentation/bloc/auth_bloc.dart';
import '../../../auth/presentation/bloc/auth_event.dart';
import '../../../auth/presentation/bloc/auth_state.dart';
import '../bloc/face_bloc.dart';
import '../bloc/face_event.dart';
import '../bloc/face_state.dart';

import '../../domain/services/face_recognition_service.dart';
import '../../domain/services/liveness_detection_service.dart';
import '../../domain/services/camera_frame_analysis.dart';
import '../../domain/services/camera_frame_snapshot.dart';
import '../../domain/services/frame_analysis_pipeline.dart';
import '../../domain/services/temporary_capture_processor.dart';
import '../../domain/services/enrollment_identity_continuity.dart';

class EnrollmentPage extends StatefulWidget {
  const EnrollmentPage({super.key});

  @override
  State<EnrollmentPage> createState() => _EnrollmentPageState();
}

class _EnrollmentPageState extends State<EnrollmentPage>
    with WidgetsBindingObserver {
  static final AppLogger _log = AppLogger.tag('Enrollment');

  CameraController? _cameraController;
  final FaceDetector _faceDetector = FaceDetector(
    options: FaceDetectorOptions(
      enableContours: true,
      enableLandmarks: true,
      enableClassification: true,
      enableTracking: true,
      minFaceSize: 0.3,
    ),
  );
  final LivenessDetectionService _livenessService = LivenessDetectionService();
  final FaceRecognitionService _faceService = FaceRecognitionService();

  bool _isCameraInitialized = false;
  String _statusMessage = 'Menginisialisasi kamera...';
  String _challenge = '';
  bool _livenessPassed = false;
  bool _faceDetected = false;
  bool _isCapturing = false; // true saat ambil foto + generate embedding
  bool _countdownStarted = false; // cegah countdown jalan lebih dari sekali
  double _livenessProgress = 0; // 0..1 progress challenge liveness
  int _step = 0; // 0: detecting, 1: liveness, 2: countdown, 3: capturing

  // ── Cek dini di TAHAP 1 (deteksi): apakah wajah ini milik mahasiswa lain ──
  bool _checkingDuplicate = false; // sedang memanggil API check-duplicate
  bool _duplicateChecked = false; // sudah dicek untuk sesi wajah saat ini
  bool _isKnownFace = false; // true → wajah sudah terdaftar atas nama lain
  String? _matchedName;
  late final SingleInflightFramePipeline<void> _framePipeline;
  final AttemptGeneration _attempts = AttemptGeneration();
  final LivenessContinuity _continuity = LivenessContinuity();
  final EnrollmentIdentityContinuity _identityContinuity =
      EnrollmentIdentityContinuity();
  final AsyncCommandSerializer _cameraCommands = AsyncCommandSerializer();
  late final TemporaryCaptureProcessor _captures;
  CameraDescription? _activeCamera;
  int _attemptId = 0;
  int _captureId = 0;
  bool _lifecycleActive = true;

  @override
  void initState() {
    super.initState();
    _log.info('halaman enrollment dibuka');
    WidgetsBinding.instance.addObserver(this);
    _framePipeline = SingleInflightFramePipeline(_analyzeFrame);
    _captures = TemporaryCaptureProcessor(
      registry: context.read<TemporaryCaptureCleanupRegistry>(),
    );
    _attemptId = _attempts.begin();
    unawaited(_initCamera());

    // Model TFLite dimuat tanpa ditunggu supaya kamera bisa tampil lebih dulu.
    // Sebelumnya kegagalannya tidak tertangani sama sekali: model gagal muat
    // baru terasa jauh kemudian sebagai "Pemeriksaan biometrik gagal" saat
    // generateEmbedding dipanggil, tanpa jejak penyebab aslinya.
    unawaited(
      _faceService.initialize().catchError((Object error, StackTrace stack) {
        _log.error(
          'inisialisasi model wajah gagal — pembuatan embedding akan gagal',
          error: error,
          stackTrace: stack,
        );
        if (mounted) {
          setState(() {
            _statusMessage =
                'Model pengenalan wajah gagal dimuat. Tutup dan buka ulang aplikasi.';
          });
        }
      }),
    );
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _log.debug('siklus hidup berubah', data: {'state': state.name});
    if (state == AppLifecycleState.resumed) {
      _lifecycleActive = true;
      unawaited(_captures.retryCleanup());
      _resetAttempt();
      _restoreStepMachineAfterResume();
      if (_cameraController?.value.isInitialized ?? false) {
        _startFaceDetection();
      }
      return;
    }
    _lifecycleActive = false;
    _attempts.cancel();
    _continuity.reset();
    _livenessService.reset();
    unawaited(_stopImageStream());
  }

  /// Kembalikan mesin langkah ke tahap deteksi setelah aplikasi dibuka lagi.
  ///
  /// `_resetAttempt()` hanya membatalkan attempt dan mereset layanan liveness;
  /// flag UI (`_step`, `_countdownStarted`, `_checkingDuplicate`) tidak ikut
  /// dibersihkan. Akibatnya, kalau aplikasi ter-background tepat saat hitung
  /// mundur berjalan, saat kembali `_step` masih 2 dan `_countdownStarted`
  /// masih true — sementara `_analyzeFrame` hanya menangani `_step` 0 dan 1.
  /// Tidak ada cabang yang cocok lagi, jadi halaman tampak hidup (border wajah
  /// tetap jalan) tapi tidak akan pernah maju sampai user keluar-masuk halaman.
  ///
  /// Hal yang sama terjadi bila cek duplikat ter-interupsi di tengah jalan:
  /// `_checkingDuplicate` tersangkut `true` selamanya dan cek tidak pernah
  /// dijalankan ulang.
  ///
  /// `_duplicateChecked` dan `_isKnownFace` sengaja dipertahankan: wajahnya
  /// sudah diperiksa, dan mengulang panggilan API di setiap resume bisa
  /// menabrak throttle `biometric-probe` di backend.
  void _restoreStepMachineAfterResume() {
    if (_step == 0 && !_checkingDuplicate && !_countdownStarted) return;
    _log.warn(
      'mesin langkah dipulihkan setelah resume',
      data: {
        'tahapSebelumnya': _step,
        'sedangCekDuplikat': _checkingDuplicate,
        'countdownBerjalan': _countdownStarted,
      },
    );
    if (!mounted) return;
    setState(() {
      _step = 0;
      _countdownStarted = false;
      _checkingDuplicate = false;
      _livenessPassed = false;
      _livenessProgress = 0;
      _isCapturing = false;
      _statusMessage = _isKnownFace
          ? 'Data biometrik tidak dapat digunakan untuk pendaftaran.'
          : 'Posisikan wajah Anda di dalam frame';
    });
  }

  Future<void> _initCamera() async {
    // Seluruh blok ini sebelumnya tanpa try/catch. Izin kamera yang ditolak
    // atau daftar kamera kosong melempar di sini, dan error-nya hanya hilang
    // ke dalam Future yang tidak ditunggu — layar berhenti di
    // "Menginisialisasi kamera..." selamanya tanpa petunjuk apa pun.
    try {
      final cameras = await _log.timed(
        'availableCameras',
        availableCameras,
        describeResult: (list) => list.length,
      );
      if (cameras.isEmpty) {
        throw CameraException('no_cameras', 'Perangkat tidak punya kamera');
      }
      _log.debug(
        'kamera tersedia',
        data: {
          'daftar': cameras
              .map((c) => '${c.name}:${c.lensDirection.name}')
              .join(', '),
        },
      );

      final frontCamera = cameras.firstWhere(
        (c) => c.lensDirection == CameraLensDirection.front,
        orElse: () => cameras.first,
      );
      _activeCamera = frontCamera;
      if (frontCamera.lensDirection != CameraLensDirection.front) {
        _log.warn(
          'kamera depan tidak ditemukan, memakai kamera pertama',
          data: {'dipakai': frontCamera.name},
        );
      }

      _cameraController = CameraController(
        frontCamera,
        ResolutionPreset.high,
        enableAudio: false,
        // ML Kit Android hanya menerima NV21 untuk image stream.
        imageFormatGroup: Platform.isIOS
            ? ImageFormatGroup.bgra8888
            : ImageFormatGroup.nv21,
      );

      await _log.timed(
        'CameraController.initialize',
        () => _cameraCommands.run(() => _cameraController!.initialize()),
      );
      _log.info(
        'kamera siap',
        data: {
          'kamera': frontCamera.name,
          'arah': frontCamera.lensDirection.name,
          'resolusi': _cameraController?.value.previewSize?.toString(),
          'format': Platform.isIOS ? 'bgra8888' : 'nv21',
        },
      );

      if (mounted && _lifecycleActive) {
        setState(() {
          _isCameraInitialized = true;
          _statusMessage = 'Posisikan wajah Anda di dalam frame';
        });
        _startFaceDetection();
      }
    } on CameraException catch (e, stack) {
      _log.error(
        'kamera gagal diinisialisasi',
        data: {'kode': e.code, 'deskripsi': e.description},
        error: e,
        stackTrace: stack,
      );
      if (mounted) {
        setState(() {
          _statusMessage = e.code.contains('Permission')
              ? 'Izin kamera ditolak. Aktifkan lewat Pengaturan aplikasi.'
              : 'Kamera gagal dibuka (${e.code}).';
        });
      }
    } catch (e, stack) {
      _log.error('kamera gagal disiapkan', error: e, stackTrace: stack);
      if (mounted) {
        setState(() => _statusMessage = 'Kamera gagal disiapkan.');
      }
    }
  }

  void _startFaceDetection() {
    if (_cameraController == null || !_cameraController!.value.isInitialized) {
      return;
    }

    unawaited(
      _cameraCommands.run(() async {
        final controller = _cameraController!;
        if (controller.value.isStreamingImages) return;
        await controller.startImageStream((image) {
          if (!_lifecycleActive || _framePipeline.isBusy) return;
          final camera = _activeCamera;
          if (camera == null) return;
          unawaited(
            _framePipeline.admit(
              (sequence) => CameraFrameSnapshot.copyFrom(
                image: image,
                camera: camera,
                deviceOrientation: controller.value.deviceOrientation,
                platform: Platform.isIOS
                    ? CameraPlatformContract.ios
                    : CameraPlatformContract.android,
                attemptId: _attemptId,
                frameId: sequence,
              ),
            ),
          );
        });
      }),
    );
  }

  Future<void> _analyzeFrame(CameraFrameSnapshot snapshot) async {
    if (!_isCurrent(snapshot.attemptId)) return;
    try {
      final faces = await _faceDetector.processImage(
        CameraFrameAnalysis.toInputImage(snapshot),
      );
      if (!_isCurrent(snapshot.attemptId)) return;
      final discontinuity = _continuity.observe(
        faceCount: faces.length,
        trackingId: faces.length == 1 ? faces.single.trackingId : null,
        frameId: snapshot.frameId,
        rotation: CanonicalCameraRotation.clockwiseDegrees(snapshot),
      );
      if (discontinuity != LivenessDiscontinuity.none) {
        _livenessService.reset();
        _livenessPassed = false;
        _livenessProgress = 0;
      }

      if (faces.isEmpty) {
        // Realtime: wajah hilang dari kamera. Reset status deteksi & hasil
        // cek dini supaya border kembali PUTIH dan pesan identitas hilang.
        // (Hanya saat masih di tahap deteksi; jangan ganggu liveness dst.)
        if (mounted) {
          setState(() {
            _faceDetected = false;
            if (_step == 0 && !_checkingDuplicate) {
              _isKnownFace = false;
              _matchedName = null;
              _duplicateChecked = false;
              _statusMessage = 'Posisikan wajah Anda di dalam frame';
            }
          });
        }
      } else if (faces.length > 1) {
        if (mounted) {
          setState(() {
            _faceDetected = false;
            _statusMessage = 'Hanya 1 wajah yang diizinkan';
          });
        }
      } else {
        final face = faces.first;
        if (mounted) setState(() => _faceDetected = true);

        // ── TAHAP 1 (DETEKSI): cek dini wajah baru vs sudah terdaftar ──
        // Sebelum lanjut ke liveness, pastikan wajah ini bukan milik
        // mahasiswa lain. Cek dilakukan SEKALI saat wajah sudah hadap lurus.
        if (_step == 0 &&
            !_duplicateChecked &&
            !_checkingDuplicate &&
            _livenessService.isFaceFacingFront(face) &&
            _livenessService.areEyesOpen(face)) {
          // Set flag SEBELUM await agar frame berikut tidak ikut memicu.
          _checkingDuplicate = true;
          await _runDetectionDuplicateCheck(snapshot.attemptId);
          return;
        }

        // Wajah sudah terdeteksi milik orang lain → jangan lanjut ke liveness.
        if (_isKnownFace) {
          return;
        }

        if (_step == 0 &&
            _duplicateChecked &&
            _livenessService.isFaceFacingFront(face) &&
            _livenessService.areEyesOpen(face)) {
          setState(() {
            _step = 1;
            _challenge = _livenessService.getRandomChallenge();
            _livenessProgress = 0;
            _statusMessage = _livenessInstruction(_challenge);
          });
          _log.info(
            'tahap 1 → 2: liveness dimulai',
            data: {'tantangan': _challenge, 'attemptId': snapshot.attemptId},
          );
        }

        if (_step == 1) {
          final passed = await _livenessService.checkChallenge(
            face,
            _challenge,
          );
          if (mounted) {
            setState(() {
              _livenessProgress = _livenessService.progress;
              _statusMessage = _livenessService.hasNeutral
                  ? _livenessInstruction(_challenge)
                  : 'Hadap lurus ke kamera & buka mata';
            });
          }
          if (passed && !_countdownStarted) {
            _log.info(
              'tantangan liveness lolos',
              data: {'tantangan': _challenge},
            );
            final livenessEmbedding = await _log.timed(
              'generateEmbeddingFromSnapshot (pengikat identitas)',
              () => _faceService.generateEmbeddingFromSnapshot(snapshot, face),
              describeResult: (vector) => describeVector(vector),
            );
            if (!_isCurrent(snapshot.attemptId)) return;
            _identityContinuity.bind(
              attemptId: snapshot.attemptId,
              embedding: livenessEmbedding,
            );
            _countdownStarted = true;
            setState(() {
              _livenessPassed = true;
              _step = 2; // countdown
              _statusMessage = 'Verifikasi berhasil!';
            });
            _startCaptureCountdown(snapshot.attemptId);
          }
        }
      }
    } catch (e, stack) {
      _logFrameError(e, stack);
    }
  }

  // Analisis frame berjalan pada laju kamera (~30 fps). Mencatat setiap
  // kegagalan akan membanjiri logcat dan justru mengubur baris penting, jadi
  // error yang identik dan berurutan hanya dicatat sekali beserta jumlahnya.
  String? _lastFrameErrorSignature;
  int _repeatedFrameErrors = 0;

  void _logFrameError(Object error, StackTrace stack) {
    final signature = '${error.runtimeType}:$error';
    if (signature == _lastFrameErrorSignature) {
      _repeatedFrameErrors++;
      // Laporkan berkala supaya kegagalan terus-menerus tetap terlihat.
      if (_repeatedFrameErrors % 30 == 0) {
        _log.warn(
          'analisis frame masih gagal berulang',
          data: {'berulang': _repeatedFrameErrors, 'error': signature},
        );
      }
      return;
    }
    _lastFrameErrorSignature = signature;
    _repeatedFrameErrors = 1;
    _log.error('analisis frame gagal', error: error, stackTrace: stack);
  }

  /// TAHAP 1 (DETEKSI): ambil 1 foto, generate embedding, lalu tanya backend
  /// apakah wajah ini sudah terdaftar atas nama mahasiswa lain.
  /// - Wajah BARU  → diam, lanjut otomatis ke tahap 2 (liveness).
  /// - Wajah LAMA → tampilkan peringatan anonim dan hentikan proses.
  ///
  /// Catatan: memakai takePicture()+JPEG (bukan frame NV21 mentah) supaya
  /// embedding konsisten dengan saat submit. Konversi NV21→YUV manual rawan
  /// gagal sehingga sebelumnya cek diam-diam error & tidak pernah jalan.
  Future<void> _runDetectionDuplicateCheck(int attemptId) async {
    final faceBloc = context.read<FaceBloc>();
    final authBloc = context.read<AuthBloc>();
    if (mounted) {
      setState(() => _statusMessage = 'Memverifikasi wajah...');
    }

    _log.info('cek duplikat: mulai', data: {'attemptId': attemptId});

    // Hentikan stream agar takePicture tidak bentrok dengan image stream.
    try {
      await _stopImageStream();
    } catch (e, stack) {
      // Sebelumnya `catch (_) {}`. Kegagalan menghentikan stream membuat
      // takePicture berikutnya gagal, jadi ini perlu terlihat walau alurnya
      // sengaja tetap diteruskan.
      _log.warn('gagal menghentikan image stream', error: e, stackTrace: stack);
    }

    try {
      final photo = await _log.timed('takePicture', _takePicture);
      final captureId = ++_captureId;
      await _captures.process(
        path: photo.path,
        attemptId: attemptId,
        captureId: captureId,
        operation: (capture) async {
          _log.debug(
            'cek duplikat: foto siap diproses',
            data: {
              'captureId': capture.captureId,
              'bytes': capture.bytes.length,
            },
          );
          final photoFaces = await _log.timed(
            'deteksi wajah pada foto',
            () => _faceDetector.processImage(
              InputImage.fromFilePath(capture.path),
            ),
            describeResult: (faces) => '${faces.length} wajah',
          );
          if (!_isCurrent(capture.attemptId)) return;

          if (photoFaces.length != 1) {
            _log.warn(
              'cek duplikat dibatalkan: jumlah wajah pada foto bukan 1',
              data: {'jumlahWajah': photoFaces.length},
            );
            // Wajah tidak tertangkap di foto → ulangi deteksi.
            if (mounted) {
              setState(() {
                _checkingDuplicate = false;
                _duplicateChecked = false;
                _statusMessage = 'Posisikan wajah Anda di dalam frame';
              });
            }
            _livenessService.reset();
            _continuity.reset();
            _startFaceDetection();
            return;
          }

          final embedding = await _log.timed(
            'generateEmbedding (cek duplikat)',
            () => _faceService.generateEmbedding(
              capture.bytes,
              photoFaces.single,
            ),
            describeResult: (vector) => describeVector(vector),
          );
          final dup = await _log.timed(
            'API checkDuplicate',
            () => faceBloc.checkDuplicate(embedding),
            describeResult: (result) => 'isDuplicate=${result.isDuplicate}',
          );
          if (!_isCurrent(capture.attemptId)) return;

          if (dup.isDuplicate) {
            _handleBiometricConflict(dup, faceBloc, authBloc);
          } else {
            // Wajah baru: lanjut ke liveness tanpa pesan apa pun.
            setState(() {
              _checkingDuplicate = false;
              _duplicateChecked = true;
              _isKnownFace = false;
              _matchedName = null;
              _statusMessage = 'Posisikan wajah Anda di dalam frame';
            });
            _startFaceDetection();
          }
        },
      );
    } catch (e, stack) {
      // Inilah sumber pesan "Pemeriksaan biometrik gagal". Sebelumnya
      // exception-nya dibuang tanpa dicatat, sehingga empat penyebab yang
      // sangat berbeda — kamera, ML Kit, TFLite, dan jaringan — tampil
      // sebagai satu pesan yang sama dan mustahil dibedakan.
      _log.error(
        'cek duplikat gagal',
        data: {
          'attemptId': attemptId,
          'jenis': e.runtimeType.toString(),
          if (e is ServerException) 'status': e.statusCode,
          if (e is ServerException) 'kode': e.code,
          if (e is ServerException) 'pesanBackend': e.message,
          if (e is ServerException) 'errors': e.errors,
        },
        error: e,
        stackTrace: stack,
      );
      if (mounted) {
        setState(() {
          _checkingDuplicate = false;
          _duplicateChecked = false;
          _statusMessage = _describeCheckFailure(e);
        });
      }
      _startFaceDetection();
    }
  }

  /// Terjemahkan kegagalan cek duplikat jadi pesan yang menunjuk penyebab.
  ///
  /// User tidak bisa memperbaiki keadaan kalau setiap kegagalan berbunyi sama;
  /// "izin kamera ditolak" dan "server tidak terjangkau" butuh tindakan yang
  /// berbeda.
  String _describeCheckFailure(Object error) {
    if (error is ServerException) {
      if (error.statusCode == 429) {
        return 'Terlalu banyak percobaan. Tunggu sebelum mencoba kembali.';
      }
      if (error.statusCode == 422) {
        return 'Data wajah ditolak server. Coba ulangi dengan pencahayaan lebih baik.';
      }
      return 'Server menolak pemeriksaan (HTTP ${error.statusCode}). Coba lagi.';
    }
    if (error is AuthException) {
      return 'Sesi berakhir. Silakan login ulang.';
    }
    if (error is NetworkException) {
      return 'Tidak dapat terhubung ke server. Periksa koneksi Anda.';
    }
    if (error is CameraException) {
      return 'Kamera bermasalah (${error.code}). Coba lagi.';
    }
    return 'Pemeriksaan biometrik gagal. Coba lagi.';
  }

  /// Setelah liveness lolos: hentikan deteksi, beri jeda 3 detik (minta user
  /// kembali hadap lurus & netral) sambil hitung mundur, baru ambil foto.
  /// Ini mencegah foto enrollment ter-capture saat kepala masih menoleh/nunduk.
  Future<void> _startCaptureCountdown(int attemptId) async {
    _log.info(
      'liveness lolos, mulai hitung mundur',
      data: {'attemptId': attemptId},
    );
    // Hentikan stream agar tidak ada deteksi lagi selama jeda.
    try {
      await _stopImageStream();
    } catch (e, stack) {
      _log.warn(
        'gagal menghentikan image stream sebelum countdown',
        error: e,
        stackTrace: stack,
      );
    }

    // Beri pesan agar user siap & tahan posisi sebelum foto diambil.
    if (mounted) {
      setState(() {
        _statusMessage =
            'Verifikasi berhasil!\nTahan posisi & hadap lurus, jangan bergerak...';
      });
    }
    await Future.delayed(const Duration(seconds: 1));
    if (!_isCurrent(attemptId)) return;

    // Hitung mundur 5 detik agar user benar-benar siap saat capture.
    for (int i = 2; i >= 1; i--) {
      if (!_isCurrent(attemptId)) return;
      setState(() {
        _statusMessage =
            'Tahan posisi, jangan bergerak.\nFoto diambil dalam $i...';
      });
      await Future.delayed(const Duration(seconds: 1));
    }

    if (!_isCurrent(attemptId)) return;
    setState(() {
      _step = 3;
      _isCapturing = true;
      _statusMessage = 'Memproses & menyimpan wajah...';
    });
    await _captureAndEnroll(attemptId);
  }

  Future<void> _captureAndEnroll(int attemptId) async {
    // Ambil referensi bloc sebelum await agar tidak memakai BuildContext
    // melintasi async gap (lint use_build_context_synchronously).
    final faceBloc = context.read<FaceBloc>();
    final authBloc = context.read<AuthBloc>();
    try {
      // stream sudah dihentikan di _startCaptureCountdown; aman bila sudah stop.
      final photo = await _takePicture();
      final captureId = ++_captureId;
      await _captures.process(
        path: photo.path,
        attemptId: attemptId,
        captureId: captureId,
        operation: (capture) async {
          // Final JPEG is a distinct, explicit capture. Detection, crop,
          // duplicate check, and submitted bytes all bind to this capture ID.
          _log.info(
            'capture final diambil',
            data: {
              'captureId': capture.captureId,
              'bytes': capture.bytes.length,
              'attemptId': capture.attemptId,
            },
          );
          final photoFaces = await _log.timed(
            'deteksi wajah pada capture final',
            () => _faceDetector.processImage(
              InputImage.fromFilePath(capture.path),
            ),
            describeResult: (faces) => '${faces.length} wajah',
          );
          if (!_isCurrent(capture.attemptId)) return;

          if (photoFaces.isEmpty) {
            throw _EnrollmentCaptureException(
              'Wajah tidak terdeteksi pada foto. Coba lagi & pastikan pencahayaan cukup.',
            );
          }
          if (photoFaces.length > 1) {
            throw _EnrollmentCaptureException(
              'Terdeteksi lebih dari satu wajah pada foto. Coba lagi.',
            );
          }

          // Pakai bounding box dari foto (bukan dari frame stream).
          final finalFace = photoFaces.single;
          if (!_livenessService.isFaceFacingFront(finalFace) ||
              !_livenessService.areEyesOpen(finalFace)) {
            throw const _EnrollmentCaptureException(
              'Wajah akhir harus menghadap lurus dengan mata terbuka. Ulangi liveness.',
            );
          }
          final embedding = await _log.timed(
            'generateEmbedding (capture final)',
            () => _faceService.generateEmbedding(capture.bytes, finalFace),
            describeResult: (vector) => describeVector(vector),
          );
          final identityMatches = _identityContinuity.matches(
            attemptId: capture.attemptId,
            candidate: embedding,
            threshold: FaceBloc.strictFaceThreshold,
          );
          _log.info(
            'pemeriksaan kontinuitas identitas',
            data: {
              'cocok': identityMatches,
              'threshold': FaceBloc.strictFaceThreshold,
            },
          );
          if (!identityMatches) {
            throw const _EnrollmentCaptureException(
              'Identitas wajah berubah atau sesi liveness kedaluwarsa. Ulangi liveness.',
            );
          }

          // ============================================================
          // Ulangi pemeriksaan terhadap capture final sebelum submit.
          // ============================================================
          if (_isCurrent(capture.attemptId)) {
            setState(() => _statusMessage = 'Memeriksa data wajah...');
          }
          final dup = await _log.timed(
            'API checkDuplicate (sebelum submit)',
            () => faceBloc.checkDuplicate(embedding),
            describeResult: (result) => 'isDuplicate=${result.isDuplicate}',
          );
          if (!_isCurrent(capture.attemptId)) return;

          if (dup.isDuplicate) {
            _handleBiometricConflict(dup, faceBloc, authBloc);
            return;
          }

          final deviceInfo = DeviceInfoPlugin();
          final deviceModel = Platform.isIOS
              ? (await deviceInfo.iosInfo).utsname.machine
              : (await deviceInfo.androidInfo).model;
          final deviceOs = Platform.isIOS
              ? 'iOS ${(await deviceInfo.iosInfo).systemVersion}'
              : 'Android ${(await deviceInfo.androidInfo).version.release}';

          if (_isCurrent(capture.attemptId)) {
            faceBloc.add(
              SubmitEnrollment(
                embedding: embedding,
                livenessPassed: _livenessPassed,
                livenessChallenge: _challenge,
                deviceModel: deviceModel,
                deviceOs: deviceOs,
                fotoEnrollment: capture.bytes,
              ),
            );
          }
        },
      );
    } catch (e, stack) {
      // _EnrollmentCaptureException adalah penolakan yang disengaja (wajah
      // tidak terdeteksi, identitas berubah, dst.) — dicatat sebagai warn.
      // Selain itu berarti kegagalan tak terduga dan perlu stack trace.
      if (e is _EnrollmentCaptureException) {
        _log.warn(
          'capture enrollment ditolak',
          data: {'alasan': e.message, 'attemptId': attemptId},
        );
      } else {
        _log.error(
          'capture enrollment gagal',
          data: {
            'attemptId': attemptId,
            'jenis': e.runtimeType.toString(),
            if (e is ServerException) 'status': e.statusCode,
            if (e is ServerException) 'errors': e.errors,
          },
          error: e,
          stackTrace: stack,
        );
      }
      if (mounted) {
        final msg = e is _EnrollmentCaptureException
            ? e.message
            : e is ServerException && e.statusCode == 429
            ? 'Terlalu banyak percobaan. Tunggu sebelum mencoba kembali.'
            : 'Gagal mengambil foto. Coba lagi.';
        setState(() {
          _statusMessage = msg;
          _step = 0;
          _isCapturing = false;
          _countdownStarted = false;
          _livenessPassed = false;
          _livenessService.reset();
          _resetAttempt();
        });
        _startFaceDetection();
      }
    }
  }

  bool _isCurrent(int attemptId) =>
      mounted && _lifecycleActive && _attempts.isCurrent(attemptId);

  void _handleBiometricConflict(
    DuplicateCheckResult duplicate,
    FaceBloc faceBloc,
    AuthBloc authBloc,
  ) {
    final shouldLogout = duplicate.logoutRequired;
    setState(() {
      _step = 0;
      _checkingDuplicate = false;
      _duplicateChecked = true;
      _isKnownFace = true;
      _matchedName = duplicate.matchedName;
      _isCapturing = false;
      _countdownStarted = false;
      _livenessPassed = false;
      _livenessProgress = 0;
      _statusMessage =
          'Data biometrik tidak dapat digunakan untuk pendaftaran.';
    });
    _log.warn(
      'konflik identitas biometrik',
      data: {
        'akanLogout': shouldLogout,
        'memilikiNamaCocok': duplicate.matchedName != null,
      },
    );
    _resetAttempt();
    if (shouldLogout) {
      _lifecycleActive = false;
      _attempts.cancel();
      authBloc.add(LogoutRequested());
      return;
    }
    // Stream tetap aktif agar pengguna dapat mengganti wajah. Hasil konflik
    // dipertahankan sampai wajah meninggalkan frame.
    _startFaceDetection();
  }

  String get _expectedName {
    final authState = context.read<AuthBloc>().state;
    return authState is Authenticated ? authState.user.nama : 'akun ini';
  }

  void _resetAttempt() {
    _attemptId = _attempts.begin();
    _continuity.reset();
    _livenessService.reset();
    _identityContinuity.reset();
  }

  Future<void> _stopImageStream() => _cameraCommands.run(() async {
    final controller = _cameraController;
    if (controller?.value.isStreamingImages ?? false) {
      await controller!.stopImageStream();
    }
  });

  Future<XFile> _takePicture() =>
      _cameraCommands.run(() => _cameraController!.takePicture());

  @override
  void dispose() {
    _log.info('halaman enrollment ditutup', data: {'tahapTerakhir': _step});
    WidgetsBinding.instance.removeObserver(this);
    _lifecycleActive = false;
    _attempts.cancel();
    final controller = _cameraController;
    if (controller != null) unawaited(_cameraCommands.run(controller.dispose));
    _faceDetector.close();
    _livenessService.dispose();
    _faceService.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        title: const Text('Pendaftaran Wajah'),
        backgroundColor: AppColors.surface,
        foregroundColor: AppColors.textPrimary,
        elevation: 0,
        centerTitle: true,
      ),
      body: SafeArea(
        child: BlocListener<FaceBloc, FaceState>(
          listener: (context, state) {
            if (state is EnrollmentSubmitted) {
              // Refresh data user agar status enrollment terbaru tersimpan.
              context.read<AuthBloc>().add(GetCurrentUserData());
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(
                  content: Text(state.message),
                  backgroundColor: AppColors.success,
                ),
              );
              // PENTING: bersihkan SELURUH stack ke /home. Memakai
              // pushReplacementNamed saja menyisakan _AuthGate (route '/') di
              // bawah, yang bisa mengevaluasi ulang enrollment_status (apalagi
              // bila GetCurrentUserData sempat mengembalikan cache lama 'belum')
              // lalu menampilkan EnrollmentPage lagi → loop. /home merender
              // MainShell langsung tanpa gate enrollment.
              Navigator.pushNamedAndRemoveUntil(
                context,
                '/home',
                (route) => false,
              );
            } else if (state is FaceError) {
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(
                  content: Text(state.message),
                  backgroundColor: AppColors.danger,
                ),
              );
            }
          },
          child: Column(
            children: [
              // Step indicators (di atas, area terang)
              Padding(
                padding: const EdgeInsets.fromLTRB(24, 16, 24, 16),
                child: Row(
                  children: [
                    _stepIndicator(1, 'Deteksi', _step >= 0),
                    Expanded(
                      child: Container(
                        height: 2,
                        color: _step >= 1
                            ? AppColors.success
                            : AppColors.border,
                      ),
                    ),
                    _stepIndicator(2, 'Liveness', _step >= 1),
                    Expanded(
                      child: Container(
                        height: 2,
                        color: _step >= 2
                            ? AppColors.success
                            : AppColors.border,
                      ),
                    ),
                    _stepIndicator(3, 'Selesai', _step >= 2),
                  ],
                ),
              ),

              // Area kamera
              Expanded(
                child: Container(
                  margin: const EdgeInsets.symmetric(horizontal: 20),
                  clipBehavior: Clip.antiAlias,
                  decoration: BoxDecoration(
                    color: Colors.black,
                    borderRadius: BorderRadius.circular(24),
                  ),
                  child: Stack(
                    alignment: Alignment.center,
                    children: [
                      if (_isCameraInitialized && _cameraController != null)
                        SizedBox.expand(
                          child: FittedBox(
                            fit: BoxFit.cover,
                            child: SizedBox(
                              width:
                                  _cameraController!
                                      .value
                                      .previewSize
                                      ?.height ??
                                  720,
                              height:
                                  _cameraController!.value.previewSize?.width ??
                                  1280,
                              child: CameraPreview(_cameraController!),
                            ),
                          ),
                        )
                      else
                        const Center(
                          child: CircularProgressIndicator(color: Colors.white),
                        ),

                      // Frame wajah. Merah bila wajah terdeteksi milik
                      // mahasiswa lain, hijau bila terdeteksi & valid.
                      Container(
                        width: 240,
                        height: 300,
                        decoration: BoxDecoration(
                          border: Border.all(
                            color: _isKnownFace
                                ? AppColors.danger
                                : (_faceDetected
                                      ? AppColors.success
                                      : Colors.white),
                            width: 3,
                          ),
                          borderRadius: BorderRadius.circular(150),
                        ),
                      ),

                      if (_isKnownFace && _matchedName != null)
                        Positioned(
                          left: 16,
                          right: 16,
                          top: 16,
                          child: Semantics(
                            liveRegion: true,
                            label:
                                'Identitas wajah tidak sesuai. Kamu adalah $_matchedName. Silakan daftarkan wajah $_expectedName.',
                            child: Container(
                              padding: const EdgeInsets.symmetric(
                                horizontal: 16,
                                vertical: 12,
                              ),
                              decoration: BoxDecoration(
                                color: Colors.black.withValues(alpha: 0.72),
                                borderRadius: BorderRadius.circular(12),
                                border: Border.all(color: Colors.white24),
                              ),
                              child: Column(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  Text(
                                    'Kamu adalah $_matchedName',
                                    textAlign: TextAlign.center,
                                    style: const TextStyle(
                                      color: AppColors.success,
                                      fontSize: 16,
                                      fontWeight: FontWeight.w700,
                                    ),
                                  ),
                                  const SizedBox(height: 4),
                                  Text(
                                    'Silakan daftarkan wajah $_expectedName',
                                    textAlign: TextAlign.center,
                                    style: const TextStyle(
                                      color: AppColors.danger,
                                      fontSize: 14,
                                      fontWeight: FontWeight.w700,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ),

                      // Instruksi ditempel ke tepi bawah panggung kamera.
                      //
                      // Sebelumnya dipasang sebagai Align + Padding(top: 380),
                      // yaitu offset tetap dari titik tengah. Di layar yang
                      // lebih pendek dari asumsinya, kotak instruksi terdorong
                      // melewati tinggi yang tersedia dan memicu
                      // "RenderFlex overflowed by 9.1 pixels on the bottom".
                      // Menambatkannya ke bawah membuat posisinya benar di
                      // semua tinggi layar tanpa angka ajaib.
                      Positioned(
                        left: 16,
                        right: 16,
                        bottom: 16,
                        child: Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 16,
                            vertical: 12,
                          ),
                          decoration: BoxDecoration(
                            color: Colors.black.withValues(alpha: 0.6),
                            borderRadius: BorderRadius.circular(12),
                          ),
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Text(
                                _statusMessage,
                                textAlign: TextAlign.center,
                                style: const TextStyle(
                                  fontSize: 15,
                                  fontWeight: FontWeight.bold,
                                  color: Colors.white,
                                ),
                              ),
                              // Progress bar liveness saat step 1
                              if (_step == 1) ...[
                                const SizedBox(height: 10),
                                ClipRRect(
                                  borderRadius: BorderRadius.circular(4),
                                  child: LinearProgressIndicator(
                                    value: _livenessProgress,
                                    minHeight: 6,
                                    backgroundColor: Colors.white24,
                                    valueColor:
                                        const AlwaysStoppedAnimation<Color>(
                                          AppColors.success,
                                        ),
                                  ),
                                ),
                              ],
                            ],
                          ),
                        ),
                      ),

                      // Overlay spinner saat capture & proses embedding
                      if (_isCapturing)
                        Positioned.fill(
                          child: Container(
                            color: Colors.black54,
                            padding: const EdgeInsets.all(24),
                            child: const Center(
                              child: Column(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  CircularProgressIndicator(
                                    color: Colors.white,
                                  ),
                                  SizedBox(height: 16),
                                  Text(
                                    'Memproses & menyimpan wajah...\nMohon tunggu sebentar',
                                    textAlign: TextAlign.center,
                                    style: TextStyle(
                                      color: Colors.white,
                                      fontWeight: FontWeight.bold,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
              ),

              // Panel bawah: info alur ATAU status submit (kondisional agar
              // tinggi tidak menumpuk & memicu overflow).
              Container(
                width: double.infinity,
                margin: const EdgeInsets.fromLTRB(20, 12, 20, 16),
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: AppColors.surface,
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(color: AppColors.border),
                ),
                child: BlocBuilder<FaceBloc, FaceState>(
                  builder: (context, state) {
                    if (state is FaceLoading) {
                      return const Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          ),
                          SizedBox(width: 12),
                          Text('Mengirim pendaftaran...'),
                        ],
                      );
                    }
                    return Row(
                      children: [
                        const Icon(
                          Icons.auto_awesome,
                          color: AppColors.primary,
                          size: 20,
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            'Perekaman berjalan otomatis. Cukup posisikan wajah & ikuti instruksi — tidak perlu menekan tombol.',
                            style: const TextStyle(
                              fontSize: 13,
                              color: AppColors.textSecondary,
                            ),
                          ),
                        ),
                      ],
                    );
                  },
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// Instruksi liveness yang jelas + progres (mis. "Kedipkan mata (1/3)").
  String _livenessInstruction(String challenge) {
    final base = switch (challenge) {
      'smile' => 'Tersenyumlah ke kamera',
      'turn_left' => 'Tolehkan kepala ke kiri',
      'turn_right' => 'Tolehkan kepala ke kanan',
      'blink' => 'Kedipkan mata',
      'nod' => 'Anggukkan kepala',
      _ => AppConstants.livenessChallengeLabels[challenge] ?? 'Ikuti instruksi',
    };
    final done = _livenessService.consecutivePass;
    final total = _livenessService.requiredConsecutivePass;
    return '$base ($done/$total)';
  }

  Widget _stepIndicator(int step, String label, bool active) {
    return Column(
      children: [
        CircleAvatar(
          radius: 14,
          backgroundColor: active ? AppColors.success : AppColors.border,
          child: Text(
            '$step',
            style: TextStyle(
              color: active ? Colors.white : AppColors.textMuted,
              fontSize: 12,
              fontWeight: FontWeight.bold,
            ),
          ),
        ),
        const SizedBox(height: 4),
        Text(
          label,
          style: TextStyle(
            fontSize: 11,
            fontWeight: active ? FontWeight.w600 : FontWeight.normal,
            color: active ? AppColors.success : AppColors.textMuted,
          ),
        ),
      ],
    );
  }
}

/// Error spesifik saat proses capture/enrollment wajah, dengan pesan yang
/// aman ditampilkan ke user.
class _EnrollmentCaptureException implements Exception {
  final String message;
  const _EnrollmentCaptureException(this.message);

  @override
  String toString() => message;
}
