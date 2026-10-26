#include "sip_engine.h"

#include <pjsua2.hpp>

#include <algorithm>
#include <cctype>
#include <chrono>
#include <cstdlib>
#include <filesystem>
#include <fstream>
#include <sstream>
#include <stdexcept>
#include <utility>
#include <windows.h>
#include <mmsystem.h>

namespace astera_sip {
namespace {

std::wstring Utf8ToWide(const std::string& value) {
  if (value.empty()) {
    return {};
  }
  const int size = MultiByteToWideChar(
      CP_UTF8, 0, value.data(), static_cast<int>(value.size()), nullptr, 0);
  std::wstring result(size, L'\0');
  MultiByteToWideChar(CP_UTF8, 0, value.data(), static_cast<int>(value.size()),
                      result.data(), size);
  return result;
}

std::string PjsipLogPath() {
  const char* local_app_data = std::getenv("LOCALAPPDATA");
  std::filesystem::path directory =
      local_app_data == nullptr
          ? std::filesystem::current_path() / "logs"
          : std::filesystem::path(local_app_data) / "AsteraSoftPhone" / "logs";
  std::filesystem::create_directories(directory);
  return (directory / "pjsip.log").string();
}

std::string SanitizedDisplayName(const std::string& value,
                                 const std::string& fallback) {
  std::string result = value.empty() ? fallback : value;
  result.erase(
      std::remove(result.begin(), result.end(), '"'),
      result.end());
  return result;
}

class RedactingLogWriter final : public pj::LogWriter {
 public:
  explicit RedactingLogWriter(const std::string& path)
      : stream_(path, std::ios::app) {}

  void write(const pj::LogEntry& entry) override {
    std::istringstream input(entry.msg);
    std::string line;
    while (std::getline(input, line)) {
      if (line.rfind("Authorization:", 0) == 0) {
        stream_ << "Authorization: <redacted>\n";
      } else if (line.rfind("Proxy-Authorization:", 0) == 0) {
        stream_ << "Proxy-Authorization: <redacted>\n";
      } else {
        stream_ << line << '\n';
      }
    }
    stream_.flush();
  }

 private:
  std::ofstream stream_;
};

}  // namespace

class SipEngine::Impl {
  class NativeCall : public pj::Call {
   public:
    NativeCall(pj::Account& account, Impl& owner,
               int call_id = PJSUA_INVALID_ID)
        : pj::Call(account, call_id), owner_(owner) {}

    void onCallState(pj::OnCallStateParam&) override {
      owner_.OnCallState(*this);
    }

    void onCallMediaState(pj::OnCallMediaStateParam&) override {
      owner_.OnCallMediaState(*this);
    }

    void onCallTransferStatus(
        pj::OnCallTransferStatusParam& parameter) override {
      owner_.OnCallTransferStatus(*this, parameter);
    }

   private:
    Impl& owner_;
  };

 public:
  Impl(RegistrationCallback registration_callback, CallCallback call_callback)
      : registration_callback_(std::move(registration_callback)),
        call_callback_(std::move(call_callback)) {}

  ~Impl() {
    try {
      Dispose();
    } catch (...) {
      // Destructors must not propagate native shutdown errors.
    }
  }

