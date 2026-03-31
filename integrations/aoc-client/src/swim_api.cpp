#include "swim_api.h"
#include <sstream>
#include <cstring>

#ifdef _WIN32
#include <windows.h>
#include <winhttp.h>
#pragma comment(lib, "winhttp.lib")
#else
#include <curl/curl.h>
#endif

SWIMApi::SWIMApi() : m_connected(false) {}
SWIMApi::~SWIMApi() {}

void SWIMApi::SetEndpoint(const std::string& baseUrl, const std::string& apiKey) {
    m_baseUrl = baseUrl;
    m_apiKey = apiKey;
}

bool SWIMApi::IngestTrack(const FlightData& data) {
    std::ostringstream json;
    json << "{\"callsign\":\"" << data.callsign << "\""
         << ",\"latitude\":" << data.position.latitude
         << ",\"longitude\":" << data.position.longitude
         << ",\"altitude\":" << data.position.altitude_ft
         << ",\"groundspeed\":" << data.position.ground_speed_kts
         << ",\"heading\":" << data.position.heading
         << ",\"on_ground\":" << (data.position.on_ground ? "true" : "false")
         << "}";

    std::string url = m_baseUrl + "/api/swim/v1/ingest/track";
    std::string response = HttpPost(url, json.str());
    m_connected = !response.empty();
    return m_connected;
}

bool SWIMApi::IngestOOOI(const std::string& callsign, FlightPhase phase, time_t timestamp) {
    char timeBuf[32];
    struct tm* utc = gmtime(&timestamp);
    strftime(timeBuf, sizeof(timeBuf), "%Y-%m-%dT%H:%M:%SZ", utc);

    std::ostringstream json;
    json << "{\"callsign\":\"" << callsign << "\""
         << ",\"phase\":\"" << PhaseToString(phase) << "\""
         << ",\"timestamp\":\"" << timeBuf << "\""
         << "}";

    std::string url = m_baseUrl + "/api/swim/v1/ingest/adl";
    std::string response = HttpPost(url, json.str());
    return !response.empty();
}

bool SWIMApi::FetchEDCT(const std::string& callsign, std::string& edct_out) {
    std::string url = m_baseUrl + "/api/swim/v1/flight?callsign=" + callsign;
    std::string response = HttpGet(url);
    if (response.empty()) return false;

    // Simple extraction of edct_utc
    size_t pos = response.find("\"edct_utc\"");
    if (pos == std::string::npos) return false;
    pos = response.find('"', pos + 11);
    if (pos == std::string::npos) return false;
    size_t end = response.find('"', pos + 1);
    if (end == std::string::npos) return false;
    edct_out = response.substr(pos + 1, end - pos - 1);
    return !edct_out.empty();
}

// Platform-specific HTTP implementations
#ifdef _WIN32

std::string SWIMApi::HttpPost(const std::string& url, const std::string& body) {
    // Simplified WinHTTP POST
    std::wstring wUrl(url.begin(), url.end());
    URL_COMPONENTS uc = {};
    uc.dwStructSize = sizeof(uc);
    wchar_t host[256] = {}, path[2048] = {};
    uc.lpszHostName = host; uc.dwHostNameLength = 256;
    uc.lpszUrlPath = path; uc.dwUrlPathLength = 2048;
    if (!WinHttpCrackUrl(wUrl.c_str(), 0, 0, &uc)) return "";

    HINTERNET hSess = WinHttpOpen(L"VATSWIM-AOC/1.0", WINHTTP_ACCESS_TYPE_DEFAULT_PROXY, NULL, NULL, 0);
    if (!hSess) return "";
    HINTERNET hConn = WinHttpConnect(hSess, host, uc.nPort, 0);
    if (!hConn) { WinHttpCloseHandle(hSess); return ""; }
    DWORD flags = (uc.nScheme == INTERNET_SCHEME_HTTPS) ? WINHTTP_FLAG_SECURE : 0;
    HINTERNET hReq = WinHttpOpenRequest(hConn, L"POST", path, NULL, NULL, NULL, flags);
    if (!hReq) { WinHttpCloseHandle(hConn); WinHttpCloseHandle(hSess); return ""; }

    std::wstring headers = L"Content-Type: application/json\r\nX-API-Key: " + std::wstring(m_apiKey.begin(), m_apiKey.end());
    WinHttpSendRequest(hReq, headers.c_str(), -1, (LPVOID)body.c_str(), (DWORD)body.size(), (DWORD)body.size(), 0);
    WinHttpReceiveResponse(hReq, NULL);

    std::string result;
    DWORD bytesRead = 0;
    char buf[4096];
    while (WinHttpReadData(hReq, buf, sizeof(buf), &bytesRead) && bytesRead > 0)
        result.append(buf, bytesRead);

    WinHttpCloseHandle(hReq);
    WinHttpCloseHandle(hConn);
    WinHttpCloseHandle(hSess);
    return result;
}

