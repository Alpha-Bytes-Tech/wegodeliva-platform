import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';
import 'package:wegodeliva_ui/wegodeliva_ui.dart';

import '../l10n/generated/auth_localizations.dart';

/// Step 2 of email sign-in (spec 09 §9.1 "OTP entry"): 6-digit code, resend timer, spam hint
/// after 60 s. Pasting or autofilling a 6-digit code submits it straight away.
class OtpScreen extends ConsumerStatefulWidget {
  const OtpScreen({
    super.key,
    required this.email,
    required this.initialTiming,
    this.onChangeEmail,
    this.deviceName,
  });

  final String email;
  final OtpTiming initialTiming;
  final VoidCallback? onChangeEmail;
  final String? deviceName;

  @override
  ConsumerState<OtpScreen> createState() => _OtpScreenState();
}

class _OtpScreenState extends ConsumerState<OtpScreen> {
  static const _spamHintAfter = 60;

  final _code = TextEditingController();
  Timer? _ticker;
  int _resendIn = 0;
  int _elapsed = 0;
  bool _busy = false;
  String? _error;
  String? _info;

  @override
  void initState() {
    super.initState();
    _startCountdown(widget.initialTiming.resendIn);
    _ticker = Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted) return;
      setState(() {
        _elapsed++;
        if (_resendIn > 0) _resendIn--;
      });
    });
  }

  @override
  void dispose() {
    _ticker?.cancel();
    _code.dispose();
    super.dispose();
  }

  void _startCountdown(int seconds) => _resendIn = seconds;

  bool get _complete => RegExp(r'^\d{6}$').hasMatch(_code.text);

  Future<void> _verify() async {
    if (!_complete || _busy) return;
    setState(() {
      _busy = true;
      _error = null;
      _info = null;
    });
    try {
      final session = await ref
          .read(authRepositoryProvider)
          .verifyOtp(widget.email, _code.text, deviceName: widget.deviceName);
      await ref.read(authControllerProvider.notifier).completeSignIn(session);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _error = e.error.message);
      _code.clear();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _resend() async {
    setState(() {
      _error = null;
      _info = null;
    });
    try {
      final timing = await ref.read(authRepositoryProvider).sendOtp(widget.email);
      if (!mounted) return;
      setState(() {
        _startCountdown(timing.resendIn);
        _info = AuthLocalizations.of(context).codeResent;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      final retryAfter = e.error.meta['retry_after'];
      setState(() {
        _error = e.error.message;
        if (retryAfter is int) _startCountdown(retryAfter);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AuthLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(
        leading: widget.onChangeEmail == null
            ? null
            : IconButton(
                icon: const Icon(Icons.arrow_back),
                tooltip: l10n.backLabel,
                onPressed: widget.onChangeEmail,
              ),
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(WgdSpacing.lg),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(l10n.otpTitle, style: Theme.of(context).textTheme.headlineSmall),
              const SizedBox(height: WgdSpacing.sm),
              Text(l10n.otpSentTo(widget.email)),
              const SizedBox(height: WgdSpacing.lg),
              TextField(
                key: const Key('otp-code'),
                controller: _code,
                autofocus: true,
                keyboardType: TextInputType.number,
                autofillHints: const [AutofillHints.oneTimeCode],
                inputFormatters: [
                  FilteringTextInputFormatter.digitsOnly,
                  LengthLimitingTextInputFormatter(6),
                ],
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.headlineMedium?.copyWith(letterSpacing: 8),
                decoration: InputDecoration(labelText: l10n.codeLabel, counterText: ''),
                onChanged: (_) {
                  setState(() {});
                  if (_complete) _verify();
                },
              ),
              if (_error != null) WgdStatusMessage.error(_error!),
              if (_info != null) WgdStatusMessage.info(_info!),
              if (_elapsed >= _spamHintAfter) WgdStatusMessage.info(l10n.spamHint),
              const SizedBox(height: WgdSpacing.md),
              WgdPrimaryButton(
                label: l10n.verifyButton,
                busyLabel: l10n.verifying,
                busy: _busy,
                onPressed: _complete ? _verify : null,
              ),
              const SizedBox(height: WgdSpacing.sm),
              if (_resendIn > 0)
                Text(l10n.resendIn(_resendIn), textAlign: TextAlign.center)
              else
                TextButton(onPressed: _resend, child: Text(l10n.resendButton)),
              if (widget.onChangeEmail != null)
                TextButton(onPressed: widget.onChangeEmail, child: Text(l10n.changeEmail)),
            ],
          ),
        ),
      ),
    );
  }
}