  void Initialize(int local_sip_port) {
    if (endpoint_) {
      return;
    }
    if (local_sip_port < 0 || local_sip_port > 65535) {
      throw std::invalid_argument("Local SIP port must be between 0 and 65535.");
    }

    Emit("initializing", 0, "PJSIP 2.17 başlatılıyor");

    endpoint_ = std::make_unique<pj::Endpoint>();
    endpoint_->libCreate();

    pj::EpConfig endpoint_config;
    endpoint_config.uaConfig.threadCnt = 0;
    endpoint_config.uaConfig.mainThreadOnly = true;
    endpoint_config.uaConfig.userAgent = "AsteraSoftPhone/1.0 PJSIP/2.17";
    endpoint_config.medConfig.sndAutoCloseTime = 1;
    endpoint_config.logConfig.level = 5;
    endpoint_config.logConfig.consoleLevel = 5;
    endpoint_config.logConfig.writer = new RedactingLogWriter(PjsipLogPath());
    endpoint_->libInit(endpoint_config);

    pj::TransportConfig transport_config;
    transport_config.port = static_cast<unsigned>(local_sip_port);
    endpoint_->transportCreate(PJSIP_TRANSPORT_UDP, transport_config);
    endpoint_->libStart();

    for (const auto& codec : endpoint_->codecEnum2()) {
      const bool pcmu = codec.codecId.rfind("PCMU/8000", 0) == 0;
      const bool pcma = codec.codecId.rfind("PCMA/8000", 0) == 0;
      endpoint_->codecSetPriority(
          codec.codecId,
          pcmu ? 255 : pcma ? 254 : 0);
    }

    tone_generator_ = std::make_unique<pj::ToneGenerator>();
    tone_generator_->createToneGenerator();
    ringtone_generator_ = std::make_unique<pj::ToneGenerator>();
    ringtone_generator_->createToneGenerator();
    ringback_generator_ = std::make_unique<pj::ToneGenerator>();
    ringback_generator_->createToneGenerator();

    Emit("disconnected", 0, "UDP transport hazır");
  }

  void RegisterAccount(const SipAccountConfiguration& config) {
    if (!endpoint_) {
      throw std::logic_error("PJSIP is not initialized.");
    }
    if (config.transport != "udp") {
      throw std::invalid_argument("Phase 1 supports UDP transport only.");
    }
    if (config.server.empty() || config.username.empty() ||
        config.auth_username.empty() || config.password.empty()) {
      throw std::invalid_argument(
          "Server, username, auth username and password are required.");
    }
    if (config.port < 1 || config.port > 65535) {
      throw std::invalid_argument("SIP port must be between 1 and 65535.");
    }
    if (config.registration_interval < 1) {
      throw std::invalid_argument("Registration interval must be positive.");
    }

    if (account_) {
      account_->shutdown();
      account_.reset();
    }

    const std::string domain =
        config.domain.empty() ? config.server : config.domain;
    account_domain_ = domain;
    const std::string display_name =
        SanitizedDisplayName(config.display_name, config.username);

    pj::AccountConfig account_config;
    account_config.idUri = "\"" + display_name + "\" <sip:" +
                           config.username + "@" + domain + ">";
    account_config.regConfig.registrarUri =
        "sip:" + config.server + ":" + std::to_string(config.port);
    account_config.regConfig.timeoutSec =
        static_cast<unsigned>(config.registration_interval);
    if (!config.proxy_server.empty()) {
      const std::string proxy_uri =
          config.proxy_server.rfind("sip:", 0) == 0
              ? config.proxy_server
              : "sip:" + config.proxy_server;
      account_config.sipConfig.proxies.push_back(proxy_uri);
    }
    account_config.sipConfig.authCreds.push_back(
        pj::AuthCredInfo(
            "digest", "*", config.auth_username, 0, config.password));

    Emit("registering", 0,
         config.server + ":" + std::to_string(config.port) +
             " sunucusuna REGISTER gönderiliyor");
    account_ = std::make_unique<RegistrationAccount>(*this);
    account_->create(account_config, true);
  }

  void Unregister() {
    if (!account_) {
      Emit("disconnected", 0, "Aktif SIP hesabı yok");
      return;
    }
    account_->setRegistration(false);
  }

  void MakeCall(const std::string& destination) {
    if (!account_) {
      throw std::logic_error("Registered SIP account is required.");
    }
    if (destination.empty()) {
      throw std::invalid_argument("Destination is required.");
    }
    if (call_ && call_->isActive()) {
      throw std::logic_error("Another call is already active.");
    }
    StopRingback();
    outgoing_call_ = true;
    early_media_active_ = false;
    call_.reset();
    const std::string uri =
        destination.rfind("sip:", 0) == 0
            ? destination
            : "sip:" + destination + "@" + account_domain_;
    call_ = std::make_unique<NativeCall>(*account_, *this);
    pj::CallOpParam parameter(true);
    ConfigureAudioOnly(parameter);
    call_->makeCall(uri, parameter);
    EmitCall("calling", uri, 0, "Aranıyor");
  }

