import 'package:flutter/material.dart';

import '../../../../core/constants/api_constants.dart';
import '../../../../core/network/api_client.dart';

/// Alur lupa password mobile dengan token yang dikirim melalui email.
///
/// Langkah 1: kirim email → `POST /auth/forgot-password`.
///   Backend selalu memberi respons generik dan tidak pernah mengembalikan token.
/// Langkah 2: token + password baru → `POST /auth/reset-password`.
class ForgotPasswordPage extends StatefulWidget {
  final ApiClient apiClient;

  const ForgotPasswordPage({super.key, required this.apiClient});

  @override
  State<ForgotPasswordPage> createState() => _ForgotPasswordPageState();
}

class _ForgotPasswordPageState extends State<ForgotPasswordPage> {
  final _formKey = GlobalKey<FormState>();
  final _emailController = TextEditingController();
  final _tokenController = TextEditingController();
  final _passwordController = TextEditingController();
  final _passwordConfirmController = TextEditingController();

  bool _tokenRequested = false;
  bool _loading = false;
  bool _obscure = true;

  @override
  void dispose() {
    _emailController.dispose();
    _tokenController.dispose();
    _passwordController.dispose();
    _passwordConfirmController.dispose();
    super.dispose();
  }

  void _showMessage(String message, {bool error = false}) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: error ? Colors.red : Colors.green,
      ),
    );
  }

  Future<void> _requestToken() async {
    final email = _emailController.text.trim();
    if (email.isEmpty || !email.contains('@')) {
      _showMessage('Masukkan email yang valid.', error: true);
      return;
    }
    setState(() => _loading = true);
    try {
      await widget.apiClient.post(
        ApiConstants.forgotPasswordEndpoint,
        data: {'email': email},
      );
      setState(() => _tokenRequested = true);
      _showMessage(
        'Jika email terdaftar, token reset telah dikirim ke email tersebut.',
      );
    } catch (e) {
      _showMessage(_errorMessage(e), error: true);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _resetPassword() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _loading = true);
    try {
      await widget.apiClient.post(
        ApiConstants.resetPasswordEndpoint,
        data: {
          'email': _emailController.text.trim(),
          'token': _tokenController.text.trim(),
          'password': _passwordController.text,
          'password_confirmation': _passwordConfirmController.text,
        },
      );
      _showMessage('Password berhasil direset. Silakan login kembali.');
      if (mounted) Navigator.of(context).pop();
    } catch (e) {
      _showMessage(_errorMessage(e), error: true);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  String _errorMessage(Object e) {
    return 'Terjadi kesalahan. Coba lagi.';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Lupa Password')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Form(
            key: _formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const Icon(Icons.lock_reset, size: 72, color: Colors.blueGrey),
                const SizedBox(height: 16),
                Text(
                  _tokenRequested
                      ? 'Masukkan token & password baru Anda.'
                      : 'Masukkan email akun Anda. Token reset akan dikirim melalui email jika akun terdaftar.',
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: Colors.black54),
                ),
                const SizedBox(height: 24),
                TextFormField(
                  controller: _emailController,
                  keyboardType: TextInputType.emailAddress,
                  enabled: !_tokenRequested,
                  decoration: const InputDecoration(
                    labelText: 'Email',
                    prefixIcon: Icon(Icons.email_outlined),
                    border: OutlineInputBorder(),
                  ),
                ),
                if (_tokenRequested) ...[
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _tokenController,
                    decoration: const InputDecoration(
                      labelText: 'Token Reset',
                      prefixIcon: Icon(Icons.vpn_key_outlined),
                      border: OutlineInputBorder(),
                    ),
                    validator: (v) => (v == null || v.trim().isEmpty)
                        ? 'Token wajib diisi'
                        : null,
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _passwordController,
                    obscureText: _obscure,
                    decoration: InputDecoration(
                      labelText: 'Password Baru',
                      prefixIcon: const Icon(Icons.lock_outline),
                      border: const OutlineInputBorder(),
                      suffixIcon: IconButton(
                        icon: Icon(
                          _obscure ? Icons.visibility : Icons.visibility_off,
                        ),
                        onPressed: () => setState(() => _obscure = !_obscure),
                      ),
                    ),
                    validator: (v) => (v == null || v.length < 8)
                        ? 'Minimal 8 karakter'
                        : null,
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _passwordConfirmController,
                    obscureText: _obscure,
                    decoration: const InputDecoration(
                      labelText: 'Konfirmasi Password',
                      prefixIcon: Icon(Icons.lock_outline),
                      border: OutlineInputBorder(),
                    ),
                    validator: (v) => (v != _passwordController.text)
                        ? 'Password tidak cocok'
                        : null,
                  ),
                ],
                const SizedBox(height: 24),
                FilledButton(
                  onPressed: _loading
                      ? null
                      : (_tokenRequested ? _resetPassword : _requestToken),
                  child: _loading
                      ? const SizedBox(
                          height: 20,
                          width: 20,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : Text(
                          _tokenRequested
                              ? 'Reset Password'
                              : 'Kirim Instruksi',
                        ),
                ),
                if (_tokenRequested)
                  TextButton(
                    onPressed: _loading
                        ? null
                        : () => setState(() => _tokenRequested = false),
                    child: const Text('Ganti email'),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
