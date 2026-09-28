export 'src/call_state.dart';
export 'src/call_event.dart';
export 'src/registration_event.dart';
export 'src/sip_account.dart';

import 'astera_sip_platform_interface.dart';
import 'src/call_event.dart';
import 'src/registration_event.dart';
import 'src/sip_account.dart';

class AsteraSip {
  Stream<RegistrationEvent> get registrationEvents =>
      AsteraSipPlatform.instance.registrationEvents;

  Stream<CallEvent> get callEvents => AsteraSipPlatform.instance.callEvents;

  Future<void> initialize({int localSipPort = 0}) =>
      AsteraSipPlatform.instance.initialize(localSipPort: localSipPort);

  Future<void> register(SipAccount account) =>
      AsteraSipPlatform.instance.register(account);

  Future<void> unregister() => AsteraSipPlatform.instance.unregister();

  Future<void> saveCredential(String accountId, String password) =>
      AsteraSipPlatform.instance.saveCredential(accountId, password);

  Future<String?> readCredential(String accountId) =>
      AsteraSipPlatform.instance.readCredential(accountId);

  Future<void> deleteCredential(String accountId) =>
      AsteraSipPlatform.instance.deleteCredential(accountId);

  Future<void> makeCall(String destination) =>
      AsteraSipPlatform.instance.makeCall(destination);
  Future<void> answerCall() => AsteraSipPlatform.instance.answerCall();
  Future<void> rejectCall() => AsteraSipPlatform.instance.rejectCall();
  Future<void> hangupCall() => AsteraSipPlatform.instance.hangupCall();
  Future<void> sendDtmf(String digit) =>
      AsteraSipPlatform.instance.sendDtmf(digit);
  Future<void> playDialTone(String digit) =>
      AsteraSipPlatform.instance.playDialTone(digit);
  Future<void> setHold(bool hold) => AsteraSipPlatform.instance.setHold(hold);
  Future<void> setMuted(bool muted) =>
      AsteraSipPlatform.instance.setMuted(muted);
  Future<void> transferCall(String destination) =>
      AsteraSipPlatform.instance.transferCall(destination);
  Future<void> setMicrophoneLevel(double level) =>
      AsteraSipPlatform.instance.setMicrophoneLevel(level);
  Future<void> setSpeakerLevel(double level) =>
      AsteraSipPlatform.instance.setSpeakerLevel(level);

  Future<void> dispose() => AsteraSipPlatform.instance.dispose();
}
