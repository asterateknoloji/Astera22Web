#include "flutter_window.h"

#include <flutter/standard_method_codec.h>
#include <commctrl.h>
#include <windowsx.h>

#include <algorithm>
#include <cwctype>
#include <optional>
#include <string>
#include <vector>

#include "flutter/generated_plugin_registrant.h"

namespace {

constexpr wchar_t kHistoryWindowClass[] = L"ASTERA_CALL_HISTORY_WINDOW";
constexpr int kHistoryListId = 1001;
constexpr wchar_t kPresenceWindowClass[] = L"ASTERA_PRESENCE_WINDOW";
constexpr int kPresenceListId = 1002;
constexpr int kPresenceFilterId = 1003;
HWND g_history_window = nullptr;
HWND g_history_list = nullptr;
HFONT g_history_font = nullptr;
HWND g_presence_window = nullptr;
HWND g_presence_filter = nullptr;
HFONT g_presence_font = nullptr;
HFONT g_presence_title_font = nullptr;
flutter::MethodChannel<flutter::EncodableValue>* g_window_channel = nullptr;
std::vector<std::string> g_history_numbers;
std::vector<std::string> g_presence_numbers;
std::vector<std::string> g_presence_all_numbers;
std::vector<std::string> g_presence_all_names;
std::vector<std::string> g_presence_all_statuses;
std::vector<std::string> g_presence_all_states;
std::vector<std::string> g_presence_all_peers;
std::vector<std::string> g_presence_all_directions;
std::vector<size_t> g_presence_visible_indices;
std::vector<RECT> g_presence_card_rects;
RECT g_presence_filter_rects[4]{};
int g_presence_state_filter = 0;
int g_presence_scroll = 0;
int g_presence_content_height = 0;
int g_presence_saved_width = 620;
int g_presence_saved_height = 720;
bool g_presence_size_loaded = false;

void PositionAuxiliaryWindows(HWND owner);

void LoadPresenceWindowSize(int default_height) {
  if (g_presence_size_loaded) return;
  g_presence_size_loaded = true;
  g_presence_saved_height = default_height;
  HKEY key = nullptr;
  if (RegOpenKeyEx(HKEY_CURRENT_USER, L"Software\\AsteraSoftphone", 0,
                   KEY_READ, &key) != ERROR_SUCCESS) {
    return;
  }
  DWORD width = 0;
  DWORD height = 0;
  DWORD size = sizeof(DWORD);
  if (RegQueryValueEx(key, L"PresenceWidth", nullptr, nullptr,
                      reinterpret_cast<LPBYTE>(&width),
                      &size) == ERROR_SUCCESS) {
    g_presence_saved_width =
        std::clamp(static_cast<int>(width), 430, 2000);
  }
  size = sizeof(DWORD);
  if (RegQueryValueEx(key, L"PresenceHeight", nullptr, nullptr,
                      reinterpret_cast<LPBYTE>(&height),
                      &size) == ERROR_SUCCESS) {
    g_presence_saved_height =
        std::clamp(static_cast<int>(height), 300, 1600);
  }
  RegCloseKey(key);
}

void SavePresenceWindowSize(HWND window) {
  RECT rect{};
  if (!GetWindowRect(window, &rect)) return;
  g_presence_saved_width =
      std::max(430, static_cast<int>(rect.right - rect.left));
  g_presence_saved_height =
      std::max(300, static_cast<int>(rect.bottom - rect.top));
  HKEY key = nullptr;
  if (RegCreateKeyEx(HKEY_CURRENT_USER, L"Software\\AsteraSoftphone", 0,
                     nullptr, 0, KEY_WRITE, nullptr, &key,
                     nullptr) != ERROR_SUCCESS) {
    return;
  }
  const DWORD width = static_cast<DWORD>(g_presence_saved_width);
  const DWORD height = static_cast<DWORD>(g_presence_saved_height);
  RegSetValueEx(key, L"PresenceWidth", 0, REG_DWORD,
                reinterpret_cast<const BYTE*>(&width), sizeof(width));
  RegSetValueEx(key, L"PresenceHeight", 0, REG_DWORD,
                reinterpret_cast<const BYTE*>(&height), sizeof(height));
  RegCloseKey(key);
}

std::wstring Utf16FromUtf8(const std::string& value) {
  if (value.empty()) {
    return {};
  }
  const int length = MultiByteToWideChar(
      CP_UTF8, 0, value.c_str(), static_cast<int>(value.size()), nullptr, 0);
  std::wstring result(length, L'\0');
  MultiByteToWideChar(CP_UTF8, 0, value.c_str(),
                      static_cast<int>(value.size()), result.data(), length);
  return result;
}

std::wstring Lowercase(std::wstring value) {
  for (auto& character : value) {
    character = static_cast<wchar_t>(std::towlower(character));
  }
  return value;
}

void RebuildPresenceRows() {
  if (!g_presence_window) {
    return;
  }
  std::wstring query;
  if (g_presence_filter) {
    const int length = GetWindowTextLength(g_presence_filter);
    query.resize(length + 1);
    if (length > 0) {
      GetWindowText(g_presence_filter, query.data(), length + 1);
    }
    query.resize(length);
    query = Lowercase(query);
  }

  g_presence_numbers.clear();
  g_presence_visible_indices.clear();
  for (size_t index = 0; index < g_presence_all_numbers.size(); ++index) {
    auto extension = Utf16FromUtf8(g_presence_all_numbers[index]);
    auto name = Utf16FromUtf8(
        index < g_presence_all_names.size() ? g_presence_all_names[index] : "");
    const auto identity = Lowercase(extension + L" " + name);
    if (!query.empty() && identity.find(query) == std::wstring::npos) {
      continue;
    }
    const std::string state = index < g_presence_all_states.size()
                                  ? g_presence_all_states[index]
                                  : "offline";
    const bool state_matches =
        g_presence_state_filter == 0 ||
        (g_presence_state_filter == 1 && state == "available") ||
        (g_presence_state_filter == 2 &&
         (state == "ringing" || state == "talking")) ||
        (g_presence_state_filter == 3 && state == "offline");
    if (!state_matches) {
      continue;
    }
    g_presence_numbers.push_back(g_presence_all_numbers[index]);
    g_presence_visible_indices.push_back(index);
  }
  InvalidateRect(g_presence_window, nullptr, TRUE);
}

COLORREF PresenceColor(const std::string& state) {
  if (state == "available") return RGB(48, 190, 125);
  if (state == "ringing") return RGB(244, 183, 64);
  if (state == "talking") return RGB(224, 82, 82);
  return RGB(164, 174, 186);
}

void DrawPresenceText(HDC dc, const std::wstring& text, RECT rect,
                      COLORREF color, HFONT font, UINT format) {
  SetTextColor(dc, color);
  SetBkMode(dc, TRANSPARENT);
  const auto old_font =
      SelectObject(dc, font ? font : GetStockObject(DEFAULT_GUI_FONT));
  DrawText(dc, text.c_str(), static_cast<int>(text.size()), &rect, format);
  SelectObject(dc, old_font);
}

void PaintPresenceBoard(HWND window) {
  PAINTSTRUCT paint{};
  HDC dc = BeginPaint(window, &paint);
  RECT client{};
  GetClientRect(window, &client);
  HBRUSH background = CreateSolidBrush(RGB(251, 248, 242));
  FillRect(dc, &client, background);
  DeleteObject(background);

  RECT title{14, 12, client.right - 90, 36};
  DrawPresenceText(dc, L"Dahili Durumlar\u0131", title, RGB(24, 36, 55),
                   g_presence_title_font, DT_LEFT | DT_VCENTER | DT_SINGLELINE);
  RECT subtitle{14, 38, client.right - 14, 56};
  DrawPresenceText(dc, L"Ayn\u0131 firmadaki aboneler", subtitle,
                   RGB(113, 128, 150), g_presence_font,
                   DT_LEFT | DT_VCENTER | DT_SINGLELINE);

  HBRUSH live_brush = CreateSolidBrush(RGB(48, 190, 125));
  HBRUSH old_brush = static_cast<HBRUSH>(SelectObject(dc, live_brush));
  Ellipse(dc, client.right - 57, 18, client.right - 49, 26);
  SelectObject(dc, old_brush);
  DeleteObject(live_brush);
  RECT live{client.right - 45, 10, client.right - 12, 34};
  DrawPresenceText(dc, L"Canl\u0131", live, RGB(48, 130, 91), g_presence_font,
                   DT_LEFT | DT_VCENTER | DT_SINGLELINE);

  HPEN divider = CreatePen(PS_SOLID, 1, RGB(218, 210, 197));
  HPEN old_pen = static_cast<HPEN>(SelectObject(dc, divider));
  MoveToEx(dc, 14, 62, nullptr);
  LineTo(dc, client.right - 14, 62);
  SelectObject(dc, old_pen);
  DeleteObject(divider);

  int counts[4] = {static_cast<int>(g_presence_all_states.size()), 0, 0, 0};
  for (const auto& state : g_presence_all_states) {
    if (state == "available") {
      counts[1]++;
    } else if (state == "ringing" || state == "talking") {
      counts[2]++;
    } else {
      counts[3]++;
    }
  }
  const wchar_t* filter_names[] = {L"T\u00FCm\u00FC", L"M\u00FCsait",
                                    L"Me\u015Fgul", L"\u00C7evrimd\u0131\u015F\u0131"};
  const int filter_widths[] = {74, 82, 88, 112};
  int filter_x = 14;
  for (int index = 0; index < 4; ++index) {
    RECT chip{filter_x, 108, filter_x + filter_widths[index], 136};
    g_presence_filter_rects[index] = chip;
    HBRUSH chip_brush = CreateSolidBrush(
        index == g_presence_state_filter ? RGB(31, 51, 78)
                                         : RGB(255, 255, 255));
    HPEN chip_pen = CreatePen(PS_SOLID, 1, RGB(210, 202, 190));
    const auto previous_brush = SelectObject(dc, chip_brush);
    const auto previous_pen = SelectObject(dc, chip_pen);
    RoundRect(dc, chip.left, chip.top, chip.right, chip.bottom, 14, 14);
    SelectObject(dc, previous_brush);
    SelectObject(dc, previous_pen);
    DeleteObject(chip_brush);
    DeleteObject(chip_pen);
    const std::wstring label =
        std::wstring(filter_names[index]) + L"  " + std::to_wstring(counts[index]);
    RECT chip_text = chip;
    DrawPresenceText(
        dc, label, chip_text,
        index == g_presence_state_filter ? RGB(255, 255, 255)
                                         : RGB(91, 107, 129),
        g_presence_font, DT_CENTER | DT_VCENTER | DT_SINGLELINE);
    filter_x += filter_widths[index] + 7;
  }

  const int available_width =
      std::max(140, static_cast<int>(client.right) - 28);
  const int columns = std::max(1, available_width / 165);
  const int gap = 8;
  const int card_width =
      (available_width - (columns - 1) * gap) / columns;
  const int card_height = 54;
  const int rows = static_cast<int>(
      (g_presence_visible_indices.size() + columns - 1) / columns);
  g_presence_content_height = 148 + rows * (card_height + gap);
  const int maximum_scroll =
      std::max(0, g_presence_content_height -
                      std::max(1, static_cast<int>(client.bottom) - 140));
  g_presence_scroll = std::clamp(g_presence_scroll, 0, maximum_scroll);
  g_presence_card_rects.clear();
  for (size_t visible = 0; visible < g_presence_visible_indices.size();
       ++visible) {
    const int row = static_cast<int>(visible) / columns;
    const int column = static_cast<int>(visible) % columns;
    RECT card{
        14 + column * (card_width + gap),
        148 + row * (card_height + gap) - g_presence_scroll,
        14 + column * (card_width + gap) + card_width,
        148 + row * (card_height + gap) - g_presence_scroll + card_height};
    g_presence_card_rects.push_back(card);
    if (card.bottom < 140 || card.top > client.bottom) continue;
    HBRUSH card_brush = CreateSolidBrush(RGB(255, 255, 255));
    HPEN card_pen = CreatePen(PS_SOLID, 1, RGB(231, 225, 215));
    const auto previous_brush = SelectObject(dc, card_brush);
    const auto previous_pen = SelectObject(dc, card_pen);
    RoundRect(dc, card.left, card.top, card.right, card.bottom, 10, 10);
    SelectObject(dc, previous_brush);
    SelectObject(dc, previous_pen);
    DeleteObject(card_brush);
    DeleteObject(card_pen);

    const size_t source = g_presence_visible_indices[visible];
    const std::string state = source < g_presence_all_states.size()
                                  ? g_presence_all_states[source]
                                  : "offline";
    const COLORREF state_color = PresenceColor(state);
    HBRUSH dot_brush = CreateSolidBrush(state_color);
    const auto previous_dot_brush = SelectObject(dc, dot_brush);
    const auto previous_dot_pen = SelectObject(dc, GetStockObject(NULL_PEN));
    Ellipse(dc, card.left + 10, card.top + 11, card.left + 18, card.top + 19);
    SelectObject(dc, previous_dot_brush);
    SelectObject(dc, previous_dot_pen);
    DeleteObject(dot_brush);

    const std::wstring identity =
        Utf16FromUtf8(g_presence_all_numbers[source]) + L" - " +
        Utf16FromUtf8(g_presence_all_names[source]);
    RECT identity_rect{
        card.left + 24, card.top + 5, card.right - 58, card.top + 25};
    DrawPresenceText(dc, identity, identity_rect, RGB(24, 36, 55),
                     g_presence_font,
                     DT_LEFT | DT_VCENTER | DT_SINGLELINE | DT_END_ELLIPSIS);
    RECT status{card.right - 58, card.top + 5, card.right - 6, card.top + 25};
    DrawPresenceText(dc, Utf16FromUtf8(g_presence_all_statuses[source]), status,
                     state_color, g_presence_font,
                     DT_RIGHT | DT_VCENTER | DT_SINGLELINE | DT_END_ELLIPSIS);
    if (source < g_presence_all_peers.size() &&
        source < g_presence_all_directions.size() &&
        !g_presence_all_peers[source].empty() &&
        !g_presence_all_directions[source].empty()) {
      const bool incoming = g_presence_all_directions[source] == "incoming";
      const std::wstring call_info =
          std::wstring(incoming ? L"\u2190 Gelen \u00B7 " : L"\u2192 Giden \u00B7 ") +
          Utf16FromUtf8(g_presence_all_peers[source]);
      RECT peer{card.left + 10, card.top + 27, card.right - 6, card.bottom - 3};
      DrawPresenceText(
          dc, call_info, peer,
          RGB(64, 78, 98), g_presence_font,
          DT_LEFT | DT_VCENTER | DT_SINGLELINE | DT_END_ELLIPSIS);
    }
  }

  SCROLLINFO scroll{};
  scroll.cbSize = sizeof(scroll);
  scroll.fMask = SIF_RANGE | SIF_PAGE | SIF_POS;
  scroll.nMin = 0;
  scroll.nMax = std::max(0, g_presence_content_height - 1);
  scroll.nPage = std::max(1, static_cast<int>(client.bottom) - 140);
  scroll.nPos = g_presence_scroll;
  SetScrollInfo(window, SB_VERT, &scroll, TRUE);

  if (g_presence_visible_indices.empty()) {
    RECT empty{14, 165, client.right - 14, 205};
    DrawPresenceText(dc, L"E\u015Fle\u015Fen dahili bulunamad\u0131.", empty,
                     RGB(113, 128, 150), g_presence_font,
                     DT_CENTER | DT_VCENTER | DT_SINGLELINE);
  }
  EndPaint(window, &paint);
}

LRESULT CALLBACK HistoryWindowProc(HWND window, UINT message, WPARAM wparam,
                                   LPARAM lparam) {
  switch (message) {
    case WM_CREATE:
      g_history_list = CreateWindowEx(
          0, L"LISTBOX", nullptr,
          WS_CHILD | WS_VISIBLE | WS_VSCROLL | LBS_NOTIFY | LBS_NOINTEGRALHEIGHT,
          10, 10, 280, 620, window,
          reinterpret_cast<HMENU>(static_cast<INT_PTR>(kHistoryListId)),
          GetModuleHandle(nullptr),
          nullptr);
      g_history_font = CreateFont(
          -17, 0, 0, 0, FW_NORMAL, FALSE, FALSE, FALSE, DEFAULT_CHARSET,
          OUT_DEFAULT_PRECIS, CLIP_DEFAULT_PRECIS, CLEARTYPE_QUALITY,
          DEFAULT_PITCH | FF_DONTCARE, L"Segoe UI");
      SendMessage(
          g_history_list, WM_SETFONT,
          reinterpret_cast<WPARAM>(
              g_history_font ? g_history_font : GetStockObject(DEFAULT_GUI_FONT)),
          TRUE);
      return 0;
    case WM_SIZE:
      if (g_history_list) {
        MoveWindow(g_history_list, 10, 10, LOWORD(lparam) - 20,
                   HIWORD(lparam) - 20, TRUE);
      }
      return 0;
    case WM_COMMAND:
      if (LOWORD(wparam) == kHistoryListId &&
          HIWORD(wparam) == LBN_SELCHANGE && g_window_channel) {
        const auto selected = static_cast<int>(
            SendMessage(g_history_list, LB_GETCURSEL, 0, 0));
        if (selected >= 0 &&
            selected < static_cast<int>(g_history_numbers.size())) {
          g_window_channel->InvokeMethod(
              "callHistorySelected",
              std::make_unique<flutter::EncodableValue>(
                  g_history_numbers[selected]));
          SendMessage(g_history_list, LB_SETCURSEL, static_cast<WPARAM>(-1), 0);
        }
      }
      return 0;
    case WM_CLOSE:
      DestroyWindow(window);
      return 0;
    case WM_DESTROY: {
      const HWND owner = GetWindow(window, GW_OWNER);
      g_history_window = nullptr;
      g_history_list = nullptr;
      if (g_history_font) {
        DeleteObject(g_history_font);
        g_history_font = nullptr;
      }
      if (owner) {
        PositionAuxiliaryWindows(owner);
      }
      return 0;
    }
  }
  return DefWindowProc(window, message, wparam, lparam);
}

LRESULT CALLBACK PresenceWindowProc(HWND window, UINT message, WPARAM wparam,
                                    LPARAM lparam) {
  switch (message) {
    case WM_CREATE:
      g_presence_filter = CreateWindowEx(
          WS_EX_CLIENTEDGE, L"EDIT", nullptr,
          WS_CHILD | WS_VISIBLE | WS_TABSTOP | ES_AUTOHSCROLL,
          14, 72, 400, 28, window,
          reinterpret_cast<HMENU>(static_cast<INT_PTR>(kPresenceFilterId)),
          GetModuleHandle(nullptr), nullptr);
      SendMessage(g_presence_filter, EM_SETCUEBANNER, TRUE,
                  reinterpret_cast<LPARAM>(L"\u0130sim veya dahili ara"));
      g_presence_font = CreateFont(
          -11, 0, 0, 0, FW_NORMAL, FALSE, FALSE, FALSE, DEFAULT_CHARSET,
          OUT_DEFAULT_PRECIS, CLIP_DEFAULT_PRECIS, CLEARTYPE_QUALITY,
          DEFAULT_PITCH | FF_DONTCARE, L"Segoe UI");
      g_presence_title_font = CreateFont(
          -17, 0, 0, 0, FW_BOLD, FALSE, FALSE, FALSE, DEFAULT_CHARSET,
          OUT_DEFAULT_PRECIS, CLIP_DEFAULT_PRECIS, CLEARTYPE_QUALITY,
          DEFAULT_PITCH | FF_DONTCARE, L"Segoe UI");
      SendMessage(
          g_presence_filter, WM_SETFONT,
          reinterpret_cast<WPARAM>(
              g_presence_font ? g_presence_font
                              : GetStockObject(DEFAULT_GUI_FONT)),
          TRUE);
      return 0;
    case WM_SIZE:
      if (g_presence_filter) {
        MoveWindow(g_presence_filter, 14, 72, LOWORD(lparam) - 28, 28, TRUE);
      }
      InvalidateRect(window, nullptr, TRUE);
      return 0;
    case WM_GETMINMAXINFO: {
      auto* limits = reinterpret_cast<MINMAXINFO*>(lparam);
      limits->ptMinTrackSize.x = 430;
      limits->ptMinTrackSize.y = 300;
      return 0;
    }
    case WM_COMMAND:
      if (LOWORD(wparam) == kPresenceFilterId &&
          HIWORD(wparam) == EN_CHANGE) {
        g_presence_scroll = 0;
        RebuildPresenceRows();
      }
      return 0;
    case WM_LBUTTONDOWN: {
      POINT point{GET_X_LPARAM(lparam), GET_Y_LPARAM(lparam)};
      for (int index = 0; index < 4; ++index) {
        if (PtInRect(&g_presence_filter_rects[index], point)) {
          g_presence_state_filter = index;
          g_presence_scroll = 0;
          RebuildPresenceRows();
          return 0;
        }
      }
      return 0;
    }
    case WM_LBUTTONDBLCLK: {
      POINT point{GET_X_LPARAM(lparam), GET_Y_LPARAM(lparam)};
      for (size_t index = 0; index < g_presence_card_rects.size(); ++index) {
        if (PtInRect(&g_presence_card_rects[index], point) &&
            index < g_presence_numbers.size() && g_window_channel) {
          g_window_channel->InvokeMethod(
              "presenceSelected",
              std::make_unique<flutter::EncodableValue>(
                  g_presence_numbers[index]));
          break;
        }
      }
      return 0;
    }
    case WM_MOUSEWHEEL:
      SendMessage(window, WM_VSCROLL,
                  GET_WHEEL_DELTA_WPARAM(wparam) > 0 ? SB_LINEUP
                                                    : SB_LINEDOWN,
                  0);
      return 0;
    case WM_VSCROLL: {
      SCROLLINFO scroll{};
      scroll.cbSize = sizeof(scroll);
      scroll.fMask = SIF_ALL;
      GetScrollInfo(window, SB_VERT, &scroll);
      int position = scroll.nPos;
      switch (LOWORD(wparam)) {
        case SB_LINEUP:
          position -= 40;
          break;
        case SB_LINEDOWN:
          position += 40;
          break;
        case SB_PAGEUP:
          position -= static_cast<int>(scroll.nPage);
          break;
        case SB_PAGEDOWN:
          position += static_cast<int>(scroll.nPage);
          break;
        case SB_THUMBTRACK:
          position = scroll.nTrackPos;
          break;
      }
      const int maximum =
          std::max(0, scroll.nMax - static_cast<int>(scroll.nPage) + 1);
      g_presence_scroll = std::clamp(position, 0, maximum);
      InvalidateRect(window, nullptr, TRUE);
      return 0;
    }
    case WM_PAINT:
      PaintPresenceBoard(window);
      return 0;
    case WM_ERASEBKGND:
      return 1;
    case WM_CLOSE:
      DestroyWindow(window);
      return 0;
    case WM_DESTROY: {
      const HWND owner = GetWindow(window, GW_OWNER);
      SavePresenceWindowSize(window);
      g_presence_window = nullptr;
      g_presence_filter = nullptr;
      g_presence_numbers.clear();
      g_presence_all_numbers.clear();
      g_presence_all_names.clear();
      g_presence_all_statuses.clear();
      g_presence_all_states.clear();
      g_presence_all_peers.clear();
      g_presence_all_directions.clear();
      g_presence_visible_indices.clear();
      g_presence_card_rects.clear();
      g_presence_state_filter = 0;
      g_presence_scroll = 0;
      if (g_presence_font) {
        DeleteObject(g_presence_font);
        g_presence_font = nullptr;
      }
      if (g_presence_title_font) {
        DeleteObject(g_presence_title_font);
        g_presence_title_font = nullptr;
      }
      if (g_window_channel) {
        g_window_channel->InvokeMethod(
            "presenceBoardClosed",
            std::make_unique<flutter::EncodableValue>());
      }
      if (owner) {
        PositionAuxiliaryWindows(owner);
      }
      return 0;
    }
  }
  return DefWindowProc(window, message, wparam, lparam);
}

void ShowHistoryWindow(HWND owner, const std::string& title,
                       const std::vector<std::string>& labels,
                       const std::vector<std::string>& numbers,
                       bool create_if_needed) {
  static bool window_class_registered = false;
  if (!window_class_registered) {
    WNDCLASS window_class{};
    window_class.hCursor = LoadCursor(nullptr, IDC_ARROW);
    window_class.hInstance = GetModuleHandle(nullptr);
    window_class.lpszClassName = kHistoryWindowClass;
    window_class.lpfnWndProc = HistoryWindowProc;
    window_class.hbrBackground =
        reinterpret_cast<HBRUSH>(COLOR_WINDOW + 1);
    RegisterClass(&window_class);
    window_class_registered = true;
  }

  RECT owner_rect{};
  GetWindowRect(owner, &owner_rect);
  const auto window_title = Utf16FromUtf8(title);
  if (!g_history_window && !create_if_needed) {
    return;
  }
  if (!g_history_window) {
    g_history_window = CreateWindowEx(
        WS_EX_TOPMOST, kHistoryWindowClass, window_title.c_str(),
        WS_OVERLAPPED | WS_CAPTION | WS_SYSMENU,
        owner_rect.right + 8, owner_rect.top, 520,
        owner_rect.bottom - owner_rect.top, owner, nullptr,
        GetModuleHandle(nullptr), nullptr);
  } else {
    SetWindowText(g_history_window, window_title.c_str());
    SetWindowPos(g_history_window, HWND_TOPMOST, owner_rect.right + 8,
                 owner_rect.top, 520, owner_rect.bottom - owner_rect.top,
                 SWP_SHOWWINDOW);
  }

  g_history_numbers = numbers;
  SendMessage(g_history_list, LB_RESETCONTENT, 0, 0);
  if (labels.empty()) {
    const std::wstring empty_text = L"Bu hesap i\u00E7in arama yok.";
    SendMessage(g_history_list, LB_ADDSTRING, 0,
                reinterpret_cast<LPARAM>(empty_text.c_str()));
  } else {
    for (const auto& label : labels) {
      const auto text = Utf16FromUtf8(label);
      SendMessage(g_history_list, LB_ADDSTRING, 0,
                  reinterpret_cast<LPARAM>(text.c_str()));
    }
  }
  ShowWindow(g_history_window, SW_SHOWNORMAL);
}

void PositionHistoryWindow(HWND owner) {
  if (!g_history_window) {
    return;
  }
  RECT owner_rect{};
  GetWindowRect(owner, &owner_rect);
  SetWindowPos(g_history_window, HWND_TOPMOST, owner_rect.right + 8,
               owner_rect.top, 0, 0,
               SWP_NOSIZE | SWP_NOACTIVATE | SWP_NOOWNERZORDER);
}

void ShowPresenceWindow(HWND owner, const std::string& title,
                        const std::vector<std::string>& names,
                        const std::vector<std::string>& statuses,
                        const std::vector<std::string>& states,
                        const std::vector<std::string>& peers,
                        const std::vector<std::string>& directions,
                        const std::vector<std::string>& numbers) {
  static bool window_class_registered = false;
  if (!window_class_registered) {
    WNDCLASS window_class{};
    window_class.style = CS_DBLCLKS;
    window_class.hCursor = LoadCursor(nullptr, IDC_ARROW);
    window_class.hInstance = GetModuleHandle(nullptr);
    window_class.lpszClassName = kPresenceWindowClass;
    window_class.lpfnWndProc = PresenceWindowProc;
    window_class.hbrBackground = reinterpret_cast<HBRUSH>(COLOR_WINDOW + 1);
    RegisterClass(&window_class);
    window_class_registered = true;
  }

  RECT owner_rect{};
  GetWindowRect(owner, &owner_rect);
  const auto window_title = Utf16FromUtf8(title);
  if (!g_presence_window) {
    LoadPresenceWindowSize(owner_rect.bottom - owner_rect.top);
    g_presence_window = CreateWindowEx(
        WS_EX_TOPMOST, kPresenceWindowClass, window_title.c_str(),
        WS_OVERLAPPED | WS_CAPTION | WS_SYSMENU | WS_THICKFRAME |
            WS_MAXIMIZEBOX | WS_VSCROLL,
        owner_rect.right + 8, owner_rect.top, g_presence_saved_width,
        g_presence_saved_height, owner, nullptr,
        GetModuleHandle(nullptr), nullptr);
  } else {
    SetWindowText(g_presence_window, window_title.c_str());
  }

  g_presence_all_numbers = numbers;
  g_presence_all_names = names;
  g_presence_all_statuses = statuses;
  g_presence_all_states = states;
  g_presence_all_peers = peers;
  g_presence_all_directions = directions;
  RebuildPresenceRows();
  ShowWindow(g_presence_window, SW_SHOWNORMAL);
}

void PositionPresenceWindow(HWND owner) {
  if (!g_presence_window) {
    return;
  }
  RECT owner_rect{};
  GetWindowRect(owner, &owner_rect);
  SetWindowPos(g_presence_window, HWND_TOPMOST, owner_rect.right + 8,
               owner_rect.top, 0, 0,
               SWP_NOSIZE | SWP_NOACTIVATE | SWP_NOOWNERZORDER);
}

void PositionAuxiliaryWindows(HWND owner) {
  RECT owner_rect{};
  GetWindowRect(owner, &owner_rect);
  int next_x = owner_rect.right + 8;
  if (g_history_window) {
    RECT history_rect{};
    GetWindowRect(g_history_window, &history_rect);
    SetWindowPos(g_history_window, HWND_TOPMOST, next_x, owner_rect.top, 0, 0,
                 SWP_NOSIZE | SWP_NOACTIVATE | SWP_NOOWNERZORDER);
    next_x += history_rect.right - history_rect.left + 8;
  }
  if (g_presence_window) {
    SetWindowPos(g_presence_window, HWND_TOPMOST, next_x, owner_rect.top, 0, 0,
                 SWP_NOSIZE | SWP_NOACTIVATE | SWP_NOOWNERZORDER);
  }
}

void BringWindowToForeground(HWND window) {
  if (IsIconic(window)) {
    ShowWindow(window, SW_RESTORE);
  } else {
    ShowWindow(window, SW_SHOW);
  }

  const HWND foreground = GetForegroundWindow();
  const DWORD current_thread = GetCurrentThreadId();
  const DWORD foreground_thread =
      foreground ? GetWindowThreadProcessId(foreground, nullptr) : 0;
  const bool attached =
      foreground_thread != 0 && foreground_thread != current_thread &&
      AttachThreadInput(current_thread, foreground_thread, TRUE);

  SetWindowPos(window, HWND_TOPMOST, 0, 0, 0, 0,
               SWP_NOMOVE | SWP_NOSIZE | SWP_SHOWWINDOW);
  BringWindowToTop(window);
  SetForegroundWindow(window);
  SetFocus(window);

  if (attached) {
    AttachThreadInput(current_thread, foreground_thread, FALSE);
  }

  FLASHWINFO flash{};
  flash.cbSize = sizeof(flash);
  flash.hwnd = window;
  flash.dwFlags = FLASHW_TRAY | FLASHW_TIMERNOFG;
  flash.uCount = 3;
  flash.dwTimeout = 0;
  FlashWindowEx(&flash);
}

}  // namespace

