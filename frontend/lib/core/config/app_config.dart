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
    final loopback = const {'localhost', '127.0.0.1', '::1'}.contains(uri.host);
    if (uri.scheme != 'https' && !(debug && uri.scheme == 'http' && loopback)) {
      throw StateError(
        'API_BASE_URL wajib HTTPS; debug HTTP hanya untuk loopback',
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
