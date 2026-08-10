import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:dio/dio.dart';
import '../../../../core/constants/api_constants.dart';
import '../../../../core/errors/exceptions.dart';
import '../../../../core/network/api_client.dart';
import 'face_event.dart';
import 'face_state.dart';

class FaceBloc extends Bloc<FaceEvent, FaceState> {
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
    final formData = FormData();
    for (final value in embedding) {
      formData.fields.add(MapEntry('embedding[]', value.toString()));
    }
    try {
      final response = await _apiClient.uploadFile(
        ApiConstants.enrollmentCheckDuplicateEndpoint,
        data: formData,
      );
      return DuplicateCheckResult(
        isDuplicate: response.data['is_duplicate'] == true,
      );
    } on ServerException catch (error) {
      if (error.statusCode == 409 && error.code == 'BIOMETRIC_CONFLICT') {
        return const DuplicateCheckResult(isDuplicate: true);
      }
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

      final response = await _apiClient.uploadFile(
        ApiConstants.enrollmentSubmitEndpoint,
        data: formData,
      );

      emit(
        EnrollmentSubmitted(response.data['message'] ?? 'Enrollment berhasil'),
      );
    } catch (e) {
      emit(FaceError(e.toString()));
    }
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
      emit(EnrollmentStatusLoaded(status));
    } catch (e) {
      emit(FaceError(e.toString()));
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
      emit(ReferenceEmbeddingLoaded(embeddingList));
    } catch (e) {
      emit(FaceError('Gagal memuat data wajah: $e'));
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