  void AnswerCall() {
    EnsureCall();
    StopRingtone();
    pj::CallOpParam parameter;
    ConfigureAudioOnly(parameter);
    parameter.statusCode = PJSIP_SC_OK;
    call_->answer(parameter);
  }

  void RejectCall() {
    EnsureCall();
    StopRingtone();
    pj::CallOpParam parameter;
    parameter.statusCode = PJSIP_SC_DECLINE;
    call_->answer(parameter);
  }

  void HangupCall() {
    EnsureCall();
    StopRingtone();
    StopRingback();
    pj::CallOpParam parameter;
    call_->hangup(parameter);
  }

  void SendDtmf(const std::string& digit) {
    EnsureCall();
    pj::CallSendDtmfParam parameter;
    parameter.method = PJSUA_DTMF_METHOD_RFC2833;
    parameter.digits = digit;
    call_->sendDtmf(parameter);
  }

  void PlayDialTone(const std::string& digit) {
    if (!tone_generator_ || digit.size() != 1) {
      return;
    }
    tone_generator_->stop();
    if (!tone_connected_) {
      tone_generator_->startTransmit(
          endpoint_->audDevManager().getPlaybackDevMedia());
      tone_connected_ = true;
    }
    pj::ToneDigit tone;
    tone.digit = digit[0];
    tone.on_msec = 120;
    tone.off_msec = 20;
    tone.volume = 0;
    pj::ToneDigitVector tones;
    tones.push_back(tone);
    tone_generator_->playDigits(tones);
    tone_disconnect_at_ =
        std::chrono::steady_clock::now() + std::chrono::milliseconds(250);
  }

  void SetHold(bool hold) {
    EnsureCall();
    pj::CallOpParam parameter;
    if (hold) {
      ConfigureAudioOnly(parameter);
      call_->setHold(parameter);
      held_ = true;
      const auto info = call_->getInfo();
      EmitCall("held", info.remoteUri, 0, "Çağrı beklemede");
    } else {
      parameter.opt.flag = PJSUA_CALL_UNHOLD;
      ConfigureAudioOnly(parameter);
      call_->reinvite(parameter);
      held_ = false;
      const auto info = call_->getInfo();
      EmitCall("connected", info.remoteUri, 0, "Çağrı devam ediyor");
    }
  }

  void SetMuted(bool muted) {
    muted_ = muted;
    ApplyAudioLevels();
  }

  void TransferCall(const std::string& destination) {
    EnsureCall();
    if (destination.empty()) {
      throw std::invalid_argument("Transfer destination is required.");
    }
    const std::string uri =
        destination.rfind("sip:", 0) == 0
            ? destination
            : "sip:" + destination + "@" + account_domain_;
    pj::CallOpParam parameter;
    call_->xfer(uri, parameter);
  }

  void SetMicrophoneLevel(float level) {
    microphone_level_ = std::clamp(level, 0.0f, 2.0f);
    ApplyAudioLevels();
  }

  void SetSpeakerLevel(float level) {
    speaker_level_ = std::clamp(level, 0.0f, 2.0f);
    ApplyAudioLevels();
  }

