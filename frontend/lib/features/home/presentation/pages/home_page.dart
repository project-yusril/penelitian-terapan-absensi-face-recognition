import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/utils/formatters.dart';
import '../../../../core/widgets/app_loading.dart';
import '../../../../core/widgets/sync_status_indicator.dart';
import '../../../../core/offline/offline_queue_service.dart';
import '../../../../core/network/connectivity_service.dart';
import '../../../auth/presentation/bloc/auth_bloc.dart';
import '../../../auth/presentation/bloc/auth_state.dart';
import '../bloc/home_bloc.dart';
import '../bloc/home_event.dart';
import '../bloc/home_state.dart';
import '../widgets/jadwal_card.dart';
import '../../domain/entities/home_entities.dart';

class HomePage extends StatefulWidget {
  const HomePage({super.key});

  @override
  State<HomePage> createState() => _HomePageState();
}

class _HomePageState extends State<HomePage> {
  @override
  void initState() {
    super.initState();
    context.read<HomeBloc>().add(LoadHomeData());
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: RefreshIndicator(
        onRefresh: () async {
          context.read<HomeBloc>().add(RefreshHomeData());
        },
        child: BlocBuilder<HomeBloc, HomeState>(
          builder: (context, state) {
            if (state is HomeLoading) return const AppLoading();
            if (state is HomeLoaded) {
              return _buildContent(state);
            }
            return const Center(child: Text('Tarik ke bawah untuk memuat'));
          },
        ),
      ),
    );
  }

  Widget _buildContent(HomeLoaded state) {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        _buildGreeting(),
        const SizedBox(height: 20),
        _buildSummaryCards(state),
        const SizedBox(height: 20),
        _buildAlphaProgress(state),
        const SizedBox(height: 20),
        _buildJadwalSection(state),
        const SizedBox(height: 20),
        _buildNotifikasiSection(state),
      ],
    );
  }

  Widget _buildGreeting() {
    final user = (context.read<AuthBloc>().state as Authenticated).user;
    final queueService = context.read<OfflineQueueService>();
    final connectivityService = context.read<ConnectivityService>();

    return Column(
      children: [
        if (queueService.pendingCount > 0 ||
            queueService.failedCount > 0 ||
            connectivityService.isSyncing)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Align(
              alignment: Alignment.centerRight,
              child: SyncStatusIndicator(
                queueService: queueService,
                connectivityService: connectivityService,
              ),
            ),
          ),
        Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            gradient: const LinearGradient(
              colors: [AppColors.primary, AppColors.primaryDark],
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
            ),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                '${Formatters.getGreeting()}, ${user.nama}',
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 18,
                  fontWeight: FontWeight.w600,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                'NIM: ${user.nim ?? "-"} | ${user.prodiNama ?? ""} - Kelas ${user.kelas ?? "-"}',
                style: const TextStyle(color: Colors.white70, fontSize: 13),
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildSummaryCards(HomeLoaded state) {
    final summary = state.summary;
    return Row(
      children: [
        _statCard(
          '${summary?.persentaseKehadiran.toStringAsFixed(0) ?? "0"}%',
          'Hadir',
          AppColors.success,
        ),
        const SizedBox(width: 8),
        _statCard('${summary?.totalHadir ?? 0}', 'Hadir', AppColors.primary),
        const SizedBox(width: 8),
        _statCard('${summary?.totalAlpha ?? 0}', 'Alpha', AppColors.danger),
        const SizedBox(width: 8),
        _statCard('${summary?.totalIzin ?? 0}', 'Izin', AppColors.info),
      ],
    );
  }

  Widget _statCard(String value, String label, Color color) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 8),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.1),
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: color.withValues(alpha: 0.3)),
        ),
        child: Column(
          children: [
            Text(
              value,
              style: TextStyle(
                fontSize: 18,
                fontWeight: FontWeight.bold,
                color: color,
              ),
            ),
            const SizedBox(height: 2),
            Text(
              label,
              style: TextStyle(
                fontSize: 11,
                color: color.withValues(alpha: 0.8),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildAlphaProgress(HomeLoaded state) {
    final summary = state.summary;
    if (summary == null) return const SizedBox.shrink();
    final spColor = AppColors.getSpColor(summary.spStatus);
    final remaining = summary.spThreshold - summary.totalAlphaJam;

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Akumulasi Alpha',
            style: TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
          ),
          const SizedBox(height: 12),
          ClipRRect(
            borderRadius: BorderRadius.circular(8),
            child: LinearProgressIndicator(
              value: summary.alphaProgress,
              minHeight: 12,
              backgroundColor: AppColors.border,
              valueColor: AlwaysStoppedAnimation(spColor),
            ),
          ),
          const SizedBox(height: 8),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                '${Formatters.formatAlphaHours(summary.totalAlphaMenit)} / ${summary.spThreshold.toStringAsFixed(0)} jam',
                style: const TextStyle(
                  fontSize: 13,
                  color: AppColors.textSecondary,
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                decoration: BoxDecoration(
                  color: spColor.withValues(alpha: 0.1),
                  borderRadius: BorderRadius.circular(4),
                ),
                child: Text(
                  Formatters.getSpLabel(summary.spStatus),
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: spColor,
                  ),
                ),
              ),
            ],
          ),
          if (remaining > 0)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                'Sisa sebelum ${summary.spStatus == "aman" ? "SP1" : "SP berikutnya"}: ${remaining.toStringAsFixed(1)} jam',
                style: const TextStyle(
                  fontSize: 12,
                  color: AppColors.textMuted,
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _buildJadwalSection(HomeLoaded state) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'Jadwal Hari Ini',
          style: TextStyle(fontWeight: FontWeight.w600, fontSize: 16),
        ),
        const SizedBox(height: 12),
        if (state.jadwalList.isEmpty)
          Container(
            padding: const EdgeInsets.all(24),
            decoration: BoxDecoration(
              color: AppColors.surface,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: AppColors.border),
            ),
            child: const Center(
              child: Text(
                'Tidak ada jadwal hari ini',
                style: TextStyle(color: AppColors.textMuted),
              ),
            ),
          )
        else
          ...state.jadwalList.map((j) => _buildJadwalCard(j)),
      ],
    );
  }

  Widget _buildJadwalCard(JadwalHariIni jadwal) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: JadwalCard(
        jadwal: jadwal,
        onTap: () =>
            Navigator.pushNamed(context, '/attendance', arguments: jadwal),
      ),
    );
  }

  Widget _buildNotifikasiSection(HomeLoaded state) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            const Text(
              'Notifikasi Terbaru',
              style: TextStyle(fontWeight: FontWeight.w600, fontSize: 16),
            ),
            TextButton(
              onPressed: () => Navigator.pushNamed(context, '/notifications'),
              child: const Text('Lihat Semua', style: TextStyle(fontSize: 13)),
            ),
          ],
        ),
        if (state.notifications.isEmpty)
          Container(
            padding: const EdgeInsets.all(24),
            decoration: BoxDecoration(
              color: AppColors.surface,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: AppColors.border),
            ),
            child: const Center(
              child: Text(
                'Tidak ada notifikasi',
                style: TextStyle(color: AppColors.textMuted),
              ),
            ),
          )
        else
          ...state.notifications
              .take(3)
              .map(
                (n) => Container(
                  margin: const EdgeInsets.only(bottom: 8),
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: n.isRead
                        ? AppColors.surface
                        : AppColors.primary.withValues(alpha: 0.05),
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: AppColors.border),
                  ),
                  child: Row(
                    children: [
                      Icon(
                        _getNotifIcon(n.type),
                        size: 20,
                        color: n.isRead
                            ? AppColors.textMuted
                            : AppColors.primary,
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              n.title,
                              style: TextStyle(
                                fontSize: 13,
                                fontWeight: n.isRead
                                    ? FontWeight.normal
                                    : FontWeight.w600,
                              ),
                            ),
                            Text(
                              n.body,
                              style: const TextStyle(
                                fontSize: 12,
                                color: AppColors.textSecondary,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              ),
      ],
    );
  }

  IconData _getNotifIcon(String type) {
    switch (type) {
      case 'sp_warning':
      case 'sp_issued':
        return Icons.warning_amber;
      case 'approval_needed':
      case 'approval_result':
        return Icons.check_circle_outline;
      case 'reminder':
        return Icons.access_time;
      default:
        return Icons.notifications_outlined;
    }
  }
}
