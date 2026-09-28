import 'dart:async';

import 'package:astera_sip/astera_sip.dart';
import 'package:flutter/foundation.dart';

import '../../core/models/app_config.dart';
import '../../core/services/app_logger.dart';

class RegistrationController extends ChangeNotifier {
  RegistrationController({
    required AsteraSip sip,
    required AppLogger logger,
    required AppConfig config,
  }) : _sip = sip,
       _logger = logger,
       _config = config;

  final AsteraSip _sip;
  final AppLogger _logger;
  final AppConfig _config;
  StreamSubscription<RegistrationEvent>? _subscription;
  StreamSubscription<CallEvent>? _callSubscription;

  RegistrationEvent event = const RegistrationEvent(
    state: RegistrationState.disconnected,
    statusCode: 0,
    detail: 'Hazır',
  );
  bool busy = false;
  CallEvent callEvent = const CallEvent(
    state: CallState.idle,
    remoteUri: '',
    statusCode: 0,
    detail: '',
  );
  bool muted = false;
  bool held = false;
  bool doNotDisturb = false;
  bool autoAnswer = false;
  double microphoneLevel = 1;
  double speakerLevel = 1;
  bool _incomingActionHandled = false;

  Future<void> initialize() async {
    await _logger.initialize();
    _subscription = _sip.registrationEvents.listen(_onRegistrationEvent);
    _callSubscription = _sip.callEvents.listen(_onCallEvent);
    try {
      await _sip.initialize(localSipPort: _config.localSipPort);
      await _logger.write('SIP', 'PJSIP initialized with UDP transport.');
    } catch (error) {
      _setFailure(error);
    }
  }

  Future<void> register(SipAccount account) async {
    if (busy) return;
    busy = true;
    notifyListeners();
    try {
      await _logger.write(
        'SIP',
        'REGISTER requested for ${account.username}@${account.server}.',
      );
      await _sip.register(account);
    } catch (error) {
      _setFailure(error);
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<void> unregister() async {
    if (busy) return;
    busy = true;
    notifyListeners();
    try {
      await _sip.unregister();
      await _logger.write('SIP', 'Unregister requested.');
    } catch (error) {
      _setFailure(error);
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<void> shutdown() async {
    await _subscription?.cancel();
    await _callSubscription?.cancel();
    _subscription = null;
    _callSubscription = null;
    try {
      await _sip.dispose();
    } catch (error) {
      await _logger.write('ERROR', 'PJSIP shutdown failed: $error');
    }
  }

  void _onRegistrationEvent(RegistrationEvent next) {
    event = next;
    notifyListeners();
    unawaited(
      _logger.write(
        'SIP',
        'Registration state=${next.state.name} '
            'code=${next.statusCode} detail=${next.detail}',
      ),
    );
  }

  Future<void> makeCall(String destination) => _sip.makeCall(destination);
  Future<void> answerCall() => _sip.answerCall();
  Future<void> rejectCall() => _sip.rejectCall();
  Future<void> hangupCall() => _sip.hangupCall();

  Future<void> toggleMute() async {
    final next = !muted;
    await _sip.setMuted(next);
    muted = next;
    notifyListeners();
  }

  Future<void> toggleHold() async {
    final next = !held;
    await _sip.setHold(next);
    held = next;
    notifyListeners();
  }

  Future<void> transferCall(String destination) =>
      _sip.transferCall(destination.trim());

  Future<void> attendedTransfer(String destination) async {
    final target = destination.trim();
    if (target.isEmpty) {
      throw ArgumentError('Aktarım hedefi gerekli.');
    }
    await _sip.sendDtmf('*2');
    await Future<void>.delayed(const Duration(milliseconds: 650));
    await _sip.sendDtmf(target);
    await Future<void>.delayed(const Duration(milliseconds: 350));
    await _sip.sendDtmf('#');
    await _logger.write(
      'CALL',
      'Asterisk attended transfer requested with feature code *2.',
    );
  }

  Future<void> playDialTone(String digit) => _sip.playDialTone(digit);

  Future<void> setMicrophoneLevel(double level) async {
    microphoneLevel = level;
    notifyListeners();
    await _sip.setMicrophoneLevel(level);
  }

  Future<void> setSpeakerLevel(double level) async {
    speakerLevel = level;
    notifyListeners();
    await _sip.setSpeakerLevel(level);
  }

  void setDoNotDisturb(bool value) {
    doNotDisturb = value;
    if (value) autoAnswer = false;
    notifyListeners();
  }

  void setAutoAnswer(bool value) {
    autoAnswer = value;
    if (value) doNotDisturb = false;
    notifyListeners();
  }

  Future<void> dialDigit(String digit) async {
    await _sip.playDialTone(digit);
    if (callEvent.state == CallState.connected) {
      await _sip.sendDtmf(digit);
    }
  }

  void _onCallEvent(CallEvent next) {
    callEvent = next;
    held = next.state == CallState.held;
    if (next.state == CallState.disconnected ||
        next.state == CallState.failed ||
        next.state == CallState.idle) {
      muted = false;
      held = false;
      _incomingActionHandled = false;
    }
    notifyListeners();
    if (next.state == CallState.incoming && !_incomingActionHandled) {
      if (doNotDisturb) {
        _incomingActionHandled = true;
        unawaited(_sip.rejectCall());
      } else if (autoAnswer) {
        _incomingActionHandled = true;
        unawaited(_sip.answerCall());
      }
    }
    unawaited(
      _logger.write(
        'CALL',
        'state=${next.state.name} remote=${next.remoteUri} '
            'code=${next.statusCode} detail=${next.detail}',
      ),
    );
  }

  void _setFailure(Object error) {
    event = RegistrationEvent(
      state: RegistrationState.registrationFailed,
      statusCode: 0,
      detail: error.toString(),
    );
    notifyListeners();
    unawaited(_logger.write('ERROR', 'Registration failure: $error'));
  }
}
