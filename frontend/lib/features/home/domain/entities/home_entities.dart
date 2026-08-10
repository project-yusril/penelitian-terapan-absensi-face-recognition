import 'package:equatable/equatable.dart';

class JadwalHariIni extends Equatable {
  final int jadwalId;
  final int mataKuliahId;
  final String mataKuliah;
  final String dosen;
  final String hari;
  final String jamMulai;
  final String jamSelesai;
  final String ruangan;
  final double geofenceLat;
  final double geofenceLon;
  final double geofenceRadius;
  final String? attendanceStatus;
  final int? attendanceId;
  final String? checkinTime;
  final String? checkoutTime;
  final DateTime? notBefore;
  final DateTime? expiresAt;
  final bool backendCanCheckIn;
  final bool backendCanCheckOut;
  final bool hasTimeAnchor;
  final DateTime? Function() anchoredNow;

  const JadwalHariIni({
    required this.jadwalId,
    required this.mataKuliahId,
    required this.mataKuliah,
    required this.dosen,
    required this.hari,
    required this.jamMulai,
    required this.jamSelesai,
    required this.ruangan,
    required this.geofenceLat,
    required this.geofenceLon,
    required this.geofenceRadius,
    this.attendanceStatus,
    this.attendanceId,
    this.checkinTime,
    this.checkoutTime,
    this.notBefore,
    this.expiresAt,
    this.backendCanCheckIn = false,
    this.backendCanCheckOut = false,
    this.hasTimeAnchor = false,
    this.anchoredNow = _noAnchoredTime,
  });

  bool get isOngoing {
    final now = anchoredNow();
    if (!hasTimeAnchor ||
        now == null ||
        notBefore == null ||
        expiresAt == null) {
      return false;
    }
    return !now.isBefore(notBefore!) && !now.isAfter(expiresAt!);
  }

  bool get isCheckedIn =>
      attendanceStatus != null && attendanceStatus != 'belum';
  bool get isCheckedOut => checkoutTime != null;
  bool get canCheckIn =>
      hasTimeAnchor && backendCanCheckIn && !isCheckedIn && isOngoing;
  bool get canCheckOut =>
      hasTimeAnchor &&
      backendCanCheckOut &&
      isCheckedIn &&
      !isCheckedOut &&
      isOngoing;
  bool get canOpenAttendance => canCheckIn || canCheckOut;

  @override
  List<Object?> get props => [
    jadwalId,
    mataKuliahId,
    attendanceStatus,
    attendanceId,
  ];
}

DateTime? _noAnchoredTime() => null;

class AttendanceSummary extends Equatable {
  final int totalHadir;
  final int totalAlpha;
  final int totalIzin;
  final int totalSakit;
  final int totalPending;
  final double persentaseKehadiran;
  final int totalAlphaMenit;
  final double totalAlphaJam;
  final String spStatus;
  final double spThreshold;

  const AttendanceSummary({
    required this.totalHadir,
    required this.totalAlpha,
    required this.totalIzin,
    required this.totalSakit,
    required this.totalPending,
    required this.persentaseKehadiran,
    required this.totalAlphaMenit,
    required this.totalAlphaJam,
    required this.spStatus,
    required this.spThreshold,
  });

  double get alphaProgress =>
      spThreshold > 0 ? (totalAlphaJam / spThreshold).clamp(0.0, 1.0) : 0;

  @override
  List<Object?> get props => [totalHadir, totalAlpha, totalAlphaJam, spStatus];
}

class NotificationItem extends Equatable {
  final int id;
  final String title;
  final String body;
  final String type;
  final bool isRead;
  final String createdAt;
  final Map<String, dynamic>? data;

  const NotificationItem({
    required this.id,
    required this.title,
    required this.body,
    required this.type,
    required this.isRead,
    required this.createdAt,
    this.data,
  });

  @override
  List<Object?> get props => [id, isRead];
}
