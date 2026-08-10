import 'package:equatable/equatable.dart';
import '../../domain/entities/sp_entity.dart';

abstract class SpState extends Equatable {
  const SpState();
  @override
  List<Object?> get props => [];
}

class SpInitial extends SpState {}

class SpLoading extends SpState {}

class SpLoaded extends SpState {
  final List<SpRecord> records;

  const SpLoaded({required this.records});

  String get currentSpLevel {
    if (records.isEmpty) return 'aman';
    final active = records.where((r) => !r.isCancelled).toList()
      ..sort((a, b) => b.id.compareTo(a.id));
    if (active.isEmpty) return 'aman';
    return active.first.spLevel;
  }

  @override
  List<Object?> get props => [records];
}

class SpError extends SpState {
  final String message;
  const SpError(this.message);
}
