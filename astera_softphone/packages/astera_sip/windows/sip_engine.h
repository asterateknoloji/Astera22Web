#ifndef FLUTTER_PLUGIN_ASTERA_SIP_ENGINE_H_
#define FLUTTER_PLUGIN_ASTERA_SIP_ENGINE_H_

#include <functional>
#include <memory>
#include <string>
#include <vector>

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

struct AudioDevice {
  int id = -1;
  std::string name;
  std::string driver;
  int input_channels = 0;
  int output_channels = 0;
};

struct AudioDeviceState {
  std::vector<AudioDevice> devices;
  int capture_device_id = -1;
  int playback_device_id = -1;
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
  AudioDeviceState GetAudioDevices();
  void SetAudioDevices(int capture_device_id, int playback_device_id);
  void SetRingtoneStyle(int style);
  void SetRingtoneFile(const std::string& path);
  void PollEvents();
  void Dispose();

 private:
  class Impl;
  std::unique_ptr<Impl> impl_;
};

}  // namespace astera_sip

#endif  // FLUTTER_PLUGIN_ASTERA_SIP_ENGINE_H_
