import 'package:absensi_mahasiswa/core/config/app_config.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('release accepts HTTPS API URL', () {
    expect(
      AppConfig.fromEnvironment(
        rawUrl: 'https://api.example.edu/api',
        debug: false,
      ).apiBaseUri.scheme,
      'https',
    );
  });

  test('normalizes origin and API path to one trailing /api/', () {
    expect(
      AppConfig.fromEnvironment(
        rawUrl: 'https://api.example.edu',
        debug: false,
      ).apiBaseUri.toString(),
      'https://api.example.edu/api/',
    );
    expect(
      AppConfig.fromEnvironment(
        rawUrl: 'https://api.example.edu/api/',
        debug: false,
      ).apiBaseUri.toString(),
      'https://api.example.edu/api/',
    );
  });

  test('release rejects cleartext API URL', () {
    expect(
      () => AppConfig.fromEnvironment(
        rawUrl: 'http://api.example.edu/api',
        debug: false,
      ),
      throwsStateError,
    );
  });

  test('debug accepts only loopback cleartext', () {
    expect(
      AppConfig.fromEnvironment(
        rawUrl: 'http://127.0.0.1:8000/api',
        debug: true,
      ).apiBaseUri.host,
      '127.0.0.1',
    );
    expect(
      () => AppConfig.fromEnvironment(
        rawUrl: 'http://192.168.1.7:8000/api',
        debug: true,
      ),
      throwsStateError,
    );
  });
}
