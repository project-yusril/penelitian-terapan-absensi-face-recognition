import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../../core/offline/offline_queue_service.dart';
import '../../../../core/network/connectivity_service.dart';
import '../../../../core/widgets/sync_status_indicator.dart';
import '../../../auth/presentation/bloc/auth_bloc.dart';
import '../../../auth/presentation/bloc/auth_event.dart';
import '../../../home/presentation/pages/home_page.dart';

import '../../../history/presentation/pages/history_page.dart';
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
    ProfilePage(),
  ];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: IndexedStack(index: _currentIndex, children: _pages),
      bottomNavigationBar: BottomNavigationBar(
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
      body: RefreshIndicator(
        onRefresh: () async {
          context.read<AuthBloc>().add(GetCurrentUserData());
          await Future.delayed(const Duration(milliseconds: 600));
        },
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          children: const [
            SizedBox(height: 120),
            Center(
              child: Padding(
                padding: EdgeInsets.symmetric(horizontal: 32),
                child: Text(
                  'Pilih mata kuliah dari jadwal di Beranda untuk absensi.\n\nTarik ke bawah untuk memuat ulang.',
                  textAlign: TextAlign.center,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
