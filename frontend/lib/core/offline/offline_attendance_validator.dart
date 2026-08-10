class OfflineAttendanceValidation {
  final bool isValid;
  final String? code;
  final String? message;

  const OfflineAttendanceValidation.valid()
    : isValid = true,
      code = null,
      message = null;

  const OfflineAttendanceValidation.invalid(this.code, this.message)
    : isValid = false;
}

class OfflineAttendanceValidator {
  static final RegExp _uuid = RegExp(
    r'^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$',
  );

  const OfflineAttendanceValidator();

  OfflineAttendanceValidation validate(Map<String, dynamic> data) {
    final clientUuid = data['client_uuid'];
    if (clientUuid is! String || !_uuid.hasMatch(clientUuid)) {
      return const OfflineAttendanceValidation.invalid(
        'invalid_client_uuid',
        'client_uuid harus berupa UUID valid',
      );
    }
    if (!_positiveInt(data['jadwal_id'])) {
      return const OfflineAttendanceValidation.invalid(
        'invalid_jadwal_id',
        'jadwal_id harus berupa integer positif',
      );
    }
    final type = data['type'];
    if (type != 'check_in' && type != 'check_out') {
      return const OfflineAttendanceValidation.invalid(
        'invalid_type',
        'type harus check_in atau check_out',
      );
    }
    if (type == 'check_out' && !_positiveInt(data['attendance_id'])) {
      return const OfflineAttendanceValidation.invalid(
        'invalid_attendance_id',
        'attendance_id wajib berupa integer positif untuk check_out',
      );
    }
    final timestamp = data['timestamp'];
    if (timestamp is! String || DateTime.tryParse(timestamp) == null) {
      return const OfflineAttendanceValidation.invalid(
        'invalid_timestamp',
        'timestamp harus berupa tanggal valid',
      );
    }
    if (!_numberInRange(data['latitude'], -90, 90)) {
      return const OfflineAttendanceValidation.invalid(
        'invalid_latitude',
        'latitude harus berada di antara -90 dan 90',
      );
    }
    if (!_numberInRange(data['longitude'], -180, 180)) {
      return const OfflineAttendanceValidation.invalid(
        'invalid_longitude',
        'longitude harus berada di antara -180 dan 180',
      );
    }
    final faceDistance = data['face_distance'];
    if (faceDistance is! num || !faceDistance.isFinite || faceDistance < 0) {
      return const OfflineAttendanceValidation.invalid(
        'invalid_face_distance',
        'face_distance harus berupa angka non-negatif',
      );
    }
    for (final field in ['mock_location_detected', 'liveness_passed']) {
      if (data[field] is! bool) {
        return OfflineAttendanceValidation.invalid(
          'invalid_$field',
          '$field harus berupa boolean',
        );
      }
    }
    final accuracy = data['gps_accuracy'];
    if (accuracy is! num || !accuracy.isFinite || accuracy < 0) {
      return const OfflineAttendanceValidation.invalid(
        'invalid_gps_accuracy',
        'gps_accuracy wajib berupa angka non-negatif finite',
      );
    }
    final locationAge = data['location_age_ms'];
    if (locationAge is! int || locationAge < 0) {
      return const OfflineAttendanceValidation.invalid(
        'invalid_location_age_ms',
        'location_age_ms wajib berupa integer non-negatif',
      );
    }
    if (!_nullableInt(data['inference_time_ms'])) {
      return const OfflineAttendanceValidation.invalid(
        'invalid_inference_time_ms',
        'inference_time_ms harus berupa integer atau null',
      );
    }
    for (final entry in const {
      'liveness_challenge': 50,
      'device_model': 100,
      'device_os': 50,
      'app_version': 20,
    }.entries) {
      final value = data[entry.key];
      if (value != null && (value is! String || value.length > entry.value)) {
        return OfflineAttendanceValidation.invalid(
          'invalid_${entry.key}',
          '${entry.key} harus berupa string maksimal ${entry.value} karakter',
        );
      }
    }
    final permit = data['permit_token'];
    if (permit is! String || permit.length != 64) {
      return const OfflineAttendanceValidation.invalid(
        'invalid_permit_token',
        'permit_token harus berupa string 64 karakter',
      );
    }
    return const OfflineAttendanceValidation.valid();
  }

  bool _positiveInt(Object? value) => value is int && value > 0;

  bool _numberInRange(Object? value, num min, num max) =>
      value is num && value.isFinite && value >= min && value <= max;

  bool _nullableInt(Object? value) => value == null || value is int;
}
