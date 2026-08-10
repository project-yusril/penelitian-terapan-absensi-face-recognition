import '../../domain/entities/leave_entities.dart';

class LeaveRequestModel extends LeaveRequest {
  const LeaveRequestModel({
    required super.id,
    required super.userId,
    super.mataKuliahId,
    super.mataKuliahName,
    required super.jenis,
    required super.tanggalMulai,
    required super.tanggalSelesai,
    required super.keterangan,
    super.fileSurat,
    required super.status,
    super.approvedBy,
    super.rejectedReason,
    required super.createdAt,
  });

  factory LeaveRequestModel.fromJson(Map<String, dynamic> json) {
    return LeaveRequestModel(
      id: json['id'] ?? 0,
      userId: json['user_id'] ?? 0,
      mataKuliahId: json['mata_kuliah_id'],
      mataKuliahName: json['mata_kuliah']?['nama'] ?? json['mata_kuliah_name'],
      jenis: json['jenis'] ?? 'izin',
      tanggalMulai: json['tanggal_mulai'] ?? '',
      tanggalSelesai: json['tanggal_selesai'] ?? '',
      keterangan: json['keterangan'] ?? '',
      fileSurat: json['file_surat'],
      status: json['status'] ?? 'pending',
      approvedBy: json['approved_by'],
      rejectedReason: json['rejected_reason'],
      createdAt: json['created_at'] ?? '',
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'user_id': userId,
      'mata_kuliah_id': mataKuliahId,
      'jenis': jenis,
      'tanggal_mulai': tanggalMulai,
      'tanggal_selesai': tanggalSelesai,
      'keterangan': keterangan,
      'file_surat': fileSurat,
      'status': status,
      'approved_by': approvedBy,
      'rejected_reason': rejectedReason,
      'created_at': createdAt,
    };
  }
}
