import 'package:equatable/equatable.dart';

abstract class LeaveEvent extends Equatable {
  const LeaveEvent();
  @override
  List<Object?> get props => [];
}

class LoadMyLeaves extends LeaveEvent {}

class SubmitLeave extends LeaveEvent {
  final String jenis;
  final int? mataKuliahId;
  final String tanggalMulai;
  final String tanggalSelesai;
  final String keterangan;
  final String? filePath;

  /// Berlaku untuk semua MK yang punya jadwal pada rentang tanggal.
  final bool allMataKuliah;

  /// Subset MK bila pengguna memilih sendiri; diabaikan saat [allMataKuliah].
  final List<int>? mataKuliahIds;

  const SubmitLeave({
    required this.jenis,
    this.mataKuliahId,
    required this.tanggalMulai,
    required this.tanggalSelesai,
    required this.keterangan,
    this.filePath,
    this.allMataKuliah = false,
    this.mataKuliahIds,
  });

  @override
  List<Object?> get props => [
    jenis,
    mataKuliahId,
    tanggalMulai,
    tanggalSelesai,
    keterangan,
    filePath,
    allMataKuliah,
    mataKuliahIds,
  ];
}
