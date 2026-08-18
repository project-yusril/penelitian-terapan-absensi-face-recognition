import 'package:dartz/dartz.dart';
import '../../../../core/errors/failures.dart';
import '../entities/leave_entities.dart';

abstract class LeaveRepository {
  Future<Either<Failure, List<LeaveRequest>>> getMyLeaves();
  Future<Either<Failure, LeaveSubmissionResult>> submitLeave({
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
