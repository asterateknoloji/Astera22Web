enum RegistrationState {
  initializing,
  registering,
  registered,
  registrationFailed,
  disconnected,
}

class RegistrationEvent {
  const RegistrationEvent({
    required this.state,
    required this.statusCode,
    required this.detail,
  });

  final RegistrationState state;
  final int statusCode;
  final String detail;

  factory RegistrationEvent.fromChannel(Object? value) {
    final map = Map<Object?, Object?>.from(value! as Map);
    final rawState = map['state'] as String? ?? 'disconnected';
    return RegistrationEvent(
      state: switch (rawState) {
        'initializing' => RegistrationState.initializing,
        'registering' => RegistrationState.registering,
        'registered' => RegistrationState.registered,
        'registrationFailed' => RegistrationState.registrationFailed,
        _ => RegistrationState.disconnected,
      },
      statusCode: map['statusCode'] as int? ?? 0,
      detail: map['detail'] as String? ?? '',
    );
  }
}
