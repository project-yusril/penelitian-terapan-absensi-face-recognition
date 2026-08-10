import 'package:equatable/equatable.dart';

class NotificationEntity extends Equatable {
  final int id;
  final String title;
  final String body;
  final String type;
  final bool isRead;
  final String? readAt;
  final String? sentVia;
  final String createdAt;
  final Map<String, dynamic>? data;

  const NotificationEntity({
    required this.id,
    required this.title,
    required this.body,
    required this.type,
    required this.isRead,
    this.readAt,
    this.sentVia,
    required this.createdAt,
    this.data,
  });

  @override
  List<Object?> get props => [id, isRead, readAt];
}
