import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../../core/offline/offline_queue_service.dart';
import '../../../../core/network/connectivity_service.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/widgets/app_loading.dart';
import '../../../../core/widgets/sync_status_indicator.dart';
import '../../../auth/presentation/bloc/auth_bloc.dart';
import '../../../auth/presentation/bloc/auth_event.dart';
import '../../../home/presentation/bloc/home_bloc.dart';
import '../../../home/presentation/bloc/home_event.dart';
import '../../../home/presentation/bloc/home_state.dart';
import '../../../home/presentation/widgets/jadwal_card.dart';
import '../../../home/presentation/pages/home_page.dart';

import '../../../history/presentation/pages/history_page.dart';
import '../../../leave_request/presentation/pages/leave_page.dart';
import '../../../profile/presentation/pages/profile_page.dart';

class MainShell extends StatefulWidget {
  const MainShell({super.key});

  @override
  State<MainShell> createState() => _MainShellState();
}

class _MainShellState extends State<MainShell> {
  int _currentIndex = 0;

  final List<Widget> _pages = const [
    HomePage(),
    _AttendanceTab(),
    HistoryPage(),
    LeavePage(),
    ProfilePage(),
  ];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: IndexedStack(index: _currentIndex, children: _pages),
      bottomNavigationBar: BottomNavigationBar(
        // 5 tab: tanpa `fixed`, BottomNavigationBar memakai mode `shifting`
        // yang menyembunyikan label tab tidak aktif. `fixed` menjaga semua
        // label (Beranda/Absensi/Riwayat/Izin/Profil) tetap terlihat.
        type: BottomNavigationBarType.fixed,
        currentIndex: _currentIndex,
        onTap: (index) {
          setState(() => _currentIndex = index);
          // Refresh data user tiap pindah tab agar status (mis. enrollment)
          // selalu terbaru di semua halaman.
          context.read<AuthBloc>().add(GetCurrentUserData());
        },

        items: const [
          BottomNavigationBarItem(
            icon: Icon(Icons.home_outlined),
            activeIcon: Icon(Icons.home),
            label: 'Beranda',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.qr_code_scanner),
            activeIcon: Icon(Icons.qr_code_scanner),
            label: 'Absensi',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.history),
            activeIcon: Icon(Icons.history),
            label: 'Riwayat',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.event_note_outlined),
            activeIcon: Icon(Icons.event_note),
            label: 'Izin',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.person_outline),
            activeIcon: Icon(Icons.person),
            label: 'Profil',
          ),
        ],
      ),
    );
  }
}

class _AttendanceTab extends StatelessWidget {
  const _AttendanceTab();

  @override
  Widget build(BuildContext context) {
    return const _AttendanceScheduleList();
  }
}

class _AttendanceScheduleList extends StatefulWidget {
  const _AttendanceScheduleList();

  @override
  State<_AttendanceScheduleList> createState() =>
      _AttendanceScheduleListState();
}

class _AttendanceScheduleListState extends State<_AttendanceScheduleList> {
  @override
  Widget build(BuildContext context) {
    final queueService = context.read<OfflineQueueService>();
    final connectivityService = context.read<ConnectivityService>();

    return Scaffold(
      appBar: AppBar(
        title: const Text('Absensi'),
        actions: [
          Padding(
            padding: const EdgeInsets.only(right: 12),
            child: SyncStatusIndicator(
              queueService: queueService,
              connectivityService: connectivityService,
            ),
          ),
        ],
      ),
      // Tab ini sebelumnya hanya menampilkan teks yang menyuruh user kembali
      // ke Beranda — sebuah tab mati yang tetap memakan satu slot navigasi.
      // Sekarang ia memakai HomeBloc yang sama, jadi jadwal hari ini bisa
      // langsung ditindaklanjuti dari sini tanpa duplikasi sumber data.
      body: RefreshIndicator(
        onRefresh: () async {
          context.read<AuthBloc>().add(GetCurrentUserData());
          context.read<HomeBloc>().add(RefreshHomeData());
          await Future.delayed(const Duration(milliseconds: 600));
        },
        child: BlocBuilder<HomeBloc, HomeState>(
          builder: (context, state) {
            if (state is HomeLoading || state is HomeInitial) {
              return const AppLoading();
            }
            if (state is HomeError) {
              return _message(
                icon: Icons.cloud_off_outlined,
                title: 'Gagal memuat jadwal',
                detail: state.message,
                onRetry: () => context.read<HomeBloc>().add(LoadHomeData()),
              );
            }
            if (state is! HomeLoaded) {
              return const AppLoading();
            }

            final jadwals = state.jadwalList;
            if (jadwals.isEmpty) {
              return _message(
                icon: Icons.event_available_outlined,
                title: 'Tidak ada jadwal hari ini',
                detail: 'Absensi hanya tersedia pada hari kuliah terjadwal.',
              );
            }

            final actionable = jadwals.where((j) => j.canOpenAttendance).length;

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(16),
              children: [
                _banner(actionable),
                const SizedBox(height: 14),
                ...jadwals.map(
                  (jadwal) => Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: JadwalCard(
                      jadwal: jadwal,
                      onTap: () => Navigator.pushNamed(
                        context,
                        '/attendance',
                        arguments: jadwal,
                      ),
                    ),
                  ),
                ),
              ],
            );
          },
        ),
      ),
    );
  }

  /// Ringkasan berapa jadwal yang benar-benar bisa diabsen sekarang, supaya
  /// user tidak menebak-nebak kartu mana yang bisa ditekan.
  Widget _banner(int actionable) {
    final ready = actionable > 0;
    final color = ready ? AppColors.primary : AppColors.textMuted;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: color.withValues(alpha: 0.35)),
      ),
      child: Row(
        children: [
          Icon(
            ready ? Icons.how_to_reg_outlined : Icons.schedule,
            color: color,
            size: 20,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              ready
                  ? '$actionable jadwal siap diabsen. Ketuk kartunya untuk mulai.'
                  : 'Belum ada jadwal yang bisa diabsen saat ini.',
              style: TextStyle(fontSize: 12.5, color: color),
            ),
          ),
        ],
      ),
    );
  }

  Widget _message({
    required IconData icon,
    required String title,
    required String detail,
    VoidCallback? onRetry,
  }) {
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.symmetric(horizontal: 32, vertical: 90),
      children: [
        Icon(icon, size: 52, color: AppColors.textMuted),
        const SizedBox(height: 14),
        Text(
          title,
          textAlign: TextAlign.center,
          style: const TextStyle(
            fontSize: 16,
            fontWeight: FontWeight.w600,
            color: AppColors.textPrimary,
          ),
        ),
        const SizedBox(height: 6),
        Text(
          detail,
          textAlign: TextAlign.center,
          style: const TextStyle(fontSize: 13, color: AppColors.textSecondary),
        ),
        if (onRetry != null) ...[
          const SizedBox(height: 18),
          Center(
            child: FilledButton(
              onPressed: onRetry,
              child: const Text('Coba Lagi'),
            ),
          ),
        ],
      ],
    );
  }
}
