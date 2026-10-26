import 'dart:async';

import 'package:astera_sip/astera_sip.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../contacts/services/crm_lookup_service.dart';
import '../../core/models/app_config.dart';
import '../../presence/services/presence_service.dart';
import '../../settings/models/sip_account_profile.dart';
import '../../settings/services/account_repository.dart';
import '../../sip/services/registration_controller.dart';

class AccountsScreen extends StatefulWidget {
  const AccountsScreen({
    required this.config,
    required this.registration,
    required this.repository,
    super.key,
  });

  final AppConfig config;
  final RegistrationController registration;
  final AccountRepository repository;

  @override
  State<AccountsScreen> createState() => _AccountsScreenState();
}

class _AccountsScreenState extends State<AccountsScreen> {
  static const _windowChannel = MethodChannel('tr.com.astera/window');
  bool _loading = true;
  String? _error;
  final _dial = TextEditingController();
  String _lastDialValue = '';
  String? _displayedIncomingUri;
  String? _incomingCallerName;
  String? _outboundCrmName;
  List<CrmContactMatch> _crmMatches = const [];
  final CrmLookupService _crmLookup = CrmLookupService();
  Timer? _crmDebounce;
  int _crmRequestSequence = 0;
  bool _callWasActive = false;
  final List<_CallHistoryEntry> _callHistory = [];
  final Map<String, String> _callHistoryCrmNames = {};
  final Set<String> _callHistoryCrmChecked = {};
  final PresenceService _presenceService = PresenceService();
  Timer? _presenceTimer;
  bool _presenceOpen = false;
  bool _presenceLoading = false;
  final Set<String> _crmTriggerSeen = {};
  final Set<String> _crmTriggerPending = {};
  bool _audioWarningShown = false;

  @override
  void initState() {
    super.initState();
    _windowChannel.setMethodCallHandler(_handleWindowMethod);
    widget.registration.addListener(_syncIncomingNumber);
    unawaited(_initialize());
  }

