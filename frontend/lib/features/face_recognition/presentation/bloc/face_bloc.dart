import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:dio/dio.dart';
import '../../../../core/constants/api_constants.dart';
import '../../../../core/errors/exceptions.dart';
import '../../../../core/logging/app_logger.dart';
import '../../../../core/network/api_client.dart';
import 'face_event.dart';
import 'face_state.dart';

class FaceBloc extends Bloc<FaceEvent, FaceState> {
  static final AppLogger _log = AppLogger.tag('FaceBloc');
  static const double strictFaceThreshold = 1.0;
  final ApiClient _apiClient;
  List<double>? _referenceEmbedding;
  double _faceThreshold = strictFaceThreshold;

  FaceBloc(ApiClient apiClient) : _apiClient = apiClient, super(FaceInitial()) {
    on<StartEnrollment>((e, emit) => emit(EnrollmentReady()));
    on<SubmitEnrollment>(_onSubmitEnrollment);
    on<CheckEnrollmentStatus>(_onCheckEnrollmentStatus);
    on<LoadReferenceEmbedding>(_onLoadReferenceEmbedding);
    on<FaceVerificationCompleted>((e, emit) {
      emit(
        FaceVerificationResultState(
          distance: e.distance,
          threshold: e.threshold,
          isMatch: e.isMatch,
          inferenceTimeMs: e.inferenceTimeMs,
        ),
      );
    });
    on<ResetFaceState>((e, emit) => emit(FaceInitial()));
  }

  List<double>? get referenceEmbedding => _referenceEmbedding;
  double get faceThreshold => _faceThreshold;

  /// Cek dini apakah embedding wajah ini sudah terdaftar untuk akun lain.
  Future<DuplicateCheckResult> checkDuplicate(List<double> embedding) async {
    // Backend memvalidasi `size:192`; mencatat panjangnya di sini membuat
    // penyebab 422 langsung terbaca tanpa menebak-nebak.
    _log.info(
      'checkDuplicate dimulai',
      data: {
        'panjangEmbedding': embedding.length,
        'ringkasan': describeVector(embedding),
        'endpoint': ApiConstants.enrollmentCheckDuplicateEndpoint,
      },
    );
    if (embedding.length != 192) {
      _log.warn(
        'panjang embedding bukan 192 — backend akan menolak dengan 422',
        data: {'panjang': embedding.length},
      );
    }

    final formData = FormData();
    for (final value in embedding) {
      formData.fields.add(MapEntry('embedding[]', value.toString()));
    }
    try {
      final response = await _apiClient.uploadFile(
        ApiConstants.enrollmentCheckDuplicateEndpoint,
        data: formData,
      );
      final isDuplicate = response.data['is_duplicate'] == true;
      _log.info('checkDuplicate selesai', data: {'isDuplicate': isDuplicate});
      return DuplicateCheckResult(isDuplicate: isDuplicate);
    } on ServerException catch (error, stack) {
      if (error.statusCode == 409 && error.code == 'BIOMETRIC_CONFLICT') {
        _log.warn(
          'checkDuplicate: backend menandai konflik biometrik (409)',
          data: {'kode': error.code},
        );
        return const DuplicateCheckResult(isDuplicate: true);
      }
      _log.error(
        'checkDuplicate gagal dengan ServerException',
        data: {
          'status': error.statusCode,
          'kode': error.code,
          'pesan': error.message,
          'errors': error.errors,
        },
        error: error,
        stackTrace: stack,
      );
      rethrow;
    } catch (error, stack) {
      _log.error(
        'checkDuplicate gagal di luar ServerException',
        error: error,
        stackTrace: stack,
      );
      rethrow;
    }
  }

