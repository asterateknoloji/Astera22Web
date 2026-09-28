import 'dart:convert';
import 'dart:io';

import 'package:astera_sip/astera_sip.dart';

import '../models/sip_account_profile.dart';

class AccountRepository {
  AccountRepository(this._sip);

  final AsteraSip _sip;
  final List<SipAccountProfile> _accounts = [];
  String? _activeAccountId;
  late final File _file;

  List<SipAccountProfile> get accounts => List.unmodifiable(_accounts);
  String? get activeAccountId => _activeAccountId;

  Future<void> initialize() async {
    final localAppData = Platform.environment['LOCALAPPDATA'];
    final directory = Directory(
      localAppData == null
          ? 'config'
          : '$localAppData${Platform.pathSeparator}AsteraSoftPhone'
                '${Platform.pathSeparator}config',
    );
    await directory.create(recursive: true);
    _file = File('${directory.path}${Platform.pathSeparator}accounts.json');
    if (!await _file.exists()) return;

    final root = jsonDecode(await _file.readAsString()) as Map<String, dynamic>;
    final items = root['accounts'] as List<dynamic>? ?? const [];
    _accounts
      ..clear()
      ..addAll(
        items.map(
          (item) => SipAccountProfile.fromJson(item as Map<String, dynamic>),
        ),
      );
    _activeAccountId = root['activeAccountId'] as String?;
    if (!_accounts.any((account) => account.id == _activeAccountId)) {
      _activeAccountId = null;
    }
  }

  Future<void> save(
    SipAccountProfile profile, {
    String password = '',
    String crmToken = '',
  }) async {
    final index = _accounts.indexWhere((account) => account.id == profile.id);
    if (index == -1) {
      _accounts.add(profile);
    } else {
      _accounts[index] = profile;
    }
    if (password.isNotEmpty) {
      await _sip.saveCredential(profile.id, password);
    }
    if (crmToken.isNotEmpty) {
      await _sip.saveCredential('crm:${profile.id}', crmToken);
    }
    _activeAccountId ??= profile.id;
    await _persist();
  }

  Future<void> setActive(String accountId) async {
    if (!_accounts.any((account) => account.id == accountId)) {
      throw StateError('SIP account does not exist.');
    }
    _activeAccountId = accountId;
    await _persist();
  }

  Future<void> delete(String accountId) async {
    _accounts.removeWhere((account) => account.id == accountId);
    await _sip.deleteCredential(accountId);
    await _sip.deleteCredential('crm:$accountId');
    if (_activeAccountId == accountId) {
      _activeAccountId = _accounts.isEmpty ? null : _accounts.first.id;
    }
    await _persist();
  }

  Future<String?> readPassword(String accountId) =>
      _sip.readCredential(accountId);

  Future<String?> readCrmToken(String accountId) =>
      _sip.readCredential('crm:$accountId');

  Future<void> _persist() async {
    final content = const JsonEncoder.withIndent('  ').convert({
      'version': 1,
      'activeAccountId': _activeAccountId,
      'accounts': _accounts.map((account) => account.toJson()).toList(),
    });
    await _file.writeAsString(content, flush: true);
  }
}