  AudioDeviceState GetAudioDevices() {
    if (!endpoint_) {
      throw std::logic_error("PJSIP is not initialized.");
    }
    auto& manager = endpoint_->audDevManager();
    const int previous_capture_id = manager.getCaptureDev();
    const int previous_playback_id = manager.getPlaybackDev();
    std::string previous_capture_name;
    std::string previous_capture_driver;
    std::string previous_playback_name;
    std::string previous_playback_driver;
    auto devices = manager.enumDev2();
    for (const auto& device : devices) {
      if (static_cast<int>(device.id) == previous_capture_id) {
        previous_capture_name = device.name;
        previous_capture_driver = device.driver;
      }
      if (static_cast<int>(device.id) == previous_playback_id) {
        previous_playback_name = device.name;
        previous_playback_driver = device.driver;
      }
    }
    if (!manager.sndIsActive()) {
      manager.refreshDevs();
      devices = manager.enumDev2();
      for (const auto& device : devices) {
        if (!previous_capture_name.empty() &&
            device.name == previous_capture_name &&
            device.driver == previous_capture_driver &&
            device.inputCount > 0) {
          manager.setCaptureDev(static_cast<int>(device.id));
        }
        if (!previous_playback_name.empty() &&
            device.name == previous_playback_name &&
            device.driver == previous_playback_driver &&
            device.outputCount > 0) {
          manager.setPlaybackDev(static_cast<int>(device.id));
        }
      }
    }
    AudioDeviceState result;
    result.capture_device_id = manager.getCaptureDev();
    result.playback_device_id = manager.getPlaybackDev();
    for (const auto& device : devices) {
      result.devices.push_back(AudioDevice{
          static_cast<int>(device.id),
          device.name,
          device.driver,
          static_cast<int>(device.inputCount),
          static_cast<int>(device.outputCount),
      });
    }
    return result;
  }

  void SetAudioDevices(int capture_device_id, int playback_device_id) {
    if (!endpoint_) {
      throw std::logic_error("PJSIP is not initialized.");
    }
    bool capture_valid = false;
    bool playback_valid = false;
    for (const auto& device : endpoint_->audDevManager().enumDev2()) {
      if (static_cast<int>(device.id) == capture_device_id &&
          device.inputCount > 0) {
        capture_valid = true;
      }
      if (static_cast<int>(device.id) == playback_device_id &&
          device.outputCount > 0) {
        playback_valid = true;
      }
    }
    if (!capture_valid || !playback_valid) {
      throw std::invalid_argument("Selected audio device is unavailable.");
    }
    auto& manager = endpoint_->audDevManager();
    manager.setCaptureDev(capture_device_id);
    manager.setPlaybackDev(playback_device_id);
    if (call_ && call_->isActive()) {
      ApplyAudioLevels();
    }
  }

  void SetRingtoneStyle(int style) {
    ringtone_style_ = std::clamp(style, 0, 3);
  }

  void SetRingtoneFile(const std::string& path) {
    StopRingtone();
    ringtone_player_.reset();
    ringtone_file_path_.clear();
    if (path.empty()) {
      return;
    }
    const std::filesystem::path file(path);
    if (!std::filesystem::is_regular_file(file)) {
      throw std::invalid_argument("Selected ringtone file is unavailable.");
    }
    ringtone_file_path_ = file.string();
    std::string extension = file.extension().string();
    std::transform(extension.begin(), extension.end(), extension.begin(),
                   [](unsigned char character) {
                     return static_cast<char>(std::tolower(character));
                   });
    if (extension == ".wav") {
      auto player = std::make_unique<pj::AudioMediaPlayer>();
      player->createPlayer(file.string());
      ringtone_player_ = std::move(player);
      return;
    }
    if (extension != ".mp3" && extension != ".mpeg" &&
        extension != ".mpg") {
      ringtone_file_path_.clear();
      throw std::invalid_argument("Selected ringtone format is unsupported.");
    }
  }

  void PollEvents() {
    if (endpoint_) {
      endpoint_->libHandleEvents(0);
      if (tone_connected_ &&
          std::chrono::steady_clock::now() >= tone_disconnect_at_) {
        tone_generator_->stop();
        tone_generator_->stopTransmit(
            endpoint_->audDevManager().getPlaybackDevMedia());
        tone_connected_ = false;
      }
    }
  }

