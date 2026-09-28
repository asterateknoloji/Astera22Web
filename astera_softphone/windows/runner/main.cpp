#include <flutter/dart_project.h>
#include <flutter/flutter_view_controller.h>
#include <commctrl.h>
#include <windows.h>

#include "flutter_window.h"
#include "utils.h"

int APIENTRY wWinMain(_In_ HINSTANCE instance, _In_opt_ HINSTANCE prev,
                      _In_ wchar_t *command_line, _In_ int show_command) {
  // Attach to console when present (e.g., 'flutter run') or create a
  // new console when running with a debugger.
  if (!::AttachConsole(ATTACH_PARENT_PROCESS) && ::IsDebuggerPresent()) {
    CreateAndAttachConsole();
  }

  // Initialize COM, so that it is available for use in the library and/or
  // plugins.
  ::CoInitializeEx(nullptr, COINIT_APARTMENTTHREADED);
  INITCOMMONCONTROLSEX common_controls{
      sizeof(INITCOMMONCONTROLSEX), ICC_LISTVIEW_CLASSES};
  ::InitCommonControlsEx(&common_controls);

  flutter::DartProject project(L"data");

  std::vector<std::string> command_line_arguments =
      GetCommandLineArguments();

  project.set_dart_entrypoint_arguments(std::move(command_line_arguments));

  FlutterWindow window(project);
  Win32Window::Point origin(80, 60);
  Win32Window::Size size(410, 720);
  if (!window.Create(L"ASTERA Softphone", origin, size)) {
    return EXIT_FAILURE;
  }
  const auto handle = window.GetHandle();
  ::SetWindowLongPtr(
      handle, GWL_STYLE,
      ::GetWindowLongPtr(handle, GWL_STYLE) & ~WS_THICKFRAME & ~WS_MAXIMIZEBOX);
  ::SetWindowPos(handle, HWND_TOPMOST, 0, 0, 0, 0,
                 SWP_NOMOVE | SWP_NOSIZE | SWP_NOACTIVATE | SWP_FRAMECHANGED);
  window.SetQuitOnClose(true);

  ::MSG msg;
  while (::GetMessage(&msg, nullptr, 0, 0)) {
    ::TranslateMessage(&msg);
    ::DispatchMessage(&msg);
  }

  ::CoUninitialize();
  return EXIT_SUCCESS;
}
