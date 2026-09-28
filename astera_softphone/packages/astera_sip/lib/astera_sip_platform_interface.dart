import 'package:plugin_platform_interface/plugin_platform_interface.dart';

import 'src/registration_event.dart';
import 'src/sip_account.dart';
import 'src/call_event.dart';
import 'astera_sip_method_channel.dart';

abstract class AsteraSipPlatform extends PlatformInterface {
  /// Constructs a AsteraSipPlatform.
  AsteraSipPlatform() : super(token: _token);

  static final Object _token = Object();

  static AsteraSipPlatform _instance = MethodChannelAsteraSip();

  /// The default instance of [AsteraSipPlatform] to use.
  ///
  /// Defaults to [MethodChannelAsteraSip].
  static AsteraSipPlatform get instance => _instance;

  /// Platform-specific implementations should set this with their own
  /// platform-specific class that extends [AsteraSipPlatform] when
  /// they register themselves.
  static set instance(AsteraSipPlatform instance) {
    PlatformInterface.verifyToken(instance, _token);
    _instance = instance;
  }

  Stream<RegistrationEvent> get registrationEvents =>
      throw UnimplementedError('registrationEvents has not been implemented.');

  Stream<CallEvent> get callEvents =>
      throw UnimplementedError('callEvents has not been implemented.');

  Future<void> initialize({int localSipPort = 0}) =>
      throw UnimplementedError('initialize() has not been implemented.');

  Future<void> register(SipAccount account) =>
      throw UnimplementedError('register() has not been implemented.');

  Future<void> unregister() =>
      throw UnimplementedError('unregister() has not been implemented.');

  Future<void> saveCredential(String accountId, String password) =>
      throw UnimplementedError('saveCredential() has not been implemented.');

  Future<String?> readCredential(String accountId) =>
      throw UnimplementedError('readCredential() has not been implemented.');

  Future<void> deleteCredential(String accountId) =>
      throw UnimplementedError('deleteCredential() has not been implemented.');

  Future<void> makeCall(String destination) =>
      throw UnimplementedError('makeCall() has not been implemented.');
  Future<void> answerCall() =>
      throw UnimplementedError('answerCall() has not been implemented.');
  Future<void> rejectCall() =>
      throw UnimplementedError('rejectCall() has not been implemented.');
  Future<void> hangupCall() =>
      throw UnimplementedError('hangupCall() has not been implemented.');
  Future<void> sendDtmf(String digit) =>
      throw UnimplementedError('sendDtmf() has not been implemented.');
  Future<void> playDialTone(String digit) =>
      throw UnimplementedError('playDialTone() has not been implemented.');
  Future<void> setHold(bool hold) =>
      throw UnimplementedError('setHold() has not been implemented.');
  Future<void> setMuted(bool muted) =>
      throw UnimplementedError('setMuted() has not been implemented.');
  Future<void> transferCall(String destination) =>
      throw UnimplementedError('transferCall() has not been implemented.');
  Future<void> setMicrophoneLevel(double level) => throw UnimplementedError(
    'setMicrophoneLevel() has not been implemented.',
  );
  Future<void> setSpeakerLevel(double level) =>
      throw UnimplementedError('setSpeakerLevel() has not been implemented.');

  Future<void> dispose() =>
      throw UnimplementedError('dispose() has not been implemented.');
}
