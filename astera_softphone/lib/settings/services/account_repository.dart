import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:astera_sip/astera_sip.dart';

import '../models/sip_account_profile.dart';

class CustomRingtone {
  const CustomRingtone({
    required this.name,
    required this.path,
    this.isBundled = false,
  });

  final String name;
  final String path;
  final bool isBundled;

  Map<String, Object> toJson() => {'name': name, 'path': path};
}

class AccountRepository {
  AccountRepository(this._sip);

  final AsteraSip _sip;
  final List<SipAccountProfile> _accounts = [];
  String? _activeAccountId;
  String? _captureDeviceKey;
  String? _playbackDeviceKey;
  bool _autoAnswerEnabled = false;
  int _autoAnswerRingCount = 2;
  int _ringtoneStyle = 0;
  String? _ringtoneFilePath;
  bool _bundledRingtoneInitialized = false;
  final List<CustomRingtone> _customRingtones = [];
  late final File _file;

  List<SipAccountProfile> get accounts => List.unmodifiable(_accounts);
  String? get activeAccountId => _activeAccountId;
  String? get captureDeviceKey => _captureDeviceKey;
  String? get playbackDeviceKey => _playbackDeviceKey;
  bool get autoAnswerEnabled => _autoAnswerEnabled;
  int get autoAnswerRingCount => _autoAnswerRingCount;
  int get ringtoneStyle => _ringtoneStyle;
  String? get ringtoneFilePath => _ringtoneFilePath;
  List<CustomRingtone> get customRingtones =>
      List.unmodifiable(_customRingtones);

  Future<void> initialize() async {
    final localAppData = Platform.environment['LOCALAPPDATA'];
    final directory = Directory(
      localAppData == null
          ? 'config'
          : '$localAppData${Platform.pathSeparator}AsteraSoftPhone'
                '${Platform.pathSeparator}config',
    );
    await directory.create(recursive: true);
    _file = File('${directory.path}${Platform.pathSeparator}accounts.json');
    if (!await _file.exists()) {
      await _discoverBundledRingtones();
      await _selectInitialBundledRingtone();
      await _persist();
      return;
    }

    final root = jsonDecode(await _file.readAsString()) as Map<String, dynamic>;
    final items = root['accounts'] as List<dynamic>? ?? const [];
    _accounts
      ..clear()
      ..addAll(
        items.map(
          (item) => SipAccountProfile.fromJson(item as Map<String, dynamic>),
        ),
      );
    _activeAccountId = root['activeAccountId'] as String?;
    _captureDeviceKey = root['captureDeviceKey'] as String?;
    _playbackDeviceKey = root['playbackDeviceKey'] as String?;
    _autoAnswerEnabled = root['autoAnswerEnabled'] as bool? ?? false;
    _autoAnswerRingCount = ((root['autoAnswerRingCount'] as num?)?.toInt() ?? 2)
        .clamp(1, 10)
        .toInt();
    _ringtoneStyle = ((root['ringtoneStyle'] as num?)?.toInt() ?? 0)
        .clamp(0, 3)
        .toInt();
    _ringtoneFilePath = root['ringtoneFilePath'] as String?;
    _bundledRingtoneInitialized =
        root['bundledRingtoneInitialized'] as bool? ?? false;
    _customRingtones.clear();
    final ringtoneItems =
        root['customRingtones'] as List<dynamic>? ?? const <dynamic>[];
    for (final item in ringtoneItems) {
      if (item is! Map<String, dynamic>) continue;
      final name = item['name'] as String?;
      final path = item['path'] as String?;
      if (name != null &&
          name.trim().isNotEmpty &&
          path != null &&
          await File(path).exists()) {
        _customRingtones.add(CustomRingtone(name: name.trim(), path: path));
      }
    }
    await _discoverBundledRingtones();
    if (_ringtoneFilePath != null &&
        await File(_ringtoneFilePath!).exists() &&
        !_customRingtones.any((tone) => tone.path == _ringtoneFilePath)) {
      _customRingtones.add(
        CustomRingtone(name: 'Özel Zil', path: _ringtoneFilePath!),
      );
    }
    if (_ringtoneFilePath == null ||
        !_customRingtones.any((tone) => tone.path == _ringtoneFilePath)) {
      _ringtoneFilePath = null;
      if (_ringtoneStyle == 3) _ringtoneStyle = 0;
    }
    await _selectInitialBundledRingtone();
    if (!_accounts.any((account) => account.id == _activeAccountId)) {
      _activeAccountId = null;
    }
  }

  Future<void> save(
    SipAccountProfile profile, {
    String password = '',
    String crmToken = '',
  }) async {
    final index = _accounts.indexWhere((account) => account.id == profile.id);
    if (index == -1) {
      _accounts.add(profile);
    } else {
      _accounts[index] = profile;
    }
    if (password.isNotEmpty) {
      await _sip.saveCredential(profile.id, password);
    }
    if (crmToken.isNotEmpty) {
      await _sip.saveCredential('crm:${profile.id}', crmToken);
    }
    _activeAccountId ??= profile.id;
    await _persist();
  }

