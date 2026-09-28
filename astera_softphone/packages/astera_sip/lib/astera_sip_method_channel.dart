import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';

import 'src/call_event.dart';
import 'src/registration_event.dart';
import 'src/sip_account.dart';
import 'astera_sip_platform_interface.dart';

/// An implementation of [AsteraSipPlatform] that uses method channels.
class MethodChannelAsteraSip extends AsteraSipPlatform {
  /// The method channel used to interact with the native platform.
  @visibleForTesting
  final methodChannel = const MethodChannel('tr.com.astera/astera_sip/methods');

  @override
  Stream<RegistrationEvent> get registrationEvents =>
      const EventChannel('tr.com.astera/astera_sip/events')
          .receiveBroadcastStream()
          .map(RegistrationEvent.fromChannel);

  @override
  Stream<CallEvent> get callEvents =>
      const EventChannel('tr.com.astera/astera_sip/call_events')
          .receiveBroadcastStream()
          .map(CallEvent.fromChannel);

  Timer? _eventPump;
  bool _pollInProgress = false;

  @override
  Future<void> initialize({int localSipPort = 0}) async {
    await methodChannel.invokeMethod<void>('initialize', <String, Object>{
      'localSipPort': localSipPort,
    });
    _eventPump ??= Timer.periodic(
      const Duration(milliseconds: 20),
      (_) => unawaited(_pollEvents()),
    );
  }

  @override
  Future<void> register(SipAccount account) =>
      methodChannel.invokeMethod<void>('register', account.toChannelMap());

  @override
  Future<void> unregister() => methodChannel.invokeMethod<void>('unregister');

  @override
  Future<void> saveCredential(String accountId, String password) =>
      methodChannel.invokeMethod<void>('saveCredential', <String, Object>{
        'accountId': accountId,
        'password': password,
      });

  @override
  Future<String?> readCredential(String accountId) =>
      methodChannel.invokeMethod<String>('readCredential', <String, Object>{
        'accountId': accountId,
      });

  @override
  Future<void> deleteCredential(String accountId) =>
      methodChannel.invokeMethod<void>('deleteCredential', <String, Object>{
        'accountId': accountId,
      });

  @override
  Future<void> makeCall(String destination) => methodChannel.invokeMethod<void>(
    'makeCall',
    <String, Object>{'destination': destination},
  );

  @override
  Future<void> answerCall() => methodChannel.invokeMethod<void>('answerCall');

  @override
  Future<void> rejectCall() => methodChannel.invokeMethod<void>('rejectCall');

  @override
  Future<void> hangupCall() => methodChannel.invokeMethod<void>('hangupCall');

  @override
  Future<void> sendDtmf(String digit) => methodChannel.invokeMethod<void>(
    'sendDtmf',
    <String, Object>{'digit': digit},
  );

  @override
  Future<void> playDialTone(String digit) => methodChannel.invokeMethod<void>(
    'playDialTone',
    <String, Object>{'digit': digit},
  );

  @override
  Future<void> setHold(bool hold) => methodChannel.invokeMethod<void>(
    'setHold',
    <String, Object>{'hold': hold},
  );

  @override
  Future<void> setMuted(bool muted) => methodChannel.invokeMethod<void>(
    'setMuted',
    <String, Object>{'muted': muted},
  );

  @override
  Future<void> transferCall(String destination) =>
      methodChannel.invokeMethod<void>('transferCall', <String, Object>{
        'destination': destination,
      });

  @override
  Future<void> setMicrophoneLevel(double level) =>
      methodChannel.invokeMethod<void>('setMicrophoneLevel', <String, Object>{
        'level': level,
      });

  @override
  Future<void> setSpeakerLevel(double level) => methodChannel
      .invokeMethod<void>('setSpeakerLevel', <String, Object>{'level': level});

  @override
  Future<void> dispose() async {
    _eventPump?.cancel();
    _eventPump = null;
    await methodChannel.invokeMethod<void>('dispose');
  }

  Future<void> _pollEvents() async {
    if (_pollInProgress) return;
    _pollInProgress = true;
    try {
      await methodChannel.invokeMethod<void>('pollEvents');
    } on PlatformException {
      // Operational failures are emitted through registrationEvents.
    } finally {
      _pollInProgress = false;
    }
  }
}
