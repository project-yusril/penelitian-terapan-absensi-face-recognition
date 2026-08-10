import '../../../../core/constants/api_constants.dart';
import '../../../../core/network/api_client.dart';
import '../../domain/entities/notification_entity.dart';

abstract class NotificationRemoteDataSource {
  Future<List<NotificationEntity>> getNotifications();
  Future<int> getUnreadCount();
  Future<void> markRead(int id);
  Future<void> markAllRead();
}

class NotificationRemoteDataSourceImpl implements NotificationRemoteDataSource {
  final ApiClient _apiClient;

  NotificationRemoteDataSourceImpl(this._apiClient);

  @override
  Future<List<NotificationEntity>> getNotifications() async {
    try {
      final response = await _apiClient.get(ApiConstants.notificationsEndpoint);
      final data = response.data['data'] as List<dynamic>;
      return data.map((e) => _parseNotification(e)).toList();
    } catch (e) {
      rethrow;
    }
  }

  @override
  Future<int> getUnreadCount() async {
    try {
      final response = await _apiClient.get(
        ApiConstants.notificationsUnreadEndpoint,
      );
      return response.data['data']['unread_count'] ?? 0;
    } catch (e) {
      rethrow;
    }
  }

  @override
  Future<void> markRead(int id) async {
    try {
      await _apiClient.put('${ApiConstants.notificationsEndpoint}/$id/read');
    } catch (e) {
      rethrow;
    }
  }

  @override
  Future<void> markAllRead() async {
    try {
      await _apiClient.put(ApiConstants.notificationsReadAllEndpoint);
    } catch (e) {
      rethrow;
    }
  }

  NotificationEntity _parseNotification(Map<String, dynamic> json) {
    return NotificationEntity(
      id: json['id'] ?? 0,
      title: json['title'] ?? '',
      body: json['body'] ?? '',
      type: json['type'] ?? '',
      isRead: json['is_read'] ?? false,
      readAt: json['read_at'],
      sentVia: json['sent_via'],
      createdAt: json['created_at'] ?? '',
      data: json['data'] as Map<String, dynamic>?,
    );
  }
}
