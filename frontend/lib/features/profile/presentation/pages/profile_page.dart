import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../auth/presentation/bloc/auth_bloc.dart';
import '../../../auth/presentation/bloc/auth_event.dart';
import '../../../auth/presentation/bloc/auth_state.dart';

class ProfilePage extends StatelessWidget {
  const ProfilePage({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<AuthBloc, AuthState>(
      builder: (context, state) {
        if (state is! Authenticated) {
          return const Center(child: Text('Memuat profil...'));
        }
        final user = state.user;

        // Prioritas foto: enrollment (signed URL) → foto profil → inisial.
        final fotoUrl = (user.fotoEnrollmentUrl?.isNotEmpty ?? false)
            ? user.fotoEnrollmentUrl
            : (user.fotoProfil?.isNotEmpty ?? false)
            ? user.fotoProfil
            : null;

        return SafeArea(
          child: RefreshIndicator(
            onRefresh: () async {
              context.read<AuthBloc>().add(GetCurrentUserData());
              // beri jeda agar indikator refresh terlihat sebentar
              await Future.delayed(const Duration(milliseconds: 600));
            },
            child: ListView(
              padding: const EdgeInsets.all(16),
              physics: const AlwaysScrollableScrollPhysics(),
              children: [
                // Profile header
                Container(
                  padding: const EdgeInsets.all(20),
                  decoration: BoxDecoration(
                    color: AppColors.surface,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: AppColors.border),
                  ),
                  child: Column(
                    children: [
                      CircleAvatar(
                        radius: 40,
                        backgroundColor: AppColors.primary.withValues(
                          alpha: 0.1,
                        ),
                        backgroundImage: fotoUrl != null
                            ? NetworkImage(fotoUrl)
                            : null,
                        child: fotoUrl != null
                            ? null
                            : Text(
                                user.nama.isNotEmpty
                                    ? user.nama[0].toUpperCase()
                                    : '?',
                                style: const TextStyle(
                                  fontSize: 32,
                                  fontWeight: FontWeight.bold,
                                  color: AppColors.primary,
                                ),
                              ),
                      ),

                      const SizedBox(height: 12),
                      Text(
                        user.nama,
                        style: const TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        user.identifier,
                        style: const TextStyle(
                          color: AppColors.textSecondary,
                          fontSize: 14,
                        ),
                      ),
                      if (user.prodiNama != null)
                        Padding(
                          padding: const EdgeInsets.only(top: 4),
                          child: Text(
                            '${user.prodiNama} ${user.kelas != null ? "- Kelas ${user.kelas}" : ""}',
                            style: const TextStyle(
                              color: AppColors.textMuted,
                              fontSize: 13,
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),

                // Enrollment status
                if (user.isMahasiswa) ...[
                  _buildMenuCard(
                    icon: Icons.face,
                    title: 'Enrollment Wajah',
                    subtitle:
                        'Status: ${_getEnrollmentLabel(user.enrollmentStatus)}',
                    trailing: Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 8,
                        vertical: 2,
                      ),
                      decoration: BoxDecoration(
                        color: _getEnrollmentColor(
                          user.enrollmentStatus,
                        ).withValues(alpha: 0.1),
                        borderRadius: BorderRadius.circular(4),
                      ),
                      child: Text(
                        _getEnrollmentLabel(user.enrollmentStatus),
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                          color: _getEnrollmentColor(user.enrollmentStatus),
                        ),
                      ),
                    ),
                    // approved → tidak boleh daftar ulang; tampilkan popup
                    // info + opsi re-enrollment (butuh persetujuan admin).
                    // belum/rejected → langsung ke halaman enrollment.
                    onTap: user.enrollmentStatus == 'approved'
                        ? () => _showApprovedDialog(context)
                        : user.enrollmentStatus == 'pending'
                        ? null
                        : () => Navigator.pushNamed(context, '/enrollment'),
                  ),
                  const SizedBox(height: 8),
                ],

                // Menu items
                _buildMenuCard(
                  icon: Icons.lock_outline,
                  title: 'Ubah Password',
                  onTap: () => Navigator.pushNamed(context, '/change-password'),
                ),
                const SizedBox(height: 8),
                _buildMenuCard(
                  icon: Icons.info_outline,
                  title: 'Tentang Aplikasi',
                  subtitle: 'Versi 1.0.0',
                  onTap: () => _showAboutDialog(context),
                ),
                const SizedBox(height: 24),

                // Logout button
                ElevatedButton.icon(
                  onPressed: () {
                    showDialog(
                      context: context,
                      builder: (ctx) => AlertDialog(
                        title: const Text('Logout'),
                        content: const Text('Anda yakin ingin keluar?'),
                        actions: [
                          TextButton(
                            onPressed: () => Navigator.pop(ctx),
                            child: const Text('Batal'),
                          ),
                          ElevatedButton(
                            onPressed: () {
                              Navigator.pop(ctx);
                              context.read<AuthBloc>().add(LogoutRequested());
                            },
                            style: ElevatedButton.styleFrom(
                              backgroundColor: AppColors.danger,
                            ),
                            child: const Text('Logout'),
                          ),
                        ],
                      ),
                    );
                  },
                  icon: const Icon(Icons.logout),
                  label: const Text('Logout'),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AppColors.danger,
                    minimumSize: const Size(double.infinity, 48),
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _buildMenuCard({
    required IconData icon,
    required String title,
    String? subtitle,
    Widget? trailing,
    VoidCallback? onTap,
  }) {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.border),
      ),
      child: ListTile(
        leading: Icon(icon, color: AppColors.primary),
        title: Text(
          title,
          style: const TextStyle(fontWeight: FontWeight.w500, fontSize: 14),
        ),
        subtitle: subtitle != null
            ? Text(subtitle, style: const TextStyle(fontSize: 12))
            : null,
        trailing:
            trailing ??
            const Icon(Icons.chevron_right, color: AppColors.textMuted),
        onTap: onTap,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      ),
    );
  }

  String _getEnrollmentLabel(String status) {
    switch (status) {
      case 'approved':
        return 'Disetujui';
      case 'pending':
        return 'Menunggu';
      case 'rejected':
        return 'Ditolak';
      default:
        return 'Belum';
    }
  }

  Color _getEnrollmentColor(String status) {
    switch (status) {
      case 'approved':
        return AppColors.success;
      case 'pending':
        return AppColors.warning;
      case 'rejected':
        return AppColors.danger;
      default:
        return AppColors.textMuted;
    }
  }

  /// Popup saat enrollment sudah disetujui: info + opsi minta re-enrollment.
  void _showApprovedDialog(BuildContext context) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Enrollment Wajah Disetujui'),
        content: const Text(
          'Wajah Anda sudah terdaftar & disetujui. Tidak perlu mendaftar ulang.\n\n'
          'Jika ingin mengubah data wajah (mis. perubahan penampilan), '
          'silakan hubungi admin untuk membuka akses re-enrollment. '
          'Setelah disetujui, Anda dapat mendaftarkan wajah baru, dan data '
          'absensi sebelumnya tetap tersimpan pada NIM Anda.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Mengerti'),
          ),
        ],
      ),
    );
  }

  void _showAboutDialog(BuildContext context) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Tentang Aplikasi'),
        content: const Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Sistem Absensi Mahasiswa',
              style: TextStyle(fontWeight: FontWeight.w600),
            ),
            SizedBox(height: 4),
            Text('Politeknik Negeri Pontianak'),
            Text('Jurusan Teknik Elektro'),
            SizedBox(height: 8),
            Text('Versi 1.0.0', style: TextStyle(color: AppColors.textMuted)),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Tutup'),
          ),
        ],
      ),
    );
  }
}
