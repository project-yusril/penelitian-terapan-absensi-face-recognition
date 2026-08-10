import 'package:dio/dio.dart';
import '../../constants/api_constants.dart';
import '../../security/session_coordinator.dart';
import '../../security/secure_session_store.dart';

class AuthInterceptor extends Interceptor {
  static const _snapshotKey = 'auth.session.snapshot';
  static const _protectedBearerKey = 'auth.protectedBearer';

  final SessionCoordinator _session;
  final Uri _apiOrigin;

  AuthInterceptor(this._session, this._apiOrigin);

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    final snapshot = _session.snapshot;
    final uri = options.uri;
    final sameOrigin =
        uri.scheme == _apiOrigin.scheme &&
        uri.host == _apiOrigin.host &&
        uri.port == _apiOrigin.port;
    if (!sameOrigin) {
      handler.reject(
        DioException(
          requestOptions: options,
          type: DioExceptionType.cancel,
          error: StateError('Cross-origin API request ditolak'),
        ),
      );
      return;
    }
    final isPublicAuth = _isPublicAuthPath(uri.path);
    options.extra[_snapshotKey] = snapshot;
    options.extra[_protectedBearerKey] = !isPublicAuth && snapshot.hasToken;
    if (isPublicAuth) {
      options.headers.remove('Authorization');
    } else if (snapshot.hasToken) {
      options.headers['Authorization'] = 'Bearer ${snapshot.token}';
    }
    options.headers['Accept'] = 'application/json';
    if (options.data is! FormData) {
      options.headers['Content-Type'] = 'application/json';
    }
    handler.next(options);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) async {
    final options = err.requestOptions;
    if (err.response?.statusCode == 401 &&
        options.extra[_protectedBearerKey] == true) {
      final snapshot = options.extra[_snapshotKey];
      if (snapshot is SessionSnapshot) {
        try {
          await _session.invalidateIfCurrent(snapshot);
        } catch (_) {
          // Credential clearing is fail-closed in memory; preserve the 401.
        }
      }
    }
    handler.next(err);
  }

  bool _isPublicAuthPath(String path) {
    return path == _apiOrigin.resolve(ApiConstants.loginEndpoint).path ||
        path == _apiOrigin.resolve(ApiConstants.forgotPasswordEndpoint).path ||
        path == _apiOrigin.resolve(ApiConstants.resetPasswordEndpoint).path;
  }
}
