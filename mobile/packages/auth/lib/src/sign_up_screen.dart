import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';
import 'package:wegodeliva_ui/wegodeliva_ui.dart';

import '../l10n/generated/auth_localizations.dart';

/// A referral code from a `/r/{code}` link, kept until sign-up uses it (spec 06 §6.8).
class PendingReferral extends Notifier<String?> {
  @override
  String? build() => null;

  void set(String? code) => state = (code == null || code.trim().isEmpty)
      ? null
      : code.trim().toUpperCase();
}

final pendingReferralProvider = NotifierProvider<PendingReferral, String?>(
  PendingReferral.new,
);

/// Customer sign-up (spec 09 §9.1 "Register"): name, email, phone, optional referral code and
/// the terms. The server emails a code straight away, so this continues to the code screen.
class SignUpScreen extends ConsumerStatefulWidget {
  const SignUpScreen({
    super.key,
    required this.onCodeSent,
    this.onSignIn,
    this.onOpenTerms,
  });

  final void Function(String email, OtpTiming timing) onCodeSent;
  final VoidCallback? onSignIn;
  final VoidCallback? onOpenTerms;

  @override
  ConsumerState<SignUpScreen> createState() => _SignUpScreenState();
}

class _SignUpScreenState extends ConsumerState<SignUpScreen> {
  static final _emailPattern = RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$');
  // Nigerian mobile numbers: 0803…, 803…, +234803… (the server normalises and checks again).
  static final _phonePattern = RegExp(r'^(\+?234|0)?[789][01]\d{8}$');

  final _formKey = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  late final TextEditingController _referral;
  bool _acceptTerms = false;
  bool _busy = false;
  String? _error;
  Map<String, List<String>> _fieldErrors = const {};

  @override
  void initState() {
    super.initState();
    _referral = TextEditingController(
      text: ref.read(pendingReferralProvider) ?? '',
    );
  }

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _phone.dispose();
    _referral.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final l10n = AuthLocalizations.of(context);
    setState(() => _fieldErrors = const {});
    if (!(_formKey.currentState?.validate() ?? false)) return;
    if (!_acceptTerms) {
      setState(() => _error = l10n.termsRequired);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    final email = _email.text.trim();
    try {
      final result = await ref
          .read(authRepositoryProvider)
          .register(
            name: _name.text,
            email: email,
            phone: _phone.text.replaceAll(RegExp(r'[\s-]'), ''),
            referralCode: _referral.text,
          );
      ref.read(pendingReferralProvider.notifier).set(null);
      if (!mounted) return;
      widget.onCodeSent(email, result.timing);
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _error = e.error.message;
          _fieldErrors = e.error.fields;
        });
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String? _serverError(String field) => _fieldErrors[field]?.first;

  @override
  Widget build(BuildContext context) {
    final l10n = AuthLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.signUpTitle)),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(WgdSpacing.lg),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Form(
                key: _formKey,
                child: AutofillGroup(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Text(l10n.signUpSubtitle),
                      const SizedBox(height: WgdSpacing.lg),
                      TextFormField(
                        controller: _name,
                        textCapitalization: TextCapitalization.words,
                        autofillHints: const [AutofillHints.name],
                        textInputAction: TextInputAction.next,
                        decoration: InputDecoration(
                          labelText: l10n.nameLabel,
                          errorText: _serverError('name'),
                        ),
                        validator: (v) => (v?.trim().length ?? 0) >= 2
                            ? null
                            : l10n.nameInvalid,
                      ),
                      const SizedBox(height: WgdSpacing.md),
                      TextFormField(
                        controller: _email,
                        keyboardType: TextInputType.emailAddress,
                        autofillHints: const [AutofillHints.email],
                        autocorrect: false,
                        textInputAction: TextInputAction.next,
                        decoration: InputDecoration(
                          labelText: l10n.emailLabel,
                          errorText: _serverError('email'),
                        ),
                        validator: (v) =>
                            _emailPattern.hasMatch(v?.trim() ?? '')
                            ? null
                            : l10n.emailInvalid,
                      ),
                      const SizedBox(height: WgdSpacing.md),
                      TextFormField(
                        controller: _phone,
                        keyboardType: TextInputType.phone,
                        autofillHints: const [AutofillHints.telephoneNumber],
                        inputFormatters: [
                          FilteringTextInputFormatter.allow(
                            RegExp(r'[\d+\s-]'),
                          ),
                        ],
                        textInputAction: TextInputAction.next,
                        decoration: InputDecoration(
                          labelText: l10n.phoneLabel,
                          hintText: '0803 123 4567',
                          errorText: _serverError('phone'),
                        ),
                        validator: (v) =>
                            _phonePattern.hasMatch(
                              (v ?? '').replaceAll(RegExp(r'[\s-]'), ''),
                            )
                            ? null
                            : l10n.phoneInvalid,
                      ),
                      const SizedBox(height: WgdSpacing.md),
                      TextFormField(
                        controller: _referral,
                        textCapitalization: TextCapitalization.characters,
                        autocorrect: false,
                        decoration: InputDecoration(
                          labelText: l10n.referralLabel,
                          errorText: _serverError('referral_code'),
                        ),
                      ),
                      const SizedBox(height: WgdSpacing.sm),
                      CheckboxListTile(
                        value: _acceptTerms,
                        contentPadding: EdgeInsets.zero,
                        controlAffinity: ListTileControlAffinity.leading,
                        onChanged: (v) =>
                            setState(() => _acceptTerms = v ?? false),
                        title: Text(l10n.acceptTerms),
                        subtitle: widget.onOpenTerms == null
                            ? null
                            : Align(
                                alignment: Alignment.centerLeft,
                                child: TextButton(
                                  onPressed: widget.onOpenTerms,
                                  child: Text(l10n.readTerms),
                                ),
                              ),
                      ),
                      if (_error != null) WgdStatusMessage.error(_error!),
                      const SizedBox(height: WgdSpacing.md),
                      WgdPrimaryButton(
                        label: l10n.createAccount,
                        busyLabel: l10n.creatingAccount,
                        busy: _busy,
                        onPressed: _submit,
                      ),
                      if (widget.onSignIn != null) ...[
                        const SizedBox(height: WgdSpacing.sm),
                        TextButton(
                          onPressed: widget.onSignIn,
                          child: Text(l10n.haveAccount),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
