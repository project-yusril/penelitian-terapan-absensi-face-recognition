import 'package:flutter/material.dart';

import '../../../../core/constants/api_constants.dart';
import '../../../../core/network/api_client.dart';

/// L-01: Halaman notifikasi nyata (list dari `NotificationController`).
///
/// Mengambil data dari `GET /notifications`, mendukung pull-to-refresh,
/// tandai satu notifikasi sebagai dibaca (`PUT /notifications/{id}/read`),
/// dan tandai semua dibaca (`PUT /notifications/read-all`).
class NotificationsPage extends StatefulWidget {
  final ApiClient apiClient;

  const NotificationsPage({super.key, required this.apiClient});

  @override
  State<NotificationsPage> createState() => _NotificationsPageState();
}

class _NotificationsPageState extends State<NotificationsPage> {
  bool _loading = true;
  String? _error;
  List<_NotificationVm> _items = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final response = await widget.apiClient.get(
        ApiConstants.notificationsEndpoint,
        queryParameters: {'per_page': 30},
      );
      final list = (response.data['data'] as List<dynamic>? ?? []);
      setState(() {
        _items = list
            .map((e) => _NotificationVm.fromJson(e as Map<String, dynamic>))
            .toList();
        _loading = false;
      });
    } catch (e) {
      setState(() {
        _error = 'Gagal memuat notifikasi. Tarik untuk mencoba lagi.';
        _loading = false;
      });
    }
  }

  Future<void> _markAsRead(_NotificationVm item) async {
    if (item.isRead) return;
    // Optimistic update
    setState(() => item.isRead = true);
    try {
      await widget.apiClient.put(
        '${ApiConstants.notificationsEndpoint}/${item.id}/read',
      );
    } catch (_) {
      if (mounted) setState(() => item.isRead = false);
    }
  }

  Future<void> _markAllAsRead() async {
    final hadUnread = _items.any((e) => !e.isRead);
    if (!hadUnread) return;
    setState(() {
      for (final e in _items) {
        e.isRead = true;
      }
    });
    try {
      await widget.apiClient.put(ApiConstants.notificationsReadAllEndpoint);
    } catch (_) {
      if (mounted) _load();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Notifikasi'),
        actions: [
          IconButton(
            tooltip: 'Tandai semua dibaca',
            icon: const Icon(Icons.done_all),
            onPressed: _items.isEmpty ? null : _markAllAsRead,
          ),
        ],
      ),
      body: RefreshIndicator(onRefresh: _load, child: _buildBody()),
    );
  }

  Widget _buildBody() {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null) {
      return ListView(
        children: [
          const SizedBox(height: 120),
          Center(child: Text(_error!)),
        ],
      );
    }
    if (_items.isEmpty) {
      return ListView(
        children: const [
          SizedBox(height: 120),
          Icon(Icons.notifications_none, size: 64, color: Colors.grey),
          SizedBox(height: 12),
          Center(child: Text('Belum ada notifikasi')),
        ],
      );
    }
    return ListView.separated(
      itemCount: _items.length,
      separatorBuilder: (context, index) => const Divider(height: 1),
      itemBuilder: (context, index) {
        final item = _items[index];
        return ListTile(
          leading: CircleAvatar(
            backgroundColor: item.isRead
                ? Colors.grey.shade300
                : Theme.of(context).colorScheme.primary.withValues(alpha: 0.15),
            child: Icon(
              _iconFor(item.type),
              color: item.isRead
                  ? Colors.grey
                  : Theme.of(context).colorScheme.primary,
            ),
          ),
          title: Text(
            item.title,
            style: TextStyle(
              fontWeight: item.isRead ? FontWeight.normal : FontWeight.bold,
            ),
          ),
          subtitle: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(item.body),
              const SizedBox(height: 4),
              Text(
                item.createdAt,
                style: const TextStyle(fontSize: 11, color: Colors.grey),
              ),
            ],
          ),
          trailing: item.isRead
              ? null
              : const Icon(Icons.circle, size: 10, color: Colors.redAccent),
          onTap: () => _markAsRead(item),
        );
      },
    );
  }

  IconData _iconFor(String type) {
    switch (type) {
      case 'sp_warning':
      case 'sp_issued':
        return Icons.warning_amber_rounded;
      case 'approval_needed':
      case 'approval_result':
        return Icons.fact_check_outlined;
      case 'enrollment_result':
        return Icons.face_retouching_natural;
      case 'attendance_reminder':
      case 'reminder':
        return Icons.alarm;
      case 'leave_request_result':
        return Icons.event_available;
      default:
        return Icons.notifications;
    }
  }
}

class _NotificationVm {
  final int id;
  final String title;
  final String body;
  final String type;
  bool isRead;
  final String createdAt;

  _NotificationVm({
    required this.id,
    required this.title,
    required this.body,
    required this.type,
    required this.isRead,
    required this.createdAt,
  });

  factory _NotificationVm.fromJson(Map<String, dynamic> json) {
    return _NotificationVm(
      id: json['id'] is int
          ? json['id'] as int
          : int.tryParse('${json['id']}') ?? 0,
      title: json['title']?.toString() ?? '',
      body: json['body']?.toString() ?? '',
      type: json['type']?.toString() ?? '',
      isRead: json['is_read'] == true || json['is_read'] == 1,
      createdAt: json['created_at']?.toString() ?? '',
    );
  }
}
