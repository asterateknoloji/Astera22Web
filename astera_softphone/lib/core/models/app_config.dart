import 'dart:convert';

import 'package:astera_sip/astera_sip.dart';
import 'package:flutter/services.dart';

class AppConfig {
  const AppConfig({
    required this.server,
    required this.port,
    required this.transport,
    required this.username,
    required this.authUsername,
    required this.displayName,
    required this.localSipPort,
  });

  final String server;
  final int port;
  final SipTransport transport;
  final String username;
  final String authUsername;
  final String displayName;
  final int localSipPort;

  static Future<AppConfig> load() async {
    final raw = await rootBundle.loadString('assets/config/app_config.json');
    final root = jsonDecode(raw) as Map<String, dynamic>;
    final sip = root['sip'] as Map<String, dynamic>;
    return AppConfig(
      server: sip['server'] as String,
      port: sip['port'] as int,
      transport: SipTransport.values.byName(sip['transport'] as String),
      username: sip['username'] as String? ?? '',
      authUsername: sip['authUsername'] as String? ?? '',
      displayName: sip['displayName'] as String? ?? '',
      localSipPort: sip['localSipPort'] as int? ?? 0,
    );
  }
}
