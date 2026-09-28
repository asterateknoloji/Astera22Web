import 'dart:convert';
import 'dart:io';

class CrmContactMatch {
  const CrmContactMatch({
    required this.company,
    required this.contact,
    required this.phone,
  });

  final String company;
  final String contact;
  final String phone;

  String get name => company.isNotEmpty ? company : contact;
  String get title => company.isEmpty ? contact : company;
  String get subtitle => contact.isEmpty ? phone : '$contact  •  $phone';
}

class CrmLookupService {
  Future<List<CrmContactMatch>> search({
    required String apiUrl,
    required String token,
    required String query,
  }) async {
    if (apiUrl.isEmpty || token.isEmpty || query.length < 3) return const [];

    final baseUri = Uri.tryParse(apiUrl);
    if (baseUri == null || !baseUri.hasScheme) return const [];
    final uri = baseUri.replace(
      queryParameters: {...baseUri.queryParameters, 'q': query},
    );
    final client = HttpClient()..connectionTimeout = const Duration(seconds: 5);
    try {
      final request = await client.getUrl(uri);
      request.headers.set(HttpHeaders.authorizationHeader, 'Bearer $token');
      final response = await request.close().timeout(
        const Duration(seconds: 30),
      );
      if (response.statusCode != HttpStatus.ok) return const [];
      final body = await utf8.decoder.bind(response).join();
      final json = jsonDecode(body) as Map<String, dynamic>;
      final contacts = json['contacts'] as List<dynamic>? ?? const [];
      return contacts
          .map((item) {
            final value = item as Map<String, dynamic>;
            return CrmContactMatch(
              company: value['company'] as String? ?? '',
              contact: value['contact'] as String? ?? '',
              phone: value['phone'] as String? ?? '',
            );
          })
          .where((item) => item.name.isNotEmpty)
          .toList();
    } catch (_) {
      return const [];
    } finally {
      client.close(force: true);
    }
  }
}
