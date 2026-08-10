import 'package:equatable/equatable.dart';

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
