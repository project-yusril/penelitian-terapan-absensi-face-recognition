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
    } catch (e) {
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
      if (!_isCurrent(snapshot.attemptId) || !passed) return;
      if (mounted) {
        setState(() {
          _livenessPassed = true;
          _currentStep = 2;
          _statusMessage = 'Memverifikasi wajah...';
        });
      }
      await _verifyFace(snapshot, face);
    } catch (e) {
      debugPrint('Detection error: $e');
    }
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
      evidence = await AttendanceCaptureOrchestrator(anchor, locationService)
          .capture(
            policy: policy,
            geofenceLat: widget.geofenceLat,
            geofenceLon: widget.geofenceLon,
            geofenceRadius: widget.geofenceRadius,
          );
    } catch (e) {
      _failSubmission('Lokasi terbaru tidak valid: $e');
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

  void _failSubmission(String message) {
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
      backgroundColor: Colors.black,
      appBar: AppBar(
        title: Text(widget.isCheckout ? 'Check-out' : 'Check-in'),
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
      ),
      body: BlocListener<FaceBloc, FaceState>(
        listener: (context, state) {
          if (state is FaceError) {
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
            // Status indicators
            Container(
              padding: const EdgeInsets.all(16),
              color: Colors.black87,
              child: Column(
                children: [
                  _buildStatusRow(
                    'Lokasi',
                    _locationValid,
                    _mockLocationDetected
                        ? 'Fake GPS'
                        : '${_distanceToGeofence.toStringAsFixed(0)}m (±${_gpsAccuracy.toStringAsFixed(0)}m)',
                    isError: _mockLocationDetected,
                  ),
                  _buildStatusRow(
                    'Liveness',
                    _livenessPassed,
                    _challenge.isNotEmpty
                        ? AppConstants.livenessChallengeLabels[_challenge] ?? ''
                        : 'Menunggu...',
                  ),
                  _buildStatusRow(
                    'Wajah',
                    _faceMatched,
                    _faceMatched
                        ? 'Match (${_faceDistance.toStringAsFixed(4)})'
                        : 'Menunggu...',
                  ),
                ],
              ),
            ),

            // Camera / status area
            Expanded(
              child: _currentStep == 0
                  ? Center(
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          if (_isValidating)
                            const CircularProgressIndicator(color: Colors.white)
                          else if (!_locationValid)
                            Column(
                              children: [
                                const Icon(
                                  Icons.location_off,
                                  size: 64,
                                  color: AppColors.danger,
                                ),
                                const SizedBox(height: 16),
                                Text(
                                  _statusMessage,
                                  style: const TextStyle(
                                    color: Colors.white,
                                    fontSize: 16,
                                  ),
                                ),
                                const SizedBox(height: 16),
                                AppButton(
                                  text: 'Coba Lagi',
                                  onPressed: _validateLocation,
                                  isExpanded: false,
                                ),
                              ],
                            ),
                        ],
                      ),
                    )
                  : Stack(
                      alignment: Alignment.center,
                      children: [
                        if (_isCameraInitialized && _cameraController != null)
                          CameraPreview(_cameraController!),
                        Container(
                          width: 220,
                          height: 280,
                          decoration: BoxDecoration(
                            border: Border.all(
                              color: _faceDetected
                                  ? AppColors.success
                                  : Colors.white54,
                              width: 3,
                            ),
                            borderRadius: BorderRadius.circular(140),
                          ),
                        ),
                        if (_challenge.isNotEmpty && _currentStep == 1)
                          Positioned(
                            bottom: 24,
                            child: Container(
                              padding: const EdgeInsets.symmetric(
                                horizontal: 20,
                                vertical: 10,
                              ),
                              decoration: BoxDecoration(
                                color: Colors.black54,
                                borderRadius: BorderRadius.circular(8),
                              ),
                              child: Text(
                                AppConstants
                                        .livenessChallengeLabels[_challenge] ??
                                    '',
                                style: const TextStyle(
                                  color: Colors.white,
                                  fontSize: 16,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                            ),
                          ),
                      ],
                    ),
            ),

            // Bottom status
            Container(
              padding: const EdgeInsets.all(20),
              decoration: const BoxDecoration(
                color: AppColors.surface,
                borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
              ),
              child: Column(
                children: [
                  Text(
                    widget.mataKuliahName,
                    style: const TextStyle(
                      fontSize: 16,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    _statusMessage,
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontSize: 14,
                      color: _currentStep == 3
                          ? AppColors.success
                          : AppColors.textSecondary,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// L-04: icon color sekarang reflect status spesifik:
  /// - hijau saat valid
  /// - merah saat error (mock GPS / fail)
  /// - abu-abu saat menunggu
  Widget _buildStatusRow(
    String label,
    bool isValid,
    String detail, {
    bool isError = false,
  }) {
    final Color iconColor;
    if (isValid) {
      iconColor = AppColors.success;
    } else if (isError) {
      iconColor = AppColors.danger;
    } else {
      iconColor = Colors.white30;
    }

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          SizedBox(
            width: 80,
            child: Text(
              label,
              style: const TextStyle(color: Colors.white70, fontSize: 13),
            ),
          ),
          Icon(
            isValid
                ? Icons.check_circle
                : (isError ? Icons.error : Icons.radio_button_unchecked),
            size: 18,
            color: iconColor,
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              detail,
              style: const TextStyle(color: Colors.white, fontSize: 13),
            ),
          ),
        ],
      ),
    );
  }
}
