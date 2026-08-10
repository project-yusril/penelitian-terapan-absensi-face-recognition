import '../../../../core/constants/api_constants.dart';
import '../../../../core/network/api_client.dart';
import '../models/user_model.dart';

abstract class AuthRemoteDataSource {
  Future<Map<String, dynamic>> login(String login, String password);
  Future<void> logout();
  Future<UserModel> getCurrentUser();
  Future<void> changePassword(
    String oldPassword,
    String newPassword,
    String confirmPassword,
  );
  Future<void> forgotPassword(String email);
  Future<void> updateFcmToken(String token);
  Future<UserModel> updateProfile(Map<String, dynamic> data);
}

class AuthRemoteDataSourceImpl implements AuthRemoteDataSource {
  final ApiClient _apiClient;

  AuthRemoteDataSourceImpl(this._apiClient);

  @override
  Future<Map<String, dynamic>> login(String login, String password) async {
    try {
      final response = await _apiClient.post(
        ApiConstants.loginEndpoint,
        data: {'login': login, 'password': password},
      );

      final data = response.data['data'];
      return {
        'user': UserModel.fromJson(data['user']),
        'token': data['token'] as String,
      };
    } catch (e) {
      rethrow;
    }
  }

  @override
  Future<void> logout() async {
    try {
      await _apiClient.post(ApiConstants.logoutEndpoint);
    } catch (e) {
      rethrow;
    }
  }

  @override
  Future<UserModel> getCurrentUser() async {
    try {
      final response = await _apiClient.get(ApiConstants.meEndpoint);
      return UserModel.fromJson(response.data['data']);
    } catch (e) {
      rethrow;
    }
  }

  @override
  Future<void> changePassword(
    String oldPassword,
    String newPassword,
    String confirmPassword,
  ) async {
    try {
      await _apiClient.post(
        ApiConstants.changePasswordEndpoint,
        data: {
          'current_password': oldPassword,
          'new_password': newPassword,
          'new_password_confirmation': confirmPassword,
        },
      );
    } catch (e) {
      rethrow;
    }
  }

  @override
  Future<void> forgotPassword(String email) async {
    try {
      await _apiClient.post(
        ApiConstants.forgotPasswordEndpoint,
        data: {'email': email},
      );
    } catch (e) {
      rethrow;
    }
  }

  @override
  Future<void> updateFcmToken(String token) async {
    try {
      await _apiClient.post(
        ApiConstants.fcmTokenEndpoint,
        data: {'fcm_token': token},
      );
    } catch (e) {
      rethrow;
    }
  }

  @override
  Future<UserModel> updateProfile(Map<String, dynamic> data) async {
    try {
      final response = await _apiClient.put(
        ApiConstants.profileEndpoint,
        data: data,
      );
      return UserModel.fromJson(response.data['data']);
    } catch (e) {
      rethrow;
    }
  }
}
