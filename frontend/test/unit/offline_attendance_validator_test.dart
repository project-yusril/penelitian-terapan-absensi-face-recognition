import 'package:absensi_mahasiswa/core/offline/offline_attendance_validator.dart';
import 'package:flutter_test/flutter_test.dart';

Map<String, dynamic> validPayload({String type = 'check_in'}) => {
  'client_uuid': '123e4567-e89b-42d3-a456-426614174000',
  'jadwal_id': 1,
  if (type == 'check_out') 'attendance_id': 2,
  'type': type,
  'timestamp': '2026-07-18T01:00:00.000Z',
  'latitude': -0.026,
  'longitude': 109.34,
  'face_distance': 0.2,
  'mock_location_detected': false,
  'liveness_passed': true,
  'gps_accuracy': 4.5,
  'location_age_ms': 250,
  'inference_time_ms': 40,
  'liveness_challenge': 'blink',
  'device_model': 'test',
  'device_os': 'test',
  'app_version': '1.0.0',
  'permit_token': 'a' * 64,
};

void main() {
  const validator = OfflineAttendanceValidator();

  test('accepts backend-compatible check-in and check-out payloads', () {
    expect(validator.validate(validPayload()).isValid, isTrue);
    expect(validator.validate(validPayload(type: 'check_out')).isValid, isTrue);
  });

  test('requires attendance_id only for check-out', () {
    final payload = validPayload(type: 'check_out')..remove('attendance_id');

    final result = validator.validate(payload);

    expect(result.isValid, isFalse);
    expect(result.code, 'invalid_attendance_id');
  });

  test('rejects invalid uuid, coordinates, booleans, and permit shape', () {
    for (final mutation in <void Function(Map<String, dynamic>)>[
      (data) => data['client_uuid'] = 'bad',
      (data) => data['latitude'] = 91,
      (data) => data['longitude'] = double.nan,
      (data) => data['liveness_passed'] = 1,
      (data) => data['permit_token'] = 'short',
    ]) {
      final payload = validPayload();
      mutation(payload);
      expect(validator.validate(payload).isValid, isFalse);
    }
  });

  test('rejects overlong nullable backend strings', () {
    final payload = validPayload()..['device_os'] = 'x' * 51;

    final result = validator.validate(payload);

    expect(result.code, 'invalid_device_os');
  });

  test('requires finite accuracy and non-negative location age', () {
    for (final mutation in <void Function(Map<String, dynamic>)>[
      (data) => data.remove('gps_accuracy'),
      (data) => data['gps_accuracy'] = double.infinity,
      (data) => data['gps_accuracy'] = -0.1,
      (data) => data.remove('location_age_ms'),
      (data) => data['location_age_ms'] = -1,
    ]) {
      final payload = validPayload();
      mutation(payload);
      expect(validator.validate(payload).isValid, isFalse);
    }
  });
}
