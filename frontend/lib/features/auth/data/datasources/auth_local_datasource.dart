import 'package:shared_preferences/shared_preferences.dart';
import '../../../../core/security/secure_session_store.dart';
import '../models/user_model.dart';

abstract class AuthLocalDataSource {
  Future<String?> getToken();
  Future<void> saveToken(String token);
  Future<void> clearToken();
  Future<void> saveUser(UserModel user);
  Future<UserModel?> getSavedUser();
  Future<void> clearAll();
}

class AuthLocalDataSourceImpl implements AuthLocalDataSource {
  final SecureSessionStore _session;

  AuthLocalDataSourceImpl(SharedPreferences _, this._session);

  @override
  Future<String?> getToken() async {
    return _session.token;
  }

  @override
  Future<void> saveToken(String token) async {
    await _session.saveToken(token);
  }

  @override
  Future<void> clearToken() async {
    await _session.clear();
  }

  @override
  Future<void> saveUser(UserModel user) async {
    await _session.saveProfile(user.toJson());
  }

  @override
  Future<UserModel?> getSavedUser() async {
    final userJson = await _session.readProfile();
    if (userJson == null) return null;
    try {
      return UserModel.fromJson(userJson);
    } catch (e) {
      return null;
    }
  }

  @override
  Future<void> clearAll() async {
    await _session.clear();
  }
}
