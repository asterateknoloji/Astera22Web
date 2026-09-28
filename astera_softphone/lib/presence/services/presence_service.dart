import 'dart:convert';
import 'dart:io';

class ExtensionPresence {
  const ExtensionPresence({
    required this.extension,
    required this.name,
    required this.state,
    required this.label,
    required this.peer,
    required this.direction,
    required this.elapsedSeconds,
  });

  final String extension;
  final String name;
  final String state;
  final String label;
  final String peer;
  final String direction;
  final int elapsedSeconds;

  factory ExtensionPresence.fromJson(Map<String, dynamic> json) {
    return ExtensionPresence(
      extension: json['extension'] as String? ?? '',
      name: json['name'] as String? ?? '',
      state: json['state'] as String? ?? 'offline',
      label: json['label'] as String? ?? 'Çevrimdışı',
      peer: json['peer'] as String? ?? '',
      direction: json['direction'] as String? ?? '',
      elapsedSeconds: (json['elapsed_seconds'] as num?)?.toInt() ?? 0,
    );
  }
}

class PresenceService {
  Future<List<ExtensionPresence>> fetch({
    required String apiUrl,
    required String sipUser,
    required String authUser,
    required String password,
  }) async {
    if (apiUrl.isEmpty) {
      throw StateError('Meşguliyet API URL gerekli.');
    }
    if (sipUser.isEmpty || password.isEmpty) {
      throw StateError('SIP kayıt bilgileri bulunamadı.');
    }
    final baseUri = Uri.tryParse(apiUrl);
    if (baseUri == null || !baseUri.hasScheme) {
      throw FormatException('Meşguliyet API URL geçersiz.');
    }
    final uri = baseUri.replace(
      queryParameters: {
        ...baseUri.queryParameters,
        'sip_user': sipUser,
      },
    );
    final client = HttpClient()..connectionTimeout = const Duration(seconds: 5);
    try {
      final request = await client.getUrl(uri);
      final credentials = base64Encode(
        utf8.encode('${authUser.isEmpty ? sipUser : authUser}:$password'),
      );
      request.headers.set(
        HttpHeaders.authorizationHeader,
        'Basic $credentials',
      );
      final response = await request.close().timeout(
        const Duration(seconds: 15),
      );
      final body = await utf8.decoder.bind(response).join();
      final json = jsonDecode(body) as Map<String, dynamic>;
      if (response.statusCode != HttpStatus.ok || json['ok'] != true) {
        throw HttpException(
          json['error'] as String? ?? 'Meşguliyet bilgisi alınamadı.',
          uri: uri,
        );
      }
      final extensions = json['extensions'] as List<dynamic>? ?? const [];
      return extensions
          .map(
            (item) => ExtensionPresence.fromJson(
              item as Map<String, dynamic>,
            ),
          )
          .where((item) => item.extension.isNotEmpty)
          .toList();
    } finally {
      client.close(force: true);
    }
  }
}
