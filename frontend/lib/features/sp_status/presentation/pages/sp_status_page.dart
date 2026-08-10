import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/utils/formatters.dart';
import '../../../../core/widgets/app_loading.dart';
import '../../domain/entities/sp_entity.dart';
import '../bloc/sp_bloc.dart';
import '../bloc/sp_event.dart';
import '../bloc/sp_state.dart';

class SpStatusPage extends StatefulWidget {
  const SpStatusPage({super.key});

  @override
  State<SpStatusPage> createState() => _SpStatusPageState();
}

class _SpStatusPageState extends State<SpStatusPage> {
  @override
  void initState() {
    super.initState();
    context.read<SpBloc>().add(LoadMySpRecords());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Status SP'),
        centerTitle: true,
        backgroundColor: AppColors.surface,
        foregroundColor: AppColors.textPrimary,
        elevation: 0,
      ),
      body: RefreshIndicator(
        onRefresh: () async {
          context.read<SpBloc>().add(RefreshMySpRecords());
        },
        child: BlocBuilder<SpBloc, SpState>(
          builder: (context, state) {
            if (state is SpLoading) return const AppLoading();
            if (state is SpError) {
              return AppErrorWidget(
                message: state.message,
                onRetry: () => context.read<SpBloc>().add(LoadMySpRecords()),
              );
            }
            if (state is SpLoaded) return _buildContent(state);
            return const Center(child: Text('Tarik ke bawah untuk memuat'));
          },
        ),
      ),
    );
  }

  Widget _buildContent(SpLoaded state) {
    if (state.records.isEmpty) {
      return ListView(
        children: [
          const SizedBox(height: 40),
          _buildCurrentStatusCard('aman', 0, 16),
          const SizedBox(height: 24),
          const AppEmptyState(
            title: 'Belum ada catatan SP',
            subtitle: 'Anda dalam status aman. Pertahankan kehadiran Anda.',
            icon: Icons.check_circle_outline,
          ),
        ],
      );
    }

    final activeRecords = state.records.where((r) => !r.isCancelled).toList()
      ..sort((a, b) => b.id.compareTo(a.id));
    final currentLevel = state.currentSpLevel;
    final latestRecord = activeRecords.isNotEmpty ? activeRecords.first : null;

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        _buildCurrentStatusCard(
          currentLevel,
          latestRecord?.totalAlphaJam ?? 0,
          _getThreshold(currentLevel),
        ),
        const SizedBox(height: 20),
        _buildRecordsList(state.records),
      ],
    );
  }

  int _getThreshold(String spLevel) {
    switch (spLevel) {
      case 'sp1':
        return 16;
      case 'sp2':
        return 32;
      case 'sp3':
        return 48;
      case 'do':
        return 64;
      default:
        return 16;
    }
  }

  Widget _buildCurrentStatusCard(
    String spLevel,
    int currentAlphaJam,
    int threshold,
  ) {
    final color = AppColors.getSpColor(spLevel);
    final label = Formatters.getSpLabel(spLevel);
    final progress = threshold > 0
        ? (currentAlphaJam / threshold).clamp(0.0, 1.0)
        : 0.0;

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [color, color.withValues(alpha: 0.7)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(16),
        boxShadow: [
          BoxShadow(
            color: color.withValues(alpha: 0.3),
            blurRadius: 12,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              const Text(
                'Status SP Saat Ini',
                style: TextStyle(color: Colors.white70, fontSize: 13),
              ),
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 12,
                  vertical: 4,
                ),
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: 0.2),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text(
                  label,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 14,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),
          Text(
            spLevel == 'aman' ? 'Anda dalam status aman' : 'Peringatan: $label',
            style: const TextStyle(
              color: Colors.white,
              fontSize: 20,
              fontWeight: FontWeight.bold,
            ),
          ),
          const SizedBox(height: 16),
          ClipRRect(
            borderRadius: BorderRadius.circular(8),
            child: LinearProgressIndicator(
              value: progress,
              minHeight: 10,
              backgroundColor: Colors.white.withValues(alpha: 0.3),
              valueColor: const AlwaysStoppedAnimation(Colors.white),
            ),
          ),
          const SizedBox(height: 8),
          Text(
            '${Formatters.formatAlphaHours(currentAlphaJam * 60)} / $threshold jam',
            style: const TextStyle(color: Colors.white70, fontSize: 13),
          ),
        ],
      ),
    );
  }

  Widget _buildRecordsList(List<SpRecord> records) {
    final sortedRecords = List<SpRecord>.from(records)
      ..sort((a, b) => b.id.compareTo(a.id));

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'Riwayat SP',
          style: TextStyle(fontWeight: FontWeight.w600, fontSize: 16),
        ),
        const SizedBox(height: 12),
        ...sortedRecords.map((r) => _buildRecordCard(r)),
      ],
    );
  }

  Widget _buildRecordCard(SpRecord record) {
    final spColor = AppColors.getSpColor(record.spLevel);
    final statusColor = _getStatusColor(record.status);

    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 10,
                  vertical: 4,
                ),
                decoration: BoxDecoration(
                  color: spColor.withValues(alpha: 0.1),
                  borderRadius: BorderRadius.circular(6),
                  border: Border.all(color: spColor.withValues(alpha: 0.3)),
                ),
                child: Text(
                  record.spLevelLabel,
                  style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.bold,
                    color: spColor,
                  ),
                ),
              ),
              const SizedBox(width: 8),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color: statusColor.withValues(alpha: 0.1),
                  borderRadius: BorderRadius.circular(4),
                ),
                child: Text(
                  record.statusLabel,
                  style: TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.w500,
                    color: statusColor,
                  ),
                ),
              ),
              const Spacer(),
              Text(
                record.nomorSurat,
                style: const TextStyle(
                  fontSize: 11,
                  color: AppColors.textMuted,
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          _buildInfoRow(
            'Tanggal Terbit',
            Formatters.formatDate(DateTime.parse(record.tanggalTerbit)),
          ),
          _buildInfoRow('Total Alpha', '${record.totalAlphaJam} jam'),
          _buildInfoRow('Rincian', record.rincianAlpha),
          const SizedBox(height: 12),
          _buildStatusFlow(record),
          if (record.hasDocument && record.isFinal) ...[
            const SizedBox(height: 12),
            SizedBox(
              width: double.infinity,
              child: OutlinedButton.icon(
                onPressed: () {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(
                      content: Text('Fitur download PDF segera hadir'),
                    ),
                  );
                },
                icon: const Icon(Icons.download, size: 18),
                label: const Text('Download PDF'),
                style: OutlinedButton.styleFrom(
                  foregroundColor: AppColors.primary,
                  side: const BorderSide(color: AppColors.primary),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(8),
                  ),
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _buildInfoRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 100,
            child: Text(
              label,
              style: const TextStyle(fontSize: 12, color: AppColors.textMuted),
            ),
          ),
          Expanded(
            child: Text(
              value,
              style: const TextStyle(
                fontSize: 12,
                color: AppColors.textPrimary,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildStatusFlow(SpRecord record) {
    final steps = [
      _FlowStep('Draft', Icons.edit_note, true),
      _FlowStep('Kaprodi', Icons.person, record.signedKaprodiAt != null),
      _FlowStep('Kajur', Icons.person_outline, record.signedKajurAt != null),
      _FlowStep('Final', Icons.check_circle, record.isFinal),
    ];

    return Row(
      children: steps.asMap().entries.expand((entry) {
        final index = entry.key;
        final step = entry.value;
        final items = <Widget>[
          Column(
            children: [
              Icon(
                step.icon,
                size: 20,
                color: step.completed ? AppColors.success : AppColors.textMuted,
              ),
              const SizedBox(height: 2),
              Text(
                step.label,
                style: TextStyle(
                  fontSize: 10,
                  color: step.completed
                      ? AppColors.success
                      : AppColors.textMuted,
                  fontWeight: step.completed
                      ? FontWeight.w600
                      : FontWeight.normal,
                ),
              ),
            ],
          ),
        ];
        if (index < steps.length - 1) {
          items.add(
            Expanded(
              child: Container(
                height: 2,
                margin: const EdgeInsets.symmetric(horizontal: 4),
                color: steps[index + 1].completed
                    ? AppColors.success
                    : AppColors.border,
              ),
            ),
          );
        }
        return items;
      }).toList(),
    );
  }

  Color _getStatusColor(String status) {
    switch (status) {
      case 'draft':
        return AppColors.textMuted;
      case 'menunggu_kaprodi':
      case 'menunggu_kajur':
        return AppColors.warning;
      case 'final':
        return AppColors.success;
      case 'dibatalkan':
        return AppColors.danger;
      default:
        return AppColors.textMuted;
    }
  }
}

class _FlowStep {
  final String label;
  final IconData icon;
  final bool completed;

  _FlowStep(this.label, this.icon, this.completed);
}
