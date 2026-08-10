import 'package:flutter/material.dart';

class AppColors {
  static const Color primary = Color(0xFF4F7CAC);
  static const Color primaryLight = Color(0xFF7BA3CC);
  static const Color primaryDark = Color(0xFF2D5A8A);

  static const Color secondary = Color(0xFF6BBFAB);
  static const Color secondaryLight = Color(0xFF9DD4C5);
  static const Color secondaryDark = Color(0xFF4A9E8A);

  static const Color success = Color(0xFF6BBF7A);
  static const Color warning = Color(0xFFE8B84A);
  static const Color danger = Color(0xFFD97B7B);
  static const Color info = Color(0xFF8B7FD4);
  static const Color sakit = Color(0xFFD4A07F);

  static const Color background = Color(0xFFF8FAFB);
  static const Color surface = Color(0xFFFFFFFF);
  static const Color border = Color(0xFFE2E8F0);
  static const Color textPrimary = Color(0xFF1E293B);
  static const Color textSecondary = Color(0xFF64748B);
  static const Color textMuted = Color(0xFF94A3B8);
  static const Color sidebarBg = Color(0xFFF1F5F9);

  static Color getStatusColor(String status) {
    switch (status.toLowerCase()) {
      case 'hadir':
      case 'hadir_terlambat':
        return success;
      case 'pending':
        return warning;
      case 'alpha':
        return danger;
      case 'izin':
        return info;
      case 'sakit':
        return sakit;
      default:
        return textMuted;
    }
  }

  static Color getSpColor(String spStatus) {
    switch (spStatus.toLowerCase()) {
      case 'aman':
        return success;
      case 'sp1':
        return warning;
      case 'sp2':
        return const Color(0xFFE88A4A);
      case 'sp3':
        return danger;
      case 'do':
        return const Color(0xFF8B0000);
      default:
        return textMuted;
    }
  }
}
