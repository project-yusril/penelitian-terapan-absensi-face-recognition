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

  test('debug accepts loopback cleartext', () {
    expect(
      AppConfig.fromEnvironment(
        rawUrl: 'http://127.0.0.1:8000/api',
        debug: true,
      ).apiBaseUri.host,
      '127.0.0.1',
    );
    expect(
      AppConfig.fromEnvironment(
        rawUrl: 'http://localhost:8000/api',
        debug: true,
      ).apiBaseUri.host,
      'localhost',
    );
  });

  // Dilonggarkan secara sadar agar HP bisa menghubungi laptop lewat Wi-Fi
  // tanpa `adb reverse`. Hanya berlaku di debug; lihat test berikutnya yang
  // memastikan release tetap menolaknya.
  test('debug accepts private LAN cleartext', () {
    for (final host in ['192.168.8.99', '10.0.2.2', '172.16.0.5']) {
      expect(
        AppConfig.fromEnvironment(
          rawUrl: 'http://$host:8000/api',
          debug: true,
        ).apiBaseUri.host,
        host,
        reason: '$host adalah alamat privat dan harus diterima saat debug',
      );
    }
  });

  test('debug still rejects cleartext to public hosts', () {
    // Pelonggaran di atas tidak boleh berubah jadi "cleartext bebas": URL
    // publik yang salah ketik harus tetap gagal keras, bukan diam-diam
    // mengirim token dan data biometrik tanpa TLS lewat internet.
    for (final host in ['api.example.edu', '8.8.8.8', '172.32.0.1']) {
      expect(
        () => AppConfig.fromEnvironment(
          rawUrl: 'http://$host:8000/api',
          debug: true,
        ),
        throwsStateError,
        reason: '$host bukan alamat privat dan harus ditolak',
      );
    }
  });

  test('release rejects private LAN cleartext too', () {
    expect(
      () => AppConfig.fromEnvironment(
        rawUrl: 'http://192.168.8.99:8000/api',
        debug: false,
      ),
      throwsStateError,
    );
  });
}
