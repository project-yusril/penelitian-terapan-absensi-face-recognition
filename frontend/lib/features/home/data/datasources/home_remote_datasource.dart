import '../../../../core/constants/api_constants.dart';
import '../../../../core/network/api_client.dart';
import '../../domain/entities/home_entities.dart';
import '../../../../core/time/server_time_anchor.dart';

abstract class HomeRemoteDataSource {
  Future<List<JadwalHariIni>> getTodaySchedule();
  Future<AttendanceSummary> getAttendanceSummary();
  Future<List<NotificationItem>> getRecentNotifications();
}

class HomeRemoteDataSourceImpl implements HomeRemoteDataSource {
  final ApiClient _apiClient;
  final ServerTimeAnchor _serverTimeAnchor;

  HomeRemoteDataSourceImpl(this._apiClient, this._serverTimeAnchor);

  @override
  Future<List<JadwalHariIni>> getTodaySchedule() async {
    try {
      final response = await _apiClient.get(ApiConstants.jadwalTodayEndpoint);
      final body = response.data as Map<String, dynamic>;
      final meta = body['meta'] as Map<dynamic, dynamic>?;
      _serverTimeAnchor.anchorFromIso(meta?['server_time']);
      final data = response.data['data'] as List<dynamic>;
      return data.map((e) => _parseJadwal(e)).toList();
    } catch (e) {
      rethrow;
    }
  }

  JadwalHariIni _parseJadwal(Map<String, dynamic> json) {
    final mataKuliah = json['mata_kuliah'] as Map<String, dynamic>?;
    final dosen = mataKuliah?['dosen'] as Map<String, dynamic>?;
    final geofence = json['geofence'] as Map<String, dynamic>?;
    final window = json['window'] as Map<dynamic, dynamic>?;
    final eligibility = json['eligibility'] as Map<dynamic, dynamic>?;
    return JadwalHariIni(
      jadwalId: _toInt(json['id']),
      mataKuliahId: _toInt(json['mata_kuliah_id']),
      mataKuliah: mataKuliah?['nama']?.toString() ?? '',
      dosen: dosen?['nama']?.toString() ?? '',
      hari: json['hari'] ?? '',
      jamMulai: json['jam_mulai'] ?? '',
      jamSelesai: json['jam_selesai'] ?? '',
      ruangan: json['ruangan'] ?? '',
      geofenceLat: _toDouble(geofence?['latitude']),
      geofenceLon: _toDouble(geofence?['longitude']),
      // H-02: backend mengirim field `radius` (bukan `radius_meter`).
      geofenceRadius: _toDouble(
        geofence?['radius'] ?? geofence?['radius_meter'],
        fallback: 50,
      ),

      attendanceStatus: json['attendance_status'],
      attendanceId: json['attendance_id'],
      checkinTime: json['checkin_time'],
      checkoutTime: json['checkout_time'],
      notBefore: DateTime.tryParse(window?['not_before']?.toString() ?? ''),
      expiresAt: DateTime.tryParse(window?['expires_at']?.toString() ?? ''),
      backendCanCheckIn: eligibility?['can_check_in'] == true,
      backendCanCheckOut: eligibility?['can_check_out'] == true,
      hasTimeAnchor: _serverTimeAnchor.isAvailable,
      anchoredNow: () => _serverTimeAnchor.now,
    );
  }

  @override
  Future<AttendanceSummary> getAttendanceSummary() async {
    try {
      final response = await _apiClient.get(
        ApiConstants.mahasiswaDashboardEndpoint,
      );
      final data = response.data['data'];
      final summary = data['summary_semester'] as Map<String, dynamic>? ?? {};
      final alpha = data['alpha_accumulation'] as Map<String, dynamic>? ?? {};
      return AttendanceSummary(
        totalHadir: _toInt(summary['hadir']),
        totalAlpha: _toInt(summary['alpha']),
        totalIzin: _toInt(summary['izin_sakit']),
        totalSakit: 0,
        totalPending: _toInt(summary['pending']),
        persentaseKehadiran: _toDouble(summary['persentase_kehadiran']),
        totalAlphaMenit: _toInt(alpha['total_alpha_menit']),
        totalAlphaJam: _toDouble(alpha['total_alpha_jam']),
        spStatus: alpha['sp_status'] ?? 'aman',
        // L-02: backend mengirim sp_threshold sebagai objek {sp1, sp2, sp3, do}
        // (dalam JAM). Progress bar SP memakai ambang SP1 sebagai acuan pertama.
        spThreshold: _toDouble(
          (data['sp_threshold'] is Map)
              ? data['sp_threshold']['sp1']
              : data['sp_threshold'],
          fallback: 16,
        ),
      );
    } catch (e) {
      rethrow;
    }
  }

  @override
  Future<List<NotificationItem>> getRecentNotifications() async {
    try {
      final response = await _apiClient.get(
        ApiConstants.notificationsEndpoint,
        queryParameters: {'per_page': 5},
      );
      final data = response.data['data'] as List<dynamic>;
      return data
          .map(
            (e) => NotificationItem(
              id: e['id'] ?? 0,
              title: e['title'] ?? '',
              body: e['body'] ?? '',
              type: e['type'] ?? '',
              isRead: e['is_read'] ?? false,
              createdAt: e['created_at'] ?? '',
              data: e['data'] as Map<String, dynamic>?,
            ),
          )
          .toList();
    } catch (e) {
      return [];
    }
  }

  int _toInt(dynamic value, {int fallback = 0}) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '') ?? fallback;
  }

  double _toDouble(dynamic value, {double fallback = 0}) {
    if (value is double) return value;
    if (value is num) return value.toDouble();
    return double.tryParse(value?.toString() ?? '') ?? fallback;
  }
}