  void Dispose() {
    StopRingtone();
    StopRingback();
    call_.reset();
    ringtone_player_.reset();
    ringback_generator_.reset();
    ringtone_generator_.reset();
    tone_generator_.reset();
    if (account_) {
      account_->shutdown();
      account_.reset();
    }
    if (endpoint_) {
      endpoint_->libDestroy();
      endpoint_.reset();
    }
  }

 private:
  class RegistrationAccount : public pj::Account {
   public:
    explicit RegistrationAccount(Impl& owner) : owner_(owner) {}
    ~RegistrationAccount() override { shutdown(); }

    void onRegState(pj::OnRegStateParam& parameter) override {
      owner_.OnRegistrationState(*this, parameter);
    }

    void onIncomingCall(pj::OnIncomingCallParam& parameter) override {
      owner_.OnIncomingCall(*this, parameter.callId);
    }

   private:
    Impl& owner_;
  };

  void OnRegistrationState(RegistrationAccount& account,
                           pj::OnRegStateParam& parameter) {
    const int status_code = static_cast<int>(parameter.code);
    const std::string detail =
        parameter.reason.empty() ? "SIP yanıtı alınmadı" : parameter.reason;

    bool is_active = false;
    try {
      is_active = account.getInfo().regIsActive;
    } catch (const pj::Error&) {
      // The account can disappear while an unregister callback is pending.
    }

    if (parameter.status != PJ_SUCCESS || status_code >= 300) {
      Emit("registrationFailed", status_code, detail);
    } else if (is_active && status_code >= 200 && status_code < 300) {
      Emit("registered", status_code, detail);
    } else if (status_code >= 100 && status_code < 200) {
      Emit("registering", status_code, detail);
    } else {
      Emit("disconnected", status_code, detail);
    }
  }

  void OnIncomingCall(RegistrationAccount& account, int call_id) {
    if (call_ && call_->isActive()) {
      pj::Call rejected(account, call_id);
      pj::CallOpParam parameter;
      parameter.statusCode = PJSIP_SC_BUSY_HERE;
      rejected.answer(parameter);
      return;
    }
    StopRingback();
    outgoing_call_ = false;
    early_media_active_ = false;
    call_ = std::make_unique<NativeCall>(account, *this, call_id);
    const auto info = call_->getInfo();
    StartRingtone();
    EmitCall("incoming", info.remoteUri, 0, "Gelen çağrı");
  }

  void OnCallState(NativeCall& call) {
    const auto info = call.getInfo();
    std::string state = "calling";
    switch (info.state) {
      case PJSIP_INV_STATE_INCOMING:
        state = "incoming";
        break;
      case PJSIP_INV_STATE_EARLY:
      case PJSIP_INV_STATE_CONNECTING:
        state = "ringing";
        if (outgoing_call_ && !early_media_active_) {
          StartRingback();
        }
        break;
      case PJSIP_INV_STATE_CONFIRMED:
        StopRingback();
        state = held_ ? "held" : "connected";
        break;
      case PJSIP_INV_STATE_DISCONNECTED:
        StopRingback();
        outgoing_call_ = false;
        early_media_active_ = false;
        held_ = false;
        state = static_cast<int>(info.lastStatusCode) >= 300
                    ? "failed"
                    : "disconnected";
        break;
      default:
        state = "calling";
        break;
    }
    if (info.state != PJSIP_INV_STATE_INCOMING) {
      StopRingtone();
    }
    EmitCall(
        state,
        info.remoteUri,
        static_cast<int>(info.lastStatusCode),
        info.lastReason.empty() ? info.stateText : info.lastReason);
  }