FlutterWindow::FlutterWindow(const flutter::DartProject& project)
    : project_(project) {}

FlutterWindow::~FlutterWindow() {}

bool FlutterWindow::OnCreate() {
  if (!Win32Window::OnCreate()) {
    return false;
  }

  RECT frame = GetClientArea();

  // The size here must match the window dimensions to avoid unnecessary surface
  // creation / destruction in the startup path.
  flutter_controller_ = std::make_unique<flutter::FlutterViewController>(
      frame.right - frame.left, frame.bottom - frame.top, project_);
  // Ensure that basic setup of the controller was successful.
  if (!flutter_controller_->engine() || !flutter_controller_->view()) {
    return false;
  }
  RegisterPlugins(flutter_controller_->engine());
  window_channel_ =
      std::make_unique<flutter::MethodChannel<flutter::EncodableValue>>(
          flutter_controller_->engine()->messenger(),
          "tr.com.astera/window",
          &flutter::StandardMethodCodec::GetInstance());
  g_window_channel = window_channel_.get();
  window_channel_->SetMethodCallHandler(
      [this](const auto& call, auto result) {
        if (call.method_name() == "bringToFront") {
          BringWindowToForeground(GetHandle());
          result->Success();
          return;
        }
        const bool show_history = call.method_name() == "showCallHistory";
        const bool update_history = call.method_name() == "updateCallHistory";
        const bool toggle_presence =
            call.method_name() == "togglePresenceBoard";
        const bool update_presence =
            call.method_name() == "updatePresenceBoard";
        if (show_history && g_history_window) {
          DestroyWindow(g_history_window);
          result->Success();
          return;
        }
        if (toggle_presence && g_presence_window) {
          DestroyWindow(g_presence_window);
          result->Success();
          return;
        }
        if (!show_history && !update_history && !toggle_presence &&
            !update_presence) {
          result->NotImplemented();
          return;
        }
        const auto* arguments =
            std::get_if<flutter::EncodableMap>(call.arguments());
        if (!arguments) {
          result->Error("invalid_arguments", "History data is required.");
          return;
        }
        const auto title_iterator =
            arguments->find(flutter::EncodableValue("title"));
        const auto labels_iterator =
            arguments->find(flutter::EncodableValue("labels"));
        const auto numbers_iterator =
            arguments->find(flutter::EncodableValue("numbers"));
        const auto statuses_iterator =
            arguments->find(flutter::EncodableValue("statuses"));
        const auto states_iterator =
            arguments->find(flutter::EncodableValue("states"));
        const auto peers_iterator =
            arguments->find(flutter::EncodableValue("peers"));
        const auto directions_iterator =
            arguments->find(flutter::EncodableValue("directions"));
        if (title_iterator == arguments->end() ||
            labels_iterator == arguments->end() ||
            numbers_iterator == arguments->end()) {
          result->Error("invalid_arguments", "Incomplete history data.");
          return;
        }
        const auto* title =
            std::get_if<std::string>(&title_iterator->second);
        const auto* encoded_labels =
            std::get_if<flutter::EncodableList>(&labels_iterator->second);
        const auto* encoded_numbers =
            std::get_if<flutter::EncodableList>(&numbers_iterator->second);
        if (!title || !encoded_labels || !encoded_numbers) {
          result->Error("invalid_arguments", "Invalid history data.");
          return;
        }
        std::vector<std::string> labels;
        std::vector<std::string> numbers;
        std::vector<std::string> statuses;
        std::vector<std::string> states;
        std::vector<std::string> peers;
        std::vector<std::string> directions;
        for (const auto& value : *encoded_labels) {
          if (const auto* text = std::get_if<std::string>(&value)) {
            labels.push_back(*text);
          }
        }
        for (const auto& value : *encoded_numbers) {
          if (const auto* text = std::get_if<std::string>(&value)) {
            numbers.push_back(*text);
          }
        }
        if (statuses_iterator != arguments->end()) {
          if (const auto* encoded_statuses =
                  std::get_if<flutter::EncodableList>(
                      &statuses_iterator->second)) {
            for (const auto& value : *encoded_statuses) {
              if (const auto* text = std::get_if<std::string>(&value)) {
                statuses.push_back(*text);
              }
            }
          }
        }
        if (states_iterator != arguments->end()) {
          if (const auto* encoded_states =
                  std::get_if<flutter::EncodableList>(
                      &states_iterator->second)) {
            for (const auto& value : *encoded_states) {
              if (const auto* text = std::get_if<std::string>(&value)) {
                states.push_back(*text);
              }
            }
          }
        }
        if (peers_iterator != arguments->end()) {
          if (const auto* encoded_peers =
                  std::get_if<flutter::EncodableList>(
                      &peers_iterator->second)) {
            for (const auto& value : *encoded_peers) {
              if (const auto* text = std::get_if<std::string>(&value)) {
                peers.push_back(*text);
              }
            }
          }
        }
        if (directions_iterator != arguments->end()) {
          if (const auto* encoded_directions =
                  std::get_if<flutter::EncodableList>(
                      &directions_iterator->second)) {
            for (const auto& value : *encoded_directions) {
              if (const auto* text = std::get_if<std::string>(&value)) {
                directions.push_back(*text);
              }
            }
          }
        }
        if (show_history || update_history) {
          ShowHistoryWindow(GetHandle(), *title, labels, numbers, show_history);
        } else if (toggle_presence ||
                   (update_presence && g_presence_window)) {
          ShowPresenceWindow(GetHandle(), *title, labels, statuses, states,
                             peers, directions, numbers);
        }
        PositionAuxiliaryWindows(GetHandle());
        result->Success();
      });
  SetChildContent(flutter_controller_->view()->GetNativeWindow());

  flutter_controller_->engine()->SetNextFrameCallback([&]() {
    this->Show();
  });

  // Flutter can complete the first frame before the "show window" callback is
  // registered. The following call ensures a frame is pending to ensure the
  // window is shown. It is a no-op if the first frame hasn't completed yet.
  flutter_controller_->ForceRedraw();

  return true;
}

void FlutterWindow::OnDestroy() {
  g_window_channel = nullptr;
  if (g_history_window) {
    DestroyWindow(g_history_window);
  }
  if (g_presence_window) {
    DestroyWindow(g_presence_window);
  }
  window_channel_.reset();
  if (flutter_controller_) {
    flutter_controller_ = nullptr;
  }

  Win32Window::OnDestroy();
}

LRESULT
FlutterWindow::MessageHandler(HWND hwnd, UINT const message,
                              WPARAM const wparam,
                              LPARAM const lparam) noexcept {
  if (message == WM_MOVE) {
    PositionAuxiliaryWindows(hwnd);
  }
  // Give Flutter, including plugins, an opportunity to handle window messages.
  if (flutter_controller_) {
    std::optional<LRESULT> result =
        flutter_controller_->HandleTopLevelWindowProc(hwnd, message, wparam,
                                                      lparam);
    if (result) {
      return *result;
    }
  }

  switch (message) {
    case WM_FONTCHANGE:
      flutter_controller_->engine()->ReloadSystemFonts();
      break;
  }

  return Win32Window::MessageHandler(hwnd, message, wparam, lparam);
}