  Future<void> setActive(String accountId) async {
    if (!_accounts.any((account) => account.id == accountId)) {
      throw StateError('SIP account does not exist.');
    }
    _activeAccountId = accountId;
    await _persist();
  }

  Future<void> delete(String accountId) async {
    _accounts.removeWhere((account) => account.id == accountId);
    await _sip.deleteCredential(accountId);
    await _sip.deleteCredential('crm:$accountId');
    if (_activeAccountId == accountId) {
      _activeAccountId = _accounts.isEmpty ? null : _accounts.first.id;
    }
    await _persist();
  }

  Future<String?> readPassword(String accountId) =>
      _sip.readCredential(accountId);

  Future<String?> readCrmToken(String accountId) =>
      _sip.readCredential('crm:$accountId');

  Future<void> setAudioDeviceKeys({
    required String captureDeviceKey,
    required String playbackDeviceKey,
  }) async {
    _captureDeviceKey = captureDeviceKey;
    _playbackDeviceKey = playbackDeviceKey;
    await _persist();
  }

  Future<void> setAutoAnswerSettings({
    required bool enabled,
    required int ringCount,
  }) async {
    _autoAnswerEnabled = enabled;
    _autoAnswerRingCount = ringCount.clamp(1, 10).toInt();
    await _persist();
  }

  Future<void> setRingtoneStyle(int style) async {
    _ringtoneStyle = style.clamp(0, 3).toInt();
    await _persist();
  }

  Future<String> importRingtoneFile(String sourcePath, String name) async {
    final ringtoneName = name.trim();
    if (ringtoneName.isEmpty || ringtoneName.length > 50) {
      throw const FormatException('Zil adı 1-50 karakter olmalıdır.');
    }
    if (_customRingtones.any(
      (tone) => tone.name.toLowerCase() == ringtoneName.toLowerCase(),
    )) {
      throw const FormatException('Bu isimde bir zil sesi zaten var.');
    }
    final source = File(sourcePath);
    if (!sourcePath.toLowerCase().endsWith('.wav') || !await source.exists()) {
      throw const FormatException('Geçerli bir WAV dosyası seçin.');
    }
    final length = await source.length();
    if (length < 44 || length > 25 * 1024 * 1024) {
      throw const FormatException(
        'WAV dosyası boş veya 25 MB sınırından büyük.',
      );
    }
    final normalizedWav = _normalizeRingtoneWav(await source.readAsBytes());

    final ringtoneDirectory = Directory(
      '${_file.parent.path}${Platform.pathSeparator}ringtone',
    );
    await ringtoneDirectory.create(recursive: true);
    final destination = File(
      '${ringtoneDirectory.path}${Platform.pathSeparator}'
      'ringtone_${DateTime.now().microsecondsSinceEpoch}.wav',
    );
    final temporary = File('${destination.path}.tmp');
    if (await temporary.exists()) await temporary.delete();
    await temporary.writeAsBytes(normalizedWav, flush: true);
    if (await destination.exists()) await destination.delete();
    await temporary.rename(destination.path);
    _customRingtones.add(
      CustomRingtone(name: ringtoneName, path: destination.path),
    );
    await _persist();
    return destination.path;
  }

  Future<void> selectRingtoneFile(String path) async {
    if (!_customRingtones.any((tone) => tone.path == path) ||
        !await File(path).exists()) {
      throw const FormatException('Seçilen zil sesi bulunamadı.');
    }
    _ringtoneFilePath = path;
    await _persist();
  }

  Future<void> deleteRingtoneFile(String path) async {
    final tone = _customRingtones.where((item) => item.path == path);
    if (tone.isEmpty || tone.first.isBundled) return;
    final file = File(path);
    if (await file.exists()) await file.delete();
    _customRingtones.removeWhere((tone) => tone.path == path);
    if (_ringtoneFilePath == path) {
      _ringtoneFilePath = null;
      _ringtoneStyle = 0;
    }
    await _persist();
  }

  Future<void> _discoverBundledRingtones() async {
    final directory = Directory(
      '${File(Platform.resolvedExecutable).parent.path}'
      '${Platform.pathSeparator}Zil',
    );
    if (!await directory.exists()) return;
    final files = await directory
        .list()
        .where((entry) {
          if (entry is! File) return false;
          final path = entry.path.toLowerCase();
          return path.endsWith('.wav') ||
              path.endsWith('.mp3') ||
              path.endsWith('.mpeg') ||
              path.endsWith('.mpg');
        })
        .cast<File>()
        .toList();
    files.sort(
      (left, right) =>
          left.path.toLowerCase().compareTo(right.path.toLowerCase()),
    );
    for (final file in files) {
      if (_customRingtones.any((tone) => tone.path == file.path)) continue;
      final fileName = file.uri.pathSegments.last;
      final dot = fileName.lastIndexOf('.');
      final baseName = dot > 0 ? fileName.substring(0, dot) : fileName;
      final name = baseName.toLowerCase() == 'zil1' ? 'Zil1' : baseName;
      _customRingtones.add(
        CustomRingtone(name: name, path: file.path, isBundled: true),
      );
    }
  }

