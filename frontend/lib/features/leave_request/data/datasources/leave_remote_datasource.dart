import 'package:dio/dio.dart';
import '../../../../core/constants/api_constants.dart';
import '../../../../core/network/api_client.dart';
import '../models/leave_model.dart';

abstract class LeaveRemoteDataSource {
  Future<List<LeaveRequestModel>> getMyLeaves();
  Future<LeaveRequestModel> submitLeave({
    required String jenis,
    required int? mataKuliahId,
    required String tanggalMulai,
    required String tanggalSelesai,
    required String keterangan,
    String? filePath,
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
  Future<LeaveRequestModel> submitLeave({
    required String jenis,
    required int? mataKuliahId,
    required String tanggalMulai,
    required String tanggalSelesai,
    required String keterangan,
    String? filePath,
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
      if (mataKuliahId != null) fields['mata_kuliah_id'] = mataKuliahId;
      if (suratFile != null) fields['file_surat'] = suratFile;

      final formData = FormData.fromMap(fields);

      final response = await _apiClient.uploadFile(
        ApiConstants.leavesEndpoint,
        data: formData,
      );
      return LeaveRequestModel.fromJson(
        response.data['data'] as Map<String, dynamic>,
      );
    } catch (e) {
      rethrow;
    }
  }
}
