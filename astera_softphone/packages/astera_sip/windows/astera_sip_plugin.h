#ifndef FLUTTER_PLUGIN_ASTERA_SIP_PLUGIN_H_
#define FLUTTER_PLUGIN_ASTERA_SIP_PLUGIN_H_

#include <flutter/encodable_value.h>
#include <flutter/event_sink.h>
#include <flutter/method_channel.h>
#include <flutter/plugin_registrar_windows.h>

#include <memory>
#include <vector>

#include "sip_engine.h"

namespace astera_sip {

class AsteraSipPlugin : public flutter::Plugin {
 public:
  static void RegisterWithRegistrar(flutter::PluginRegistrarWindows *registrar);

  AsteraSipPlugin();

  virtual ~AsteraSipPlugin();

  // Disallow copy and assign.
  AsteraSipPlugin(const AsteraSipPlugin&) = delete;
  AsteraSipPlugin& operator=(const AsteraSipPlugin&) = delete;

  // Called when a method is called on this plugin's channel from Dart.
  void HandleMethodCall(
      const flutter::MethodCall<flutter::EncodableValue> &method_call,
      std::unique_ptr<flutter::MethodResult<flutter::EncodableValue>> result);

  void OnListen(
      std::unique_ptr<flutter::EventSink<flutter::EncodableValue>> events);
  void OnCancel();
  void OnCallListen(
      std::unique_ptr<flutter::EventSink<flutter::EncodableValue>> events);
  void OnCallCancel();

 private:
  void EmitRegistrationEvent(const RegistrationEvent& event);
  void EmitCallEvent(const CallEvent& event);

  std::unique_ptr<SipEngine> sip_engine_;
  std::unique_ptr<flutter::EventSink<flutter::EncodableValue>> event_sink_;
  std::unique_ptr<flutter::EventSink<flutter::EncodableValue>> call_event_sink_;
  std::vector<RegistrationEvent> pending_events_;
  std::vector<CallEvent> pending_call_events_;
};

}  // namespace astera_sip

#endif  // FLUTTER_PLUGIN_ASTERA_SIP_PLUGIN_H_
