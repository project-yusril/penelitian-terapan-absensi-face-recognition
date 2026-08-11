import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../../core/logging/app_logger.dart';
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
  static final AppLogger _log = AppLogger.tag('History');
  static const int _pageSize = 20;

  final List<dynamic> _history = [];
  final ScrollController _scrollController = ScrollController();
  bool _isLoading = true;
  String? _error;
  int _currentPage = 1;
  bool _hasMore = true;

  @override
  void initState() {
    super.initState();
    // Paginasi dipicu oleh posisi scroll, bukan dari dalam itemBuilder.
    // Versi sebelumnya memanggil _loadHistory() di dalam builder, sehingga
    // permintaan jaringan bisa terpicu berkali-kali pada satu rangkaian
    // build — efek samping saat membangun widget selalu berbahaya.
    _scrollController.addListener(_onScroll);
    _loadHistory();
  }

  @override
  void dispose() {
    _scrollController
      ..removeListener(_onScroll)
      ..dispose();
    super.dispose();
  }

  void _onScroll() {
    if (!_scrollController.hasClients) return;
    final position = _scrollController.position;
    if (position.pixels >= position.maxScrollExtent - 240) {
      _loadHistory();
    }
  }

  Future<void> _loadHistory({bool refresh = false}) async {
    if (refresh) {
      _currentPage = 1;
      _history.clear();
      _hasMore = true;
      _error = null;
    } else if (_isLoading && _history.isNotEmpty) {
      // Cegah permintaan bertumpuk saat scroll cepat.
      return;
    }

    if (!_hasMore) return;

    setState(() => _isLoading = true);

    try {
      final apiClient = context.read<ApiClient>();
      final response = await apiClient.get(
        ApiConstants.attendanceHistoryEndpoint,
        queryParameters: {'page': _currentPage, 'per_page': _pageSize},
      );

      final data = response.data['data'] as List<dynamic>;
      if (!mounted) return;

      setState(() {
        _history.addAll(data);
        _isLoading = false;
        _hasMore = data.length >= _pageSize;
        _currentPage++;
        _error = null;
      });
    } catch (e, stack) {
      _log.error(
        'gagal memuat riwayat absensi',
        data: {'halaman': _currentPage},
        error: e,
        stackTrace: stack,
      );
      if (!mounted) return;
      setState(() {
        _isLoading = false;
        // `$e` kini menghasilkan pesan yang terbaca karena exception domain
        // sudah punya toString(); tidak lagi "Instance of 'ServerException'".
        _error = '$e';
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
              : _history.isEmpty
              ? _emptyState()
              : ListView.builder(
                  controller: _scrollController,
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.all(16),
                  itemCount: _history.length + (_hasMore ? 1 : 0),
                  itemBuilder: (context, index) {
                    if (index == _history.length) {
                      // Hanya indikator; pemuatannya dipicu oleh listener
                      // scroll, bukan oleh proses build ini.
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

  /// Daftar kosong sebelumnya merender ListView tanpa isi — layar putih polos
  /// yang tidak bisa dibedakan dari kegagalan memuat.
  Widget _emptyState() {
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.symmetric(horizontal: 32, vertical: 90),
      children: const [
        Icon(Icons.history_toggle_off, size: 52, color: AppColors.textMuted),
        SizedBox(height: 14),
        Text(
          'Belum ada riwayat absensi',
          textAlign: TextAlign.center,
          style: TextStyle(
            fontSize: 16,
            fontWeight: FontWeight.w600,
            color: AppColors.textPrimary,
          ),
        ),
        SizedBox(height: 6),
        Text(
          'Riwayat akan muncul di sini setelah Anda melakukan check-in.',
          textAlign: TextAlign.center,
          style: TextStyle(fontSize: 13, color: AppColors.textSecondary),
        ),
      ],
    );
  }

  Widget _timeChip(IconData icon, String label, String value, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 12, color: color),
          const SizedBox(width: 5),
          Text(
            '$label $value',
            style: TextStyle(
              fontSize: 11.5,
              fontWeight: FontWeight.w600,
              color: color,
            ),
          ),
        ],
      ),
    );
  }

  /// Ambil teks dari field yang bentuknya bisa String ATAU objek relasi.
  ///
  /// Endpoint riwayat mengembalikan model `Attendance` mentah dengan relasi
  /// `mataKuliah` ter-eager-load, sehingga `mata_kuliah` datang sebagai objek
  /// `{id, kode_mk, nama}` — bukan String. Menyerahkannya langsung ke `Text`
  /// melempar `type '_Map<String, dynamic>' is not a subtype of type 'String'`
  /// saat build, dan itu membuat SETIAP baris riwayat gagal dirender.
  static String _label(dynamic value, {String fallback = '-'}) {
    if (value == null) return fallback;
    if (value is String) return value.trim().isEmpty ? fallback : value;
    if (value is Map) {
      final nama = value['nama'] ?? value['name'] ?? value['kode_mk'];
      return nama is String && nama.trim().isNotEmpty ? nama : fallback;
    }
    return value.toString();
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
                  _label(item['mata_kuliah']),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontWeight: FontWeight.w600,
                    fontSize: 14,
                  ),
                ),
              ),
              const SizedBox(width: 8),
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
            // `tanggal` juga datang sebagai ISO penuh dari model mentah.
            Formatters.formatDateFromIso(item['tanggal'] as String?),
            style: const TextStyle(fontSize: 12, color: AppColors.textMuted),
          ),
          const SizedBox(height: 6),
          // Backend mengirim timestamp ISO UTC. Menampilkannya mentah bukan
          // hanya jelek, tapi juga salah jam bagi user.
          if (checkinTime != null)
            Row(
              children: [
                _timeChip(
                  Icons.login,
                  'Masuk',
                  Formatters.formatClockFromIso(checkinTime as String?),
                  AppColors.success,
                ),
                const SizedBox(width: 8),
                _timeChip(
                  Icons.logout,
                  'Pulang',
                  checkoutTime == null
                      ? '—'
                      : Formatters.formatClockFromIso(checkoutTime as String?),
                  checkoutTime == null
                      ? AppColors.textMuted
                      : AppColors.primary,
                ),
              ],
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