  Future<void> _initialize() async {
    try {
      await widget.registration.initialize();
      await widget.repository.initialize();
      widget.registration.configureAutoAnswer(
        enabled: widget.repository.autoAnswerEnabled,
        ringCount: widget.repository.autoAnswerRingCount,
      );
      var ringtoneStyle = widget.repository.ringtoneStyle;
      final ringtoneFilePath = widget.repository.ringtoneFilePath;
      if (ringtoneStyle == 3 && ringtoneFilePath != null) {
        try {
          await widget.registration.setRingtoneFile(ringtoneFilePath);
        } catch (_) {
          ringtoneStyle = 0;
          await widget.repository.setRingtoneStyle(ringtoneStyle);
        }
      }
      await widget.registration.setRingtoneStyle(ringtoneStyle);
      await _restoreAudioDevices();
      final activeId = widget.repository.activeAccountId;
      if (activeId != null) {
        final matches = widget.repository.accounts.where(
          (account) => account.id == activeId,
        );
        if (matches.isNotEmpty) {
          await _connect(matches.first);
        }
      }
    } catch (error) {
      _error = error.toString();
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  void dispose() {
    _windowChannel.setMethodCallHandler(null);
    widget.registration.removeListener(_syncIncomingNumber);
    unawaited(widget.registration.shutdown());
    widget.registration.dispose();
    _crmDebounce?.cancel();
    _presenceTimer?.cancel();
    _dial.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF182437),
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 430),
            child: Padding(
              padding: const EdgeInsets.all(10),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      const Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              'ASTERA',
                              maxLines: 1,
                              style: TextStyle(
                                color: Colors.white,
                                fontSize: 22,
                                fontWeight: FontWeight.w800,
                                letterSpacing: 1.2,
                              ),
                            ),
                            Text(
                              'SOFTPHONE  •  SIP HESAPLARI',
                              maxLines: 1,
                              style: TextStyle(
                                color: Color(0xFF9FB1CA),
                                fontSize: 10,
                                letterSpacing: 1.1,
                              ),
                            ),
                          ],
                        ),
                      ),
                      TextButton.icon(
                        onPressed: _openWebsite,
                        icon: const Icon(Icons.language, size: 14),
                        label: const Text('Web sitesi: www.astera.com.tr'),
                        style: TextButton.styleFrom(
                          foregroundColor: const Color(0xFF61D7A5),
                          minimumSize: Size.zero,
                          padding: const EdgeInsets.symmetric(
                            horizontal: 6,
                            vertical: 6,
                          ),
                          textStyle: const TextStyle(
                            fontSize: 10,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  SizedBox(
                    height: 34,
                    child: Row(
                      children: [
                        Expanded(
                          child: FilledButton.icon(
                            onPressed: _loading ? null : () => _openEditor(),
                            icon: const Icon(Icons.person_add_alt_1, size: 15),
                            label: const Text('Hesap Ekle'),
                            style: FilledButton.styleFrom(
                              minimumSize: Size.zero,
                              padding: const EdgeInsets.symmetric(
                                horizontal: 6,
                              ),
                              textStyle: const TextStyle(
                                fontSize: 10,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ),
                        ),
                        const SizedBox(width: 5),
                        Expanded(
                          child: FilledButton.icon(
                            onPressed: _loading ? null : _openCallHistory,
                            icon: const Icon(Icons.history, size: 15),
                            label: const Text('Aramalar'),
                            style: FilledButton.styleFrom(
                              minimumSize: Size.zero,
                              padding: const EdgeInsets.symmetric(
                                horizontal: 6,
                              ),
                              backgroundColor: const Color(0xFFF4B740),
                              foregroundColor: const Color(0xFF182437),
                              textStyle: const TextStyle(
                                fontSize: 10,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ),
                        ),
                        const SizedBox(width: 5),
                        Expanded(
                          child: FilledButton.icon(
                            onPressed: _loading || _presenceLoading
                                ? null
                                : _togglePresenceBoard,
                            icon: _presenceLoading
                                ? const SizedBox.square(
                                    dimension: 13,
                                    child: CircularProgressIndicator(
                                      strokeWidth: 2,
                                    ),
                                  )
                                : const Icon(Icons.groups_outlined, size: 15),
                            label: const Text('Meşguliyet'),
                            style: FilledButton.styleFrom(
                              minimumSize: Size.zero,
                              padding: const EdgeInsets.symmetric(
                                horizontal: 6,
                              ),
                              backgroundColor: const Color(0xFF35C98A),
                              foregroundColor: const Color(0xFF10251D),
                              textStyle: const TextStyle(
                                fontSize: 10,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ),
                        ),
                        const SizedBox(width: 5),
                        Expanded(
                          child: FilledButton.icon(
                            onPressed: _loading ? null : _openAudioSettings,
                            icon: const Icon(Icons.settings_outlined, size: 15),
                            label: const Text('Ayarlar'),
                            style: FilledButton.styleFrom(
                              minimumSize: Size.zero,
                              padding: const EdgeInsets.symmetric(
                                horizontal: 6,
                              ),
                              backgroundColor: const Color(0xFF607086),
                              foregroundColor: Colors.white,
                              textStyle: const TextStyle(
                                fontSize: 10,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 8),
                  _RegistrationStatus(
                    controller: widget.registration,
                    onChanged: _toggleRegistration,
                  ),
                  const SizedBox(height: 6),
                  Expanded(
                    child: _loading
                        ? const Center(child: CircularProgressIndicator())
                        : _buildAccounts(),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildAccounts() {
    if (_error != null) {
      return Center(
        child: Text(_error!, style: const TextStyle(color: Colors.redAccent)),
      );
    }
    final accounts = widget.repository.accounts;
    if (accounts.isEmpty) {
      return Center(
        child: _Panel(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(
                Icons.person_add_alt_1,
                size: 48,
                color: Color(0xFF728096),
              ),
              const SizedBox(height: 14),
              const Text(
                'Henüz SIP hesabı eklenmedi',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 8),
              const Text(
                'Başlamak için sunucu ve hesap bilgilerini ekleyin.',
                style: TextStyle(color: Color(0xFF728096)),
              ),
              const SizedBox(height: 18),
              FilledButton.icon(
                onPressed: () => _openEditor(),
                icon: const Icon(Icons.add),
                label: const Text('HESAP EKLE'),
              ),
            ],
          ),
        ),
      );
    }

    final activeId = widget.repository.activeAccountId ?? accounts.first.id;
    final active = accounts.firstWhere(
      (account) => account.id == activeId,
      orElse: () => accounts.first,
    );
    return SingleChildScrollView(
      child: Column(
        children: [
          _Panel(
            child: Row(
              children: [
                Expanded(
                  child: DropdownButtonFormField<String>(
                    initialValue: active.id,
                    decoration: const InputDecoration(
                      labelText: 'Aktif hesap',
                      prefixIcon: Icon(Icons.account_circle_outlined),
                      isDense: true,
                    ),
                    items: accounts
                        .map(
                          (account) => DropdownMenuItem(
                            value: account.id,
                            child: Text(
                              '${account.accountName} (${account.username})',
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        )
                        .toList(),
                    onChanged: (id) {
                      if (id == null) return;
                      _activate(
                        accounts.firstWhere((account) => account.id == id),
                      );
                    },
                  ),
                ),
                IconButton.filled(
                  tooltip: 'Hesabı düzenle',
                  onPressed: () => _openEditor(active),
                  style: IconButton.styleFrom(
                    backgroundColor: const Color(0xFFF4B740),
                    foregroundColor: const Color(0xFF182437),
                    fixedSize: const Size(32, 32),
                    padding: EdgeInsets.zero,
                  ),
                  icon: const Icon(Icons.edit_outlined),
                ),
                const SizedBox(width: 6),
                IconButton.filled(
                  tooltip: 'Hesabı sil',
                  onPressed: () => _delete(active),
                  style: IconButton.styleFrom(
                    backgroundColor: const Color(0xFF182437),
                    foregroundColor: Colors.white,
                    fixedSize: const Size(32, 32),
                    padding: EdgeInsets.zero,
                  ),
                  icon: const Icon(Icons.delete_outline),
                ),
              ],
            ),
          ),
          const SizedBox(height: 8),
          _Panel(
            child: Column(
              children: [
                TextField(
                  controller: _dial,
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.w600,
                    letterSpacing: 1.5,
                  ),
                  decoration: InputDecoration(
                    hintText: 'Numara girin',
                    labelText: _incomingCallerName ?? _outboundCrmName,
                    floatingLabelBehavior:
                        (_incomingCallerName ?? _outboundCrmName) == null
                        ? FloatingLabelBehavior.auto
                        : FloatingLabelBehavior.always,
                    prefixIcon: const Icon(Icons.dialpad),
                    isDense: true,
                    contentPadding: const EdgeInsets.symmetric(vertical: 10),
                  ),
                  onChanged: _onDialChanged,
                  onSubmitted: (_) => _submitDial(),
                  textInputAction: TextInputAction.done,
                ),
                if (_crmMatches.isNotEmpty)
                  _CrmSuggestions(
                    matches: _crmMatches.take(4).toList(),
                    onSelect: _selectCrmMatch,
                    onCall: _callCrmMatch,
                  ),
                const SizedBox(height: 7),
                _DialPad(onDigit: _appendDigit),
                const SizedBox(height: 6),
                Row(
                  children: [
                    Expanded(
                      child: OutlinedButton(
                        style: OutlinedButton.styleFrom(
                          foregroundColor: const Color(0xFF172033),
                          minimumSize: const Size(0, 34),
                          tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                        ),
                        onPressed: _dial.text.isEmpty
                            ? null
                            : () {
                                final text = _dial.text;
                                _dial.text = text.substring(0, text.length - 1);
                                _lastDialValue = _dial.text;
                                setState(() {});
                                _scheduleCrmLookup(_dial.text);
                              },
                        child: const Icon(Icons.backspace_outlined),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: OutlinedButton(
                        style: OutlinedButton.styleFrom(
                          foregroundColor: const Color(0xFF172033),
                          minimumSize: const Size(0, 34),
                          tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                        ),
                        onPressed: () {
                          _dial.clear();
                          _lastDialValue = '';
                          _clearCrmMatch();
                        },
                        child: const Text('C'),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                AnimatedBuilder(
                  animation: widget.registration,
                  builder: (context, _) {
                    final incoming =
                        widget.registration.callEvent.state ==
                        CallState.incoming;
                    final canCall =
                        widget.registration.callEvent.state == CallState.idle ||
                        widget.registration.callEvent.state ==
                            CallState.disconnected ||
                        widget.registration.callEvent.state == CallState.failed;
                    return Column(
                      children: [
                        SizedBox(
                          width: double.infinity,
                          child: FilledButton.icon(
                            style: FilledButton.styleFrom(
                              minimumSize: const Size(0, 38),
                              tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                            ),
                            onPressed: incoming
                                ? _answerCall
                                : canCall && _dial.text.trim().isNotEmpty
                                ? _makeCall
                                : null,
                            icon: Icon(
                              incoming ? Icons.call : Icons.phone_forwarded,
                            ),
                            label: Text(incoming ? 'CEVAPLA' : 'ARA'),
                          ),
                        ),
                        const SizedBox(height: 8),
                        _PersistentCallControls(
                          controller: widget.registration,
                          onTransfer: () =>
                              _showTransferDialog(widget.registration),
                          onAttendedTransfer: () =>
                              _showAttendedTransferDialog(widget.registration),
                          onError: _showError,
                        ),
                      ],
                    );
                  },
                ),
              ],
            ),
          ),
          const SizedBox(height: 8),
          AnimatedBuilder(
            animation: widget.registration,
            builder: (context, _) => _BehaviorBar(
              controller: widget.registration,
              onAutoAnswerChanged: _setAutoAnswer,
              onAutoAnswerRingCountChanged: _setAutoAnswerRingCount,
            ),
          ),
        ],
      ),
    );
  }

  void _appendDigit(String digit) {
    _dial.text += digit;
    _lastDialValue = _dial.text;
    _dial.selection = TextSelection.collapsed(offset: _dial.text.length);
    setState(() {});
    _scheduleCrmLookup(_dial.text);
    unawaited(widget.registration.dialDigit(digit).catchError(_showError));
  }

  void _onDialChanged(String value) {
    final addedCharacter = value.length > _lastDialValue.length;
    _lastDialValue = value;
    setState(() {});
    _scheduleCrmLookup(value);
    if (!addedCharacter || value.isEmpty) return;
    final digit = value[value.length - 1];
    if ('0123456789*#'.contains(digit)) {
      unawaited(widget.registration.dialDigit(digit).catchError(_showError));
    }
  }

  void _scheduleCrmLookup(String value) {
    _crmDebounce?.cancel();
    final digits = value.replaceAll(RegExp(r'\D'), '');
    if (digits.length < 3 || _incomingCallerName != null) {
      _clearCrmMatch();
      return;
    }
    final sequence = ++_crmRequestSequence;
    _crmDebounce = Timer(const Duration(milliseconds: 300), () async {
      final activeId = widget.repository.activeAccountId;
      final matches = widget.repository.accounts.where(
        (account) => account.id == activeId,
      );
      if (matches.isEmpty || matches.first.crmApiUrl.isEmpty) return;
      final token = await widget.repository.readCrmToken(matches.first.id);
      final result = await _crmLookup.search(
        apiUrl: matches.first.crmApiUrl,
        token: token ?? '',
        query: digits,
      );
      if (!mounted || sequence != _crmRequestSequence) return;
      setState(() {
        _crmMatches = result;
        _outboundCrmName = result.isEmpty ? null : result.first.name;
      });
    });
  }

  void _selectCrmMatch(CrmContactMatch match) {
    _dial.value = TextEditingValue(
      text: match.phone,
      selection: TextSelection.collapsed(offset: match.phone.length),
    );
    _lastDialValue = match.phone;
    setState(() {
      _crmMatches = const [];
      _outboundCrmName = match.name;
    });
  }

  void _callCrmMatch(CrmContactMatch match) {
    _selectCrmMatch(match);
    _submitDial();
  }

  void _clearCrmMatch() {
    _crmRequestSequence++;
    if (!mounted) return;
    setState(() {
      _crmMatches = const [];
      _outboundCrmName = null;
    });
  }

  void _submitDial() {
    final state = widget.registration.callEvent.state;
    final canCall =
        state == CallState.idle ||
        state == CallState.disconnected ||
        state == CallState.failed;
    if (canCall && _dial.text.trim().isNotEmpty) {
      unawaited(_makeCall());
    }
  }

  void _syncIncomingNumber() {
    final event = widget.registration.callEvent;
    if (event.state == CallState.failed &&
        _looksLikeAudioDeviceError(event.detail)) {
      unawaited(
        _showSystemAudioWarning(
          'Arama için mikrofon veya hoparlör/kulaklık kullanılamıyor.\n\n'
          'Aygıtı bağlayın ve Ayarlar bölümünden yeniden seçin.',
        ),
      );
    }
    final active =
        event.state == CallState.incoming ||
        event.state == CallState.calling ||
        event.state == CallState.ringing ||
        event.state == CallState.connected ||
        event.state == CallState.held;
    if (active) _callWasActive = true;
    if (event.state == CallState.incoming) {
      _crmDebounce?.cancel();
      _crmRequestSequence++;
      _crmMatches = const [];
      _outboundCrmName = null;
      if (_displayedIncomingUri == event.remoteUri) return;
      _displayedIncomingUri = event.remoteUri;
      unawaited(
        _windowChannel.invokeMethod<void>('bringToFront').catchError((_) {}),
      );
      final match = RegExp(r'sip:([^@;>]+)').firstMatch(event.remoteUri);
      final number = match?.group(1) ?? event.remoteUri;
      unawaited(_openCrmTrigger('ring', number));
      final callerName = _callerNameFromUri(event.remoteUri, number);
      _dial.value = TextEditingValue(
        text: number,
        selection: TextSelection.collapsed(offset: number.length),
      );
      _lastDialValue = number;
      if (_incomingCallerName != callerName && mounted) {
        setState(() => _incomingCallerName = callerName);
      }
      _addCallHistory(number, _CallDirection.incoming);
    } else if (event.state == CallState.connected &&
        _displayedIncomingUri != null) {
      final match = RegExp(r'sip:([^@;>]+)').firstMatch(_displayedIncomingUri!);
      final number = match?.group(1) ?? _displayedIncomingUri!;
      unawaited(_openCrmTrigger('answer', number));
    } else if (event.state == CallState.idle ||
        event.state == CallState.disconnected ||
        event.state == CallState.failed) {
      _displayedIncomingUri = null;
      _crmTriggerSeen.clear();
      _crmTriggerPending.clear();
      if (_incomingCallerName != null && mounted) {
        setState(() => _incomingCallerName = null);
      }
      if (_callWasActive) {
        _callWasActive = false;
        _dial.clear();
        _lastDialValue = '';
        _clearCrmMatch();
        if (widget.repository.accounts.isNotEmpty) {
          unawaited(_refreshCallHistory());
        }
      }
    }
  }

  String? _callerNameFromUri(String uri, String number) {
    final quoted = RegExp(r'^\s*"([^"]+)"\s*<').firstMatch(uri);
    final unquoted = RegExp(
      r'^\s*([^<"]+?)\s*<sip:',
      caseSensitive: false,
    ).firstMatch(uri);
    final name = (quoted?.group(1) ?? unquoted?.group(1) ?? '').trim();
    if (name.isEmpty || name.toLowerCase() == 'unknown') return null;
    final nameDigits = name.replaceAll(RegExp(r'\D'), '');
    final numberDigits = number.replaceAll(RegExp(r'\D'), '');
    final numericOnly = RegExp(r'^[\s+().-]*\d[\s+().\d-]*$').hasMatch(name);
    if (numericOnly && nameDigits == numberDigits) return null;
    return name;
  }

  Future<void> _openCrmTrigger(String event, String number) async {
    final digits = number.replaceAll(RegExp(r'\D'), '');
    if (digits.isEmpty) return;
    final eventKey = '$event|$digits';
    if (_crmTriggerSeen.contains(eventKey) ||
        !_crmTriggerPending.add(eventKey)) {
      return;
    }

    try {
      try {
        await _loadPresence();
      } catch (_) {
        // CRM politikası santralden doğrulanamıyorsa güvenli biçimde kapalı
        // kabul edilir. AMI hatasında politika yine de alınmış olabilir.
        if (_presenceService.lastUrlTrigger == null) return;
      }
      final config = _presenceService.lastUrlTrigger;
      if (config == null || config.urlTemplate.isEmpty) return;
      if (digits.length < config.minDigits) return;
      if (config.trigger != event && config.trigger != 'both') return;

      final caller = switch (config.numberFormat) {
        'raw' => number,
        'e164_tr' =>
          digits.startsWith('90')
              ? '+$digits'
              : (digits.startsWith('0')
                    ? '+90${digits.substring(1)}'
                    : '+90$digits'),
        _ => digits,
      };
      final replacements = {
        '{caller}': Uri.encodeComponent(caller),
        '{extension}': Uri.encodeComponent(config.extension),
        '{department}': Uri.encodeComponent(config.department),
        '{event}': Uri.encodeComponent(event),
        '{callid}': '',
      };
      var url = config.urlTemplate;
      for (final entry in replacements.entries) {
        url = url.replaceAll(entry.key, entry.value);
      }
      if (!config.urlTemplate.contains('{caller}')) {
        url += Uri.encodeComponent(caller);
      }
      var uri = Uri.tryParse(url);
      if (uri == null || !['http', 'https'].contains(uri.scheme)) return;
      if (config.accessToken.isNotEmpty &&
          uri.host.toLowerCase() == config.panelHost.toLowerCase()) {
        uri = uri.replace(
          queryParameters: {
            ...uri.queryParameters,
            'softphone_token': config.accessToken,
          },
        );
      }

      await _windowChannel.invokeMethod<void>(
        'openExternalUrl',
        uri.toString(),
      );
      _crmTriggerSeen.add(eventKey);
    } catch (error) {
      _showError('CRM açılamadı: $error');
    } finally {
      _crmTriggerPending.remove(eventKey);
    }
  }

  Future<void> _makeCall() async {
    final destination = _dial.text.trim();
    try {
      await widget.registration.makeCall(destination);
      _addCallHistory(destination, _CallDirection.outgoing);
    } catch (error) {
      _showError(error);
    }
  }

  void _addCallHistory(String number, _CallDirection direction) {
    final accountId = widget.repository.activeAccountId;
    if (accountId == null || number.isEmpty) return;
    _callHistory.insert(
      0,
      _CallHistoryEntry(
        accountId: accountId,
        number: number,
        direction: direction,
        startedAt: DateTime.now(),
      ),
    );
    if (_callHistory.length > 100) {
      _callHistory.removeRange(100, _callHistory.length);
    }
    unawaited(_refreshCallHistory());
  }

  Future<void> _openCallHistory() => _sendCallHistory('showCallHistory');

  Future<void> _refreshCallHistory() => _sendCallHistory('updateCallHistory');

  Future<void> _sendCallHistory(String method) async {
    if (widget.repository.accounts.isEmpty) {
      if (method == 'showCallHistory') {
        _showError('Önce bir SIP hesabı ekleyin.');
      }
      return;
    }
    final accountId = widget.repository.activeAccountId;
    final calls = _callHistory
        .where((entry) => entry.accountId == accountId)
        .toList();
    final active = widget.repository.accounts.firstWhere(
      (account) => account.id == accountId,
      orElse: () => widget.repository.accounts.first,
    );
    await _resolveCallHistoryCrmNames(active, calls);
    await _windowChannel.invokeMethod<void>(method, {
      'title': 'Aramalar - ${active.accountName}',
      'labels': calls.map((call) {
        final incoming = call.direction == _CallDirection.incoming;
        final hour = call.startedAt.hour.toString().padLeft(2, '0');
        final minute = call.startedAt.minute.toString().padLeft(2, '0');
        final crmName = _callHistoryCrmNames[_historyCrmKey(call)];
        final identity = crmName == null
            ? call.number
            : '$crmName  •  ${call.number}';
        return '${incoming ? 'Gelen' : 'Giden'}  $identity  $hour:$minute';
      }).toList(),
      'numbers': calls.map((call) => call.number).toList(),
    });
  }

  Future<void> _resolveCallHistoryCrmNames(
    SipAccountProfile account,
    List<_CallHistoryEntry> calls,
  ) async {
    if (account.crmApiUrl.isEmpty || calls.isEmpty) return;
    final token = await widget.repository.readCrmToken(account.id);
    if (token == null || token.isEmpty) return;

    final pending = <String, _CallHistoryEntry>{};
    for (final call in calls) {
      final key = _historyCrmKey(call);
      final digits = call.number.replaceAll(RegExp(r'\D'), '');
      if (digits.length >= 3 && !_callHistoryCrmChecked.contains(key)) {
        pending.putIfAbsent(key, () => call);
      }
      if (pending.length >= 20) break;
    }
    await Future.wait(
      pending.entries.map((entry) async {
        final matches = await _crmLookup.search(
          apiUrl: account.crmApiUrl,
          token: token,
          query: entry.value.number,
        );
        _callHistoryCrmChecked.add(entry.key);
        if (matches.isNotEmpty) {
          final match = matches.first;
          _callHistoryCrmNames[entry.key] = match.contact.isEmpty
              ? match.company
              : '${match.company} / ${match.contact}';
        }
      }),
    );
  }

  String _historyCrmKey(_CallHistoryEntry call) =>
      '${call.accountId}:${call.number.replaceAll(RegExp(r'\D'), '')}';

  Future<void> _handleWindowMethod(MethodCall call) async {
    if (call.method == 'presenceBoardClosed') {
      _presenceTimer?.cancel();
      _presenceTimer = null;
      if (mounted) setState(() => _presenceOpen = false);
      return;
    }
    if (call.method != 'callHistorySelected' &&
        call.method != 'presenceSelected') {
      return;
    }
    final number = call.arguments as String?;
    if (number == null || number.isEmpty) return;
    _dial.value = TextEditingValue(
      text: number,
      selection: TextSelection.collapsed(offset: number.length),
    );
    _lastDialValue = number;
    await _makeCall();
  }

  Future<void> _togglePresenceBoard() async {
    if (_presenceOpen) {
      _presenceTimer?.cancel();
      _presenceTimer = null;
      await _windowChannel.invokeMethod<void>('togglePresenceBoard');
      if (mounted) setState(() => _presenceOpen = false);
      return;
    }
    if (widget.repository.accounts.isEmpty) {
      _showError('Önce bir SIP hesabı ekleyin.');
      return;
    }
    if (mounted) setState(() => _presenceLoading = true);
    try {
      final rows = await _loadPresence();
      await _sendPresence('togglePresenceBoard', rows);
      if (!mounted) return;
      setState(() => _presenceOpen = true);
      _presenceTimer?.cancel();
      _presenceTimer = Timer.periodic(const Duration(seconds: 3), (_) {
        unawaited(_refreshPresenceBoard());
      });
    } catch (error) {
      _showError(error);
    } finally {
      if (mounted) setState(() => _presenceLoading = false);
    }
  }

  Future<List<ExtensionPresence>> _loadPresence() async {
    final activeId = widget.repository.activeAccountId;
    final active = widget.repository.accounts.firstWhere(
      (account) => account.id == activeId,
      orElse: () => widget.repository.accounts.first,
    );
    final password = await widget.repository.readPassword(active.id);
    final configuredUrl = active.presenceApiUrl.trim();
    final fallbackUrl = active.crmApiUrl.trim().isNotEmpty
        ? active.crmApiUrl.trim()
        : active.server.trim();
    final baseUrl = configuredUrl.isNotEmpty ? configuredUrl : fallbackUrl;
    final parsed = Uri.tryParse(
      baseUrl.contains('://') ? baseUrl : 'https://$baseUrl',
    );
    if (parsed == null || !parsed.hasScheme || parsed.host.isEmpty) {
      throw StateError('Meşguliyet API adresi geçersiz.');
    }
    final presenceUrl = configuredUrl.isNotEmpty
        ? parsed.toString()
        : parsed
              .replace(
                path: '/softphone_presence.php',
                query: null,
                fragment: null,
              )
              .toString();
    return _presenceService.fetch(
      apiUrl: presenceUrl,
      sipUser: active.username,
      authUser: active.authUsername,
      password: password ?? '',
    );
  }

  Future<void> _refreshPresenceBoard() async {
    if (!_presenceOpen) return;
    try {
      final rows = await _loadPresence();
      if (_presenceOpen) await _sendPresence('updatePresenceBoard', rows);
    } catch (_) {
      // Son bilinen durum, bağlantı geri gelene kadar panoda kalır.
    }
  }

  Future<void> _sendPresence(String method, List<ExtensionPresence> rows) {
    final effectiveRows = _withLocalCallPresence(rows);
    final statuses = effectiveRows.map((row) {
      final label = switch (row.state) {
        'available' => 'Müsait',
        'ringing' => row.direction == 'outgoing' ? 'Aranıyor' : 'Çalıyor',
        'talking' => 'Meşgul',
        _ => 'Çevrimdışı',
      };
      return label;
    }).toList();
    return _windowChannel.invokeMethod<void>(method, {
      'title': 'Meşguliyet',
      'labels': effectiveRows.map((row) => row.name).toList(),
      'statuses': statuses,
      'states': effectiveRows.map((row) => row.state).toList(),
      'peers': effectiveRows.map((row) => row.peer).toList(),
      'directions': effectiveRows.map((row) => row.direction).toList(),
      'numbers': effectiveRows.map((row) => row.extension).toList(),
    });
  }

  List<ExtensionPresence> _withLocalCallPresence(List<ExtensionPresence> rows) {
    if (widget.repository.accounts.isEmpty) return rows;
    final event = widget.registration.callEvent;
    final activeCall = switch (event.state) {
      CallState.calling ||
      CallState.ringing ||
      CallState.incoming ||
      CallState.connected ||
      CallState.held => true,
      _ => false,
    };
    if (!activeCall) return rows;

    final activeId = widget.repository.activeAccountId;
    final account = widget.repository.accounts.firstWhere(
      (item) => item.id == activeId,
      orElse: () => widget.repository.accounts.first,
    );
    final accountNumbers = <String>{
      account.username,
      if (account.username.contains('_')) account.username.split('_').last,
      account.displayName.replaceAll(RegExp(r'\D'), ''),
    }..removeWhere((number) => number.isEmpty);
    final ownIndex = rows.indexWhere(
      (row) => accountNumbers.contains(row.extension),
    );
    if (ownIndex < 0) return rows;

    final match = RegExp(
      r'sip:([^@;>]+)',
      caseSensitive: false,
    ).firstMatch(event.remoteUri);
    final peerNumber = match?.group(1) ?? event.remoteUri;
    final peerName = _callerNameFromUri(event.remoteUri, peerNumber);
    final peer = peerName == null ? peerNumber : '$peerName · $peerNumber';
    final incoming =
        event.state == CallState.incoming || _displayedIncomingUri != null;
    final talking =
        event.state == CallState.connected || event.state == CallState.held;
    final current = rows[ownIndex];
    final result = List<ExtensionPresence>.of(rows);
    result[ownIndex] = ExtensionPresence(
      extension: current.extension,
      name: current.name,
      state: talking ? 'talking' : 'ringing',
      label: talking ? 'Görüşmede' : (incoming ? 'Çalıyor' : 'Aranıyor'),
      peer: peer,
      direction: incoming ? 'incoming' : 'outgoing',
      elapsedSeconds: current.elapsedSeconds,
    );
    return result;
  }

  Future<void> _answerCall() async {
    try {
      await widget.registration.answerCall();
    } catch (error) {
      _showError(error);
    }
  }

  Future<void> _showTransferDialog(RegistrationController controller) async {
    final destination = await _readTransferDestination(
      controller,
      'Çağrıyı Aktar',
    );
    if (destination == null) return;
    try {
      await controller.transferCall(destination);
    } catch (error) {
      _showError(error);
    }
  }

  Future<void> _showAttendedTransferDialog(
    RegistrationController controller,
  ) async {
    final destination = await _readTransferDestination(
      controller,
      'Kontrollü Aktarım (*2)',
    );
    if (destination == null) return;
    try {
      await controller.attendedTransfer(destination);
    } catch (error) {
      _showError(error);
    }
  }

  Future<String?> _readTransferDestination(
    RegistrationController controller,
    String title,
  ) async {
    final input = TextEditingController();
    var previousValue = '';
    final destination = await showDialog<String>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(title),
        content: TextField(
          controller: input,
          autofocus: true,
          keyboardType: TextInputType.phone,
          decoration: const InputDecoration(labelText: 'Hedef numara'),
          onChanged: (value) {
            final addedCharacter = value.length > previousValue.length;
            previousValue = value;
            if (!addedCharacter || value.isEmpty) return;
            final digit = value[value.length - 1];
            if ('0123456789*#'.contains(digit)) {
              unawaited(controller.playDialTone(digit).catchError(_showError));
            }
          },
          onSubmitted: (value) => Navigator.pop(dialogContext, value.trim()),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext),
            child: const Text('İPTAL'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, input.text.trim()),
            child: const Text('AKTAR'),
          ),
        ],
      ),
    );
    input.dispose();
    if (destination == null || destination.isEmpty) return null;
    return destination;
  }

  Future<void> _openEditor([SipAccountProfile? account]) async {
    final result = await showDialog<_AccountEditResult>(
      context: context,
      barrierDismissible: false,
      builder: (_) => _AccountEditor(account: account, defaults: widget.config),
    );
    if (result == null) return;

    try {
      await widget.repository.save(
        result.profile,
        password: result.password,
        crmToken: result.crmToken,
      );
      if (mounted) setState(() {});
    } catch (error) {
      _showError(error);
    }
  }

  Future<void> _activate(SipAccountProfile account) async {
    try {
      await widget.repository.setActive(account.id);
      if (mounted) setState(() {});
      await _connect(account);
    } catch (error) {
      _showError(error);
    }
  }

  Future<void> _connect(SipAccountProfile account) async {
    try {
      final password = await widget.repository.readPassword(account.id);
      if (password == null || password.isEmpty) {
        throw StateError(
          'Bu hesap için parola bulunamadı. Hesabı düzenleyip parola girin.',
        );
      }
      await widget.repository.setActive(account.id);
      if (mounted) setState(() {});
      await widget.registration.register(account.toSipAccount(password));
      await _refreshCallHistory();
    } catch (error) {
      _showError(error);
    }
  }

  Future<void> _toggleRegistration(bool enabled) async {
    if (!enabled) {
      await widget.registration.unregister();
      return;
    }
    final activeId = widget.repository.activeAccountId;
    final accounts = widget.repository.accounts.where(
      (account) => account.id == activeId,
    );
    if (accounts.isEmpty) {
      _showError('Önce aktif bir SIP hesabı seçin.');
      return;
    }
    await _connect(accounts.first);
  }

  Future<void> _delete(SipAccountProfile account) async {
    final approved = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Hesabı sil'),
        content: Text('${account.accountName} hesabı silinsin mi?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('İPTAL'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('SİL'),
          ),
        ],
      ),
    );
    if (approved != true) return;
    if (widget.repository.activeAccountId == account.id) {
      await widget.registration.unregister();
    }
    await widget.repository.delete(account.id);
    if (mounted) setState(() {});
  }

  void _setAutoAnswer(bool enabled) {
    widget.registration.setAutoAnswer(enabled);
    unawaited(
      widget.repository
          .setAutoAnswerSettings(
            enabled: enabled,
            ringCount: widget.registration.autoAnswerRingCount,
          )
          .catchError(_showError),
    );
  }

  void _setAutoAnswerRingCount(int ringCount) {
    widget.registration.setAutoAnswerRingCount(ringCount);
    unawaited(
      widget.repository
          .setAutoAnswerSettings(
            enabled: widget.registration.autoAnswer,
            ringCount: ringCount,
          )
          .catchError(_showError),
    );
  }

  Future<void> _restoreAudioDevices() async {
    final captureKey = widget.repository.captureDeviceKey;
    final playbackKey = widget.repository.playbackDeviceKey;
    AudioDeviceState state;
    try {
      state = await widget.registration.getAudioDevices();
    } catch (_) {
      await _showSystemAudioWarning(
        'Windows ses aygıtları okunamadı.\n\n'
        'Mikrofon ve hoparlör/kulaklık bağlantısını kontrol edin, '
        'ardından Ayarlar bölümünden yeniden seçin.',
      );
      return;
    }
    if (!_hasRequiredAudioDevices(state)) {
      await _showMissingAudioDeviceWarning(state);
      return;
    }
    _audioWarningShown = false;
    if (captureKey == null && playbackKey == null) return;

    AudioDevice? capture;
    AudioDevice? playback;
    for (final device in state.devices) {
      if (device.supportsInput && device.key == captureKey) {
        capture = device;
      }
      if (device.supportsOutput && device.key == playbackKey) {
        playback = device;
      }
    }
    for (final device in state.devices) {
      if (capture == null &&
          device.supportsInput &&
          device.id == state.captureDeviceId) {
        capture = device;
      }
      if (playback == null &&
          device.supportsOutput &&
          device.id == state.playbackDeviceId) {
        playback = device;
      }
    }
    if (capture == null || playback == null) return;
    await widget.registration.setAudioDevices(
      captureDeviceId: capture.id,
      playbackDeviceId: playback.id,
    );
  }

  Future<void> _openWebsite() async {
    try {
      await _windowChannel.invokeMethod<void>(
        'openExternalUrl',
        'https://www.astera.com.tr',
      );
    } catch (error) {
      _showError('Web sitesi açılamadı: $error');
    }
  }

  Future<void> _openAudioSettings() async {
    try {
      final state = await widget.registration.getAudioDevices();
      if (!mounted) return;
      if (!_hasRequiredAudioDevices(state)) {
        await _showMissingAudioDeviceWarning(state);
      } else {
        _audioWarningShown = false;
      }
      if (!mounted) return;
      final result = await showDialog<_AudioSettingsResult>(
        context: context,
        barrierDismissible: false,
        builder: (_) => _AudioSettingsDialog(
          state: state,
          controller: widget.registration,
          repository: widget.repository,
          ringtoneStyle: widget.repository.ringtoneStyle,
          ringtoneFilePath: widget.repository.ringtoneFilePath,
        ),
      );
      if (result == null) return;
      await widget.registration.setAudioDevices(
        captureDeviceId: result.capture.id,
        playbackDeviceId: result.playback.id,
      );
      await widget.repository.setAudioDeviceKeys(
        captureDeviceKey: result.capture.key,
        playbackDeviceKey: result.playback.key,
      );
      if (result.ringtoneStyle == 3) {
        final selectedPath = result.ringtoneFilePath;
        if (selectedPath == null) {
          throw const FormatException('Zil sesi seçilmedi.');
        }
        await widget.repository.selectRingtoneFile(selectedPath);
        await widget.registration.setRingtoneFile(selectedPath);
      }
      await widget.registration.setRingtoneStyle(result.ringtoneStyle);
      await widget.repository.setRingtoneStyle(result.ringtoneStyle);
      _audioWarningShown = false;
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Ses aygıtları kaydedildi.')),
        );
      }
    } catch (error) {
      _showError('Ayarlar kaydedilemedi: $error');
    }
  }

  void _showError(Object error) {
    if (_looksLikeAudioDeviceError(error)) {
      unawaited(
        _showSystemAudioWarning(
          'Arama için mikrofon veya hoparlör/kulaklık kullanılamıyor.\n\n'
          'Aygıtı bağlayın ve Ayarlar > Ses Aygıtları bölümünden seçin.',
        ),
      );
    }
    if (!mounted) return;
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text(error.toString())));
  }

  bool _hasRequiredAudioDevices(AudioDeviceState state) =>
      state.devices.any((device) => device.supportsInput) &&
      state.devices.any((device) => device.supportsOutput);

  Future<void> _showMissingAudioDeviceWarning(AudioDeviceState state) {
    final hasInput = state.devices.any((device) => device.supportsInput);
    final hasOutput = state.devices.any((device) => device.supportsOutput);
    final missing = [
      if (!hasInput) 'mikrofon',
      if (!hasOutput) 'hoparlör/kulaklık',
    ].join(' ve ');
    return _showSystemAudioWarning(
      '${missing[0].toUpperCase()}${missing.substring(1)} bulunamadı.\n\n'
      'Aygıtı bağlayın ve Ayarlar bölümünden yeniden seçin.',
    );
  }

  Future<void> _showSystemAudioWarning(String message) async {
    if (_audioWarningShown) return;
    _audioWarningShown = true;
    try {
      await _windowChannel.invokeMethod<void>('showSystemWarning', message);
    } catch (_) {
      // Eski Windows runner ile de alt bildirim çalışmaya devam eder.
    }
  }

  static bool _looksLikeAudioDeviceError(Object error) {
    final text = error.toString().toLowerCase();
    return text.contains('audio') ||
        text.contains('pjmedia') ||
        text.contains('microphone') ||
        text.contains('speaker') ||
        text.contains('ses aygıt') ||
        text.contains('capture device') ||
        text.contains('playback device');
  }
}

class _AudioSettingsResult {
  const _AudioSettingsResult({
    required this.capture,
    required this.playback,
    required this.ringtoneStyle,
    required this.ringtoneFilePath,
  });

  final AudioDevice capture;
  final AudioDevice playback;
  final int ringtoneStyle;
  final String? ringtoneFilePath;
}

class _AudioSettingsDialog extends StatefulWidget {
  const _AudioSettingsDialog({
    required this.state,
    required this.controller,
    required this.repository,
    required this.ringtoneStyle,
    required this.ringtoneFilePath,
  });

  final AudioDeviceState state;
  final RegistrationController controller;
  final AccountRepository repository;
  final int ringtoneStyle;
  final String? ringtoneFilePath;

  @override
  State<_AudioSettingsDialog> createState() => _AudioSettingsDialogState();
}

class _AudioSettingsDialogState extends State<_AudioSettingsDialog> {
  static const _windowChannel = MethodChannel('tr.com.astera/window');
  late List<AudioDevice> _inputs;
  late List<AudioDevice> _outputs;
  int? _captureId;
  int? _playbackId;
  late int _ringtoneStyle;
  String? _ringtoneFilePath;
  late List<CustomRingtone> _customRingtones;
  String? _ringtoneError;
  Timer? _refreshTimer;
  bool _refreshing = false;

  @override
  void initState() {
    super.initState();
    _inputs = widget.state.devices
        .where((device) => device.supportsInput)
        .toList(growable: false);
    _outputs = widget.state.devices
        .where((device) => device.supportsOutput)
        .toList(growable: false);
    _captureId =
        _inputs.any((device) => device.id == widget.state.captureDeviceId)
        ? widget.state.captureDeviceId
        : (_inputs.isEmpty ? null : _inputs.first.id);
    _playbackId =
        _outputs.any((device) => device.id == widget.state.playbackDeviceId)
        ? widget.state.playbackDeviceId
        : (_outputs.isEmpty ? null : _outputs.first.id);
    _ringtoneStyle = widget.ringtoneStyle;
    _ringtoneFilePath = widget.ringtoneFilePath;
    _customRingtones = widget.repository.customRingtones;
    _refreshTimer = Timer.periodic(
      const Duration(seconds: 2),
      (_) => unawaited(_refreshDevices()),
    );
  }

  @override
  void dispose() {
    _refreshTimer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final ready = _captureId != null && _playbackId != null;
    return AlertDialog(
      insetPadding: const EdgeInsets.all(8),
      contentPadding: const EdgeInsets.fromLTRB(14, 10, 14, 4),
      title: Row(
        children: [
          const Icon(Icons.settings_outlined, size: 20),
          const SizedBox(width: 8),
          const Expanded(
            child: Text('Ayarlar', style: TextStyle(fontSize: 16)),
          ),
          IconButton(
            tooltip: 'Ses aygıtlarını yenile',
            onPressed: _refreshing ? null : _refreshDevices,
            icon: _refreshing
                ? const SizedBox.square(
                    dimension: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.refresh),
          ),
        ],
      ),
      content: SizedBox(
        width: 560,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'SES AYGITLARI',
              style: TextStyle(
                color: Color(0xFF607086),
                fontSize: 9,
                fontWeight: FontWeight.w800,
                letterSpacing: 0.8,
              ),
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<int>(
              key: ValueKey('capture:$_captureId:${_inputs.length}'),
              initialValue: _captureId,
              isExpanded: true,
              style: const TextStyle(fontSize: 10, color: Color(0xFF182437)),
              decoration: const InputDecoration(
                labelText: 'Mikrofon',
                labelStyle: TextStyle(fontSize: 10),
                prefixIcon: Icon(Icons.mic_none_outlined, size: 18),
                prefixIconConstraints: BoxConstraints(minWidth: 34),
                contentPadding: EdgeInsets.symmetric(
                  horizontal: 8,
                  vertical: 10,
                ),
                isDense: true,
              ),
              items: _inputs
                  .map(
                    (device) => DropdownMenuItem(
                      value: device.id,
                      child: Text(
                        _deviceLabel(device),
                        style: const TextStyle(fontSize: 10),
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                  )
                  .toList(),
              onChanged: (value) => setState(() => _captureId = value),
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<int>(
              key: ValueKey('playback:$_playbackId:${_outputs.length}'),
              initialValue: _playbackId,
              isExpanded: true,
              style: const TextStyle(fontSize: 10, color: Color(0xFF182437)),
              decoration: const InputDecoration(
                labelText: 'Hoparlör / Kulaklık',
                labelStyle: TextStyle(fontSize: 10),
                prefixIcon: Icon(Icons.headphones_outlined, size: 18),
                prefixIconConstraints: BoxConstraints(minWidth: 34),
                contentPadding: EdgeInsets.symmetric(
                  horizontal: 8,
                  vertical: 10,
                ),
                isDense: true,
              ),
              items: _outputs
                  .map(
                    (device) => DropdownMenuItem(
                      value: device.id,
                      child: Text(
                        _deviceLabel(device),
                        style: const TextStyle(fontSize: 10),
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                  )
                  .toList(),
              onChanged: (value) => setState(() => _playbackId = value),
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<int>(
              initialValue: _ringtoneStyle,
              isExpanded: true,
              style: const TextStyle(fontSize: 10, color: Color(0xFF182437)),
              decoration: const InputDecoration(
                labelText: 'Zil Sesi',
                labelStyle: TextStyle(fontSize: 10),
                prefixIcon: Icon(Icons.notifications_none, size: 18),
                prefixIconConstraints: BoxConstraints(minWidth: 34),
                contentPadding: EdgeInsets.symmetric(
                  horizontal: 8,
                  vertical: 10,
                ),
                isDense: true,
              ),
              items: const [
                DropdownMenuItem(value: 0, child: Text('ASTERA Klasik')),
                DropdownMenuItem(value: 1, child: Text('Yumuşak')),
                DropdownMenuItem(value: 2, child: Text('Çift Zil')),
                DropdownMenuItem(value: 3, child: Text('Dosyadan Zil')),
              ],
              onChanged: (value) {
                if (value != null) {
                  setState(() {
                    _ringtoneStyle = value;
                    _ringtoneError = null;
                  });
                }
              },
            ),
            const SizedBox(height: 8),
            if (_customRingtones.isNotEmpty) ...[
              Row(
                children: [
                  Expanded(
                    child: DropdownButtonFormField<String>(
                      key: ValueKey(
                        'ringtone:$_ringtoneFilePath:${_customRingtones.length}',
                      ),
                      initialValue:
                          _customRingtones.any(
                            (tone) => tone.path == _ringtoneFilePath,
                          )
                          ? _ringtoneFilePath
                          : null,
                      isExpanded: true,
                      style: const TextStyle(
                        fontSize: 10,
                        color: Color(0xFF182437),
                      ),
                      decoration: const InputDecoration(
                        labelText: 'Kayıtlı Zil Tonu',
                        labelStyle: TextStyle(fontSize: 10),
                        prefixIcon: Icon(
                          Icons.library_music_outlined,
                          size: 18,
                        ),
                        prefixIconConstraints: BoxConstraints(minWidth: 34),
                        contentPadding: EdgeInsets.symmetric(
                          horizontal: 8,
                          vertical: 10,
                        ),
                        isDense: true,
                      ),
                      items: _customRingtones
                          .map(
                            (tone) => DropdownMenuItem(
                              value: tone.path,
                              child: Text(
                                tone.name,
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                          )
                          .toList(),
                      onChanged: (path) {
                        if (path == null) return;
                        setState(() {
                          _ringtoneFilePath = path;
                          _ringtoneStyle = 3;
                          _ringtoneError = null;
                        });
                      },
                    ),
                  ),
                  IconButton(
                    tooltip: 'Seçili zil tonunu sil',
                    onPressed:
                        _ringtoneFilePath == null ||
                            _customRingtones
                                    .where(
                                      (tone) => tone.path == _ringtoneFilePath,
                                    )
                                    .firstOrNull
                                    ?.isBundled ==
                                true
                        ? null
                        : _deleteSelectedRingtone,
                    icon: const Icon(Icons.delete_outline, color: Colors.red),
                  ),
                ],
              ),
              const SizedBox(height: 8),
            ],
            Row(
              children: [
                OutlinedButton.icon(
                  onPressed: _pickRingtoneWav,
                  icon: const Icon(Icons.audio_file_outlined, size: 17),
                  label: const Text(
                    'WAV İÇERİ AKTAR',
                    style: TextStyle(fontSize: 10),
                  ),
                ),
                const SizedBox(width: 10),
                const Expanded(
                  child: Text(
                    'WAV dosyasını ad vererek zil listesine ekler.',
                    style: TextStyle(fontSize: 9.5, color: Color(0xFF7A8798)),
                  ),
                ),
              ],
            ),
            if (_ringtoneError != null) ...[
              const SizedBox(height: 6),
              Text(
                _ringtoneError!,
                style: const TextStyle(fontSize: 9.5, color: Colors.redAccent),
              ),
            ],
            const SizedBox(height: 12),
            Text(
              ready
                  ? 'Seçimler kaydedilir ve sonraki açılışlarda otomatik uygulanır.'
                  : 'Windows üzerinde kullanılabilir mikrofon veya çıkış aygıtı bulunamadı.',
              style: TextStyle(
                fontSize: 9.5,
                color: ready ? const Color(0xFF65748A) : Colors.redAccent,
              ),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('İPTAL'),
        ),
        FilledButton.icon(
          onPressed: ready ? _save : null,
          icon: const Icon(Icons.save_outlined, size: 17),
          label: const Text('KAYDET'),
        ),
      ],
    );
  }

  static String _deviceLabel(AudioDevice device) {
    return device.name;
  }

  static String _defaultRingtoneName(String path) {
    final fileName = path.split(RegExp(r'[/\\]')).last;
    final extensionIndex = fileName.toLowerCase().lastIndexOf('.wav');
    return extensionIndex > 0
        ? fileName.substring(0, extensionIndex)
        : fileName;
  }

  Future<void> _pickRingtoneWav() async {
    try {
      final path = await _windowChannel.invokeMethod<String>('pickRingtoneWav');
      if (!mounted || path == null || path.isEmpty) return;
      final nameController = TextEditingController(
        text: _defaultRingtoneName(path),
      );
      final name = await showDialog<String>(
        context: context,
        builder: (dialogContext) => AlertDialog(
          title: const Text('Zil Tonuna İsim Ver'),
          content: TextField(
            controller: nameController,
            autofocus: true,
            maxLength: 50,
            decoration: const InputDecoration(
              labelText: 'Zil adı',
              hintText: 'Örnek: Ofis Zili',
            ),
            onSubmitted: (value) {
              if (value.trim().isNotEmpty) {
                Navigator.pop(dialogContext, value.trim());
              }
            },
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(dialogContext),
              child: const Text('İPTAL'),
            ),
            FilledButton(
              onPressed: () {
                final value = nameController.text.trim();
                if (value.isNotEmpty) Navigator.pop(dialogContext, value);
              },
              child: const Text('İÇERİ AKTAR'),
            ),
          ],
        ),
      );
      nameController.dispose();
      if (!mounted || name == null) return;
      final importedPath = await widget.repository.importRingtoneFile(
        path,
        name,
      );
      if (!mounted) return;
      setState(() {
        _customRingtones = widget.repository.customRingtones;
        _ringtoneFilePath = importedPath;
        _ringtoneStyle = 3;
        _ringtoneError = null;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() => _ringtoneError = 'WAV dosyası seçilemedi: $error');
    }
  }

  Future<void> _deleteSelectedRingtone() async {
    final path = _ringtoneFilePath;
    if (path == null) return;
    final tone = _customRingtones
        .where((item) => item.path == path)
        .firstOrNull;
    if (tone == null) return;
    final approved = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Zil Tonunu Sil'),
        content: Text('"${tone.name}" zil tonu silinsin mi?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('VAZGEÇ'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('SİL'),
          ),
        ],
      ),
    );
    if (approved != true || !mounted) return;
    final wasActive = widget.repository.ringtoneFilePath == path;
    try {
      if (wasActive) {
        await widget.controller.setRingtoneStyle(0);
        await widget.controller.setRingtoneFile('');
      }
      await widget.repository.deleteRingtoneFile(path);
      if (!mounted) return;
      setState(() {
        _customRingtones = widget.repository.customRingtones;
        _ringtoneFilePath = widget.repository.ringtoneFilePath;
        _ringtoneStyle = widget.repository.ringtoneStyle;
        _ringtoneError = null;
      });
    } catch (error) {
      if (wasActive) {
        await widget.controller.setRingtoneFile(path);
        await widget.controller.setRingtoneStyle(3);
      }
      if (mounted) {
        setState(() => _ringtoneError = 'Zil tonu silinemedi: $error');
      }
    }
  }

  Future<void> _refreshDevices() async {
    if (_refreshing) return;
    _refreshing = true;
    if (mounted) setState(() {});
    try {
      final previousCapture = _selectedById(_inputs, _captureId)?.key;
      final previousPlayback = _selectedById(_outputs, _playbackId)?.key;
      final state = await widget.controller.getAudioDevices();
      final inputs = state.devices
          .where((device) => device.supportsInput)
          .toList(growable: false);
      final outputs = state.devices
          .where((device) => device.supportsOutput)
          .toList(growable: false);
      if (!mounted) return;
      setState(() {
        _inputs = inputs;
        _outputs = outputs;
        _captureId = _preferredDeviceId(
          inputs,
          previousCapture,
          state.captureDeviceId,
        );
        _playbackId = _preferredDeviceId(
          outputs,
          previousPlayback,
          state.playbackDeviceId,
        );
      });
    } catch (_) {
      // Keep the previous list; the next automatic refresh will retry.
    } finally {
      _refreshing = false;
      if (mounted) setState(() {});
    }
  }

  static AudioDevice? _selectedById(List<AudioDevice> devices, int? id) {
    for (final device in devices) {
      if (device.id == id) return device;
    }
    return null;
  }

  static int? _preferredDeviceId(
    List<AudioDevice> devices,
    String? preferredKey,
    int activeId,
  ) {
    for (final device in devices) {
      if (device.key == preferredKey) return device.id;
    }
    for (final device in devices) {
      if (device.id == activeId) return device.id;
    }
    return devices.isEmpty ? null : devices.first.id;
  }

  void _save() {
    if (_ringtoneStyle == 3 && _ringtoneFilePath == null) {
      setState(() => _ringtoneError = 'Özel zil için bir WAV dosyası seçin.');
      return;
    }
    final capture = _inputs.firstWhere((device) => device.id == _captureId);
    final playback = _outputs.firstWhere((device) => device.id == _playbackId);
    Navigator.pop(
      context,
      _AudioSettingsResult(
        capture: capture,
        playback: playback,
        ringtoneStyle: _ringtoneStyle,
        ringtoneFilePath: _ringtoneFilePath,
      ),
    );
  }
}

class _PersistentCallControls extends StatelessWidget {
  const _PersistentCallControls({
    required this.controller,
    required this.onTransfer,
    required this.onAttendedTransfer,
    required this.onError,
  });

  final RegistrationController controller;
  final Future<void> Function() onTransfer;
  final Future<void> Function() onAttendedTransfer;
  final ValueChanged<Object> onError;

  @override
  Widget build(BuildContext context) {
    final state = controller.callEvent.state;
    final connected = state == CallState.connected || state == CallState.held;
    final hasCall =
        state == CallState.incoming ||
        state == CallState.calling ||
        state == CallState.ringing ||
        connected;
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        _ControlButton(
          icon: controller.muted ? Icons.mic_off : Icons.mic,
          label: controller.muted ? 'SES AÇ' : 'SESSİZ',
          selected: controller.muted,
          onPressed: connected ? () => _run(controller.toggleMute) : null,
        ),
        _ControlButton(
          icon: controller.held ? Icons.play_arrow : Icons.pause,
          label: controller.held ? 'DEVAM' : 'BEKLET',
          selected: controller.held,
          onPressed: connected ? () => _run(controller.toggleHold) : null,
        ),
        _ControlButton(
          icon: Icons.swap_horiz,
          label: 'AKTAR',
          onPressed: connected ? () => _run(onTransfer) : null,
        ),
        _ControlButton(
          icon: Icons.connect_without_contact,
          label: 'K. AKTAR',
          onPressed: connected ? () => _run(onAttendedTransfer) : null,
        ),
        _ControlButton(
          icon: Icons.call_end,
          label: state == CallState.incoming ? 'REDDET' : 'KAPAT',
          destructive: true,
          onPressed: hasCall
              ? () => _run(
                  state == CallState.incoming
                      ? controller.rejectCall
                      : controller.hangupCall,
                )
              : null,
        ),
      ],
    );
  }

  Future<void> _run(Future<void> Function() action) async {
    try {
      await action();
    } catch (error) {
      onError(error);
    }
  }
}

class _ControlButton extends StatelessWidget {
  const _ControlButton({
    required this.icon,
    required this.label,
    required this.onPressed,
    this.selected = false,
    this.destructive = false,
  });

  final IconData icon;
  final String label;
  final VoidCallback? onPressed;
  final bool selected;
  final bool destructive;

  @override
  Widget build(BuildContext context) {
    final color = destructive
        ? const Color(0xFFEF5B5B)
        : selected
        ? const Color(0xFFF4B740)
        : const Color(0xFF182437);
    return SizedBox(
      width: 68,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 3),
        child: FilledButton(
          style: FilledButton.styleFrom(
            backgroundColor: color,
            foregroundColor: selected ? const Color(0xFF182437) : Colors.white,
            minimumSize: const Size(0, 40),
            tapTargetSize: MaterialTapTargetSize.shrinkWrap,
            padding: const EdgeInsets.symmetric(vertical: 4, horizontal: 3),
          ),
          onPressed: onPressed,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: 16),
              const SizedBox(height: 1),
              Text(label, style: const TextStyle(fontSize: 9)),
            ],
          ),
        ),
      ),
    );
  }
}

class _BehaviorBar extends StatelessWidget {
  const _BehaviorBar({
    required this.controller,
    required this.onAutoAnswerChanged,
    required this.onAutoAnswerRingCountChanged,
  });

  final RegistrationController controller;
  final ValueChanged<bool> onAutoAnswerChanged;
  final ValueChanged<int> onAutoAnswerRingCountChanged;

  @override
  Widget build(BuildContext context) {
    return _Panel(
      child: Row(
        children: [
          Expanded(
            child: SwitchListTile(
              dense: true,
              visualDensity: VisualDensity.compact,
              contentPadding: EdgeInsets.zero,
              title: const Text(
                'Rahatsız Etme',
                style: TextStyle(fontSize: 10),
              ),
              value: controller.doNotDisturb,
              onChanged: (value) {
                controller.setDoNotDisturb(value);
                if (value) onAutoAnswerChanged(false);
              },
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Row(
              children: [
                Expanded(
                  child: SwitchListTile(
                    dense: true,
                    visualDensity: VisualDensity.compact,
                    contentPadding: EdgeInsets.zero,
                    title: const Text(
                      'Otomatik Cevap',
                      style: TextStyle(fontSize: 10),
                    ),
                    value: controller.autoAnswer,
                    onChanged: onAutoAnswerChanged,
                  ),
                ),
                if (controller.autoAnswer)
                  DropdownButtonHideUnderline(
                    child: DropdownButton<int>(
                      value: controller.autoAnswerRingCount,
                      isDense: true,
                      style: const TextStyle(
                        fontSize: 10,
                        color: Color(0xFF182437),
                      ),
                      items: List.generate(
                        10,
                        (index) => DropdownMenuItem(
                          value: index + 1,
                          child: Text('${index + 1} zil'),
                        ),
                      ),
                      onChanged: (value) {
                        if (value != null) {
                          onAutoAnswerRingCountChanged(value);
                        }
                      },
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _DialPad extends StatelessWidget {
  const _DialPad({required this.onDigit});

  final ValueChanged<String> onDigit;

  static const _digits = [
    ('1', ''),
    ('2', 'ABC'),
    ('3', 'DEF'),
    ('4', 'GHI'),
    ('5', 'JKL'),
    ('6', 'MNO'),
    ('7', 'PQRS'),
    ('8', 'TUV'),
    ('9', 'WXYZ'),
    ('*', ''),
    ('0', '+'),
    ('#', ''),
  ];

  @override
  Widget build(BuildContext context) {
    return GridView.builder(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 3,
        mainAxisSpacing: 5,
        crossAxisSpacing: 5,
        childAspectRatio: 2.9,
      ),
      itemCount: _digits.length,
      itemBuilder: (context, index) {
        final (digit, letters) = _digits[index];
        return OutlinedButton(
          style: OutlinedButton.styleFrom(
            foregroundColor: const Color(0xFF172033),
            side: const BorderSide(color: Color(0xFFD7DEE8)),
            minimumSize: Size.zero,
            padding: EdgeInsets.zero,
          ),
          onPressed: () => onDigit(digit),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text(
                digit,
                style: const TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.w700,
                ),
              ),
              if (letters.isNotEmpty)
                Text(
                  letters,
                  style: const TextStyle(color: Color(0xFF8B98AA), fontSize: 9),
                ),
            ],
          ),
        );
      },
    );
  }
}

class _RegistrationStatus extends StatelessWidget {
  const _RegistrationStatus({
    required this.controller,
    required this.onChanged,
  });

  final RegistrationController controller;
  final ValueChanged<bool> onChanged;

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
        final switchedOn =
            event.state == RegistrationState.initializing ||
            event.state == RegistrationState.registering ||
            event.state == RegistrationState.registered;
        return _Panel(
          child: Row(
            children: [
              Container(
                width: 12,
                height: 12,
                decoration: BoxDecoration(color: color, shape: BoxShape.circle),
              ),
              const SizedBox(width: 12),
              Text(label, style: const TextStyle(fontWeight: FontWeight.w800)),
              const SizedBox(width: 14),
              Expanded(
                child: Text(
                  event.detail,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(color: Color(0xFF728096)),
                ),
              ),
              if (event.statusCode != 0) Text('SIP ${event.statusCode}'),
              const SizedBox(width: 8),
              Tooltip(
                message: switchedOn ? 'UNREGISTER' : 'REGISTER',
                child: SizedBox(
                  width: 40,
                  height: 26,
                  child: FittedBox(
                    child: Switch(
                      value: switchedOn,
                      onChanged: controller.busy ? null : onChanged,
                      activeTrackColor: const Color(0xFF2CB67D),
                      inactiveTrackColor: const Color(0xFF8B98AA),
                    ),
                  ),
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}

class _CrmSuggestions extends StatelessWidget {
  const _CrmSuggestions({
    required this.matches,
    required this.onSelect,
    required this.onCall,
  });

  final List<CrmContactMatch> matches;
  final ValueChanged<CrmContactMatch> onSelect;
  final ValueChanged<CrmContactMatch> onCall;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(top: 4),
      decoration: BoxDecoration(
        color: const Color(0xFFFFF3CD),
        border: Border.all(color: const Color(0xFFF4B740)),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          for (var index = 0; index < matches.length; index++) ...[
            if (index > 0) const Divider(height: 1, color: Color(0xFFD8C47C)),
            InkWell(
              onTap: () => onSelect(matches[index]),
              child: SizedBox(
                height: 42,
                child: Row(
                  children: [
                    const SizedBox(width: 9),
                    const Icon(
                      Icons.business_outlined,
                      size: 17,
                      color: Color(0xFF182437),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            matches[index].title,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              color: Color(0xFF182437),
                              fontSize: 12,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                          Text(
                            matches[index].subtitle,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              color: Color(0xFF45556E),
                              fontSize: 10,
                            ),
                          ),
                        ],
                      ),
                    ),
                    IconButton(
                      tooltip: 'Hemen ara',
                      visualDensity: VisualDensity.compact,
                      onPressed: () => onCall(matches[index]),
                      icon: const Icon(
                        Icons.call,
                        size: 18,
                        color: Color(0xFF182437),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _AccountEditor extends StatefulWidget {
  const _AccountEditor({required this.defaults, this.account});

  final AppConfig defaults;
  final SipAccountProfile? account;

  @override
  State<_AccountEditor> createState() => _AccountEditorState();
}

class _AccountEditorState extends State<_AccountEditor> {
  final _formKey = GlobalKey<FormState>();
  late final Map<String, TextEditingController> _fields;

  @override
  void initState() {
    super.initState();
    final account = widget.account;
    _fields = {
      'name': TextEditingController(text: account?.accountName ?? ''),
      'server': TextEditingController(
        text: account?.server ?? widget.defaults.server,
      ),
      'proxy': TextEditingController(text: account?.proxyServer ?? ''),
      'username': TextEditingController(
        text: account?.username ?? widget.defaults.username,
      ),
      'auth': TextEditingController(
        text: account?.authUsername ?? widget.defaults.authUsername,
      ),
      'domain': TextEditingController(text: account?.domain ?? ''),
      'password': TextEditingController(),
      'display': TextEditingController(
        text: account?.displayName ?? widget.defaults.displayName,
      ),
      'port': TextEditingController(
        text: (account?.port ?? widget.defaults.port).toString(),
      ),
      'interval': TextEditingController(
        text: (account?.registrationInterval ?? 300).toString(),
      ),
      'crmUrl': TextEditingController(text: account?.crmApiUrl ?? ''),
      'presenceUrl': TextEditingController(text: account?.presenceApiUrl ?? ''),
      'crmToken': TextEditingController(),
    };
  }

  @override
  void dispose() {
    for (final controller in _fields.values) {
      controller.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      insetPadding: const EdgeInsets.all(12),
      titlePadding: const EdgeInsets.fromLTRB(18, 16, 18, 4),
      contentPadding: const EdgeInsets.fromLTRB(18, 8, 18, 0),
      actionsPadding: const EdgeInsets.fromLTRB(12, 6, 12, 12),
      title: Text(
        widget.account == null ? 'SIP Hesabı Ekle' : 'SIP Hesabını Düzenle',
        style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
      ),
      content: SizedBox(
        width: 520,
        child: Form(
          key: _formKey,
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Bağlanacağınız santral hesabının bilgilerini girin.',
                  style: TextStyle(fontSize: 11, color: Color(0xFF65748A)),
                ),
                const SizedBox(height: 12),
                _sectionTitle('HESAP'),
                _field('Hesap adı', 'name', hint: 'Örn. Ofis hesabı'),
                _row(_field('SIP sunucusu', 'server'), _field('Port', 'port')),
                _sectionTitle('KULLANICI BİLGİLERİ'),
                _field('Dahili / SIP kullanıcı adı', 'username'),
                _field('Kimlik doğrulama kullanıcısı', 'auth'),
                _field(
                  'SIP parolası',
                  'password',
                  obscure: true,
                  required: widget.account == null,
                ),
                if (widget.account != null)
                  const Padding(
                    padding: EdgeInsets.only(left: 2, bottom: 8),
                    child: Text(
                      'Parolayı değiştirmeyecekseniz boş bırakın.',
                      style: TextStyle(fontSize: 10, color: Color(0xFF65748A)),
                    ),
                  ),
                _field('Görünen ad (Caller ID)', 'display', required: false),
                Theme(
                  data: Theme.of(context).copyWith(
                    dividerColor: Colors.transparent,
                    visualDensity: VisualDensity.compact,
                  ),
                  child: ExpansionTile(
                    tilePadding: const EdgeInsets.symmetric(horizontal: 2),
                    childrenPadding: EdgeInsets.zero,
                    title: const Text(
                      'Gelişmiş Ayarlar',
                      style: TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    subtitle: const Text(
                      'Proxy, domain ve entegrasyonlar',
                      style: TextStyle(fontSize: 10),
                    ),
                    children: [
                      _field(
                        'SIP proxy sunucusu',
                        'proxy',
                        hint: 'Opsiyonel',
                        required: false,
                      ),
                      _field(
                        'SIP domain / etki alanı',
                        'domain',
                        hint: 'Opsiyonel',
                        required: false,
                      ),
                      _field(
                        'Meşguliyet API adresi',
                        'presenceUrl',
                        hint: 'Boşsa SIP sunucusundan otomatik belirlenir',
                        required: false,
                      ),
                      _field(
                        'CRM API adresi',
                        'crmUrl',
                        hint: 'Opsiyonel',
                        required: false,
                      ),
                      _field(
                        'CRM erişim anahtarı',
                        'crmToken',
                        hint: widget.account == null
                            ? 'Opsiyonel'
                            : 'Değişmeyecekse boş bırakın',
                        obscure: true,
                        required: false,
                      ),
                      _row(
                        _readonlyField('Taşıma protokolü', 'UDP'),
                        _field('Kayıt yenileme (sn)', 'interval'),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('İPTAL', style: TextStyle(fontSize: 11)),
        ),
        FilledButton(
          onPressed: _save,
          child: Text(
            widget.account == null ? 'HESABI EKLE' : 'DEĞİŞİKLİKLERİ KAYDET',
            style: const TextStyle(fontSize: 11),
          ),
        ),
      ],
    );
  }

  Widget _row(Widget left, Widget right) => Padding(
    padding: const EdgeInsets.only(bottom: 8),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(child: left),
        const SizedBox(width: 8),
        Expanded(child: right),
      ],
    ),
  );

  Widget _field(
    String label,
    String key, {
    bool obscure = false,
    bool required = true,
    String? hint,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: TextFormField(
        controller: _fields[key],
        obscureText: obscure,
        style: const TextStyle(fontSize: 12),
        validator: required
            ? (value) => value == null || value.trim().isEmpty
                  ? '$label gerekli'
                  : null
            : null,
        decoration: InputDecoration(
          labelText: label,
          hintText: hint,
          isDense: true,
          labelStyle: const TextStyle(fontSize: 11),
          floatingLabelStyle: const TextStyle(fontSize: 11),
          hintStyle: const TextStyle(fontSize: 10),
          contentPadding: const EdgeInsets.symmetric(
            horizontal: 11,
            vertical: 11,
          ),
        ),
      ),
    );
  }

  Widget _readonlyField(String label, String value) => Padding(
    padding: const EdgeInsets.only(bottom: 8),
    child: TextFormField(
      initialValue: value,
      readOnly: true,
      style: const TextStyle(fontSize: 12),
      decoration: InputDecoration(
        labelText: label,
        isDense: true,
        labelStyle: const TextStyle(fontSize: 11),
        floatingLabelStyle: const TextStyle(fontSize: 11),
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 11,
          vertical: 11,
        ),
      ),
    ),
  );

  Widget _sectionTitle(String title) => Padding(
    padding: const EdgeInsets.only(left: 2, bottom: 7),
    child: Text(
      title,
      style: const TextStyle(
        color: Color(0xFF607086),
        fontSize: 9,
        fontWeight: FontWeight.w800,
        letterSpacing: 0.8,
      ),
    ),
  );

  void _save() {
    if (!_formKey.currentState!.validate()) return;
    final port = int.tryParse(_fields['port']!.text);
    final interval = int.tryParse(_fields['interval']!.text);
    if (port == null || port < 1 || port > 65535 || interval == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Port veya kayıt süresi geçersiz.')),
      );
      return;
    }

    Navigator.pop(
      context,
      _AccountEditResult(
        profile: SipAccountProfile(
          id:
              widget.account?.id ??
              DateTime.now().microsecondsSinceEpoch.toString(),
          accountName: _fields['name']!.text.trim(),
          server: _fields['server']!.text.trim(),
          port: port,
          transport: SipTransport.udp,
          username: _fields['username']!.text.trim(),
          authUsername: _fields['auth']!.text.trim(),
          domain: _fields['domain']!.text.trim(),
          displayName: _fields['display']!.text.trim(),
          registrationInterval: interval,
          proxyServer: _fields['proxy']!.text.trim(),
          crmApiUrl: _fields['crmUrl']!.text.trim(),
          presenceApiUrl: _fields['presenceUrl']!.text.trim(),
        ),
        password: _fields['password']!.text,
        crmToken: _fields['crmToken']!.text.trim(),
      ),
    );
  }
}

class _AccountEditResult {
  const _AccountEditResult({
    required this.profile,
    required this.password,
    required this.crmToken,
  });

  final SipAccountProfile profile;
  final String password;
  final String crmToken;
}

enum _CallDirection { incoming, outgoing }

class _CallHistoryEntry {
  const _CallHistoryEntry({
    required this.accountId,
    required this.number,
    required this.direction,
    required this.startedAt,
  });

  final String accountId;
  final String number;
  final _CallDirection direction;
  final DateTime startedAt;
}

class _Panel extends StatelessWidget {
  const _Panel({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: const Color(0xFFFBF8F2),
        borderRadius: BorderRadius.circular(14),
      ),
      child: child,
    );
  }
}
