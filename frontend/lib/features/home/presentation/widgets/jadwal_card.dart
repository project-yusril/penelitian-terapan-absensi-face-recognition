import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/utils/formatters.dart';
import '../../domain/entities/home_entities.dart';

/// Kartu satu jadwal kuliah beserta status absensinya.
///
/// Tata letaknya menempatkan status pada barisnya sendiri, BUKAN bersaing
/// horizontal dengan judul. Versi sebelumnya memakai
/// `Row(children: [bar, Expanded(konten), badge])`, dan karena badge tidak
/// dibatasi lebarnya, `Expanded` hanya kebagian sisa ruang. Begitu teks status
/// memanjang, judul terjepit sampai selebar beberapa huruf dan pecah menurun
/// satu karakter per baris.
///
/// Menaruh status di baris terpisah membuat lebar judul tidak lagi bergantung
/// pada panjang teks status, sehingga tata letaknya aman untuk teks apa pun.
class JadwalCard extends StatelessWidget {
  final JadwalHariIni jadwal;
  final VoidCallback? onTap;

  const JadwalCard({super.key, required this.jadwal, this.onTap});

  /// Urutan pemeriksaan penting: keadaan yang lebih spesifik didahulukan,
  /// dan "Belum dimulai" hanya menjadi cadangan terakhir untuk jadwal yang
  /// memang belum masuk jendela absensinya.
  _JadwalVisual get _visual {
    if (jadwal.isCheckedIn && jadwal.isCheckedOut) {
      return _JadwalVisual(
        color: AppColors.textMuted,
        icon: Icons.task_alt,
        label: 'Selesai',
        detail: _range(jadwal.checkinTime, jadwal.checkoutTime),
      );
    }
    if (jadwal.isCheckedIn) {
      return _JadwalVisual(
        color: AppColors.success,
        icon: Icons.login,
        label: 'Check-in',
        detail: Formatters.formatClockFromIso(jadwal.checkinTime),
      );
    }
    if (jadwal.isExcused) {
      return _JadwalVisual(
        color: AppColors.info,
        icon: Icons.event_busy_outlined,
        label: jadwal.attendanceStatus == 'sakit' ? 'Sakit' : 'Izin',
      );
    }
    if (jadwal.isMissed) {
      // Alpha yang sudah tercatat backend vs jam yang baru saja terlewat.
      // Keduanya sama-sama tidak hadir, tapi hanya yang pertama sudah pasti
      // terhitung sebagai alpha di rekap.
      return _JadwalVisual(
        color: AppColors.danger,
        icon: Icons.event_busy,
        label: jadwal.attendanceStatus == 'alpha' ? 'Alpha' : 'Terlewat',
        detail: jadwal.attendanceStatus == 'alpha'
            ? null
            : 'tidak absen',
      );
    }
    if (jadwal.isOngoing) {
      return const _JadwalVisual(
        color: AppColors.warning,
        icon: Icons.play_circle_outline,
        label: 'Sedang berlangsung',
      );
    }
    return const _JadwalVisual(
      color: AppColors.textMuted,
      icon: Icons.schedule,
      label: 'Belum dimulai',
    );
  }

  static String _range(String? from, String? to) =>
      '${Formatters.formatClockFromIso(from)} – '
      '${Formatters.formatClockFromIso(to)}';

  @override
  Widget build(BuildContext context) {
    final visual = _visual;
    final actionable = jadwal.canOpenAttendance;

    return Semantics(
      button: actionable,
      label: jadwal.canCheckOut
          ? 'Check-out ${jadwal.mataKuliah}'
          : 'Check-in ${jadwal.mataKuliah}',
      child: Material(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(14),
        child: InkWell(
          key: ValueKey('attendance-action-${jadwal.jadwalId}'),
          borderRadius: BorderRadius.circular(14),
          onTap: actionable ? onTap : null,
          child: Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(14),
              border: Border.all(
                color: actionable ? AppColors.primary : AppColors.border,
                width: actionable ? 1.4 : 1,
              ),
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 4,
                  height: 56,
                  decoration: BoxDecoration(
                    color: visual.color,
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        jadwal.mataKuliah,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontWeight: FontWeight.w600,
                          fontSize: 15,
                          color: AppColors.textPrimary,
                        ),
                      ),
                      const SizedBox(height: 6),
                      _metaRow(
                        Icons.access_time,
                        '${Formatters.trimSeconds(jadwal.jamMulai)} – '
                        '${Formatters.trimSeconds(jadwal.jamSelesai)}',
                      ),
                      const SizedBox(height: 2),
                      _metaRow(Icons.place_outlined, jadwal.ruangan),
                      const SizedBox(height: 2),
                      _metaRow(Icons.person_outline, jadwal.dosen),
                      const SizedBox(height: 10),
                      _statusChip(visual),
                    ],
                  ),
                ),
                if (actionable) ...[
                  const SizedBox(width: 8),
                  const Icon(
                    Icons.chevron_right,
                    color: AppColors.primary,
                    size: 22,
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _metaRow(IconData icon, String text) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, size: 13, color: AppColors.textMuted),
        const SizedBox(width: 5),
        Expanded(
          child: Text(
            text,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(
              fontSize: 12.5,
              color: AppColors.textSecondary,
            ),
          ),
        ),
      ],
    );
  }

  Widget _statusChip(_JadwalVisual visual) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
      decoration: BoxDecoration(
        color: visual.color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(visual.icon, size: 13, color: visual.color),
          const SizedBox(width: 5),
          Flexible(
            child: Text(
              visual.detail == null
                  ? visual.label
                  : '${visual.label} ${visual.detail}',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                fontSize: 11.5,
                color: visual.color,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _JadwalVisual {
  final Color color;
  final IconData icon;
  final String label;
  final String? detail;

  const _JadwalVisual({
    required this.color,
    required this.icon,
    required this.label,
    this.detail,
  });
}
