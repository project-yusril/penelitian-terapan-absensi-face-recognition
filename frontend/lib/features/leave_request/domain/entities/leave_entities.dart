import 'package:equatable/equatable.dart';

/// Mata kuliah yang tidak dibuatkan izin saat pengajuan multi-MK.
class SkippedCourse extends Equatable {
  final int mataKuliahId;
  final String? nama;

  /// Kode alasan dari backend: `duplikat` atau `tanpa_jadwal`.
  final String alasan;
  final String pesan;

  const SkippedCourse({
    required this.mataKuliahId,
    this.nama,
    required this.alasan,
    required this.pesan,
  });

  @override
  List<Object?> get props => [mataKuliahId, nama, alasan, pesan];
}

/// Hasil pengajuan izin, menyatukan mode single-MK (satu baris) dan multi-MK
/// (beberapa baris + ringkasan MK yang dilewati).
class LeaveSubmissionResult extends Equatable {
  final List<LeaveRequest> created;
  final List<SkippedCourse> skipped;

  const LeaveSubmissionResult({required this.created, this.skipped = const []});

  int get createdCount => created.length;

  @override
  List<Object?> get props => [created, skipped];
}

class LeaveRequest extends Equatable {
  final int id;
  final int userId;
  final int? mataKuliahId;
  final String? mataKuliahName;
  final String jenis;
  final String tanggalMulai;
  final String tanggalSelesai;
  final String keterangan;
  final String? fileSurat;
  final String status;
  final int? approvedBy;
  final String? rejectedReason;
  final String createdAt;

  const LeaveRequest({
    required this.id,
    required this.userId,
    this.mataKuliahId,
    this.mataKuliahName,
    required this.jenis,
    required this.tanggalMulai,
    required this.tanggalSelesai,
    required this.keterangan,
    this.fileSurat,
    required this.status,
    this.approvedBy,
    this.rejectedReason,
    required this.createdAt,
  });

  @override
  List<Object?> get props => [
    id,
    userId,
    mataKuliahId,
    mataKuliahName,
    jenis,
    tanggalMulai,
    tanggalSelesai,
    keterangan,
    fileSurat,
    status,
    approvedBy,
    rejectedReason,
    createdAt,
  ];
}
