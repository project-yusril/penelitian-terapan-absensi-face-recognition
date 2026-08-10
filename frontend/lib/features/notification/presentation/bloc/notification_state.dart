import 'package:equatable/equatable.dart';
import '../../domain/entities/notification_entity.dart';

abstract class NotificationState extends Equatable {
  const NotificationState();
  @override
  List<Object?> get props => [];
}

class NotificationInitial extends NotificationState {}

class NotificationLoading extends NotificationState {}

class NotificationLoaded extends NotificationState {
  final List<NotificationEntity> notifications;

  const NotificationLoaded({required this.notifications});

  @override
  List<Object?> get props => [notifications];
}

class NotificationUnreadCount extends NotificationState {
  final int count;

  const NotificationUnreadCount(this.count);

  @override
  List<Object?> get props => [count];
}

class NotificationError extends NotificationState {
  final String message;
  const NotificationError(this.message);
  @override
  List<Object?> get props => [message];
}