  Future<void> _onSubmitEnrollment(
    SubmitEnrollment event,
    Emitter<FaceState> emit,
  ) async {
    emit(FaceLoading());
    try {
      if (event.fotoEnrollment == null || event.fotoEnrollment!.isEmpty) {
        emit(const FaceError('Foto enrollment wajib diambil terlebih dahulu'));
        return;
      }

      // Multipart: array harus dikirim dengan key `embedding[]`, dan boolean
      // harus '1'/'0' (Laravel rule `boolean` tidak menerima string 'true').
      final formData = FormData();
      for (final value in event.embedding) {
        formData.fields.add(MapEntry('embedding[]', value.toString()));
      }
      formData.fields.add(
        MapEntry('liveness_passed', event.livenessPassed ? '1' : '0'),
      );
      formData.fields.add(
        MapEntry(
          'enrollment_device',
          '${event.deviceModel} (${event.deviceOs})',
        ),
      );
      formData.files.add(
        MapEntry(
          'foto',
          MultipartFile.fromBytes(
            event.fotoEnrollment!,
            filename: 'enrollment.jpg',
          ),
        ),
      );

      _log.info(
        'mengirim enrollment',
        data: {
          'panjangEmbedding': event.embedding.length,
          'livenessPassed': event.livenessPassed,
          'fotoBytes': event.fotoEnrollment!.length,
          'perangkat': '${event.deviceModel} (${event.deviceOs})',
        },
      );

      final response = await _apiClient.uploadFile(
        ApiConstants.enrollmentSubmitEndpoint,
        data: formData,
      );

      _log.info(
        'enrollment terkirim',
        data: {'status': response.statusCode},
      );
      emit(
        EnrollmentSubmitted(response.data['message'] ?? 'Enrollment berhasil'),
      );
    } catch (e, stack) {
      _log.error('submit enrollment gagal', error: e, stackTrace: stack);
      emit(FaceError(_describeError(e)));
    }
  }

  /// Ubah exception jadi pesan yang menjelaskan penyebab, bukan sekadar
  /// `Instance of 'ServerException'` yang dihasilkan `toString()` bawaan.
  static String _describeError(Object error) {
    if (error is ServerException) {
      final detail = error.errors?.entries
          .map((entry) => '${entry.key}: ${entry.value}')
          .join('; ');
      return [
        error.message,
        if (error.statusCode != null) '(HTTP ${error.statusCode})',
        if (detail != null && detail.isNotEmpty) detail,
      ].join(' ');
    }
    if (error is AuthException) return error.message;
    if (error is NetworkException) return error.message;
    return error.toString();
  }

  Future<void> _onCheckEnrollmentStatus(
    CheckEnrollmentStatus event,
    Emitter<FaceState> emit,
  ) async {
    emit(FaceLoading());
    try {
      final response = await _apiClient.get(
        ApiConstants.enrollmentStatusEndpoint,
      );
      final status = response.data['data']['enrollment_status'] ?? 'belum';
      _log.info('status enrollment dimuat', data: {'status': status});
      emit(EnrollmentStatusLoaded(status));
    } catch (e, stack) {
      _log.error('gagal memuat status enrollment', error: e, stackTrace: stack);
      emit(FaceError(_describeError(e)));
    }
  }

  Future<void> _onLoadReferenceEmbedding(
    LoadReferenceEmbedding event,
    Emitter<FaceState> emit,
  ) async {
    emit(FaceLoading());
    try {
      final response = await _apiClient.get(
        ApiConstants.enrollmentMyEmbeddingEndpoint,
      );
      final data = response.data['data'];
      final embeddingList = (data['embedding'] as List<dynamic>)
          .map((e) => (e as num).toDouble())
          .toList();
      _referenceEmbedding = embeddingList;
      // H-04: backend mengirim `face_threshold` (single source of truth).
      // Field lama `threshold` dijaga sebagai fallback untuk kompatibilitas.
      final raw = data['face_threshold'] ?? data['threshold'];
      _faceThreshold = (raw is num) ? raw.toDouble() : strictFaceThreshold;
      _log.info(
        'embedding referensi dimuat',
        data: {
          'ringkasan': describeVector(embeddingList),
          'threshold': _faceThreshold,
          'sumberThreshold': data['face_threshold'] != null
              ? 'face_threshold'
              : (data['threshold'] != null ? 'threshold' : 'default'),
        },
      );
      emit(ReferenceEmbeddingLoaded(embeddingList));
    } catch (e, stack) {
      _log.error(
        'gagal memuat embedding referensi',
        error: e,
        stackTrace: stack,
      );
      emit(FaceError('Gagal memuat data wajah: ${_describeError(e)}'));
    }
  }
}

class FaceVerificationResultState extends FaceState {
  final double distance;
  final double threshold;
  final bool isMatch;
  final int inferenceTimeMs;
  const FaceVerificationResultState({
    required this.distance,
    required this.threshold,
    required this.isMatch,
    required this.inferenceTimeMs,
  });
}

/// Hasil pengecekan dini duplikat wajah saat enrollment.
class DuplicateCheckResult {
  final bool isDuplicate;
  const DuplicateCheckResult({required this.isDuplicate});
}
