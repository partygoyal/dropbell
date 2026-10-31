import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'customer_api.dart';
import 'ui.dart';

class PasswordRecoveryPage extends StatefulWidget {
  const PasswordRecoveryPage({super.key, required this.api});
  final CustomerApi api;
  @override
  State<PasswordRecoveryPage> createState() => _PasswordRecoveryPageState();
}

class _PasswordRecoveryPageState extends State<PasswordRecoveryPage> {
  final email = TextEditingController(),
      password = TextEditingController(),
      confirm = TextEditingController();
  final form = GlobalKey<FormState>();
  bool busy = false, done = false;
  String? message, error, token;
  @override
  void dispose() {
    email.dispose();
    password.dispose();
    confirm.dispose();
    super.dispose();
  }

  Future<void> submit() async {
    if (!form.currentState!.validate() || busy) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final result = await widget.api.request(
        'POST',
        token == null
            ? 'customer/auth/forgot-password'
            : 'customer/auth/reset-password',
        {
          'email': email.text.trim(),
          if (token != null) ...{
            'token': token,
            'password': password.text,
            'password_confirmation': confirm.text,
          },
        },
      );
      if (!mounted) return;
      setState(() {
        message = result['message'];
        if (token != null) {
          done = true;
          token = null;
        } else if (kDebugMode && result['local_reset_url'] != null) {
          token = Uri.parse(result['local_reset_url']).queryParameters['token'];
        }
      });
    } catch (e) {
      if (mounted) setState(() => error = friendlyError(e));
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Reset password')),
    body: ShopBody(
      children: [
        const SectionTitle('Forgot your password?'),
        const Text(
          'Enter your account email. Open the reset link in your email, choose a new password, then return here to sign in.',
        ),
        const SizedBox(height: 20),
        if (!done)
          Form(
            key: form,
            child: Column(
              children: [
                TextFormField(
                  controller: email,
                  enabled: !busy && token == null,
                  keyboardType: TextInputType.emailAddress,
                  decoration: const InputDecoration(labelText: 'Email'),
                  validator: (v) =>
                      RegExp(
                        r'^[^\s@]+@[^\s@]+\.[^\s@]+$',
                      ).hasMatch(v?.trim() ?? '')
                      ? null
                      : 'Enter a valid email',
                ),
                if (token != null) ...[
                  const SizedBox(height: 14),
                  const Text(
                    'Local development reset: no email was sent. Set your new password below.',
                  ),
                  const SizedBox(height: 14),
                  TextFormField(
                    controller: password,
                    obscureText: true,
                    decoration: const InputDecoration(
                      labelText: 'New password',
                    ),
                    validator: (v) => (v?.length ?? 0) < 8
                        ? 'Use at least 8 characters'
                        : null,
                  ),
                  const SizedBox(height: 14),
                  TextFormField(
                    controller: confirm,
                    obscureText: true,
                    decoration: const InputDecoration(
                      labelText: 'Confirm password',
                    ),
                    validator: (v) =>
                        v == password.text ? null : 'Passwords do not match',
                  ),
                ],
              ],
            ),
          ),
        if (message != null)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 16),
            child: Text(message!, style: const TextStyle(color: blue)),
          ),
        if (error != null) ErrorBox(error!),
        if (!done)
          FilledButton(
            onPressed: busy ? null : submit,
            child: Text(
              busy
                  ? 'Please wait…'
                  : token == null
                  ? 'Send reset link'
                  : 'Save new password',
            ),
          ),
        TextButton(
          onPressed: busy ? null : () => Navigator.pop(context),
          child: const Text('Back to sign in'),
        ),
      ],
    ),
  );
}
