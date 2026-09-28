import 'call_state.dart';

class CallEvent {
  const CallEvent({
    required this.state,
    required this.remoteUri,
    required this.statusCode,
    required this.detail,
  });

  final CallState state;
  final String remoteUri;
  final int statusCode;
  final String detail;

  factory CallEvent.fromChannel(Object? value) {
    final map = Map<Object?, Object?>.from(value! as Map);
    final rawState = map['state'] as String? ?? 'idle';
    return CallEvent(
      state: switch (rawState) {
        'calling' => CallState.calling,
        'ringing' => CallState.ringing,
        'incoming' => CallState.incoming,
        'connected' => CallState.connected,
        'held' => CallState.held,
        'disconnected' => CallState.disconnected,
        'failed' => CallState.failed,
        _ => CallState.idle,
      },
      remoteUri: map['remoteUri'] as String? ?? '',
      statusCode: map['statusCode'] as int? ?? 0,
      detail: map['detail'] as String? ?? '',
    );
  }
}
