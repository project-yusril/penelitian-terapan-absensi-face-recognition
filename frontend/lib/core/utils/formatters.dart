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

  /// Jam lokal `HH:mm` dari timestamp ISO yang dikirim backend.
  ///
  /// Backend mengirim UTC (mis. `2026-08-11T06:58:07.000000Z`). Menampilkan
  /// nilai itu apa adanya salah dua kali: jamnya bukan waktu setempat, dan
  /// teks sepanjang itu merusak tata letak kartu. Mengembalikan [fallback]
  /// bila nilainya kosong atau tidak bisa diurai, supaya UI tidak pernah
  /// menampilkan string mentah.
  /// Sengaja TANPA locale `id_ID`.
  ///
  /// Pola `HH:mm` murni angka — tidak ada nama bulan/hari yang bergantung
  /// bahasa — sehingga tidak butuh `initializeDateFormatting`. Menyertakan
  /// locale justru membuat fungsi ini melempar `LocaleDataException` bila data
  /// locale belum dimuat, dan itu terlalu rapuh untuk sesuatu yang dipakai di
  /// banyak kartu daftar.
  static String formatClockFromIso(String? iso, {String fallback = '-'}) {
    if (iso == null || iso.trim().isEmpty) return fallback;
    final parsed = DateTime.tryParse(iso);
    if (parsed == null) return fallback;
    return DateFormat('HH:mm').format(parsed.toLocal());
  }

  /// Tanggal `dd MMM yyyy` dari timestamp ISO backend.
  ///
  /// Butuh `initializeDateFormatting('id_ID')` (dipanggil saat boot) karena
  /// nama bulannya bergantung bahasa.
  static String formatDateFromIso(String? iso, {String fallback = '-'}) {
    if (iso == null || iso.trim().isEmpty) return fallback;
    final parsed = DateTime.tryParse(iso);
    if (parsed == null) return fallback;
    return DateFormat('dd MMM yyyy', 'id_ID').format(parsed.toLocal());
  }

  /// `13:00:00` → `13:00`. Jam jadwal disimpan dengan detik yang selalu nol.
  static String trimSeconds(String? clock, {String fallback = '-'}) {
    if (clock == null || clock.trim().isEmpty) return fallback;
    final parts = clock.trim().split(':');
    if (parts.length < 2) return clock.trim();
    return '${parts[0].padLeft(2, '0')}:${parts[1].padLeft(2, '0')}';
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
