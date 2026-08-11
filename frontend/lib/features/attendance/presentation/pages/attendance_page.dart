import 'dart:async';
import 'dart:io';
import 'package:camera/camera.dart';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:device_info_plus/device_info_plus.dart';
import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:geolocator/geolocator.dart';
import 'package:google_mlkit_face_detection/google_mlkit_face_detection.dart';
import 'package:safe_device/safe_device.dart';
import 'package:uuid/uuid.dart';
import '../../../../core/constants/api_constants.dart';
import '../../../../core/constants/app_constants.dart';
import '../../../../core/errors/exceptions.dart';
import '../../../../core/logging/app_logger.dart';
import '../../../../core/network/api_client.dart';
import '../../../../core/offline/offline_queue_item.dart';

import '../../../../core/offline/offline_queue_service.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/utils/location_utils.dart';
import '../../../../core/widgets/app_button.dart';
import '../../../../core/time/server_time_anchor.dart';
import '../../domain/services/attendance_location_service.dart';
import '../../domain/services/attendance_capture_orchestrator.dart';
import '../../../face_recognition/domain/services/face_recognition_service.dart';
import '../../../face_recognition/domain/services/liveness_detection_service.dart';
import '../../../face_recognition/domain/services/camera_frame_analysis.dart';
import '../../../face_recognition/domain/services/camera_frame_snapshot.dart';
import '../../../face_recognition/domain/services/frame_analysis_pipeline.dart';
import '../../../face_recognition/presentation/bloc/face_bloc.dart';
import '../../../face_recognition/presentation/bloc/face_event.dart';
import '../../../face_recognition/presentation/bloc/face_state.dart';

/// Halaman Check-in/Check-out.
///
/// FIX yang berlaku di file ini:
///   - C-01 : verifikasi wajah pakai CameraImage asli (bukan `Uint8List(0)`).
///   - C-02 : `liveness_passed` baru dikirim true setelah challenge benar2 lolos.
///   - C-04 : liveness pakai pola NEUTRAL → CHALLENGE temporal.
///   - H-02 : tolak GPS dengan akurasi > [maxGpsAccuracy] meter.
///   - L-03 : saat offline, mock GPS tetap di-cek ulang (tidak dipaksa false).
///   - L-04 : warna status row mencerminkan mock GPS dengan benar.
///   - M-02 : payload selalu menyertakan `client_uuid` (idempotency offline sync).
class AttendancePage extends StatefulWidget {
  final int jadwalId;
  final int mataKuliahId;
  final String mataKuliahName;
  final double geofenceLat;
  final double geofenceLon;
  final double geofenceRadius;
  final bool isCheckout;
  final int? attendanceId;

  /// H-02/L-08: GPS akurasi minimum yang masih bisa diterima (meter).
  /// Selaras dengan baseline terdokumentasi `AppConstants.gpsAccuracyMinimum`
  /// (20 m) dan default backend `gps_accuracy_minimum`. Server per-prodi tetap
  /// menjadi sumber kebenaran; ini hanya pre-check UI agar tidak lebih longgar
  /// dari kebijakan default server. Lebih kecil = lebih ketat.
  static const double maxGpsAccuracy = AppConstants.gpsAccuracyMinimum;

  const AttendancePage({
    super.key,
    required this.jadwalId,
    required this.mataKuliahId,
    required this.mataKuliahName,
    required this.geofenceLat,
    required this.geofenceLon,
    required this.geofenceRadius,
    this.isCheckout = false,
    this.attendanceId,
  });

  @override
  State<AttendancePage> createState() => _AttendancePageState();
}

