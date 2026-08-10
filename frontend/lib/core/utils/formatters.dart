import 'package:intl/intl.dart';

class Formatters {
  static String formatDate(DateTime date) {
    return DateFormat('dd MMM yyyy', 'id_ID').format(date);
  }

  static String formatDateTime(DateTime date) {
    return DateFormat('dd MMM yyyy HH:mm', 'id_ID').format(date);
  }

  static String formatTime(DateTime date) {
    return DateFormat('HH:mm', 'id_ID').format(date);
  }

  static String formatDuration(int minutes) {
    if (minutes < 60) return '$minutes mnt';
    final hours = minutes ~/ 60;
    final mins = minutes % 60;
    if (mins == 0) return '$hours jam';
    return '$hours jam $mins mnt';
  }

  static String formatDistance(double meters) {
    if (meters < 1000) return '${meters.toStringAsFixed(0)}m';
    return '${(meters / 1000).toStringAsFixed(1)}km';
  }

  static String formatPercentage(double value) {
    return '${value.toStringAsFixed(1)}%';
  }

  static String formatAlphaHours(int totalMinutes) {
    final hours = totalMinutes ~/ 60;
    final minutes = totalMinutes % 60;
    if (hours == 0) return '$minutes mnt';
    if (minutes == 0) return '$hours jam';
    return '$hours jam $minutes mnt';
  }

  static String getGreeting() {
    final hour = DateTime.now().hour;
    if (hour < 11) return 'Selamat Pagi';
    if (hour < 15) return 'Selamat Siang';
    if (hour < 18) return 'Selamat Sore';
    return 'Selamat Malam';
  }

  static String getStatusLabel(String status) {
    switch (status.toLowerCase()) {
      case 'hadir':
        return 'Hadir';
      case 'hadir_terlambat':
        return 'Hadir (Terlambat)';
      case 'pending':
        return 'Pending';
      case 'alpha':
        return 'Alpha';
      case 'izin':
        return 'Izin';
      case 'sakit':
        return 'Sakit';
      default:
        return status;
    }
  }

  static String getSpLabel(String spStatus) {
    switch (spStatus.toLowerCase()) {
      case 'aman':
        return 'AMAN';
      case 'sp1':
        return 'SP 1';
      case 'sp2':
        return 'SP 2';
      case 'sp3':
        return 'SP 3';
      case 'do':
        return 'DO';
      default:
        return spStatus;
    }
  }
}
