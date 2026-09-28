import 'package:astera_sip/astera_sip.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:astera_sip/astera_sip_method_channel.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  final platform = MethodChannelAsteraSip();
  const channel = MethodChannel('tr.com.astera/astera_sip/methods');
  MethodCall? receivedCall;

  setUp(() {
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(channel, (MethodCall methodCall) async {
          receivedCall = methodCall;
          return null;
        });
  });

  tearDown(() {
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(channel, null);
  });

  test('register sends account without logging credentials', () async {
    await platform.register(
      const SipAccount(
        id: '1001',
        server: 'santral.astera.com.tr',
        port: 5060,
        transport: SipTransport.udp,
        username: '1001',
        password: 'secret',
        displayName: '1001',
      ),
    );

    expect(receivedCall?.method, 'register');
    expect((receivedCall?.arguments as Map)['server'], 'santral.astera.com.tr');
  });
}
