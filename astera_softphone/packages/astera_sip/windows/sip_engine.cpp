#include "sip_engine.h"

#include <pjsua2.hpp>

#include <algorithm>
#include <cstdlib>
#include <filesystem>
#include <fstream>
#include <sstream>
#include <stdexcept>
#include <utility>

namespace astera_sip {
namespace {

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
    tone_generator_->startTransmit(
        endpoint_->audDevManager().getPlaybackDevMedia());
    ringtone_generator_ = std::make_unique<pj::ToneGenerator>();
    ringtone_generator_->createToneGenerator();
    ringtone_generator_->startTransmit(
        endpoint_->audDevManager().getPlaybackDevMedia());

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
    pj::ToneDigit tone;
    tone.digit = digit[0];
    tone.on_msec = 120;
    tone.off_msec = 20;
    tone.volume = 0;
    pj::ToneDigitVector tones;
    tones.push_back(tone);
    tone_generator_->playDigits(tones);
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

  void PollEvents() {
    if (endpoint_) {
      endpoint_->libHandleEvents(0);
    }
  }

  void Dispose() {
    call_.reset();
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
        break;
      case PJSIP_INV_STATE_CONFIRMED:
        state = held_ ? "held" : "connected";
        break;
      case PJSIP_INV_STATE_DISCONNECTED:
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
    if (!ringtone_generator_ || ringtone_active_) {
      return;
    }
    pj::ToneDesc tone;
    tone.freq1 = 440;
    tone.freq2 = 480;
    tone.on_msec = 1000;
    tone.off_msec = 3000;
    tone.volume = 0;
    pj::ToneDescVector tones;
    tones.push_back(tone);
    ringtone_generator_->play(tones, true);
    ringtone_active_ = true;
  }

  void StopRingtone() {
    if (!ringtone_generator_ || !ringtone_active_) {
      return;
    }
    ringtone_generator_->stop();
    ringtone_active_ = false;
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
  std::string account_domain_;
  bool muted_ = false;
  bool held_ = false;
  bool ringtone_active_ = false;
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
