#include "astera_sip_plugin.h"

#include <windows.h>
#include <wincred.h>
#include <mmdeviceapi.h>
#include <functiondiscoverykeys_devpkey.h>
#include <propvarutil.h>

#include <flutter/event_channel.h>
#include <flutter/event_stream_handler_functions.h>
#include <flutter/method_channel.h>
#include <flutter/plugin_registrar_windows.h>
#include <flutter/standard_method_codec.h>

#include <cstdint>
#include <cwctype>
#include <memory>
#include <stdexcept>
#include <string>
#include <vector>

namespace astera_sip {
namespace {

const flutter::EncodableMap& RequireArguments(
    const flutter::MethodCall<flutter::EncodableValue>& call) {
  const auto* arguments =
      std::get_if<flutter::EncodableMap>(call.arguments());
  if (arguments == nullptr) {
    throw std::invalid_argument("Method arguments are missing.");
  }
  return *arguments;
}

std::string RequireString(const flutter::EncodableMap& map,
                          const std::string& key) {
  const auto iterator = map.find(flutter::EncodableValue(key));
  if (iterator == map.end()) {
    throw std::invalid_argument("Missing argument: " + key);
  }
  const auto* value = std::get_if<std::string>(&iterator->second);
  if (value == nullptr) {
    throw std::invalid_argument("Invalid string argument: " + key);
  }
  return *value;
}

int GetInt(const flutter::EncodableMap& map,
           const std::string& key,
           int fallback) {
  const auto iterator = map.find(flutter::EncodableValue(key));
  if (iterator == map.end()) {
    return fallback;
  }
  if (const auto* value = std::get_if<int32_t>(&iterator->second)) {
    return *value;
  }
  if (const auto* value = std::get_if<int64_t>(&iterator->second)) {
    return static_cast<int>(*value);
  }
  throw std::invalid_argument("Invalid integer argument: " + key);
}

bool RequireBool(const flutter::EncodableMap& map, const std::string& key) {
  const auto iterator = map.find(flutter::EncodableValue(key));
  if (iterator == map.end()) {
    throw std::invalid_argument("Missing argument: " + key);
  }
  const auto* value = std::get_if<bool>(&iterator->second);
  if (value == nullptr) {
    throw std::invalid_argument("Invalid boolean argument: " + key);
  }
  return *value;
}

double RequireDouble(const flutter::EncodableMap& map,
                     const std::string& key) {
  const auto iterator = map.find(flutter::EncodableValue(key));
  if (iterator == map.end()) {
    throw std::invalid_argument("Missing argument: " + key);
  }
  const auto* value = std::get_if<double>(&iterator->second);
  if (value == nullptr) {
    throw std::invalid_argument("Invalid number argument: " + key);
  }
  return *value;
}

std::wstring Utf8ToWide(const std::string& value) {
  if (value.empty()) {
    return {};
  }
  const int size = MultiByteToWideChar(
      CP_UTF8, 0, value.data(), static_cast<int>(value.size()), nullptr, 0);
  std::wstring result(size, L'\0');
  MultiByteToWideChar(
      CP_UTF8, 0, value.data(), static_cast<int>(value.size()),
      result.data(), size);
  return result;
}

std::string NormalizeDeviceText(const std::string& value) {
  if (value.empty()) {
    return {};
  }
  if (MultiByteToWideChar(
          CP_UTF8,
          MB_ERR_INVALID_CHARS,
          value.data(),
          static_cast<int>(value.size()),
          nullptr,
          0) > 0) {
    return value;
  }

  const int wide_size = MultiByteToWideChar(
      CP_ACP,
      0,
      value.data(),
      static_cast<int>(value.size()),
      nullptr,
      0);
  if (wide_size <= 0) {
    return "Bilinmeyen ses aygiti";
  }
  std::wstring wide(wide_size, L'\0');
  MultiByteToWideChar(
      CP_ACP,
      0,
      value.data(),
      static_cast<int>(value.size()),
      wide.data(),
      wide_size);
  const int utf8_size = WideCharToMultiByte(
      CP_UTF8,
      0,
      wide.data(),
      wide_size,
      nullptr,
      0,
      nullptr,
      nullptr);
  if (utf8_size <= 0) {
    return "Bilinmeyen ses aygiti";
  }
  std::string utf8(utf8_size, '\0');
  WideCharToMultiByte(
      CP_UTF8,
      0,
      wide.data(),
      wide_size,
      utf8.data(),
      utf8_size,
      nullptr,
      nullptr);
  return utf8;
}

std::string WideToUtf8(const std::wstring& value) {
  if (value.empty()) {
    return {};
  }
  const int size = WideCharToMultiByte(
      CP_UTF8,
      0,
      value.data(),
      static_cast<int>(value.size()),
      nullptr,
      0,
      nullptr,
      nullptr);
  if (size <= 0) {
    return {};
  }
  std::string result(size, '\0');
  WideCharToMultiByte(
      CP_UTF8,
      0,
      value.data(),
      static_cast<int>(value.size()),
      result.data(),
      size,
      nullptr,
      nullptr);
  return result;
}

std::vector<std::wstring> WindowsAudioDeviceNames(EDataFlow flow) {
  std::vector<std::wstring> names;
  const HRESULT initialize_result =
      CoInitializeEx(nullptr, COINIT_MULTITHREADED);
  const bool uninitialize =
      initialize_result == S_OK || initialize_result == S_FALSE;
  if (FAILED(initialize_result) &&
      initialize_result != RPC_E_CHANGED_MODE) {
    return names;
  }

  IMMDeviceEnumerator* enumerator = nullptr;
  IMMDeviceCollection* collection = nullptr;
  if (SUCCEEDED(CoCreateInstance(
          __uuidof(MMDeviceEnumerator),
          nullptr,
          CLSCTX_ALL,
          __uuidof(IMMDeviceEnumerator),
          reinterpret_cast<void**>(&enumerator))) &&
      SUCCEEDED(enumerator->EnumAudioEndpoints(
          flow,
          DEVICE_STATE_ACTIVE,
          &collection))) {
    UINT count = 0;
    collection->GetCount(&count);
    for (UINT index = 0; index < count; ++index) {
      IMMDevice* device = nullptr;
      IPropertyStore* properties = nullptr;
      PROPVARIANT value;
      PropVariantInit(&value);
      if (SUCCEEDED(collection->Item(index, &device)) &&
          SUCCEEDED(device->OpenPropertyStore(STGM_READ, &properties)) &&
          SUCCEEDED(properties->GetValue(PKEY_Device_FriendlyName, &value)) &&
          value.vt == VT_LPWSTR &&
          value.pwszVal != nullptr) {
        names.emplace_back(value.pwszVal);
      }
      PropVariantClear(&value);
      if (properties != nullptr) {
        properties->Release();
      }
      if (device != nullptr) {
        device->Release();
      }
    }
  }
  if (collection != nullptr) {
    collection->Release();
  }
  if (enumerator != nullptr) {
    enumerator->Release();
  }
  if (uninitialize) {
    CoUninitialize();
  }
  return names;
}

bool StartsWithIgnoringCase(const std::wstring& value,
                            const std::wstring& prefix) {
  if (prefix.empty() || prefix.size() > value.size()) {
    return false;
  }
  for (size_t index = 0; index < prefix.size(); ++index) {
    if (std::towlower(value[index]) != std::towlower(prefix[index])) {
      return false;
    }
  }
  return true;
}

std::string FullAudioDeviceName(
    const AudioDevice& device,
    const std::vector<std::wstring>& capture_names,
    const std::vector<std::wstring>& playback_names) {
  const std::string normalized = NormalizeDeviceText(device.name);
  const std::wstring prefix = Utf8ToWide(normalized);
  const auto& names =
      device.input_channels > 0 && device.output_channels == 0
          ? capture_names
          : playback_names;
  for (const auto& name : names) {
    if (StartsWithIgnoringCase(name, prefix)) {
      return WideToUtf8(name);
    }
  }
  return normalized;
}

std::wstring CredentialTarget(const std::string& account_id) {
  return L"AsteraSoftPhone/SIP/" + Utf8ToWide(account_id);
}

void SaveCredential(const std::string& account_id,
                    const std::string& password) {
  if (password.size() > CRED_MAX_CREDENTIAL_BLOB_SIZE) {
    throw std::invalid_argument("SIP password is too long.");
  }
  const auto target = CredentialTarget(account_id);
  const auto user_name = Utf8ToWide(account_id);
  CREDENTIALW credential{};
  credential.Type = CRED_TYPE_GENERIC;
  credential.TargetName = const_cast<wchar_t*>(target.c_str());
  credential.CredentialBlobSize = static_cast<DWORD>(password.size());
  credential.CredentialBlob = reinterpret_cast<LPBYTE>(
      const_cast<char*>(password.data()));
  credential.Persist = CRED_PERSIST_LOCAL_MACHINE;
  credential.UserName = const_cast<wchar_t*>(user_name.c_str());
  if (!CredWriteW(&credential, 0)) {
    throw std::runtime_error("Windows Credential Manager write failed.");
  }
}

std::string ReadCredential(const std::string& account_id) {
  PCREDENTIALW credential = nullptr;
  const auto target = CredentialTarget(account_id);
  if (!CredReadW(target.c_str(), CRED_TYPE_GENERIC, 0, &credential)) {
    if (GetLastError() == ERROR_NOT_FOUND) {
      return {};
    }
    throw std::runtime_error("Windows Credential Manager read failed.");
  }
  std::string password(
      reinterpret_cast<const char*>(credential->CredentialBlob),
      credential->CredentialBlobSize);
  CredFree(credential);
  return password;
}

void DeleteCredential(const std::string& account_id) {
  const auto target = CredentialTarget(account_id);
  if (!CredDeleteW(target.c_str(), CRED_TYPE_GENERIC, 0) &&
      GetLastError() != ERROR_NOT_FOUND) {
    throw std::runtime_error("Windows Credential Manager delete failed.");
  }
}

}  // namespace

// static
void AsteraSipPlugin::RegisterWithRegistrar(
    flutter::PluginRegistrarWindows *registrar) {
  auto method_channel =
      std::make_unique<flutter::MethodChannel<flutter::EncodableValue>>(
          registrar->messenger(), "tr.com.astera/astera_sip/methods",
          &flutter::StandardMethodCodec::GetInstance());

  auto plugin = std::make_unique<AsteraSipPlugin>();

  method_channel->SetMethodCallHandler(
      [plugin_pointer = plugin.get()](const auto &call, auto result) {
        plugin_pointer->HandleMethodCall(call, std::move(result));
      });

  auto event_channel =
      std::make_unique<flutter::EventChannel<flutter::EncodableValue>>(
          registrar->messenger(), "tr.com.astera/astera_sip/events",
          &flutter::StandardMethodCodec::GetInstance());
  event_channel->SetStreamHandler(
      std::make_unique<
          flutter::StreamHandlerFunctions<flutter::EncodableValue>>(
          [plugin_pointer = plugin.get()](
              const flutter::EncodableValue*,
              std::unique_ptr<
                  flutter::EventSink<flutter::EncodableValue>>&& events) {
            plugin_pointer->OnListen(std::move(events));
            return nullptr;
          },
          [plugin_pointer = plugin.get()](const flutter::EncodableValue*) {
            plugin_pointer->OnCancel();
            return nullptr;
          }));

  auto call_event_channel =
      std::make_unique<flutter::EventChannel<flutter::EncodableValue>>(
          registrar->messenger(), "tr.com.astera/astera_sip/call_events",
          &flutter::StandardMethodCodec::GetInstance());
  call_event_channel->SetStreamHandler(
      std::make_unique<
          flutter::StreamHandlerFunctions<flutter::EncodableValue>>(
          [plugin_pointer = plugin.get()](
              const flutter::EncodableValue*,
              std::unique_ptr<
                  flutter::EventSink<flutter::EncodableValue>>&& events) {
            plugin_pointer->OnCallListen(std::move(events));
            return nullptr;
          },
          [plugin_pointer = plugin.get()](const flutter::EncodableValue*) {
            plugin_pointer->OnCallCancel();
            return nullptr;
          }));

  registrar->AddPlugin(std::move(plugin));
}

AsteraSipPlugin::AsteraSipPlugin()
    : sip_engine_(std::make_unique<SipEngine>(
          [this](const RegistrationEvent& event) {
            EmitRegistrationEvent(event);
          },
          [this](const CallEvent& event) {
            EmitCallEvent(event);
          })) {}

AsteraSipPlugin::~AsteraSipPlugin() {
  try {
    sip_engine_->Dispose();
  } catch (...) {
    // Plugin teardown cannot report errors to Dart.
  }
}

void AsteraSipPlugin::HandleMethodCall(
    const flutter::MethodCall<flutter::EncodableValue> &method_call,
    std::unique_ptr<flutter::MethodResult<flutter::EncodableValue>> result) {
  try {
    if (method_call.method_name() == "initialize") {
      const auto& arguments = RequireArguments(method_call);
      sip_engine_->Initialize(GetInt(arguments, "localSipPort", 0));
      result->Success();
    } else if (method_call.method_name() == "register") {
      const auto& arguments = RequireArguments(method_call);
      SipAccountConfiguration config;
      config.server = RequireString(arguments, "server");
      config.port = GetInt(arguments, "port", 5060);
      config.transport = RequireString(arguments, "transport");
      config.username = RequireString(arguments, "username");
      config.auth_username = RequireString(arguments, "authUsername");
      config.password = RequireString(arguments, "password");
      config.display_name = RequireString(arguments, "displayName");
      config.domain = RequireString(arguments, "domain");
      config.proxy_server = RequireString(arguments, "proxyServer");
      config.registration_interval =
          GetInt(arguments, "registrationInterval", 300);
      sip_engine_->RegisterAccount(config);
      result->Success();
    } else if (method_call.method_name() == "unregister") {
      sip_engine_->Unregister();
      result->Success();
    } else if (method_call.method_name() == "saveCredential") {
      const auto& arguments = RequireArguments(method_call);
      SaveCredential(
          RequireString(arguments, "accountId"),
          RequireString(arguments, "password"));
      result->Success();
    } else if (method_call.method_name() == "readCredential") {
      const auto& arguments = RequireArguments(method_call);
      const auto password =
          ReadCredential(RequireString(arguments, "accountId"));
      if (password.empty()) {
        result->Success();
      } else {
        result->Success(flutter::EncodableValue(password));
      }
    } else if (method_call.method_name() == "deleteCredential") {
      const auto& arguments = RequireArguments(method_call);
      DeleteCredential(RequireString(arguments, "accountId"));
      result->Success();
    } else if (method_call.method_name() == "makeCall") {
      const auto& arguments = RequireArguments(method_call);
      sip_engine_->MakeCall(RequireString(arguments, "destination"));
      result->Success();
    } else if (method_call.method_name() == "answerCall") {
      sip_engine_->AnswerCall();
      result->Success();
    } else if (method_call.method_name() == "rejectCall") {
      sip_engine_->RejectCall();
      result->Success();
    } else if (method_call.method_name() == "hangupCall") {
      sip_engine_->HangupCall();
      result->Success();
    } else if (method_call.method_name() == "sendDtmf") {
      const auto& arguments = RequireArguments(method_call);
      sip_engine_->SendDtmf(RequireString(arguments, "digit"));
      result->Success();
    } else if (method_call.method_name() == "playDialTone") {
      const auto& arguments = RequireArguments(method_call);
      sip_engine_->PlayDialTone(RequireString(arguments, "digit"));
      result->Success();
    } else if (method_call.method_name() == "setHold") {
      const auto& arguments = RequireArguments(method_call);
      sip_engine_->SetHold(RequireBool(arguments, "hold"));
      result->Success();
    } else if (method_call.method_name() == "setMuted") {
      const auto& arguments = RequireArguments(method_call);
      sip_engine_->SetMuted(RequireBool(arguments, "muted"));
      result->Success();
    } else if (method_call.method_name() == "transferCall") {
      const auto& arguments = RequireArguments(method_call);
      sip_engine_->TransferCall(RequireString(arguments, "destination"));
      result->Success();
    } else if (method_call.method_name() == "setMicrophoneLevel") {
      const auto& arguments = RequireArguments(method_call);
      sip_engine_->SetMicrophoneLevel(
          static_cast<float>(RequireDouble(arguments, "level")));
      result->Success();
    } else if (method_call.method_name() == "setSpeakerLevel") {
      const auto& arguments = RequireArguments(method_call);
      sip_engine_->SetSpeakerLevel(
          static_cast<float>(RequireDouble(arguments, "level")));
      result->Success();
    } else if (method_call.method_name() == "getAudioDevices") {
      const auto state = sip_engine_->GetAudioDevices();
      const auto capture_names = WindowsAudioDeviceNames(eCapture);
      const auto playback_names = WindowsAudioDeviceNames(eRender);
      flutter::EncodableList devices;
      devices.reserve(state.devices.size());
      for (const auto& device : state.devices) {
        devices.emplace_back(flutter::EncodableMap{
            {flutter::EncodableValue("id"),
             flutter::EncodableValue(device.id)},
            {flutter::EncodableValue("name"),
             flutter::EncodableValue(FullAudioDeviceName(
                 device, capture_names, playback_names))},
            {flutter::EncodableValue("driver"),
             flutter::EncodableValue(NormalizeDeviceText(device.driver))},
            {flutter::EncodableValue("inputChannels"),
             flutter::EncodableValue(device.input_channels)},
            {flutter::EncodableValue("outputChannels"),
             flutter::EncodableValue(device.output_channels)},
        });
      }
      result->Success(flutter::EncodableValue(flutter::EncodableMap{
          {flutter::EncodableValue("devices"),
           flutter::EncodableValue(devices)},
          {flutter::EncodableValue("captureDeviceId"),
           flutter::EncodableValue(state.capture_device_id)},
          {flutter::EncodableValue("playbackDeviceId"),
           flutter::EncodableValue(state.playback_device_id)},
      }));
    } else if (method_call.method_name() == "setAudioDevices") {
      const auto& arguments = RequireArguments(method_call);
      sip_engine_->SetAudioDevices(
          GetInt(arguments, "captureDeviceId", -1),
          GetInt(arguments, "playbackDeviceId", -1));
      result->Success();
    } else if (method_call.method_name() == "setRingtoneStyle") {
      const auto& arguments = RequireArguments(method_call);
      sip_engine_->SetRingtoneStyle(GetInt(arguments, "style", 0));
      result->Success();
    } else if (method_call.method_name() == "setRingtoneFile") {
      const auto& arguments = RequireArguments(method_call);
      sip_engine_->SetRingtoneFile(RequireString(arguments, "path"));
      result->Success();
    } else if (method_call.method_name() == "pollEvents") {
      sip_engine_->PollEvents();
      result->Success();
    } else if (method_call.method_name() == "dispose") {
      sip_engine_->Dispose();
      result->Success();
    } else {
      result->NotImplemented();
    }
  } catch (const std::exception& exception) {
    result->Error("sip_error", exception.what());
  } catch (...) {
    result->Error("sip_error", "Unknown native PJSIP error.");
  }
}

void AsteraSipPlugin::OnListen(
    std::unique_ptr<flutter::EventSink<flutter::EncodableValue>> events) {
  event_sink_ = std::move(events);
  for (const auto& event : pending_events_) {
    EmitRegistrationEvent(event);
  }
  pending_events_.clear();
}

void AsteraSipPlugin::OnCancel() {
  event_sink_.reset();
}

void AsteraSipPlugin::OnCallListen(
    std::unique_ptr<flutter::EventSink<flutter::EncodableValue>> events) {
  call_event_sink_ = std::move(events);
  for (const auto& event : pending_call_events_) {
    EmitCallEvent(event);
  }
  pending_call_events_.clear();
}

void AsteraSipPlugin::OnCallCancel() {
  call_event_sink_.reset();
}

void AsteraSipPlugin::EmitRegistrationEvent(
    const RegistrationEvent& event) {
  if (!event_sink_) {
    pending_events_.push_back(event);
    return;
  }

  flutter::EncodableMap data;
  data[flutter::EncodableValue("state")] =
      flutter::EncodableValue(event.state);
  data[flutter::EncodableValue("statusCode")] =
      flutter::EncodableValue(event.status_code);
  data[flutter::EncodableValue("detail")] =
      flutter::EncodableValue(event.detail);
  event_sink_->Success(flutter::EncodableValue(std::move(data)));
}

void AsteraSipPlugin::EmitCallEvent(const CallEvent& event) {
  if (!call_event_sink_) {
    pending_call_events_.push_back(event);
    return;
  }

  flutter::EncodableMap data;
  data[flutter::EncodableValue("state")] =
      flutter::EncodableValue(event.state);
  data[flutter::EncodableValue("remoteUri")] =
      flutter::EncodableValue(event.remote_uri);
  data[flutter::EncodableValue("statusCode")] =
      flutter::EncodableValue(event.status_code);
  data[flutter::EncodableValue("detail")] =
      flutter::EncodableValue(event.detail);
  call_event_sink_->Success(flutter::EncodableValue(std::move(data)));
}

}  // namespace astera_sip
