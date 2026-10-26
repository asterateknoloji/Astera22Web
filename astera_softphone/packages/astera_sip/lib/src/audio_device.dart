class AudioDevice {
  const AudioDevice({
    required this.id,
    required this.name,
    required this.driver,
    required this.inputChannels,
    required this.outputChannels,
  });

  final int id;
  final String name;
  final String driver;
  final int inputChannels;
  final int outputChannels;

  bool get supportsInput => inputChannels > 0;
  bool get supportsOutput => outputChannels > 0;
  String get key => '$driver\u0000$name';

  factory AudioDevice.fromChannel(Map<Object?, Object?> map) {
    return AudioDevice(
      id: map['id'] as int? ?? -1,
      name: map['name'] as String? ?? '',
      driver: map['driver'] as String? ?? '',
      inputChannels: map['inputChannels'] as int? ?? 0,
      outputChannels: map['outputChannels'] as int? ?? 0,
    );
  }
}

class AudioDeviceState {
  const AudioDeviceState({
    required this.devices,
    required this.captureDeviceId,
    required this.playbackDeviceId,
  });

  final List<AudioDevice> devices;
  final int captureDeviceId;
  final int playbackDeviceId;

  factory AudioDeviceState.fromChannel(Map<Object?, Object?> map) {
    final rawDevices = map['devices'] as List<Object?>? ?? const [];
    return AudioDeviceState(
      devices: rawDevices
          .whereType<Map<Object?, Object?>>()
          .map(AudioDevice.fromChannel)
          .toList(growable: false),
      captureDeviceId: map['captureDeviceId'] as int? ?? -1,
      playbackDeviceId: map['playbackDeviceId'] as int? ?? -1,
    );
  }
}
