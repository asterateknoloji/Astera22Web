enum SipTransport { udp, tcp, tls }

class SipAccount {
  const SipAccount({
    required this.id,
    required this.server,
    required this.port,
    required this.transport,
    required this.username,
    required this.password,
    required this.displayName,
    this.authUsername = '',
    this.domain = '',
    this.proxyServer = '',
    this.registrationInterval = 300,
    this.enabled = true,
  });

  final String id;
  final String server;
  final int port;
  final SipTransport transport;
  final String username;
  final String authUsername;
  final String password;
  final String displayName;
  final String domain;
  final String proxyServer;
  final int registrationInterval;
  final bool enabled;

  void validate() {
    if (server.trim().isEmpty) {
      throw const FormatException('SIP server is required.');
    }
    if (port < 1 || port > 65535) {
      throw const FormatException('SIP port must be between 1 and 65535.');
    }
    if (username.trim().isEmpty) {
      throw const FormatException('SIP username is required.');
    }
    if (password.isEmpty) {
      throw const FormatException('SIP password is required.');
    }
    if (transport != SipTransport.udp) {
      throw const FormatException('Phase 1 supports UDP transport only.');
    }
    if (registrationInterval < 1) {
      throw const FormatException('Registration interval must be positive.');
    }
  }

  Map<String, Object> toChannelMap() {
    validate();
    return <String, Object>{
      'id': id,
      'server': server.trim(),
      'port': port,
      'transport': transport.name,
      'username': username.trim(),
      'authUsername': authUsername.trim().isEmpty
          ? username.trim()
          : authUsername.trim(),
      'password': password,
      'displayName': displayName.trim(),
      'domain': domain.trim(),
      'proxyServer': proxyServer.trim(),
      'registrationInterval': registrationInterval,
      'enabled': enabled,
    };
  }
}
