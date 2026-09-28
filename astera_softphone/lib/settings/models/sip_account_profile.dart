import 'package:astera_sip/astera_sip.dart';

class SipAccountProfile {
  const SipAccountProfile({
    required this.id,
    required this.accountName,
    required this.server,
    required this.port,
    required this.transport,
    required this.username,
    required this.authUsername,
    required this.domain,
    required this.displayName,
    required this.registrationInterval,
    this.proxyServer = '',
    this.crmApiUrl = '',
    this.presenceApiUrl = '',
    this.enabled = true,
  });

  final String id;
  final String accountName;
  final String server;
  final int port;
  final SipTransport transport;
  final String username;
  final String authUsername;
  final String domain;
  final String displayName;
  final int registrationInterval;
  final String proxyServer;
  final String crmApiUrl;
  final String presenceApiUrl;
  final bool enabled;

  factory SipAccountProfile.fromJson(Map<String, dynamic> json) {
    return SipAccountProfile(
      id: json['id'] as String,
      accountName: json['accountName'] as String,
      server: json['server'] as String,
      port: json['port'] as int,
      transport: SipTransport.values.byName(json['transport'] as String),
      username: json['username'] as String,
      authUsername: json['authUsername'] as String? ?? '',
      domain: json['domain'] as String? ?? '',
      displayName: json['displayName'] as String? ?? '',
      registrationInterval: json['registrationInterval'] as int? ?? 300,
      proxyServer: json['proxyServer'] as String? ?? '',
      crmApiUrl: json['crmApiUrl'] as String? ?? '',
      presenceApiUrl: json['presenceApiUrl'] as String? ?? '',
      enabled: json['enabled'] as bool? ?? true,
    );
  }

  Map<String, Object> toJson() => <String, Object>{
    'id': id,
    'accountName': accountName,
    'server': server,
    'port': port,
    'transport': transport.name,
    'username': username,
    'authUsername': authUsername,
    'domain': domain,
    'displayName': displayName,
    'registrationInterval': registrationInterval,
    'proxyServer': proxyServer,
    'crmApiUrl': crmApiUrl,
    'presenceApiUrl': presenceApiUrl,
    'enabled': enabled,
  };

  SipAccount toSipAccount(String password) => SipAccount(
    id: id,
    server: server,
    port: port,
    transport: transport,
    username: username,
    authUsername: authUsername,
    password: password,
    displayName: displayName,
    domain: domain,
    proxyServer: proxyServer,
    registrationInterval: registrationInterval,
    enabled: enabled,
  );
}