  void OnCallMediaState(NativeCall& call) {
    const auto info = call.getInfo();
    for (const auto& media : info.media) {
      if (media.type != PJMEDIA_TYPE_AUDIO ||
          media.status != PJSUA_CALL_MEDIA_ACTIVE) {
        continue;
      }
      auto audio = call.getAudioMedia(static_cast<int>(media.index));
      auto& audio_devices = endpoint_->audDevManager();
      if (outgoing_call_) {
        // 183 Session Progress may carry the PBX/operator's own early media.
        // Prefer that audio and avoid playing the local ringback over it.
        early_media_active_ = true;
        StopRingback();
      }
      audio.adjustTxLevel(speaker_level_);
      audio_devices.getCaptureDevMedia().adjustTxLevel(
          muted_ ? 0.0f : microphone_level_);
      audio_devices.getCaptureDevMedia().startTransmit(audio);
      audio.startTransmit(audio_devices.getPlaybackDevMedia());
    }
  }

  void OnCallTransferStatus(
      NativeCall& call,
      pj::OnCallTransferStatusParam& parameter) {
    const auto info = call.getInfo();
    if (parameter.finalNotify && parameter.statusCode >= 200 &&
        parameter.statusCode < 300) {
      parameter.cont = false;
      held_ = false;
      muted_ = false;
      pj::CallOpParam hangup_parameter;
      call.hangup(hangup_parameter);
      EmitCall(
          "disconnected",
          info.remoteUri,
          static_cast<int>(parameter.statusCode),
          "Çağrı aktarıldı");
      return;
    }
    if (parameter.finalNotify && parameter.statusCode >= 300) {
      parameter.cont = false;
      EmitCall(
          held_ ? "held" : "connected",
          info.remoteUri,
          static_cast<int>(parameter.statusCode),
          "Aktarım başarısız: " + parameter.reason);
    }
  }

  void EnsureCall() const {
    if (!call_ || !call_->isActive()) {
      throw std::logic_error("There is no active call.");
    }
  }

  static void ConfigureAudioOnly(pj::CallOpParam& parameter) {
    parameter.opt.audioCount = 1;
    parameter.opt.videoCount = 0;
    parameter.opt.textCount = 0;
  }

  void StartRingtone() {
    if (ringtone_active_) {
      return;
    }
    if (ringtone_style_ == 3 && !ringtone_file_path_.empty()) {
      if (ringtone_player_) {
        ringtone_player_->setPos(0);
        ringtone_player_->startTransmit(
            endpoint_->audDevManager().getPlaybackDevMedia());
        custom_ringtone_active_ = true;
        ringtone_active_ = true;
        return;
      }
      mciSendStringW(L"close AsteraRingtone", nullptr, 0, nullptr);
      const std::wstring command =
          L"open \"" + Utf8ToWide(ringtone_file_path_) +
          L"\" type mpegvideo alias AsteraRingtone";
      if (mciSendStringW(command.c_str(), nullptr, 0, nullptr) == 0 &&
          mciSendStringW(L"play AsteraRingtone repeat", nullptr, 0, nullptr) ==
              0) {
        custom_ringtone_active_ = true;
        mci_ringtone_active_ = true;
        ringtone_active_ = true;
        return;
      }
      mciSendStringW(L"close AsteraRingtone", nullptr, 0, nullptr);
    }
    if (!ringtone_generator_) {
      return;
    }
    pj::ToneDescVector tones;
    pj::ToneDesc tone;
    tone.volume = 0;
    if (ringtone_style_ == 1) {
      tone.freq1 = 425;
      tone.freq2 = 425;
      tone.on_msec = 800;
      tone.off_msec = 3200;
      tones.push_back(tone);
    } else if (ringtone_style_ == 2) {
      tone.freq1 = 440;
      tone.freq2 = 480;
      tone.on_msec = 450;
      tone.off_msec = 200;
      tones.push_back(tone);
      tone.on_msec = 450;
      tone.off_msec = 2900;
      tones.push_back(tone);
    } else {
      tone.freq1 = 440;
      tone.freq2 = 480;
      tone.on_msec = 1000;
      tone.off_msec = 3000;
      tones.push_back(tone);
    }
    ringtone_generator_->startTransmit(
        endpoint_->audDevManager().getPlaybackDevMedia());
    ringtone_generator_->play(tones, true);
    ringtone_active_ = true;
  }

