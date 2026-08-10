import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import '../constants/api_constants.dart';
import '../config/app_config.dart';
import '../security/session_coordinator.dart';
import '../errors/exceptions.dart';
import 'interceptors/auth_interceptor.dart';

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
    _dio.interceptors.add(
      LogInterceptor(
        request: kDebugMode,
        requestHeader: false,
        requestBody: false,
        responseHeader: false,
        responseBody: false,
        logPrint: (obj) => debugPrint('[API] $obj'),
      ),
    );
  }

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

    switch (response.statusCode) {
      case 401:
        return AuthException(
          message: 'Sesi Anda telah berakhir. Silakan login kembali.',
        );
      case 403:
        return ServerException(
          message: 'Anda tidak memiliki akses.',
          statusCode: 403,
        );
      case 404:
        return ServerException(
          message: 'Data tidak ditemukan.',
          statusCode: 404,
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
        );
    }
  }
}
