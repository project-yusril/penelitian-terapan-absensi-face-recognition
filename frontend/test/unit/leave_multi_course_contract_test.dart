import 'dart:convert';

import 'package:absensi_mahasiswa/core/config/app_config.dart';
import 'package:absensi_mahasiswa/core/network/api_client.dart';
import 'package:absensi_mahasiswa/core/security/secure_session_store.dart';
import 'package:absensi_mahasiswa/core/security/session_coordinator.dart';
import 'package:absensi_mahasiswa/features/leave_request/data/datasources/leave_remote_datasource.dart';
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

class _FormDataAdapter implements HttpClientAdapter {
  _FormDataAdapter(this.body);

  final String body;
  final fields = <String, List<String>>{};

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    final data = options.data;
    if (data is FormData) {
      for (final entry in data.fields) {
        fields.putIfAbsent(entry.key, () => <String>[]).add(entry.value);
      }
    }
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

LeaveRemoteDataSourceImpl _dataSource(_FormDataAdapter adapter) {
  final client = ApiClient(
    AppConfig.fromEnvironment(rawUrl: 'https://host.example/api', debug: false),
    SessionCoordinator(_MemorySessionStore()),
  );
  client.dio.httpClientAdapter = adapter;
  return LeaveRemoteDataSourceImpl(client);
}

void main() {
  test('multi-MK submission sends all_mata_kuliah and parses the envelope', () async {
    final adapter = _FormDataAdapter(
      jsonEncode({
        'success': true,
        'message': 'Pengajuan izin dibuat untuk 2 mata kuliah',
        'data': {
          'created_count': 2,
          'leave_requests': [
            {'id': 1, 'user_id': 7, 'mata_kuliah_id': 11, 'status': 'pending'},
            {'id': 2, 'user_id': 7, 'mata_kuliah_id': 12, 'status': 'pending'},
          ],
          'skipped': [
            {
              'mata_kuliah_id': 13,
              'nama': 'Statistika',
              'alasan': 'tanpa_jadwal',
              'pesan': 'Tidak ada jadwal aktif pada rentang tanggal',
            },
          ],
        },
      }),
    );

    final result = await _dataSource(adapter).submitLeave(
      jenis: 'sakit',
      mataKuliahId: null,
      tanggalMulai: '2026-08-17',
      tanggalSelesai: '2026-08-17',
      keterangan: 'Demam',
      allMataKuliah: true,
    );

    expect(adapter.fields['all_mata_kuliah'], ['1']);
    expect(adapter.fields.containsKey('mata_kuliah_id'), isFalse);
    expect(result.createdCount, 2);
    expect(result.created.map((e) => e.mataKuliahId), [11, 12]);
    expect(result.skipped.single.nama, 'Statistika');
    expect(result.skipped.single.alasan, 'tanpa_jadwal');
  });

  test('explicit course ids are sent as a repeated field', () async {
    final adapter = _FormDataAdapter(
      jsonEncode({
        'success': true,
        'data': {
          'created_count': 1,
          'leave_requests': [
            {'id': 3, 'user_id': 7, 'mata_kuliah_id': 11, 'status': 'pending'},
          ],
          'skipped': const [],
        },
      }),
    );

    final result = await _dataSource(adapter).submitLeave(
      jenis: 'izin',
      mataKuliahId: 99,
      tanggalMulai: '2026-08-17',
      tanggalSelesai: '2026-08-18',
      keterangan: 'Lomba',
      mataKuliahIds: const [11, 12],
    );

    expect(adapter.fields['mata_kuliah_ids[]'], ['11', '12']);
    expect(adapter.fields.containsKey('mata_kuliah_id'), isFalse);
    expect(result.createdCount, 1);
    expect(result.skipped, isEmpty);
  });

  test('single-MK submission keeps the legacy object response', () async {
    final adapter = _FormDataAdapter(
      jsonEncode({
        'success': true,
        'data': {
          'id': 5,
          'user_id': 7,
          'mata_kuliah_id': 11,
          'jenis': 'izin',
          'status': 'pending',
        },
      }),
    );

    final result = await _dataSource(adapter).submitLeave(
      jenis: 'izin',
      mataKuliahId: 11,
      tanggalMulai: '2026-08-17',
      tanggalSelesai: '2026-08-17',
      keterangan: 'Urusan keluarga',
    );

    expect(adapter.fields['mata_kuliah_id'], ['11']);
    expect(adapter.fields.containsKey('all_mata_kuliah'), isFalse);
    expect(result.createdCount, 1);
    expect(result.created.single.id, 5);
    expect(result.skipped, isEmpty);
  });
}
