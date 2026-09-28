#ifndef FLUTTER_PLUGIN_ASTERA_SIP_ENGINE_H_
#define FLUTTER_PLUGIN_ASTERA_SIP_ENGINE_H_

#include <functional>
#include <memory>
#include <string>

namespace astera_sip {

struct SipAccountConfiguration {
  std::string server;
  int port = 5060;
  std::string transport = "udp";
  std::string username;
  std::string auth_username;
  std::string password;
  std::string display_name;
  std::string domain;
  std::string proxy_server;
  int registration_interval = 300;
};

struct RegistrationEvent {
  std::string state;
  int status_code = 0;
  std::string detail;
};

struct CallEvent {
  std::string state;
  std::string remote_uri;
  int status_code = 0;
  std::string detail;
};

class SipEngine {
 public:
  using RegistrationCallback = std::function<void(const RegistrationEvent&)>;
  using CallCallback = std::function<void(const CallEvent&)>;

  SipEngine(RegistrationCallback registration_callback,
            CallCallback call_callback);
  ~SipEngine();

  SipEngine(const SipEngine&) = delete;
  SipEngine& operator=(const SipEngine&) = delete;

  void Initialize(int local_sip_port);
  void RegisterAccount(const SipAccountConfiguration& config);
  void Unregister();
  void MakeCall(const std::string& destination);
  void AnswerCall();
  void RejectCall();
  void HangupCall();
  void SendDtmf(const std::string& digit);
  void PlayDialTone(const std::string& digit);
  void SetHold(bool hold);
  void SetMuted(bool muted);
  void TransferCall(const std::string& destination);
  void SetMicrophoneLevel(float level);
  void SetSpeakerLevel(float level);
  void PollEvents();
  void Dispose();

 private:
  class Impl;
  std::unique_ptr<Impl> impl_;
};

}  // namespace astera_sip

#endif  // FLUTTER_PLUGIN_ASTERA_SIP_ENGINE_H_
