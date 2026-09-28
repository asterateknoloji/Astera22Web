import 'dart:async';

import 'package:astera_sip/astera_sip.dart';
import 'package:flutter/material.dart';

import '../../core/models/app_config.dart';
import '../../sip/services/registration_controller.dart';

class RegistrationScreen extends StatefulWidget {
  const RegistrationScreen({
    required this.config,
    required this.controller,
    super.key,
  });

  final AppConfig config;
  final RegistrationController controller;

  @override
  State<RegistrationScreen> createState() => _RegistrationScreenState();
}

class _RegistrationScreenState extends State<RegistrationScreen> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _server;
  late final TextEditingController _port;
  late final TextEditingController _username;
  late final TextEditingController _authUsername;
  late final TextEditingController _displayName;
  final _password = TextEditingController();

  @override
  void initState() {
    super.initState();
    _server = TextEditingController(text: widget.config.server);
    _port = TextEditingController(text: widget.config.port.toString());
    _username = TextEditingController(text: widget.config.username);
    _authUsername = TextEditingController(text: widget.config.authUsername);
    _displayName = TextEditingController(text: widget.config.displayName);
    unawaited(widget.controller.initialize());
  }

  @override
  void dispose() {
    unawaited(widget.controller.shutdown());
    widget.controller.dispose();
    _server.dispose();
    _port.dispose();
    _username.dispose();
    _authUsername.dispose();
    _displayName.dispose();
    _password.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF182437),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(32),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 760),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'ASTERA',
                    style: TextStyle(
                      color: Colors.white,
                      fontSize: 26,
                      fontWeight: FontWeight.w800,
                      letterSpacing: 1.2,
                    ),
                  ),
                  const Text(
                    'SOFTPHONE  •  NATIVE PJSIP',
                    style: TextStyle(
                      color: Color(0xFF9FB1CA),
                      fontSize: 12,
                      letterSpacing: 1.1,
                    ),
                  ),
                  const SizedBox(height: 24),
                  _RegistrationCard(controller: widget.controller),
                  const SizedBox(height: 18),
                  _SettingsCard(
                    formKey: _formKey,
                    server: _server,
                    port: _port,
                    username: _username,
                    authUsername: _authUsername,
                    password: _password,
                    displayName: _displayName,
                    transport: widget.config.transport.name.toUpperCase(),
                  ),
                  const SizedBox(height: 18),
                  AnimatedBuilder(
                    animation: widget.controller,
                    builder: (context, _) => Row(
                      children: [
                        Expanded(
                          child: FilledButton(
                            onPressed: widget.controller.busy
                                ? null
                                : _register,
                            child: const Text('REGISTER'),
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: OutlinedButton(
                            onPressed: widget.controller.busy
                                ? null
                                : widget.controller.unregister,
                            child: const Text('DISCONNECT'),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  void _register() {
    if (!_formKey.currentState!.validate()) return;
    widget.controller.register(
      SipAccount(
        id: _username.text.trim(),
        server: _server.text.trim(),
        port: int.parse(_port.text),
        transport: widget.config.transport,
        username: _username.text.trim(),
        authUsername: _authUsername.text.trim(),
        password: _password.text,
        displayName: _displayName.text.trim(),
      ),
    );
  }
}

class _RegistrationCard extends StatelessWidget {
  const _RegistrationCard({required this.controller});

  final RegistrationController controller;

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: controller,
      builder: (context, _) {
        final event = controller.event;
        final color = switch (event.state) {
          RegistrationState.registered => const Color(0xFF2CB67D),
          RegistrationState.initializing ||
          RegistrationState.registering => const Color(0xFFF4B740),
          RegistrationState.registrationFailed => const Color(0xFFEF5B5B),
          _ => const Color(0xFF8B98AA),
        };
        final label = switch (event.state) {
          RegistrationState.initializing => 'INITIALIZING',
          RegistrationState.registering => 'REGISTERING',
          RegistrationState.registered => 'REGISTERED',
          RegistrationState.registrationFailed => 'REGISTRATION FAILED',
          _ => 'DISCONNECTED',
        };

        return _Card(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 13,
                height: 13,
                margin: const EdgeInsets.only(top: 6),
                decoration: BoxDecoration(color: color, shape: BoxShape.circle),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      label,
                      style: const TextStyle(
                        fontSize: 19,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    const SizedBox(height: 5),
                    Text(
                      event.detail,
                      style: const TextStyle(color: Color(0xFF728096)),
                    ),
                    if (event.statusCode != 0) ...[
                      const SizedBox(height: 5),
                      Text(
                        'SIP ${event.statusCode}',
                        style: TextStyle(
                          color: color,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}

class _SettingsCard extends StatelessWidget {
  const _SettingsCard({
    required this.formKey,
    required this.server,
    required this.port,
    required this.username,
    required this.authUsername,
    required this.password,
    required this.displayName,
    required this.transport,
  });

  final GlobalKey<FormState> formKey;
  final TextEditingController server;
  final TextEditingController port;
  final TextEditingController username;
  final TextEditingController authUsername;
  final TextEditingController password;
  final TextEditingController displayName;
  final String transport;

  @override
  Widget build(BuildContext context) {
    return _Card(
      child: Form(
        key: formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'SIP ACCOUNT',
              style: TextStyle(fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 18),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(child: _field('Server', server)),
                const SizedBox(width: 12),
                SizedBox(
                  width: 110,
                  child: _field(
                    'Port',
                    port,
                    numeric: true,
                    validator: (value) {
                      final number = int.tryParse(value ?? '');
                      return number == null || number < 1 || number > 65535
                          ? 'Invalid port'
                          : null;
                    },
                  ),
                ),
                const SizedBox(width: 12),
                SizedBox(
                  width: 110,
                  child: TextFormField(
                    initialValue: transport,
                    readOnly: true,
                    decoration: const InputDecoration(labelText: 'Transport'),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(child: _field('Username / Extension', username)),
                const SizedBox(width: 12),
                Expanded(child: _field('Auth username', authUsername)),
                const SizedBox(width: 12),
                Expanded(
                  child: _field('Password', password, obscureText: true),
                ),
              ],
            ),
            const SizedBox(height: 12),
            _field('Display name', displayName),
          ],
        ),
      ),
    );
  }

  Widget _field(
    String label,
    TextEditingController controller, {
    bool obscureText = false,
    bool numeric = false,
    String? Function(String?)? validator,
  }) {
    return TextFormField(
      controller: controller,
      obscureText: obscureText,
      keyboardType: numeric ? TextInputType.number : TextInputType.text,
      validator:
          validator ??
          (value) => value == null || value.trim().isEmpty
              ? '$label is required'
              : null,
      decoration: InputDecoration(labelText: label),
    );
  }
}

class _Card extends StatelessWidget {
  const _Card({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(24),
      decoration: BoxDecoration(
        color: const Color(0xFFFBF8F2),
        borderRadius: BorderRadius.circular(14),
      ),
      child: child,
    );
  }
}