  Future<void> _selectInitialBundledRingtone() async {
    if (_bundledRingtoneInitialized) return;
    _bundledRingtoneInitialized = true;
    final defaults = _customRingtones.where(
      (tone) => tone.isBundled && tone.name.toLowerCase() == 'zil1',
    );
    if (defaults.isEmpty) return;
    _ringtoneFilePath = defaults.first.path;
    _ringtoneStyle = 3;
  }

  static Uint8List _normalizeRingtoneWav(Uint8List bytes) {
    if (bytes.length < 44 ||
        ascii.decode(bytes.sublist(0, 4), allowInvalid: true) != 'RIFF' ||
        ascii.decode(bytes.sublist(8, 12), allowInvalid: true) != 'WAVE') {
      throw const FormatException('Seçilen dosya geçerli bir WAV değil.');
    }
    final input = ByteData.sublistView(bytes);
    int? formatOffset;
    int? formatSize;
    int? audioOffset;
    int? audioSize;
    var offset = 12;
    while (offset + 8 <= bytes.length) {
      final chunkName = ascii.decode(
        bytes.sublist(offset, offset + 4),
        allowInvalid: true,
      );
      final chunkSize = input.getUint32(offset + 4, Endian.little);
      final chunkOffset = offset + 8;
      if (chunkOffset + chunkSize > bytes.length) {
        throw const FormatException('WAV dosyasının yapısı bozuk.');
      }
      if (chunkName == 'fmt ') {
        formatOffset = chunkOffset;
        formatSize = chunkSize;
      } else if (chunkName == 'data' && audioOffset == null) {
        audioOffset = chunkOffset;
        audioSize = chunkSize;
      }
      offset = chunkOffset + chunkSize + (chunkSize.isOdd ? 1 : 0);
    }
    if (formatOffset == null ||
        formatSize == null ||
        formatSize < 16 ||
        audioOffset == null ||
        audioSize == null) {
      throw const FormatException('WAV ses verisi okunamadı.');
    }
    final audioFormat = input.getUint16(formatOffset, Endian.little);
    final channelCount = input.getUint16(formatOffset + 2, Endian.little);
    final sampleRate = input.getUint32(formatOffset + 4, Endian.little);
    final bitsPerSample = input.getUint16(formatOffset + 14, Endian.little);
    if (audioFormat != 1 ||
        channelCount < 1 ||
        channelCount > 8 ||
        bitsPerSample != 16 ||
        sampleRate == 0) {
      throw const FormatException(
        'Zil sesi 16 bit PCM WAV biçiminde olmalıdır.',
      );
    }
    final inputFrameSize = channelCount * 2;
    final sampleCount = audioSize ~/ inputFrameSize;
    if (sampleCount == 0) {
      throw const FormatException('WAV dosyasında ses verisi bulunamadı.');
    }

    final output = Uint8List(44 + sampleCount * 2);
    final outputData = ByteData.sublistView(output);
    _writeAscii(output, 0, 'RIFF');
    outputData.setUint32(4, output.length - 8, Endian.little);
    _writeAscii(output, 8, 'WAVE');
    _writeAscii(output, 12, 'fmt ');
    outputData.setUint32(16, 16, Endian.little);
    outputData.setUint16(20, 1, Endian.little);
    outputData.setUint16(22, 1, Endian.little);
    outputData.setUint32(24, sampleRate, Endian.little);
    outputData.setUint32(28, sampleRate * 2, Endian.little);
    outputData.setUint16(32, 2, Endian.little);
    outputData.setUint16(34, 16, Endian.little);
    _writeAscii(output, 36, 'data');
    outputData.setUint32(40, sampleCount * 2, Endian.little);
    for (var sampleIndex = 0; sampleIndex < sampleCount; sampleIndex++) {
      var mixedSample = 0;
      final frameOffset = audioOffset + sampleIndex * inputFrameSize;
      for (var channel = 0; channel < channelCount; channel++) {
        mixedSample += input.getInt16(frameOffset + channel * 2, Endian.little);
      }
      outputData.setInt16(
        44 + sampleIndex * 2,
        mixedSample ~/ channelCount,
        Endian.little,
      );
    }
    return output;
  }

  static void _writeAscii(Uint8List target, int offset, String value) {
    target.setRange(offset, offset + value.length, ascii.encode(value));
  }

  Future<void> _persist() async {
    final content = const JsonEncoder.withIndent('  ').convert({
      'version': 5,
      'activeAccountId': _activeAccountId,
      'captureDeviceKey': _captureDeviceKey,
      'playbackDeviceKey': _playbackDeviceKey,
      'autoAnswerEnabled': _autoAnswerEnabled,
      'autoAnswerRingCount': _autoAnswerRingCount,
      'ringtoneStyle': _ringtoneStyle,
      'ringtoneFilePath': _ringtoneFilePath,
      'bundledRingtoneInitialized': _bundledRingtoneInitialized,
      'customRingtones': _customRingtones
          .where((ringtone) => !ringtone.isBundled)
          .map((ringtone) => ringtone.toJson())
          .toList(),
      'accounts': _accounts.map((account) => account.toJson()).toList(),
    });
    await _file.writeAsString(content, flush: true);
  }
}
