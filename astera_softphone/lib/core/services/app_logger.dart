import 'dart:io';

class AppLogger {
  File? _file;
  Future<void> _pendingWrite = Future<void>.value();

  Future<void> initialize() async {
    final base = Platform.environment['LOCALAPPDATA'];
    final directory = Directory(
      base == null
          ? 'logs'
          : '$base${Platform.pathSeparator}AsteraSoftPhone'
                '${Platform.pathSeparator}logs',
    );
    await directory.create(recursive: true);
    _file = File(
      '${directory.path}${Platform.pathSeparator}astera-softphone.log',
    );
  }

  Future<void> write(String category, String message) {
    final line = '${DateTime.now().toIso8601String()} [$category] $message\n';
    _pendingWrite = _pendingWrite.then((_) async {
      await _file?.writeAsString(line, mode: FileMode.append, flush: true);
    });
    return _pendingWrite;
  }
}
