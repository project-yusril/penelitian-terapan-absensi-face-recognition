import 'dart:convert';

import 'package:dio/dio.dart';

import '../../logging/app_logger.dart';

/// Mencatat setiap panggilan HTTP: metode, path, status, durasi, dan — saat
/// gagal — isi respons error dari backend.
///
/// Menggantikan `LogInterceptor` bawaan Dio yang sebelumnya dipasang dengan
/// semua flag isi dimatikan (`requestBody: false`, `responseBody: false`).
/// Konfigurasi itu aman tetapi membuat kegagalan API mustahil didiagnosis:
/// yang tercetak hanya bahwa ada error, tanpa status maupun pesan backend.
///
/// Di sini isi respons error tetap dicetak karena di situlah pesan validasi
/// Laravel berada, tetapi dilewatkan [redactMap] lebih dulu sehingga field
/// sensitif (token, embedding, foto) diringkas, bukan dibocorkan.
class LoggingInterceptor extends Interceptor {
  LoggingInterceptor();

  final AppLogger _log = AppLogger.tag('HTTP');

  static const String _startKey = 'logging.startedAtMs';

  int? _elapsedMs(RequestOptions options) {
    final started = options.extra[_startKey];
    if (started is! int) return null;
    return DateTime.now().millisecondsSinceEpoch - started;
  }

  @override
  void onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) {
    options.extra[_startKey] = DateTime.now().millisecondsSinceEpoch;
    _log.info(
      '→ ${options.method} ${options.path}',
      data: {
        'baseUrl': options.baseUrl,
        'tipeData': _describePayload(options.data),
        if (options.queryParameters.isNotEmpty)
          'query': redactMap(Map<String, Object?>.from(options.queryParameters)),
      },
    );
    handler.next(options);
  }

  @override
  void onResponse(
    Response<dynamic> response,
    ResponseInterceptorHandler handler,
  ) {
    _log.info(
      '← ${response.statusCode} ${response.requestOptions.method} '
      '${response.requestOptions.path}',
      data: {'ms': _elapsedMs(response.requestOptions)},
    );
    handler.next(response);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    final response = err.response;
    _log.error(
      '✗ ${response?.statusCode ?? err.type.name} '
      '${err.requestOptions.method} ${err.requestOptions.path}',
      data: {
        'ms': _elapsedMs(err.requestOptions),
        'tipeDio': err.type.name,
        'status': response?.statusCode,
        // Inilah bagian yang menjawab "kenapa gagal": pesan & error validasi
        // dari Laravel. Tanpa ini, 422 tidak bisa dibedakan dari 500.
        'respons': _describeErrorBody(response?.data),
      },
      error: err.error ?? err.message,
      stackTrace: err.stackTrace,
    );
    handler.next(err);
  }

  /// Sebut jenis payload tanpa mencetak isinya (bisa berisi foto/embedding).
  String _describePayload(Object? data) {
    if (data == null) return '<kosong>';
    if (data is FormData) {
      final fieldNames = data.fields.map((entry) => entry.key).toSet();
      return 'FormData(fields=${data.fields.length}'
          '${fieldNames.isEmpty ? '' : ' keys=${fieldNames.join(",")}'}'
          ', files=${data.files.length})';
    }
    if (data is Map) return '<map len=${data.length}>';
    if (data is List) return '<list len=${data.length}>';
    return data.runtimeType.toString();
  }

  /// Hasilnya sengaja berupa String, bukan Map.
  ///
  /// AppLogger meringkas setiap nilai Map bersarang menjadi `<map len=N>` agar
  /// satu baris log tidak meledak. Kalau isi respons dikembalikan sebagai Map,
  /// aturan itu ikut menelannya — pesan backend yang justru paling dibutuhkan
  /// (`message`, `code`, `errors`) hilang persis saat sedang dibutuhkan.
  /// Meng-encode-nya lebih dulu, setelah redaksi, membuatnya lolos utuh.
  Object? _describeErrorBody(Object? body) {
    if (body == null) return null;
    if (body is Map) {
      final redacted = redactMap(
        body.map((key, value) => MapEntry(key.toString(), value)),
      );
      try {
        return jsonEncode(redacted, toEncodable: (value) => value.toString());
      } catch (_) {
        return redacted.toString();
      }
    }
    if (body is String) {
      // Halaman error HTML Laravel bisa puluhan ribu karakter; potong saja.
      return body.length > 500 ? '${body.substring(0, 500)}…' : body;
    }
    return body.toString();
  }
}
