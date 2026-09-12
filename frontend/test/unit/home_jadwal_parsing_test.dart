import 'dart:convert';

import 'package:absensi_mahasiswa/core/config/app_config.dart';
import 'package:absensi_mahasiswa/core/network/api_client.dart';
import 'package:absensi_mahasiswa/core/security/secure_session_store.dart';
import 'package:absensi_mahasiswa/core/security/session_coordinator.dart';
import 'package:absensi_mahasiswa/core/time/server_time_anchor.dart';
import 'package:absensi_mahasiswa/features/home/data/datasources/home_remote_datasource.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

class _MemorySessionStore implements SessionStore {
  @override
  SessionSnapshot snapshot = const SessionSnapshot(null, 0);

  @override
  Future<void> clear() async {}

  @override
  Future<bool> clearIfMatches(SessionSnapshot expected) async => false;

  @override
  Future<void> saveToken(String value) async {}
}

class _StubAdapter implements HttpClientAdapter {
  _StubAdapter(this.body);

  final String body;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    return ResponseBody.fromString(
      body,
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

HomeRemoteDataSourceImpl _dataSource(Map<String, dynamic> payload) {
  final client = ApiClient(
    AppConfig.fromEnvironment(rawUrl: 'https://host.example/api', debug: false),
    SessionCoordinator(_MemorySessionStore()),
  );
  client.dio.httpClientAdapter = _StubAdapter(
    jsonEncode({'data': payload['data']}),
  );
  return HomeRemoteDataSourceImpl(client, ServerTimeAnchor());
}

void main() {
  test(
    'membaca dosen & kelas dari level jadwal (skema kelas master)',
    () async {
      final ds = _dataSource({
        'data': [
          {
            'id': 1,
            'mata_kuliah_id': 11,
            'hari': 'Senin',
            'jam_mulai': '08:00',
            'jam_selesai': '10:00',
            'ruangan': 'Lab 1',
            'mata_kuliah': {'id': 11, 'nama': 'Pemrograman Mobile'},
            // RENCANA 2: dosen & kelas di level jadwal.
            'dosen': {'id': 7, 'nama': 'Dr. Rani'},
            'kelas': {'id': 3, 'tingkat': '4', 'nama': 'B'},
            'geofence': {
              'latitude': -0.0263,
              'longitude': 109.3425,
              'radius': 50,
            },
            'window': {
              'not_before': '2026-08-20T07:45:00+07:00',
              'expires_at': '2026-08-20T10:15:00+07:00',
            },
            'eligibility': {'can_check_in': true, 'can_check_out': false},
          },
        ],
      });

      final list = await ds.getTodaySchedule();

      expect(list, hasLength(1));
      expect(list.first.dosen, 'Dr. Rani');
      expect(list.first.kelas, '4B');
      expect(list.first.mataKuliah, 'Pemrograman Mobile');
    },
  );

  test('fallback ke mata_kuliah.dosen untuk payload lama', () async {
    final ds = _dataSource({
      'data': [
        {
          'id': 2,
          'mata_kuliah_id': 12,
          'hari': 'Selasa',
          'jam_mulai': '13:00',
          'jam_selesai': '15:00',
          'ruangan': 'Lab 2',
          'mata_kuliah': {
            'id': 12,
            'nama': 'Basis Data',
            'dosen': {'nama': 'Pak Budi'},
          },
          'geofence': {'latitude': 0, 'longitude': 0, 'radius': 50},
        },
      ],
    });

    final list = await ds.getTodaySchedule();

    expect(list.first.dosen, 'Pak Budi');
    expect(list.first.kelas, isNull);
  });
}
