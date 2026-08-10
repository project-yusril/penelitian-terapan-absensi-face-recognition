import 'package:equatable/equatable.dart';
import '../../domain/entities/home_entities.dart';

abstract class HomeState extends Equatable {
  const HomeState();
  @override
  List<Object?> get props => [];
}

class HomeInitial extends HomeState {}

class HomeLoading extends HomeState {}

class HomeLoaded extends HomeState {
  final List<JadwalHariIni> jadwalList;
  final AttendanceSummary? summary;
  final List<NotificationItem> notifications;

  const HomeLoaded({
    required this.jadwalList,
    this.summary,
    required this.notifications,
  });

  @override
  List<Object?> get props => [jadwalList, summary, notifications];
}

class HomeError extends HomeState {
  final String message;
  const HomeError(this.message);
}
