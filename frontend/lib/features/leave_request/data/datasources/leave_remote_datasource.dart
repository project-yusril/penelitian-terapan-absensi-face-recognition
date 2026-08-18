import 'package:dio/dio.dart';
import '../../../../core/constants/api_constants.dart';
import '../../../../core/network/api_client.dart';
import '../models/leave_model.dart';

/// Mata kuliah yang diikuti mahasiswa, untuk pilihan saat mengajukan izin.
class EnrolledCourse {
  final int id;
  final String nama;

  const EnrolledCourse({required this.id, required this.nama});
}

abstract class LeaveRemoteDataSource {
  Future<List<LeaveRequestModel>> getMyLeaves();
  Future<List<EnrolledCourse>> getEnrolledCourses();
  Future<LeaveSubmissionResultModel> submitLeave({
    required String jenis,
    required int? mataKuliahId,
    required String tanggalMulai,
    required String tanggalSelesai,
    required String keterangan,
    String? filePath,
    bool allMataKuliah = false,
    List<int>? mataKuliahIds,
  });
}

class LeaveRemoteDataSourceImpl implements LeaveRemoteDataSource {
  final ApiClient _apiClient;

  LeaveRemoteDataSourceImpl(this._apiClient);

  @override
  Future<List<LeaveRequestModel>> getMyLeaves() async {
    try {
      final response = await _apiClient.get(ApiConstants.leavesEndpoint);
      final data = response.data['data'] as List<dynamic>;
      return data
          .map(
            (json) => LeaveRequestModel.fromJson(json as Map<String, dynamic>),
          )
          .toList();
    } catch (e) {
      rethrow;
    }
  }

  @override
  Future<List<EnrolledCourse>> getEnrolledCourses() async {
    try {
      // GET /mahasiswa/jadwal → { data: { "Senin": [ {mata_kuliah:{id,nama}}, ... ], ... } }
      // Kumpulkan mata kuliah unik dari seluruh jadwal yang diikuti mahasiswa.
      final response = await _apiClient.get(ApiConstants.jadwalEndpoint);
      final data = response.data['data'];
      final byId = <int, EnrolledCourse>{};
      if (data is Map) {
        for (final entry in data.values) {
          if (entry is! List) continue;
          for (final jadwal in entry) {
            final mk = (jadwal as Map)['mata_kuliah'];
            if (mk is! Map) continue;
            final id = mk['id'];
            if (id is! int) continue;
            byId[id] = EnrolledCourse(
              id: id,
              nama: mk['nama']?.toString() ?? 'MK $id',
            );
          }
        }
      }
      final list = byId.values.toList()
        ..sort((a, b) => a.nama.compareTo(b.nama));
      return list;
    } catch (e) {
      rethrow;
    }
  }

  @override
  Future<LeaveSubmissionResultModel> submitLeave({
    required String jenis,
    required int? mataKuliahId,
    required String tanggalMulai,
    required String tanggalSelesai,
    required String keterangan,
    String? filePath,
    bool allMataKuliah = false,
    List<int>? mataKuliahIds,
  }) async {
    try {
      MultipartFile? suratFile;
      if (filePath != null) {
        suratFile = await MultipartFile.fromFile(filePath);
      }

      final fields = <String, dynamic>{
        'jenis': jenis,
        'tanggal_mulai': tanggalMulai,
        'tanggal_selesai': tanggalSelesai,
        'keterangan': keterangan,
      };
      // Mode multi-MK: backend melakukan fan-out satu izin per MK. `mata_kuliah_id`
      // tunggal hanya dikirim pada mode lama agar kontrak lama tetap berlaku.
      if (allMataKuliah) {
        fields['all_mata_kuliah'] = '1';
      } else if (mataKuliahIds != null && mataKuliahIds.isNotEmpty) {
        fields['mata_kuliah_ids'] = mataKuliahIds;
      } else if (mataKuliahId != null) {
        fields['mata_kuliah_id'] = mataKuliahId;
      }
      if (suratFile != null) fields['file_surat'] = suratFile;

      // `multiCompatible` menulis list sebagai `mata_kuliah_ids[]`, bentuk yang
      // dibaca PHP/Laravel sebagai array. Format `multi` bawaan Dio mengulang
      // key tanpa kurung dan hanya menyisakan nilai terakhir di sisi backend.
      final formData = FormData.fromMap(fields, ListFormat.multiCompatible);

      final response = await _apiClient.uploadFile(
        ApiConstants.leavesEndpoint,
        data: formData,
      );
      return LeaveSubmissionResultModel.fromJson(
        response.data['data'] as Map<String, dynamic>,
      );
    } catch (e) {
      rethrow;
    }
  }
}
