import 'package:flutter_bloc/flutter_bloc.dart';
import '../../domain/entities/notification_entity.dart';
import '../../domain/repositories/notification_repository.dart';
import 'notification_event.dart';
import 'notification_state.dart';

class NotificationBloc extends Bloc<NotificationEvent, NotificationState> {
  final NotificationRepository _repository;

  NotificationBloc(this._repository) : super(NotificationInitial()) {
    on<LoadNotifications>(_onLoadNotifications);
    on<LoadUnreadCount>(_onLoadUnreadCount);
    on<MarkAsRead>(_onMarkAsRead);
    on<MarkAllAsRead>(_onMarkAllAsRead);
  }

  Future<void> _onLoadNotifications(
    LoadNotifications event,
    Emitter<NotificationState> emit,
  ) async {
    emit(NotificationLoading());
    final result = await _repository.getNotifications();
    result.fold(
      (failure) => emit(NotificationError(failure.message)),
      (notifications) => emit(NotificationLoaded(notifications: notifications)),
    );
  }

  Future<void> _onLoadUnreadCount(
    LoadUnreadCount event,
    Emitter<NotificationState> emit,
  ) async {
    final result = await _repository.getUnreadCount();
    result.fold(
      (failure) => emit(NotificationError(failure.message)),
      (count) => emit(NotificationUnreadCount(count)),
    );
  }

  Future<void> _onMarkAsRead(
    MarkAsRead event,
    Emitter<NotificationState> emit,
  ) async {
    final result = await _repository.markRead(event.id);
    result.fold((failure) => emit(NotificationError(failure.message)), (_) {
      final currentState = state;
      if (currentState is NotificationLoaded) {
        final updated = currentState.notifications.map((n) {
          if (n.id == event.id) {
            return NotificationEntity(
              id: n.id,
              title: n.title,
              body: n.body,
              type: n.type,
              isRead: true,
              readAt: DateTime.now().toIso8601String(),
              sentVia: n.sentVia,
              createdAt: n.createdAt,
              data: n.data,
            );
          }
          return n;
        }).toList();
        emit(NotificationLoaded(notifications: updated));
      }
    });
  }

  Future<void> _onMarkAllAsRead(
    MarkAllAsRead event,
    Emitter<NotificationState> emit,
  ) async {
    final result = await _repository.markAllRead();
    result.fold((failure) => emit(NotificationError(failure.message)), (_) {
      final currentState = state;
      if (currentState is NotificationLoaded) {
        final updated = currentState.notifications.map((n) {
          return NotificationEntity(
            id: n.id,
            title: n.title,
            body: n.body,
            type: n.type,
            isRead: true,
            readAt: DateTime.now().toIso8601String(),
            sentVia: n.sentVia,
            createdAt: n.createdAt,
            data: n.data,
          );
        }).toList();
        emit(NotificationLoaded(notifications: updated));
      }
    });
  }
}
