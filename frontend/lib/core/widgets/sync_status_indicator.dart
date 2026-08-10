import 'package:flutter/material.dart';
import '../theme/app_colors.dart';
import '../offline/offline_queue_item.dart';
import '../offline/offline_queue_service.dart';
import '../network/connectivity_service.dart';

class SyncStatusIndicator extends StatelessWidget {
  final OfflineQueueService queueService;
  final ConnectivityService connectivityService;

  const SyncStatusIndicator({
    super.key,
    required this.queueService,
    required this.connectivityService,
  });

  @override
  Widget build(BuildContext context) {
    return StreamBuilder<SyncState>(
      stream: connectivityService.syncStateStream,
      builder: (context, snapshot) {
        final pendingCount = queueService.pendingCount;
        final failedCount = queueService.failedCount;
        final isSyncing = connectivityService.isSyncing;

        if (pendingCount == 0 && failedCount == 0 && !isSyncing) {
          return const SizedBox.shrink();
        }

        return GestureDetector(
          onTap: () => _showSyncDetails(context),
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
            decoration: BoxDecoration(
              color: _getBackgroundColor(isSyncing, failedCount, pendingCount),
              borderRadius: BorderRadius.circular(16),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withValues(alpha: 0.1),
                  blurRadius: 4,
                  offset: const Offset(0, 2),
                ),
              ],
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                if (isSyncing) ...[
                  const SizedBox(
                    width: 14,
                    height: 14,
                    child: CircularProgressIndicator(
                      strokeWidth: 2,
                      color: Colors.white,
                    ),
                  ),
                  const SizedBox(width: 6),
                  Text(
                    'Menyinkron...',
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ] else if (failedCount > 0) ...[
                  const Icon(Icons.sync_problem, size: 16, color: Colors.white),
                  const SizedBox(width: 4),
                  Text(
                    '$failedCount gagal',
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ] else ...[
                  const Icon(Icons.cloud_upload, size: 16, color: Colors.white),
                  const SizedBox(width: 4),
                  Text(
                    '$pendingCount antrian',
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
              ],
            ),
          ),
        );
      },
    );
  }

  Color _getBackgroundColor(bool isSyncing, int failedCount, int pendingCount) {
    if (isSyncing) return AppColors.primary;
    if (failedCount > 0) return AppColors.danger;
    if (pendingCount > 0) return AppColors.warning;
    return AppColors.success;
  }

  void _showSyncDetails(BuildContext context) {
    final allItems = queueService.getAllItems();

    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (context) => Container(
        padding: const EdgeInsets.all(20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                const Text(
                  'Antrian Offline',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                ),
                if (queueService.hasPendingItems)
                  TextButton.icon(
                    onPressed: () {
                      connectivityService.syncPendingItems();
                      if (context.mounted) Navigator.pop(context);
                    },
                    icon: const Icon(Icons.sync, size: 18),
                    label: const Text('Sinkron Sekarang'),
                  ),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              '${queueService.pendingCount} menunggu · ${queueService.failedCount} gagal',
              style: TextStyle(color: AppColors.textSecondary, fontSize: 13),
            ),
            const SizedBox(height: 16),
            if (allItems.isEmpty)
              const Center(
                child: Padding(
                  padding: EdgeInsets.all(24),
                  child: Text(
                    'Tidak ada data dalam antrian',
                    style: TextStyle(color: Colors.grey),
                  ),
                ),
              )
            else
              Flexible(
                child: ListView.builder(
                  shrinkWrap: true,
                  itemCount: allItems.length,
                  itemBuilder: (context, index) {
                    final item = allItems[index];
                    return ListTile(
                      dense: true,
                      leading: Icon(
                        _getItemIcon(item.status),
                        color: _getItemColor(item.status),
                        size: 20,
                      ),
                      title: Text(
                        item.type == OfflineQueueItem.checkInType
                            ? 'Check-in'
                            : item.type == OfflineQueueItem.checkOutType
                            ? 'Check-out'
                            : 'Sinkron Offline',
                        style: const TextStyle(fontSize: 14),
                      ),
                      subtitle: Text(
                        _formatTime(item.createdAt),
                        style: const TextStyle(fontSize: 12),
                      ),
                      trailing: item.lastError != null
                          ? Tooltip(
                              message: item.lastError,
                              child: const Icon(
                                Icons.info_outline,
                                size: 16,
                                color: Colors.grey,
                              ),
                            )
                          : null,
                    );
                  },
                ),
              ),
            if (allItems.any(
              (item) =>
                  item.status == OfflineQueueItem.statusFailed &&
                  item.failureKind == OfflineQueueItem.failureTransient,
            )) ...[
              const SizedBox(height: 12),
              SizedBox(
                width: double.infinity,
                child: OutlinedButton.icon(
                  onPressed: () async {
                    await queueService.retryFailed();
                    if (context.mounted) Navigator.pop(context);
                    connectivityService.syncPendingItems();
                  },
                  icon: const Icon(Icons.refresh),
                  label: const Text('Coba Ulang Gangguan Jaringan'),
                ),
              ),
            ],
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
  }

  IconData _getItemIcon(String status) {
    switch (status) {
      case OfflineQueueItem.statusPending:
        return Icons.schedule;
      case OfflineQueueItem.statusSyncing:
        return Icons.sync;
      case OfflineQueueItem.statusFailed:
        return Icons.error_outline;
      case OfflineQueueItem.statusCompleted:
        return Icons.check_circle;
      default:
        return Icons.help_outline;
    }
  }

  Color _getItemColor(String status) {
    switch (status) {
      case OfflineQueueItem.statusPending:
        return AppColors.warning;
      case OfflineQueueItem.statusSyncing:
        return AppColors.primary;
      case OfflineQueueItem.statusFailed:
        return AppColors.danger;
      case OfflineQueueItem.statusCompleted:
        return AppColors.success;
      default:
        return Colors.grey;
    }
  }

  String _formatTime(DateTime dt) {
    final now = DateTime.now();
    final diff = now.difference(dt);
    if (diff.inMinutes < 1) return 'Baru saja';
    if (diff.inMinutes < 60) return '${diff.inMinutes}m lalu';
    if (diff.inHours < 24) return '${diff.inHours}j lalu';
    return '${dt.day}/${dt.month} ${dt.hour}:${dt.minute.toString().padLeft(2, '0')}';
  }
}