  void StopRingtone() {
    if (!ringtone_active_) {
      return;
    }
    if (custom_ringtone_active_) {
      if (mci_ringtone_active_) {
        mciSendStringW(L"stop AsteraRingtone", nullptr, 0, nullptr);
        mciSendStringW(L"close AsteraRingtone", nullptr, 0, nullptr);
      } else if (ringtone_player_) {
        ringtone_player_->stopTransmit(
            endpoint_->audDevManager().getPlaybackDevMedia());
        ringtone_player_->setPos(0);
      }
    } else if (ringtone_generator_) {
      ringtone_generator_->stop();
      ringtone_generator_->stopTransmit(
          endpoint_->audDevManager().getPlaybackDevMedia());
    }
    custom_ringtone_active_ = false;
    mci_ringtone_active_ = false;
    ringtone_active_ = false;
  }

  void StartRingback() {
    if (ringback_active_ || !ringback_generator_) {
      return;
    }
    pj::ToneDesc tone;
    tone.freq1 = 425;
    tone.freq2 = 425;
    tone.on_msec = 1000;
    tone.off_msec = 4000;
    tone.volume = 0;
    pj::ToneDescVector tones;
    tones.push_back(tone);
    ringback_generator_->startTransmit(
        endpoint_->audDevManager().getPlaybackDevMedia());
    ringback_generator_->play(tones, true);
    ringback_active_ = true;
  }

  void StopRingback() {
    if (!ringback_active_ || !ringback_generator_ || !endpoint_) {
      return;
    }
    ringback_generator_->stop();
    ringback_generator_->stopTransmit(
        endpoint_->audDevManager().getPlaybackDevMedia());
    ringback_active_ = false;
  }

  void ApplyAudioLevels() {
    if (!endpoint_) {
      return;
    }
    endpoint_->audDevManager().getCaptureDevMedia().adjustTxLevel(
        muted_ ? 0.0f : microphone_level_);
    if (call_ && call_->isActive()) {
      call_->getAudioMedia(-1).adjustTxLevel(speaker_level_);
    }
  }

  void Emit(std::string state, int status_code, std::string detail) {
    registration_callback_(RegistrationEvent{
        std::move(state),
        status_code,
        std::move(detail),
    });
  }

  void EmitCall(std::string state,
                std::string remote_uri,
                int status_code,
                std::string detail) {
    call_callback_(CallEvent{
        std::move(state),
        std::move(remote_uri),
        status_code,
        std::move(detail),
    });
  }

