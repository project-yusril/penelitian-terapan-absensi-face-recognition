import 'package:flutter/foundation.dart';

class AppConfig {
  final Uri apiBaseUri;

  const AppConfig._(this.apiBaseUri);

  factory AppConfig.fromEnvironment({
    String rawUrl = const String.fromEnvironment('API_BASE_URL'),
    bool debug = kDebugMode,
  }) {
    final uri = Uri.tryParse(rawUrl);
    if (uri == null ||
        !uri.hasScheme ||
        uri.host.isEmpty ||
        uri.userInfo.isNotEmpty ||
        uri.hasQuery ||
        uri.hasFragment) {
      throw StateError(
        'API_BASE_URL harus berupa URL absolut tanpa query/fragment',
      );
    }
    final plaintextAllowed = debug && uri.scheme == 'http' && isDevHost(uri.host);
    if (uri.scheme != 'https' && !plaintextAllowed) {
      throw StateError(
        'API_BASE_URL wajib HTTPS; debug HTTP hanya untuk loopback '
        'atau alamat LAN privat',
      );
    }
    final apiSegments = uri.pathSegments
        .where((segment) => segment.isNotEmpty)
        .toList();
    if (apiSegments.isEmpty || apiSegments.last != 'api') {
      apiSegments.add('api');
    }
    return AppConfig._(uri.replace(pathSegments: [...apiSegments, '']));
  }
}

/// Host yang boleh diakses tanpa TLS **hanya pada build debug**.
///
/// Loopback dipakai saat perangkat dijembatani lewat `adb reverse`, sedangkan
/// alamat LAN privat dipakai saat HP menghubungi laptop langsung lewat Wi-Fi.
/// Keduanya tidak pernah meninggalkan jaringan lokal.
///
/// Build release tidak pernah melewati jalur ini: pemanggilnya menuntut
/// `debug == true`, sehingga produksi tetap HTTPS-only. Jangan melonggarkan
/// syarat itu — lalu lintas aplikasi ini memuat token sesi dan data biometrik.
bool isDevHost(String host) {
  const loopback = {'localhost', '127.0.0.1', '::1'};
  if (loopback.contains(host)) return true;

  final octets = host.split('.');
  if (octets.length != 4) return false;

  final numbers = <int>[];
  for (final octet in octets) {
    final value = int.tryParse(octet);
    if (value == null || value < 0 || value > 255) return false;
    numbers.add(value);
  }

  // Rentang privat RFC 1918 + loopback 127.0.0.0/8.
  if (numbers[0] == 127) return true;
  if (numbers[0] == 10) return true;
  if (numbers[0] == 192 && numbers[1] == 168) return true;
  if (numbers[0] == 172 && numbers[1] >= 16 && numbers[1] <= 31) return true;

  return false;
}
