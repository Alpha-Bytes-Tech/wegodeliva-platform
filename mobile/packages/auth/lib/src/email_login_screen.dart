import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';
import 'package:wegodeliva_ui/wegodeliva_ui.dart';

import '../l10n/generated/auth_localizations.dart';

/// Step 1 of email sign-in: asks for the email address and requests a code.
class EmailLoginScreen extends ConsumerStatefulWidget {
  const EmailLoginScreen({super.key, required this.onCodeSent, this.appSubtitle});

  /// Called after the backend accepted the request (it answers the same for unknown emails).
  final void Function(String email, OtpTiming timing) onCodeSent;

  /// "Vendor" / "Rider" under the wordmark.
  final String? appSubtitle;

  @override
  ConsumerState<EmailLoginScreen> createState() => _EmailLoginScreenState();
}

class _EmailLoginScreenState extends ConsumerState<EmailLoginScreen> {
  static final _emailPattern = RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$');

  final _formKey = GlobalKey<FormState>();
  final _email = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    final email = _email.text.trim();
    try {
      final timing = await ref.read(authRepositoryProvider).sendOtp(email);
      if (!mounted) return;
      widget.onCodeSent(email, timing);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.error.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AuthLocalizations.of(context);
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(WgdSpacing.lg),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    WgdBrandWordmark(subtitle: widget.appSubtitle),
                    const SizedBox(height: WgdSpacing.xl),
                    Text(l10n.loginTitle, style: Theme.of(context).textTheme.headlineSmall),
                    const SizedBox(height: WgdSpacing.sm),
                    Text(l10n.loginSubtitle),
                    const SizedBox(height: WgdSpacing.lg),
                    TextFormField(
                      controller: _email,
                      keyboardType: TextInputType.emailAddress,
                      autofillHints: const [AutofillHints.email],
                      autocorrect: false,
                      textInputAction: TextInputAction.done,
                      decoration: InputDecoration(labelText: l10n.emailLabel),
                      validator: (value) =>
                          _emailPattern.hasMatch(value?.trim() ?? '') ? null : l10n.emailInvalid,
                      onFieldSubmitted: (_) => _submit(),
                    ),
                    if (_error != null) WgdStatusMessage.error(_error!),
                    const SizedBox(height: WgdSpacing.md),
                    WgdPrimaryButton(
                      label: l10n.continueButton,
                      busyLabel: l10n.sendingCode,
                      busy: _busy,
                      onPressed: _submit,
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