  RegistrationCallback registration_callback_;
  CallCallback call_callback_;
  std::unique_ptr<pj::Endpoint> endpoint_;
  std::unique_ptr<RegistrationAccount> account_;
  std::unique_ptr<NativeCall> call_;
  std::unique_ptr<pj::ToneGenerator> tone_generator_;
  std::unique_ptr<pj::ToneGenerator> ringtone_generator_;
  std::unique_ptr<pj::ToneGenerator> ringback_generator_;
  std::unique_ptr<pj::AudioMediaPlayer> ringtone_player_;
  std::string account_domain_;
  std::string ringtone_file_path_;
  bool muted_ = false;
  bool held_ = false;
  bool ringtone_active_ = false;
  bool custom_ringtone_active_ = false;
  bool mci_ringtone_active_ = false;
  bool ringback_active_ = false;
  bool outgoing_call_ = false;
  bool early_media_active_ = false;
  int ringtone_style_ = 0;
  bool tone_connected_ = false;
  std::chrono::steady_clock::time_point tone_disconnect_at_{};
  float microphone_level_ = 1.0f;
  float speaker_level_ = 1.0f;
};

SipEngine::SipEngine(RegistrationCallback registration_callback,
                     CallCallback call_callback)
    : impl_(std::make_unique<Impl>(
          std::move(registration_callback), std::move(call_callback))) {}

SipEngine::~SipEngine() = default;

void SipEngine::Initialize(int local_sip_port) {
  try {
    impl_->Initialize(local_sip_port);
  } catch (const pj::Error& error) {
    throw std::runtime_error(error.info());
  }
}

void SipEngine::RegisterAccount(const SipAccountConfiguration& config) {
  try {
    impl_->RegisterAccount(config);
  } catch (const pj::Error& error) {
    throw std::runtime_error(error.info());
  }
}

void SipEngine::Unregister() {
  try {
    impl_->Unregister();
  } catch (const pj::Error& error) {
    throw std::runtime_error(error.info());
  }
}

void SipEngine::MakeCall(const std::string& destination) {
  try {
    impl_->MakeCall(destination);
  } catch (const pj::Error& error) {
    throw std::runtime_error(error.info());
  }
}

void SipEngine::AnswerCall() {
  try {
    impl_->AnswerCall();
  } catch (const pj::Error& error) {
    throw std::runtime_error(error.info());
  }
}

void SipEngine::RejectCall() {
  try {
    impl_->RejectCall();
  } catch (const pj::Error& error) {
    throw std::runtime_error(error.info());
  }
}

void SipEngine::HangupCall() {
  try {
    impl_->HangupCall();
  } catch (const pj::Error& error) {
    throw std::runtime_error(error.info());
  }
}

void SipEngine::SendDtmf(const std::string& digit) {
  try {
    impl_->SendDtmf(digit);
  } catch (const pj::Error& error) {
    throw std::runtime_error(error.info());
  }
}

void SipEngine::PlayDialTone(const std::string& digit) {
  try {
    impl_->PlayDialTone(digit);
  } catch (const pj::Error& error) {
    throw std::runtime_error(error.info());
  }
}

void SipEngine::SetHold(bool hold) {
  try {
    impl_->SetHold(hold);
  } catch (const pj::Error& error) {
    throw std::runtime_error(error.info());
  }
}

void SipEngine::SetMuted(bool muted) {
  try {
    impl_->SetMuted(muted);
  } catch (const pj::Error& error) {
    throw std::runtime_error(error.info());
  }
}

void SipEngine::TransferCall(const std::string& destination) {
  try {
    impl_->TransferCall(destination);
  } catch (const pj::Error& error) {
    throw std::runtime_error(error.info());
  }
}

void SipEngine::SetMicrophoneLevel(float level) {
  try {
    impl_->SetMicrophoneLevel(level);
  } catch (const pj::Error& error) {
    throw std::runtime_error(error.info());
  }
}

void SipEngine::SetSpeakerLevel(float level) {
  try {
    impl_->SetSpeakerLevel(level);
  } catch (const pj::Error& error) {
    throw std::runtime_error(error.info());
  }
}

AudioDeviceState SipEngine::GetAudioDevices() {
  try {
    return impl_->GetAudioDevices();
  } catch (const pj::Error& error) {
    throw std::runtime_error(error.info());
  }
}

void SipEngine::SetAudioDevices(int capture_device_id,
                                int playback_device_id) {
  try {
    impl_->SetAudioDevices(capture_device_id, playback_device_id);
  } catch (const pj::Error& error) {
    throw std::runtime_error(error.info());
  }
}

void SipEngine::SetRingtoneStyle(int style) {
  impl_->SetRingtoneStyle(style);
}

void SipEngine::SetRingtoneFile(const std::string& path) {
  try {
    impl_->SetRingtoneFile(path);
  } catch (const pj::Error& error) {
    throw std::runtime_error(error.info());
  }
}

void SipEngine::PollEvents() {
  try {
    impl_->PollEvents();
  } catch (const pj::Error& error) {
    throw std::runtime_error(error.info());
  }
}

void SipEngine::Dispose() {
  try {
    impl_->Dispose();
  } catch (const pj::Error& error) {
    throw std::runtime_error(error.info());
  }
}

}  // namespace astera_sip
