import 'package:equatable/equatable.dart';
import '../../domain/entities/leave_entities.dart';

abstract class LeaveState extends Equatable {
  const LeaveState();
  @override
  List<Object?> get props => [];
}

class LeaveInitial extends LeaveState {}

class LeaveLoading extends LeaveState {}

class LeaveLoaded extends LeaveState {
  final List<LeaveRequest> leaves;
  const LeaveLoaded(this.leaves);
  @override
  List<Object?> get props => [leaves];
}

class LeaveSubmitted extends LeaveState {
  final LeaveRequest leave;
  const LeaveSubmitted(this.leave);
  @override
  List<Object?> get props => [leave];
}

class LeaveError extends LeaveState {
  final String message;
  const LeaveError(this.message);
  @override
  List<Object?> get props => [message];
}
