import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:intl/intl.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/widgets/app_loading.dart';
import '../../domain/entities/notification_entity.dart';
import '../bloc/notification_bloc.dart';
import '../bloc/notification_event.dart';
import '../bloc/notification_state.dart';

class NotificationPage extends StatefulWidget {
  const NotificationPage({super.key});

  @override
  State<NotificationPage> createState() => _NotificationPageState();
}

class _NotificationPageState extends State<NotificationPage> {
  @override
  void initState() {
    super.initState();
    context.read<NotificationBloc>().add(LoadNotifications());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Notifikasi'),
        backgroundColor: AppColors.surface,
        foregroundColor: AppColors.textPrimary,
        elevation: 0,
        actions: [
          TextButton(
            onPressed: () {
              context.read<NotificationBloc>().add(MarkAllAsRead());
            },
            child: const Text(
              'Tandai Semua Dibaca',
              style: TextStyle(color: AppColors.primary, fontSize: 13),
            ),
          ),
        ],
      ),
      body: BlocConsumer<NotificationBloc, NotificationState>(
        listener: (context, state) {
          if (state is NotificationError) {
            ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(
                content: Text(state.message),
                backgroundColor: AppColors.danger,
              ),
            );
          }
        },
        builder: (context, state) {
          if (state is NotificationLoading) return const AppLoading();
          if (state is NotificationLoaded) {
            return _buildList(state.notifications);
          }
          return const AppLoading();
        },
      ),
    );
  }

  Widget _buildList(List<NotificationEntity> notifications) {
    if (notifications.isEmpty) {
      return RefreshIndicator(
        onRefresh: _onRefresh,
        child: ListView(
          children: const [
            SizedBox(height: 120),
            AppEmptyState(
              title: 'Tidak ada notifikasi',
              subtitle: 'Notifikasi baru akan muncul di sini',
              icon: Icons.notifications_none,
            ),
          ],
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: _onRefresh,
      child: ListView.separated(
        padding: const EdgeInsets.symmetric(vertical: 8),
        itemCount: notifications.length,
        separatorBuilder: (_, _) =>
            Divider(height: 1, color: AppColors.border.withValues(alpha: 0.5)),
        itemBuilder: (context, index) {
          final notif = notifications[index];
          return _NotificationTile(
            notification: notif,
            onTap: () {
              if (!notif.isRead) {
                context.read<NotificationBloc>().add(MarkAsRead(notif.id));
              }
            },
          );
        },
      ),
    );
  }

  Future<void> _onRefresh() async {
    context.read<NotificationBloc>().add(LoadNotifications());
  }
}

class _NotificationTile extends StatelessWidget {
  final NotificationEntity notification;
  final VoidCallback onTap;

  const _NotificationTile({required this.notification, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final notif = notification;
    return InkWell(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        color: notif.isRead
            ? AppColors.surface
            : AppColors.primary.withValues(alpha: 0.04),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(
                color: _getTypeColor(notif.type).withValues(alpha: 0.1),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Icon(
                _getTypeIcon(notif.type),
                size: 20,
                color: _getTypeColor(notif.type),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          notif.title,
                          style: TextStyle(
                            fontSize: 14,
                            fontWeight: notif.isRead
                                ? FontWeight.normal
                                : FontWeight.w600,
                            color: AppColors.textPrimary,
                          ),
                        ),
                      ),
                      if (!notif.isRead)
                        Container(
                          width: 8,
                          height: 8,
                          margin: const EdgeInsets.only(left: 8),
                          decoration: const BoxDecoration(
                            color: AppColors.primary,
                            shape: BoxShape.circle,
                          ),
                        ),
                    ],
                  ),
                  const SizedBox(height: 4),
                  Text(
                    notif.body,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      fontSize: 13,
                      color: AppColors.textSecondary,
                      height: 1.3,
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    _formatTimeAgo(notif.createdAt),
                    style: const TextStyle(
                      fontSize: 11,
                      color: AppColors.textMuted,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  IconData _getTypeIcon(String type) {
    switch (type) {
      case 'sp_warning':
      case 'sp_issued':
        return Icons.warning_amber_rounded;
      case 'approval_needed':
      case 'approval_result':
        return Icons.check_circle_outline;
      case 'reminder':
        return Icons.access_time;
      case 'attendance':
        return Icons.fact_check_outlined;
      default:
        return Icons.notifications_outlined;
    }
  }

  Color _getTypeColor(String type) {
    switch (type) {
      case 'sp_warning':
      case 'sp_issued':
        return AppColors.warning;
      case 'approval_needed':
      case 'approval_result':
        return AppColors.success;
      case 'reminder':
        return AppColors.info;
      case 'attendance':
        return AppColors.primary;
      default:
        return AppColors.textMuted;
    }
  }

  String _formatTimeAgo(String dateString) {
    try {
      final date = DateTime.parse(dateString);
      final now = DateTime.now();
      final diff = now.difference(date);

      if (diff.inSeconds < 60) return 'Baru saja';
      if (diff.inMinutes < 60) return '${diff.inMinutes} menit lalu';
      if (diff.inHours < 24) return '${diff.inHours} jam lalu';
      if (diff.inDays < 7) return '${diff.inDays} hari lalu';
      return DateFormat('dd MMM yyyy', 'id').format(date);
    } catch (_) {
      return dateString;
    }
  }
}
