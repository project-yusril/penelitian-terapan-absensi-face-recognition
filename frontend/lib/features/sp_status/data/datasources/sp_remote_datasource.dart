import '../../../../core/constants/api_constants.dart';
import '../../../../core/network/api_client.dart';
import '../../domain/entities/sp_entity.dart';

abstract class SpRemoteDataSource {
  Future<List<SpRecord>> getMySpRecords();
}

class SpRemoteDataSourceImpl implements SpRemoteDataSource {
  final ApiClient _apiClient;

  SpRemoteDataSourceImpl(this._apiClient);

  @override
  Future<List<SpRecord>> getMySpRecords() async {
    try {
      final response = await _apiClient.get(ApiConstants.spMyEndpoint);
      final data = response.data['data'] as List<dynamic>;
      return data.map((e) => _parseSpRecord(e)).toList();
    } catch (e) {
      rethrow;
    }
  }

  SpRecord _parseSpRecord(Map<String, dynamic> json) {
    return SpRecord(
      id: json['id'] ?? 0,
      userId: json['user_id'] ?? 0,
      semesterId: json['semester_id'] ?? 0,
      spLevel: json['sp_level'] ?? '',
      nomorSurat: json['nomor_surat'] ?? '',
      tanggalTerbit: json['tanggal_terbit'] ?? '',
      totalAlphaJam: json['total_alpha_jam'] ?? 0,
      rincianAlpha: json['rincian_alpha'] ?? '',
      status: json['status'] ?? '',
      documentPath: json['document_path'],
      signedKaprodiAt: json['signed_kaprodi_at'],
      signedKajurAt: json['signed_kajur_at'],
      createdAt: json['created_at'] ?? '',
    );
  }
}