class _AttendancePageState extends State<AttendancePage>
    with WidgetsBindingObserver {
  static final AppLogger _log = AppLogger.tag('Attendance');

  // Step 0: Location validation
  // Step 1: Liveness detection
  // Step 2: Face verification
  // Step 3: Submit attendance
  int _currentStep = 0;
  String _statusMessage = 'Memvalidasi lokasi...';
  bool _isValidating = true;
  bool _locationValid = false;
  bool _mockLocationDetected = false;
  double _distanceToGeofence = 0;
  double _gpsAccuracy = 0;
  Position? _currentPosition;

  // Face recognition
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
  bool _faceDetected = false;
  bool _livenessPassed = false; // C-02: track real liveness result
  /// Progres challenge liveness (0..1) untuk progress bar, selaras dengan
  /// halaman enrollment. Tanpa ini user tidak tahu tantangannya sudah terbaca
  /// berapa kali dan cenderung menyerah di tengah jalan.
  double _livenessProgress = 0;
  String _challenge = '';
  double _faceThreshold = 1.0;
  double _faceDistance = 0;
  bool _faceMatched = false;
  int _inferenceTimeMs = 0;
  String? _permitToken;
  AttendanceLocationPolicy? _locationPolicy;

  CameraDescription? _activeCamera;
  late final SingleInflightFramePipeline<void> _framePipeline;
  final AttemptGeneration _attempts = AttemptGeneration();
  final LivenessContinuity _continuity = LivenessContinuity();
  final AsyncCommandSerializer _cameraCommands = AsyncCommandSerializer();
  int _attemptId = 0;
  bool _lifecycleActive = true;

  /// M-02: UUID yang di-generate sekali per attempt; dikirim ke backend.
  final String _clientUuid = const Uuid().v4();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _framePipeline = SingleInflightFramePipeline(_analyzeFrame);
    _attemptId = _attempts.begin();
    _faceService.initialize();
    _loadReferenceEmbedding();
    _validateLocation();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _lifecycleActive = true;
      _attemptId = _attempts.begin();
      _continuity.reset();
      _livenessService.reset();
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

  void _loadReferenceEmbedding() {
    context.read<FaceBloc>().add(LoadReferenceEmbedding());
  }

  Future<void> _validateLocation() async {
    setState(() {
      _isValidating = true;
      _statusMessage = 'Memeriksa lokasi...';
      _mockLocationDetected = false;
    });

    try {
      // L-03: cek mock GPS (akan dicek lagi sebelum submit untuk amankan offline)
      final isMockLocation = await SafeDevice.isMockLocation;
      if (isMockLocation) {
        setState(() {
          _mockLocationDetected = true;
          _isValidating = false;
          _statusMessage = 'Terdeteksi manipulasi lokasi!';
        });
        return;
      }

      LocationPermission permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
        if (permission == LocationPermission.denied) {
          setState(() {
            _isValidating = false;
            _statusMessage = 'Izin lokasi ditolak';
          });
          return;
        }
      }

      _currentPosition = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
        ),
      );

      _distanceToGeofence = LocationUtils.haversineDistance(
        _currentPosition!.latitude,
        _currentPosition!.longitude,
        widget.geofenceLat,
        widget.geofenceLon,
      );

      _gpsAccuracy = _currentPosition!.accuracy;

      // H-02: tolak akurasi GPS yang terlalu rendah
      if (_gpsAccuracy > AttendancePage.maxGpsAccuracy) {
        setState(() {
          _locationValid = false;
          _isValidating = false;
          _statusMessage =
              'Akurasi GPS terlalu rendah (${_gpsAccuracy.toStringAsFixed(0)}m). Pastikan GPS aktif & berada di luar gedung.';
        });
        return;
      }

      if (_distanceToGeofence > widget.geofenceRadius) {
        setState(() {
          _locationValid = false;
          _isValidating = false;
          _statusMessage =
              'Anda di luar area perkuliahan (${_distanceToGeofence.toStringAsFixed(0)}m)';
        });
        return;
      }

      setState(() {
        _locationValid = true;
        _isValidating = false;
        _currentStep = 1;
        _statusMessage = 'Lokasi valid. Siap untuk verifikasi wajah.';
      });
      _initCamera();
    } catch (e) {
      setState(() {
        _isValidating = false;
        _statusMessage = 'Gagal memvalidasi lokasi: $e';
      });
    }
  }

  Future<void> _initCamera() async {
    final cameras = await availableCameras();
    final frontCamera = cameras.firstWhere(
      (c) => c.lensDirection == CameraLensDirection.front,
      orElse: () => cameras.first,
    );
    _activeCamera = frontCamera; // L-04

    _cameraController = CameraController(
      frontCamera,

      ResolutionPreset.high,
      enableAudio: false,
      // ML Kit Android hanya menerima NV21 untuk image stream.
      imageFormatGroup: Platform.isIOS
          ? ImageFormatGroup.bgra8888
          : ImageFormatGroup.nv21,
    );

    await _cameraCommands.run(() => _cameraController!.initialize());
    if (!mounted || !_lifecycleActive) return;
    final apiClient = context.read<ApiClient>();
    final anchor = context.read<ServerTimeAnchor>();
    try {
      final response = await apiClient.post(
        ApiConstants.attendancePermitEndpoint,
        data: {
          'jadwal_id': widget.jadwalId,
          'action': widget.isCheckout ? 'check_out' : 'check_in',
          'client_uuid': _clientUuid,
          if (widget.isCheckout) 'attendance_id': widget.attendanceId,
        },
      );
      final permit = response.data['data'] as Map<String, dynamic>;
      if (!anchor.anchorFromIso(permit['server_time'])) {
        throw StateError('Waktu server pada permit tidak valid');
      }
      _permitToken = permit['permit_token']?.toString();
      _challenge = permit['liveness_challenge']?.toString() ?? '';
      _locationPolicy = AttendanceLocationPolicy.fromJson(
        permit['location_policy'] as Map<dynamic, dynamic>?,
      );
      if (_permitToken == null ||
          _locationPolicy!.maxAccuracyMeters <= 0 ||
          _locationPolicy!.maxAgeSeconds <= 0) {
        throw StateError('Kebijakan permit absensi tidak lengkap');
      }
    } catch (e, stack) {
      _log.error(
        'gagal memperoleh permit absensi',
        data: {
          'jadwalId': widget.jadwalId,
          'aksi': widget.isCheckout ? 'check_out' : 'check_in',
          if (e is ServerException) 'status': e.statusCode,
          if (e is ServerException) 'kode': e.code,
          if (e is ServerException) 'errors': e.errors,
        },
        error: e,
        stackTrace: stack,
      );
      if (mounted) {
        setState(() {
          _currentStep = -1;
          _statusMessage = 'Tidak dapat memperoleh permit absensi: $e';
        });
      }
      return;
    }
    setState(() {
      _isCameraInitialized = true;
      _statusMessage =
          AppConstants.livenessChallengeLabels[_challenge] ?? 'Ikuti instruksi';
    });
    _startFaceDetection();
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
      }
      if (faces.isEmpty) {
        if (mounted) setState(() => _faceDetected = false);
        return;
      }
      if (faces.length > 1) {
        if (mounted) {
          setState(() {
            _faceDetected = false;
            _statusMessage = 'Hanya 1 wajah yang diizinkan';
          });
        }
        return;
      }
      final face = faces.single;
      if (mounted) setState(() => _faceDetected = true);
      if (_currentStep != 1) return;
      final passed = await _livenessService.checkChallenge(face, _challenge);
      if (!_isCurrent(snapshot.attemptId)) return;
      if (mounted) {
        setState(() {
          _livenessProgress = _livenessService.progress;
          _statusMessage = _livenessService.hasNeutral
              ? _livenessInstruction(_challenge)
              : 'Hadap lurus ke kamera & buka mata';
        });
      }
      if (!passed) return;
      if (mounted) {
        setState(() {
          _livenessPassed = true;
          _currentStep = 2;
          _statusMessage = 'Memverifikasi wajah...';
        });
      }
      await _verifyFace(snapshot, face);
    } catch (e, stack) {
      _log.error('analisis frame absensi gagal', error: e, stackTrace: stack);
    }
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
    return '$base (${_livenessService.consecutivePass}'
        '/${_livenessService.requiredConsecutivePass})';
  }

  Future<void> _verifyFace(CameraFrameSnapshot snapshot, Face face) async {
    final faceBloc = context.read<FaceBloc>();
    final refEmbedding = faceBloc.referenceEmbedding;
    final threshold = faceBloc.faceThreshold;

    if (refEmbedding == null) {
      setState(() {
        _statusMessage =
            'Data wajah tidak ditemukan. Silakan enrollment ulang.';
        _currentStep = -1;
      });
      return;
    }

    try {
      await _stopImageStream();
      if (!_isCurrent(snapshot.attemptId)) return;

      final result = await _faceService.verifyFaceFromSnapshot(
        snapshot,
        face,
        refEmbedding,
        threshold,
      );

      if (!_isCurrent(snapshot.attemptId) || !mounted) return;
      setState(() {
        _faceDistance = result.distance;
        _faceMatched = result.isMatch;
        _inferenceTimeMs = result.inferenceTimeMs;
        _faceThreshold = threshold;

        if (result.isMatch) {
          _currentStep = 3;
          _statusMessage = 'Verifikasi berhasil! Mengirim data...';
        } else {
          _statusMessage = 'Verifikasi wajah gagal. Coba lagi.';
          _currentStep = 1;
          _livenessPassed = false; // C-02: reset
          _challenge = _livenessService.getRandomChallenge();
        }
      });

      if (result.isMatch) {
        await _submitAttendance(snapshot.attemptId);
      } else {
        _attemptId = _attempts.begin();
        _continuity.reset();
        _livenessService.reset();
        _startFaceDetection();
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _statusMessage = 'Error verifikasi: $e';
          _currentStep = 1;
          _livenessPassed = false;
        });
      }
      _attemptId = _attempts.begin();
      _continuity.reset();
      _livenessService.reset();
      _startFaceDetection();
    }
  }

  bool _isCurrent(int attemptId) =>
      mounted && _lifecycleActive && _attempts.isCurrent(attemptId);

  Future<void> _stopImageStream() => _cameraCommands.run(() async {
    final controller = _cameraController;
    if (controller?.value.isStreamingImages ?? false) {
      await controller!.stopImageStream();
    }
  });

  Future<void> _submitAttendance(int attemptId) async {
    final queueService = context.read<OfflineQueueService>();
    final apiClient = context.read<ApiClient>();
    final anchor = context.read<ServerTimeAnchor>();
    final locationService = context.read<AttendanceLocationService>();
    final deviceInfo = DeviceInfoPlugin();
    final deviceModel = Platform.isIOS
        ? (await deviceInfo.iosInfo).utsname.machine
        : (await deviceInfo.androidInfo).model;
    final deviceOs = Platform.isIOS
        ? 'iOS ${(await deviceInfo.iosInfo).systemVersion}'
        : 'Android ${(await deviceInfo.androidInfo).version.release}';
    if (!_isCurrent(attemptId)) return;

    final policy = _locationPolicy;
    if (_permitToken == null || policy == null || anchor.now == null) {
      _failSubmission(
        'Permit atau waktu server tidak tersedia. Muat ulang jadwal.',
      );
      return;
    }

    late final AttendanceCaptureEvidence evidence;
    try {
      // Kebijakan lokasi dicatat lebih dulu: `capture()` menolak fix yang
      // akurasinya di atas ambang ATAU yang usianya melewati batas, dan
      // batas usia itu sangat ketat (default 10 detik). Tanpa angka ini,
      // kegagalan di sini mustahil dibedakan dari masalah GPS biasa.
      _log.info(
        'mengambil bukti lokasi untuk pengiriman',
        data: {
          'maxAkurasiMeter': policy.maxAccuracyMeters,
          'maxUsiaDetik': policy.maxAgeSeconds,
          'radiusGeofence': widget.geofenceRadius,
        },
      );
      evidence = await AttendanceCaptureOrchestrator(anchor, locationService)
          .capture(
            policy: policy,
            geofenceLat: widget.geofenceLat,
            geofenceLon: widget.geofenceLon,
            geofenceRadius: widget.geofenceRadius,
          );
      _log.info(
        'bukti lokasi diperoleh',
        data: {
          'jarakMeter': evidence.location.distanceMeters,
          'akurasiMeter': evidence.location.position.accuracy,
          'usiaMs': evidence.locationAgeMs,
          'mockTerdeteksi': evidence.location.mockDetected,
        },
      );
    } catch (e, stack) {
      _failSubmission(
        'Lokasi terbaru tidak valid: $e',
        error: e,
        stackTrace: stack,
      );
      return;
    }
    if (!_isCurrent(attemptId)) return;
    final freshFix = evidence.location;

    _currentPosition = null;
    _gpsAccuracy = freshFix.position.accuracy;
    _distanceToGeofence = freshFix.distanceMeters;

    final data = <String, dynamic>{
      'client_uuid': _clientUuid, // M-02
      'permit_token': _permitToken,
      if (widget.isCheckout) 'attendance_id': widget.attendanceId,
      'jadwal_id': widget.jadwalId,
      'latitude': freshFix.position.latitude,
      'longitude': freshFix.position.longitude,
      'gps_accuracy': freshFix.position.accuracy,
      'location_age_ms': evidence.locationAgeMs,
      'face_distance': _faceDistance,
      'face_threshold': _faceThreshold,
      'liveness_passed': _livenessPassed, // C-02
      'liveness_challenge': _challenge,
      'inference_time_ms': _inferenceTimeMs,
      'device_model': deviceModel,
      'device_os': deviceOs,
      'app_version': '1.0.0',
      'mock_location_detected': freshFix.mockDetected,
      'timestamp': evidence.capturedAt.toUtc().toIso8601String(),
    };

    // Check connectivity
    final connectivity = Connectivity();
    final results = await connectivity.checkConnectivity();
    if (!_isCurrent(attemptId)) return;
    final isOffline = results.every((r) => r == ConnectivityResult.none);

    if (isOffline) {
      // Saat offline kita pakai payload yang KOMPATIBEL dengan endpoint sync.
      // Backend `/attendance/sync-offline` expect `type` & validasi anti-spoofing
      // (lihat OfflineSyncController C-05).
      final offlinePayload = <String, dynamic>{
        ...data,
        'type': widget.isCheckout ? 'check_out' : 'check_in',
      };
      await queueService.enqueue(
        type: widget.isCheckout
            ? OfflineQueueItem.checkOutType
            : OfflineQueueItem.checkInType,
        data: offlinePayload,
      );

      if (!_isCurrent(attemptId)) return;
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Data disimpan secara offline. Akan disinkronkan saat online.',
          ),
          backgroundColor: AppColors.warning,
          duration: Duration(seconds: 3),
        ),
      );

      Navigator.pop(context, {
        'success': true,
        'offline': true,
        'data': offlinePayload,
        'distance': _distanceToGeofence,
        'faceDistance': _faceDistance,
        'inferenceTimeMs': _inferenceTimeMs,
      });
      return;
    }

    // C-02: Online — submit langsung ke API. Sebelumnya halaman hanya
    // pop tanpa pernah memanggil endpoint, sehingga absensi online TIDAK
    // pernah tersimpan ke server. (apiClient sudah diambil di atas, pre-await)
    final endpoint = widget.isCheckout
        ? ApiConstants.checkOutEndpoint
        : ApiConstants.checkInEndpoint;

    setState(() {
      _statusMessage = 'Mengirim data absensi...';
    });

    try {
      if (!_isCurrent(attemptId)) return;
      final response = await apiClient.post(endpoint, data: data);
      if (!_isCurrent(attemptId)) return;
      if (!mounted) return;
      final respData = response.data is Map<String, dynamic>
          ? response.data['data']
          : null;
      Navigator.pop(context, {
        'success': true,
        'offline': false,
        'data': respData ?? data,
        'distance': _distanceToGeofence,
        'faceDistance': _faceDistance,
        'inferenceTimeMs': _inferenceTimeMs,
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _statusMessage = 'Gagal mengirim absensi: $e';
        _currentStep = 1;
        _livenessPassed = false;
      });
      _attemptId = _attempts.begin();
      _continuity.reset();
      _livenessService.reset();
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Gagal mengirim absensi: $e'),
          backgroundColor: AppColors.danger,
          duration: const Duration(seconds: 3),
        ),
      );
      _startFaceDetection();
    }
  }

  /// Batalkan pengiriman dan kembalikan alur ke tahap liveness.
  ///
  /// Selalu mencatat alasannya. Sebelumnya fungsi ini hanya mengganti pesan di
  /// layar lalu diam, sehingga siklus "verifikasi berhasil → gagal kirim →
  /// ulangi" berputar tanpa meninggalkan satu baris log pun — dari luar
  /// tampak seperti aplikasi menggantung, padahal ada kegagalan berulang.
  void _failSubmission(String message, {Object? error, StackTrace? stackTrace}) {
    _log.error(
      'pengiriman absensi dibatalkan',
      data: {
        'alasan': message,
        'jarakMeter': _distanceToGeofence,
        'akurasiMeter': _gpsAccuracy,
        'adaPermit': _permitToken != null,
        'adaKebijakanLokasi': _locationPolicy != null,
      },
      error: error,
      stackTrace: stackTrace,
    );
    if (!mounted) return;
    setState(() {
      _statusMessage = message;
      _currentStep = 1;
      _livenessPassed = false;
    });
  }

  @override
  void dispose() {
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
        title: Text(widget.isCheckout ? 'Check-out' : 'Check-in'),
        backgroundColor: AppColors.surface,
        foregroundColor: AppColors.textPrimary,
        elevation: 0,
        centerTitle: true,
      ),
      body: BlocListener<FaceBloc, FaceState>(
        listener: (context, state) {
          if (state is FaceError) {
            // Satu-satunya event FaceBloc yang dikirim halaman ini adalah
            // LoadReferenceEmbedding, jadi FaceError berarti data wajah
            // pembanding gagal dimuat — absensi mustahil dilanjutkan.
            //
            // Sebelumnya ini hanya snackbar yang hilang beberapa detik
            // kemudian, menyisakan layar yang tampak siap padahal sudah
            // buntu. Sekarang alasannya ditahan di layar.
            _log.error(
              'gagal memuat data wajah pembanding',
              data: {'pesan': state.message},
            );
            setState(() {
              _currentStep = -1;
              _statusMessage = state.message;
            });
            ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(
                content: Text(state.message),
                backgroundColor: AppColors.danger,
              ),
            );
          }
        },
        // SafeArea: sebelumnya konten menempel langsung ke notch di atas dan
        // gesture bar di bawah karena Scaffold gelap dipakai tanpa inset.
        child: SafeArea(
          child: Column(
            children: [
              _buildStepIndicators(),
              _buildStatusStrip(),
              Expanded(child: _buildStage()),
              _buildBottomPanel(),
            ],
          ),
        ),
      ),
    );
  }

  /// Indikator 4 tahap, sejajar dengan alur `_currentStep`.
  Widget _buildStepIndicators() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 16, 20, 14),
      child: Row(
        children: [
          _stepIndicator(1, 'Lokasi', _locationValid),
          _stepConnector(_currentStep >= 1),
          _stepIndicator(2, 'Liveness', _currentStep >= 1),
          _stepConnector(_currentStep >= 2),
          _stepIndicator(3, 'Wajah', _currentStep >= 2),
          _stepConnector(_currentStep >= 3),
          _stepIndicator(4, 'Selesai', _currentStep >= 3),
        ],
      ),
    );
  }

  /// Tiga penanda syarat absensi yang wajib terlihat sepanjang proses:
  /// lokasi, liveness, dan kecocokan wajah.
  Widget _buildStatusStrip() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
      child: Row(
        children: [
          Expanded(
            child: _statusChip(
              icon: Icons.location_on_outlined,
              label: 'Lokasi',
              value: _locationStatusText(),
              state: _mockLocationDetected
                  ? _CheckState.error
                  : (_locationValid ? _CheckState.ok : _CheckState.pending),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: _statusChip(
              icon: Icons.face_retouching_natural_outlined,
              label: 'Liveness',
              value: _livenessPassed
                  ? 'Lolos'
                  : (_challenge.isEmpty
                        ? 'Menunggu'
                        : AppConstants.livenessChallengeLabels[_challenge] ??
                              'Ikuti instruksi'),
              state: _livenessPassed ? _CheckState.ok : _CheckState.pending,
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: _statusChip(
              icon: Icons.verified_user_outlined,
              label: 'Wajah',
              value: _faceMatched
                  ? 'Cocok (${_faceDistance.toStringAsFixed(2)})'
                  : 'Menunggu',
              state: _faceMatched ? _CheckState.ok : _CheckState.pending,
            ),
          ),
        ],
      ),
    );
  }

  String _locationStatusText() {
    if (_mockLocationDetected) return 'Fake GPS';
    if (_isValidating) return 'Memeriksa…';
    if (!_locationValid) return 'Di luar area';
    return '${_distanceToGeofence.toStringAsFixed(0)} m '
        '(±${_gpsAccuracy.toStringAsFixed(0)} m)';
  }

  /// Panggung utama: kartu gelap membulat yang sama bentuknya dengan halaman
  /// enrollment. Isinya berganti sesuai tahap, tetapi ukurannya tetap sehingga
  /// tata letak tidak melompat saat berpindah tahap.
  Widget _buildStage() {
    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 20),
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        color: Colors.black,
        borderRadius: BorderRadius.circular(24),
      ),
      child: _currentStep == -1
          ? _buildBlockedStage()
          : (_currentStep == 0 ? _buildLocationStage() : _buildCameraStage()),
    );
  }

  /// Tahap 1 — lokasi. Di sinilah pesan "di luar area perkuliahan" tampil
  /// besar dan disertai angka jarak, supaya user tahu harus mendekat berapa
  /// jauh alih-alih hanya diberi tahu bahwa dia gagal.
  Widget _buildLocationStage() {
    if (_isValidating) {
      return const Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            CircularProgressIndicator(color: Colors.white),
            SizedBox(height: 16),
            Text(
              'Memvalidasi lokasi…',
              style: TextStyle(color: Colors.white, fontSize: 15),
            ),
          ],
        ),
      );
    }

    final selisih = _distanceToGeofence - widget.geofenceRadius;
    final title = _mockLocationDetected
        ? 'Lokasi palsu terdeteksi'
        : 'Anda di luar area perkuliahan';
    final detail = _mockLocationDetected
        ? 'Matikan aplikasi Fake GPS / mock location, lalu coba lagi.'
        : 'Mendekatlah sekitar ${selisih.clamp(0, double.infinity).toStringAsFixed(0)} m '
              'lagi ke ${widget.mataKuliahName}.';

    return Padding(
      padding: const EdgeInsets.all(24),
      child: Center(
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                _mockLocationDetected
                    ? Icons.gpp_maybe_outlined
                    : Icons.wrong_location_outlined,
                size: 56,
                color: AppColors.danger,
              ),
              const SizedBox(height: 16),
              Text(
                title,
                textAlign: TextAlign.center,
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 17,
                  fontWeight: FontWeight.bold,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                detail,
                textAlign: TextAlign.center,
                style: const TextStyle(color: Colors.white70, fontSize: 13),
              ),
              if (!_mockLocationDetected) ...[
                const SizedBox(height: 16),
                _distanceReadout(),
              ],
              const SizedBox(height: 20),
              AppButton(
                text: 'Coba Lagi',
                onPressed: _validateLocation,
                isExpanded: false,
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// Angka jarak vs radius — membuat kegagalan lokasi bisa ditindaklanjuti.
  Widget _distanceReadout() {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
      decoration: BoxDecoration(
        color: Colors.white10,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          _readoutItem(
            'Jarak',
            '${_distanceToGeofence.toStringAsFixed(0)} m',
          ),
          Container(
            width: 1,
            height: 28,
            margin: const EdgeInsets.symmetric(horizontal: 14),
            color: Colors.white24,
          ),
          _readoutItem('Radius', '${widget.geofenceRadius.toStringAsFixed(0)} m'),
          Container(
            width: 1,
            height: 28,
            margin: const EdgeInsets.symmetric(horizontal: 14),
            color: Colors.white24,
          ),
          _readoutItem('Akurasi', '±${_gpsAccuracy.toStringAsFixed(0)} m'),
        ],
      ),
    );
  }

  Widget _readoutItem(String label, String value) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          label,
          style: const TextStyle(color: Colors.white54, fontSize: 11),
        ),
        const SizedBox(height: 2),
        Text(
          value,
          style: const TextStyle(
            color: Colors.white,
            fontSize: 14,
            fontWeight: FontWeight.w600,
          ),
        ),
      ],
    );
  }

  /// Tahap terkunci: wajah belum pernah didaftarkan, jadi verifikasi mustahil.
  Widget _buildBlockedStage() {
    return Padding(
      padding: const EdgeInsets.all(24),
      child: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(
              Icons.no_accounts_outlined,
              size: 56,
              color: AppColors.warning,
            ),
            const SizedBox(height: 16),
            Text(
              _statusMessage,
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.white, fontSize: 15),
            ),
          ],
        ),
      ),
    );
  }

  /// Tahap 2–4 — kamera, bingkai wajah, instruksi liveness, dan progres.
  Widget _buildCameraStage() {
    return Stack(
      alignment: Alignment.center,
      children: [
        if (_isCameraInitialized && _cameraController != null)
          SizedBox.expand(
            child: FittedBox(
              fit: BoxFit.cover,
              child: SizedBox(
                width: _cameraController!.value.previewSize?.height ?? 720,
                height: _cameraController!.value.previewSize?.width ?? 1280,
                child: CameraPreview(_cameraController!),
              ),
            ),
          )
        else
          const Center(child: CircularProgressIndicator(color: Colors.white)),

        // Bingkai wajah: hijau saat wajah terbaca, putih saat menunggu.
        Container(
          width: 240,
          height: 300,
          decoration: BoxDecoration(
            border: Border.all(
              color: _faceDetected ? AppColors.success : Colors.white,
              width: 3,
            ),
            borderRadius: BorderRadius.circular(150),
          ),
        ),

        // Instruksi ditempel ke bawah panggung (bukan offset tetap dari
        // tengah) agar tidak meluber di layar pendek.
        Positioned(
          left: 16,
          right: 16,
          bottom: 16,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
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
                if (_currentStep == 1) ...[
                  const SizedBox(height: 10),
                  ClipRRect(
                    borderRadius: BorderRadius.circular(4),
                    child: LinearProgressIndicator(
                      value: _livenessProgress,
                      minHeight: 6,
                      backgroundColor: Colors.white24,
                      valueColor: const AlwaysStoppedAnimation<Color>(
                        AppColors.success,
                      ),
                    ),
                  ),
                ],
              ],
            ),
          ),
        ),

        // Tahap akhir: kunci layar agar user tidak menyangka masih perlu
        // melakukan sesuatu saat data sedang dikirim.
        if (_currentStep == 3)
          Positioned.fill(
            child: Container(
              color: Colors.black54,
              padding: const EdgeInsets.all(24),
              child: Center(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const CircularProgressIndicator(color: Colors.white),
                    const SizedBox(height: 16),
                    Text(
                      _statusMessage,
                      textAlign: TextAlign.center,
                      style: const TextStyle(
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
    );
  }

  /// Panel bawah: identitas mata kuliah + pesan tahap berjalan.
  Widget _buildBottomPanel() {
    final outOfArea = !_isValidating && !_locationValid;
    return Container(
      width: double.infinity,
      margin: const EdgeInsets.fromLTRB(20, 12, 20, 16),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: outOfArea
            ? AppColors.danger.withValues(alpha: 0.10)
            : AppColors.surface,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
          color: outOfArea ? AppColors.danger : AppColors.border,
        ),
      ),
      child: Row(
        children: [
          Icon(
            outOfArea
                ? Icons.error_outline
                : (widget.isCheckout ? Icons.logout : Icons.login),
            size: 20,
            color: outOfArea ? AppColors.danger : AppColors.primary,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  widget.mataKuliahName,
                  style: const TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w600,
                    color: AppColors.textPrimary,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  outOfArea
                      ? 'Absensi hanya bisa dilakukan di dalam area perkuliahan.'
                      : _statusMessage,
                  style: TextStyle(
                    fontSize: 12.5,
                    color: outOfArea
                        ? AppColors.danger
                        : (_currentStep == 3
                              ? AppColors.success
                              : AppColors.textSecondary),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _stepConnector(bool active) {
    return Expanded(
      child: Container(
        height: 2,
        color: active ? AppColors.success : AppColors.border,
      ),
    );
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

  /// Kartu ringkas satu syarat absensi.
  Widget _statusChip({
    required IconData icon,
    required String label,
    required String value,
    required _CheckState state,
  }) {
    final Color accent = switch (state) {
      _CheckState.ok => AppColors.success,
      _CheckState.error => AppColors.danger,
      _CheckState.pending => AppColors.textMuted,
    };

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: accent.withValues(alpha: 0.45)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(icon, size: 14, color: accent),
              const SizedBox(width: 4),
              Expanded(
                child: Text(
                  label,
                  style: const TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                    color: AppColors.textSecondary,
                  ),
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
          const SizedBox(height: 3),
          Text(
            value,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              fontSize: 11.5,
              fontWeight: FontWeight.w600,
              color: state == _CheckState.pending
                  ? AppColors.textMuted
                  : accent,
            ),
          ),
        ],
      ),
    );
  }
}

/// Status satu syarat absensi pada strip indikator.
enum _CheckState { ok, error, pending }
