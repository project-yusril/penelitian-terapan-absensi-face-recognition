import 'package:dartz/dartz.dart';
import '../../../../core/errors/failures.dart';
import '../entities/home_entities.dart';

abstract class HomeRepository {
  Future<Either<Failure, List<JadwalHariIni>>> getTodaySchedule();
  Future<Either<Failure, AttendanceSummary>> getAttendanceSummary();
  Future<Either<Failure, List<NotificationItem>>> getRecentNotifications();
}