std::string SWIMApi::HttpGet(const std::string& url) {
    std::wstring wUrl(url.begin(), url.end());
    URL_COMPONENTS uc = {};
    uc.dwStructSize = sizeof(uc);
    wchar_t host[256] = {}, path[2048] = {};
    uc.lpszHostName = host; uc.dwHostNameLength = 256;
    uc.lpszUrlPath = path; uc.dwUrlPathLength = 2048;
    if (!WinHttpCrackUrl(wUrl.c_str(), 0, 0, &uc)) return "";

    HINTERNET hSess = WinHttpOpen(L"VATSWIM-AOC/1.0", WINHTTP_ACCESS_TYPE_DEFAULT_PROXY, NULL, NULL, 0);
    if (!hSess) return "";
    HINTERNET hConn = WinHttpConnect(hSess, host, uc.nPort, 0);
    if (!hConn) { WinHttpCloseHandle(hSess); return ""; }
    DWORD flags = (uc.nScheme == INTERNET_SCHEME_HTTPS) ? WINHTTP_FLAG_SECURE : 0;
    HINTERNET hReq = WinHttpOpenRequest(hConn, L"GET", path, NULL, NULL, NULL, flags);
    if (!hReq) { WinHttpCloseHandle(hConn); WinHttpCloseHandle(hSess); return ""; }

    std::wstring apiHeader = L"X-API-Key: " + std::wstring(m_apiKey.begin(), m_apiKey.end());
    WinHttpAddRequestHeaders(hReq, apiHeader.c_str(), -1, WINHTTP_ADDREQ_FLAG_ADD);
    WinHttpSendRequest(hReq, NULL, 0, NULL, 0, 0, 0);
    WinHttpReceiveResponse(hReq, NULL);

    std::string result;
    DWORD bytesRead = 0;
    char buf[4096];
    while (WinHttpReadData(hReq, buf, sizeof(buf), &bytesRead) && bytesRead > 0)
        result.append(buf, bytesRead);

    WinHttpCloseHandle(hReq);
    WinHttpCloseHandle(hConn);
    WinHttpCloseHandle(hSess);
    return result;
}

#else
// Linux/macOS -- use libcurl
static size_t WriteCallback(void* contents, size_t size, size_t nmemb, std::string* out) {
    out->append((char*)contents, size * nmemb);
    return size * nmemb;
}

std::string SWIMApi::HttpPost(const std::string& url, const std::string& body) {
    CURL* curl = curl_easy_init();
    if (!curl) return "";
    std::string result;
    struct curl_slist* headers = NULL;
    headers = curl_slist_append(headers, "Content-Type: application/json");
    std::string apiHeader = "X-API-Key: " + m_apiKey;
    headers = curl_slist_append(headers, apiHeader.c_str());

    curl_easy_setopt(curl, CURLOPT_URL, url.c_str());
    curl_easy_setopt(curl, CURLOPT_POSTFIELDS, body.c_str());
    curl_easy_setopt(curl, CURLOPT_HTTPHEADER, headers);
    curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, WriteCallback);
    curl_easy_setopt(curl, CURLOPT_WRITEDATA, &result);
    curl_easy_setopt(curl, CURLOPT_TIMEOUT, 10L);
    curl_easy_perform(curl);
    curl_slist_free_all(headers);
    curl_easy_cleanup(curl);
    return result;
}

std::string SWIMApi::HttpGet(const std::string& url) {
    CURL* curl = curl_easy_init();
    if (!curl) return "";
    std::string result;
    struct curl_slist* headers = NULL;
    std::string apiHeader = "X-API-Key: " + m_apiKey;
    headers = curl_slist_append(headers, apiHeader.c_str());

    curl_easy_setopt(curl, CURLOPT_URL, url.c_str());
    curl_easy_setopt(curl, CURLOPT_HTTPHEADER, headers);
    curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, WriteCallback);
    curl_easy_setopt(curl, CURLOPT_WRITEDATA, &result);
    curl_easy_setopt(curl, CURLOPT_TIMEOUT, 10L);
    curl_easy_perform(curl);
    curl_slist_free_all(headers);
    curl_easy_cleanup(curl);
    return result;
}
#endif
