#include "include/astera_sip/astera_sip_plugin_c_api.h"

#include <flutter/plugin_registrar_windows.h>

#include "astera_sip_plugin.h"

void AsteraSipPluginCApiRegisterWithRegistrar(
    FlutterDesktopPluginRegistrarRef registrar) {
  astera_sip::AsteraSipPlugin::RegisterWithRegistrar(
      flutter::PluginRegistrarManager::GetInstance()
          ->GetRegistrar<flutter::PluginRegistrarWindows>(registrar));
}
