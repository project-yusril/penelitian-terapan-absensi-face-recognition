import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/utils/formatters.dart';
import '../../../../core/network/api_client.dart';
import '../../../../core/constants/api_constants.dart';
import '../../../../core/widgets/app_loading.dart';

class HistoryPage extends StatefulWidget {
  const HistoryPage({super.key});

  @override
  State<HistoryPage> createState() => _HistoryPageState();
}

class _HistoryPageState extends State<HistoryPage> {
  final List<dynamic> _history = [];
  bool _isLoading = true;
  String? _error;
  int _currentPage = 1;
  bool _hasMore = true;

  @override
  void initState() {
    super.initState();
    _loadHistory();
  }

  Future<void> _loadHistory({bool refresh = false}) async {
    if (refresh) {
      _currentPage = 1;
      _history.clear();
      _hasMore = true;
    }

    if (!_hasMore) return;

    setState(() => _isLoading = true);

    try {
      final apiClient = context.read<ApiClient>();
      final response = await apiClient.get(
        ApiConstants.attendanceHistoryEndpoint,
        queryParameters: {'page': _currentPage, 'per_page': 20},
      );

      final data = response.data['data'] as List<dynamic>;

      setState(() {
        _history.addAll(data);
        _isLoading = false;
        _hasMore = data.length >= 20;
        _currentPage++;
      });
    } catch (e) {
      setState(() {
        _isLoading = false;
        _error = e.toString();
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Riwayat Absensi')),
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: () => _loadHistory(refresh: true),
          child: _isLoading && _history.isEmpty
              ? const AppLoading()
              : _error != null && _history.isEmpty
              ? Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        _error!,
                        style: const TextStyle(color: AppColors.danger),
                      ),
                      const SizedBox(height: 16),
                      ElevatedButton(
                        onPressed: () => _loadHistory(refresh: true),
                        child: const Text('Coba Lagi'),
                      ),
                    ],
                  ),
                )
              : ListView.builder(
                  padding: const EdgeInsets.all(16),
                  itemCount: _history.length + (_hasMore ? 1 : 0),
                  itemBuilder: (context, index) {
                    if (index == _history.length) {
                      _loadHistory();
                      return const Padding(
                        padding: EdgeInsets.all(16),
                        child: Center(child: CircularProgressIndicator()),
                      );
                    }
                    return _buildHistoryCard(_history[index]);
                  },
                ),
        ),
      ),
    );
  }

  Widget _buildHistoryCard(Map<String, dynamic> item) {
    final status = item['status'] ?? 'alpha';
    final statusColor = AppColors.getStatusColor(status);
    final checkinTime = item['checkin_time'];
    final checkoutTime = item['checkout_time'];
    final alphaMenit = item['alpha_menit'] ?? 0;

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Expanded(
                child: Text(
                  item['mata_kuliah'] ?? '-',
                  style: const TextStyle(
                    fontWeight: FontWeight.w600,
                    fontSize: 14,
                  ),
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                decoration: BoxDecoration(
                  color: statusColor.withValues(alpha: 0.1),
                  borderRadius: BorderRadius.circular(4),
                ),
                child: Text(
                  Formatters.getStatusLabel(status),
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: statusColor,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            item['tanggal'] ?? '-',
            style: const TextStyle(fontSize: 12, color: AppColors.textMuted),
          ),
          const SizedBox(height: 4),
          if (checkinTime != null)
            Text(
              'Check-in: $checkinTime${checkoutTime != null ? " | Check-out: $checkoutTime" : ""}',
              style: const TextStyle(
                fontSize: 12,
                color: AppColors.textSecondary,
              ),
            ),
          if (alphaMenit > 0)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                'Alpha: ${Formatters.formatDuration(alphaMenit)}',
                style: const TextStyle(fontSize: 12, color: AppColors.danger),
              ),
            ),
        ],
      ),
    );
  }
}
