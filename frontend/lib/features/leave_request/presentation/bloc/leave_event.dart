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

  const SubmitLeave({
    required this.jenis,
    this.mataKuliahId,
    required this.tanggalMulai,
    required this.tanggalSelesai,
    required this.keterangan,
    this.filePath,
  });

  @override
  List<Object?> get props => [
    jenis,
    mataKuliahId,
    tanggalMulai,
    tanggalSelesai,
    keterangan,
    filePath,
  ];
}
