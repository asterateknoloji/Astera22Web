# ASTERA Softphone

Flutter UI with a native PJSIP/PJSUA2 transport. Phase 1 implements Windows
x64 UDP transport and SIP REGISTER only. It does not use WebRTC, SIP.js,
WebSocket SIP, Electron, or a browser runtime.

## Versions

- Flutter 3.47.5 / Dart 3.13.4
- PJSIP/PJSUA2 2.17, commit `5a457451fa2712ba18e12b01738e8ff3af2b26fd`
- Visual Studio Build Tools 2022, MSVC v143

## Architecture

- `lib/`: shared Flutter configuration, controller, logging, and UI
- `packages/astera_sip/`: platform plugin API and Windows implementation
- `native/pjsip/windows/`: staged x64 headers and static libraries
- `scripts/`: reproducible native build scripts

Flutter sends lifecycle commands over `MethodChannel`. Registration events
return over `EventChannel`. PJSUA2 objects remain entirely in native code.
PJSIP is configured with `mainThreadOnly=true`; Dart pumps
`Endpoint::libHandleEvents()` every 20 ms so callbacks stay on Flutter's
platform thread.

## Build

Enable Windows Developer Mode first; Flutter uses symbolic links for plugins.

```powershell
start ms-settings:developers
flutter pub get
powershell -ExecutionPolicy Bypass -File scripts\build_pjsip_windows.ps1
flutter test
flutter analyze
flutter build windows --release
```

Executable:

```text
build\windows\x64\runner\Release\astera_softphone.exe
```

The SIP password is never stored in source or the JSON asset. Phase 1 keeps
the password in memory after the user enters it. Application logs are written
under `%LOCALAPPDATA%\AsteraSoftPhone\logs`; credentials are not logged.
`Username / Extension` is used in the SIP identity while `Auth username`
(for example `120_1020`) is used for digest authentication.

## Asterisk verification

```text
asterisk -rvvv
pjsip set logger on
pjsip show endpoint 1001
pjsip show aor 1001
pjsip show contacts
```

`pjsip show registrations` is for Asterisk's outbound registrations and does
not verify a phone registering as endpoint `1001`.

## Known constraints

- A real REGISTER result requires a valid password and network access to the
  configured Asterisk server.
- Android SDK/NDK and the Android native plugin are deferred to Phase 3.
- iOS requires macOS/Xcode and is deferred to Phase 4.
- PJSIP 2.17 has security advisories that must be reviewed or patched before
  production deployment.
- PJSIP GPL/commercial licensing must be resolved before proprietary
  distribution.
# astera_softphone

A new Flutter project.

## Getting Started

This project is a starting point for a Flutter application.

A few resources to get you started if this is your first Flutter project:

- [Learn Flutter](https://docs.flutter.dev/get-started/learn-flutter)
- [Write your first Flutter app](https://docs.flutter.dev/get-started/codelab)
- [Flutter learning resources](https://docs.flutter.dev/reference/learning-resources)

For help getting started with Flutter development, view the
[online documentation](https://docs.flutter.dev/), which offers tutorials,
samples, guidance on mobile development, and a full API reference.
