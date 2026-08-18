import 'package:dio/dio.dart';
import '../constants/api_constants.dart';
import '../config/app_config.dart';
import '../logging/app_logger.dart';
import '../security/session_coordinator.dart';
import '../errors/exceptions.dart';
import 'interceptors/auth_interceptor.dart';
import 'interceptors/logging_interceptor.dart';

class ApiClient {
  late final Dio _dio;
  final AppConfig _config;
  final SessionCoordinator _session;

  ApiClient(this._config, this._session) {
    _dio = Dio(
      BaseOptions(
        baseUrl: _config.apiBaseUri.toString(),
        connectTimeout: const Duration(
          milliseconds: ApiConstants.connectTimeoutMs,
        ),
        receiveTimeout: const Duration(
          milliseconds: ApiConstants.receiveTimeoutMs,
        ),
        sendTimeout: const Duration(milliseconds: ApiConstants.sendTimeoutMs),
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      ),
    );

    _dio.interceptors.add(AuthInterceptor(_session, _config.apiBaseUri));
    // Request/response bodies and headers may contain credentials or biometrics.
    // LoggingInterceptor mencetak metode/status/durasi selalu, dan isi respons
    // hanya saat error — setelah dilewatkan redaksi field sensitif.
    _dio.interceptors.add(LoggingInterceptor());

    _log.info(
      'ApiClient siap',
      data: {
        'baseUrl': _config.apiBaseUri.toString(),
        'connectTimeoutMs': ApiConstants.connectTimeoutMs,
      },
    );
  }

  static final AppLogger _log = AppLogger.tag('ApiClient');

  Dio get dio => _dio;

  Future<Response> get(
    String path, {
    Map<String, dynamic>? queryParameters,
  }) async {
    try {
      return await _dio.get(path, queryParameters: queryParameters);
    } on DioException catch (e) {
      throw _handleError(e);
    }
  }

  Future<Response> post(String path, {dynamic data}) async {
    try {
      return await _dio.post(path, data: data);
    } on DioException catch (e) {
      throw _handleError(e);
    }
  }

  Future<Response> put(String path, {dynamic data}) async {
    try {
      return await _dio.put(path, data: data);
    } on DioException catch (e) {
      throw _handleError(e);
    }
  }

  Future<Response> delete(String path) async {
    try {
      return await _dio.delete(path);
    } on DioException catch (e) {
      throw _handleError(e);
    }
  }

  Future<Response> uploadFile(String path, {required FormData data}) async {
    try {
      return await _dio.post(
        path,
        data: data,
        options: Options(contentType: 'multipart/form-data'),
      );
    } on DioException catch (e) {
      throw _handleError(e);
    }
  }

  Exception _handleError(DioException e) {
    _log.warn(
      'memetakan DioException → exception domain',
      data: {
        'tipeDio': e.type.name,
        'status': e.response?.statusCode,
        'path': e.requestOptions.path,
      },
    );
    switch (e.type) {
      case DioExceptionType.connectionTimeout:
      case DioExceptionType.sendTimeout:
      case DioExceptionType.receiveTimeout:
        return NetworkException(
          message: 'Koneksi timeout. Periksa jaringan Anda.',
        );
      case DioExceptionType.connectionError:
        return NetworkException(message: 'Tidak dapat terhubung ke server.');
      case DioExceptionType.badResponse:
        return _handleBadResponse(e.response!);
      default:
        return ServerException(message: 'Terjadi kesalahan: ${e.message}');
    }
  }

  Exception _handleBadResponse(Response response) {
    final Map<String, dynamic>? data = response.data is Map<String, dynamic>
        ? response.data as Map<String, dynamic>
        : null;
    final message = data?['message'] ?? 'Terjadi kesalahan';
    final details = <String, dynamic>{
      if (data?['matched_name'] is String)
        'matched_name': (data!['matched_name'] as String).trim(),
      if (data?['logout_required'] is bool)
        'logout_required': data!['logout_required'] as bool,
    };

    _log.error(
      'respons error dari backend',
      data: {
        'status': response.statusCode,
        'path': response.requestOptions.path,
        'pesanBackend': message,
        'kode': data?['code'],
        // `errors` berisi detail per-field dari validasi Laravel; inilah yang
        // menjelaskan 422 (mis. "embedding must contain 192 items").
        'errors': data?['errors'],
      },
    );

    // Backend sudah menulis pesan yang spesifik dan layak dibaca user, mis.
    // "Enrollment wajah belum disetujui." Sebelumnya pesan itu dibuang dan
    // diganti kalimat generik, sehingga semua 403 terlihat sama persis dan
    // user tidak tahu harus berbuat apa. Pakai pesan backend bila ada, dan
    // sediakan kalimat umum hanya sebagai cadangan.
    final backendMessage =
        data?['message'] is String &&
            (data!['message'] as String).trim().isNotEmpty
        ? (data['message'] as String).trim()
        : null;

    switch (response.statusCode) {
      case 401:
        // Dibiarkan generik: pesan ini memicu alur login ulang, jadi harus
        // konsisten apa pun penyebab spesifik di server.
        return AuthException(
          message: 'Sesi Anda telah berakhir. Silakan login kembali.',
        );
      case 403:
        return ServerException(
          message: backendMessage ?? 'Anda tidak memiliki akses.',
          statusCode: 403,
          code: data?['code'] as String?,
        );
      case 404:
        return ServerException(
          message: backendMessage ?? 'Data tidak ditemukan.',
          statusCode: 404,
          code: data?['code'] as String?,
        );
      case 422:
        return ServerException(
          message: message,
          statusCode: 422,
          errors: data?['errors'] as Map<String, dynamic>?,
        );
      case 429:
        return ServerException(
          message: 'Terlalu banyak percobaan. Coba lagi nanti.',
          statusCode: 429,
          code: data?['code'] as String?,
        );
      default:
        return ServerException(
          message: message,
          statusCode: response.statusCode,
          code: data?['code'] as String?,
          details: details.isEmpty ? null : details,
        );
    }
  }
}
