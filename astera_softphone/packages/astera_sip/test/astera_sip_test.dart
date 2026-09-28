import 'package:flutter_test/flutter_test.dart';
import 'package:astera_sip/astera_sip.dart';
import 'package:astera_sip/astera_sip_platform_interface.dart';
import 'package:astera_sip/astera_sip_method_channel.dart';
import 'package:plugin_platform_interface/plugin_platform_interface.dart';

class MockAsteraSipPlatform
    with MockPlatformInterfaceMixin
    implements AsteraSipPlatform {
  SipAccount? registeredAccount;

  @override
  Stream<RegistrationEvent> get registrationEvents => const Stream.empty();

  @override
  Stream<CallEvent> get callEvents => const Stream.empty();

  @override
  Future<void> initialize({int localSipPort = 0}) async {}

  @override
  Future<void> register(SipAccount account) async {
    registeredAccount = account;
  }

  @override
  Future<void> unregister() async {}

  @override
  Future<void> saveCredential(String accountId, String password) async {}

  @override
  Future<String?> readCredential(String accountId) async => null;

  @override
  Future<void> deleteCredential(String accountId) async {}

  @override
  Future<void> makeCall(String destination) async {}
  @override
  Future<void> answerCall() async {}
  @override
  Future<void> rejectCall() async {}
  @override
  Future<void> hangupCall() async {}
  @override
  Future<void> sendDtmf(String digit) async {}
  @override
  Future<void> playDialTone(String digit) async {}
  @override
  Future<void> setHold(bool hold) async {}
  @override
  Future<void> setMuted(bool muted) async {}
  @override
  Future<void> transferCall(String destination) async {}
  @override
  Future<void> setMicrophoneLevel(double level) async {}
  @override
  Future<void> setSpeakerLevel(double level) async {}

  @override
  Future<void> dispose() async {}
}

void main() {
  final AsteraSipPlatform initialPlatform = AsteraSipPlatform.instance;

  test('$MethodChannelAsteraSip is the default instance', () {
    expect(initialPlatform, isInstanceOf<MethodChannelAsteraSip>());
  });

  test('register delegates a validated account', () async {
    final asteraSipPlugin = AsteraSip();
    final fakePlatform = MockAsteraSipPlatform();
    AsteraSipPlatform.instance = fakePlatform;
    const account = SipAccount(
      id: '1001',
      server: 'santral.astera.com.tr',
      port: 5060,
      transport: SipTransport.udp,
      username: '1001',
      password: 'secret',
      displayName: '1001',
    );

    await asteraSipPlugin.register(account);

    expect(fakePlatform.registeredAccount, same(account));
  });
}
