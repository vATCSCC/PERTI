#include "SWIMClient.h"
#include <windows.h>
#include <winhttp.h>
#include <sstream>

#pragma comment(lib, "winhttp.lib")

SWIMClient::SWIMClient()
    : m_connected(false) {}

SWIMClient::~SWIMClient() {}

void SWIMClient::SetEndpoint(const std::string& baseUrl, const std::string& apiKey) {
    m_baseUrl = baseUrl;
    m_apiKey = apiKey;
}

bool SWIMClient::FetchFlights(const std::string& airport, std::vector<SWIMFlight>& out) {
    std::string url = m_baseUrl + "/api/swim/v1/flights?destination=" + airport + "&active=1";
    std::string response = HttpGet(url);
    if (response.empty()) {
        m_connected = false;
        return false;
    }
    m_connected = true;

    if (!ParseFlightsJSON(response, out)) {
        return false;
    }

    // Cache flights by callsign
    for (const auto& f : out) {
        m_flights[f.callsign] = f;
    }
    return true;
}

bool SWIMClient::FetchPrograms(std::vector<SWIMProgram>& out) {
    std::string url = m_baseUrl + "/api/swim/v1/tmi/active";
    std::string response = HttpGet(url);
    if (response.empty()) {
        return false;
    }
    if (!ParseProgramsJSON(response, out)) {
        return false;
    }
    m_programs = out;
    return true;
}

const SWIMFlight* SWIMClient::GetFlight(const std::string& callsign) const {
    auto it = m_flights.find(callsign);
    return (it != m_flights.end()) ? &it->second : nullptr;
}

std::string SWIMClient::HttpGet(const std::string& url) {
    // Use WinHTTP for HTTPS requests
    // Parse URL components
    std::wstring wUrl(url.begin(), url.end());

    URL_COMPONENTS urlComp = {};
    urlComp.dwStructSize = sizeof(urlComp);
    wchar_t hostName[256] = {};
    wchar_t urlPath[2048] = {};
    urlComp.lpszHostName = hostName;
    urlComp.dwHostNameLength = 256;
    urlComp.lpszUrlPath = urlPath;
    urlComp.dwUrlPathLength = 2048;

    if (!WinHttpCrackUrl(wUrl.c_str(), 0, 0, &urlComp)) {
        m_lastError = "URL parse error";
        return "";
    }

    HINTERNET hSession = WinHttpOpen(L"VATSWIM-EuroScope/1.0",
        WINHTTP_ACCESS_TYPE_DEFAULT_PROXY, NULL, NULL, 0);
    if (!hSession) {
        m_lastError = "WinHTTP session error";
        return "";
    }

    HINTERNET hConnect = WinHttpConnect(hSession, hostName, urlComp.nPort, 0);
    if (!hConnect) {
        WinHttpCloseHandle(hSession);
        m_lastError = "Connection error";
        return "";
    }

    DWORD flags = (urlComp.nScheme == INTERNET_SCHEME_HTTPS) ? WINHTTP_FLAG_SECURE : 0;
    HINTERNET hRequest = WinHttpOpenRequest(hConnect, L"GET", urlPath,
        NULL, NULL, NULL, flags);
    if (!hRequest) {
        WinHttpCloseHandle(hConnect);
        WinHttpCloseHandle(hSession);
        m_lastError = "Request open error";
        return "";
    }

    // Add API key header
    std::wstring apiKeyHeader = L"X-API-Key: " + std::wstring(m_apiKey.begin(), m_apiKey.end());
    WinHttpAddRequestHeaders(hRequest, apiKeyHeader.c_str(), -1, WINHTTP_ADDREQ_FLAG_ADD);

    if (!WinHttpSendRequest(hRequest, NULL, 0, NULL, 0, 0, 0)) {
        WinHttpCloseHandle(hRequest);
        WinHttpCloseHandle(hConnect);
        WinHttpCloseHandle(hSession);
        m_lastError = "Send error";
        return "";
    }

    if (!WinHttpReceiveResponse(hRequest, NULL)) {
        WinHttpCloseHandle(hRequest);
        WinHttpCloseHandle(hConnect);
        WinHttpCloseHandle(hSession);
        m_lastError = "Receive error";
        return "";
    }

    std::string result;
    DWORD bytesRead = 0;
    char buffer[4096];
    while (WinHttpReadData(hRequest, buffer, sizeof(buffer), &bytesRead) && bytesRead > 0) {
        result.append(buffer, bytesRead);
    }

    WinHttpCloseHandle(hRequest);
    WinHttpCloseHandle(hConnect);
    WinHttpCloseHandle(hSession);

    return result;
}

// Simple JSON parsing -- extract fields from flat JSON objects
// For production, use a proper JSON library (nlohmann/json, rapidjson)
// This is a minimal implementation for the plugin context

static std::string extractJsonString(const std::string& json, const std::string& key) {
    std::string search = "\"" + key + "\"";
    size_t pos = json.find(search);
    if (pos == std::string::npos) return "";

    pos = json.find(':', pos + search.length());
    if (pos == std::string::npos) return "";

    // Skip whitespace
    pos = json.find_first_not_of(" \t\r\n", pos + 1);
    if (pos == std::string::npos) return "";

    if (json[pos] == '"') {
        size_t end = json.find('"', pos + 1);
        if (end == std::string::npos) return "";
        return json.substr(pos + 1, end - pos - 1);
    } else if (json[pos] == 'n') {
        return ""; // null
    } else {
        // Number
        size_t end = json.find_first_of(",}] \t\r\n", pos);
        return json.substr(pos, end - pos);
    }
}

static long long extractJsonLong(const std::string& json, const std::string& key) {
    std::string val = extractJsonString(json, key);
    if (val.empty()) return 0;
    try {
        return std::stoll(val);
    } catch (...) {
        return 0;
    }
}

static int extractJsonInt(const std::string& json, const std::string& key) {
    std::string val = extractJsonString(json, key);
    if (val.empty()) return 0;
    try {
        return std::stoi(val);
    } catch (...) {
        return 0;
    }
}

bool SWIMClient::ParseFlightsJSON(const std::string& json, std::vector<SWIMFlight>& out) {
    // Find array elements -- look for objects between { and }
    size_t pos = 0;
    while ((pos = json.find('{', pos)) != std::string::npos) {
        size_t end = json.find('}', pos);
        if (end == std::string::npos) break;

        std::string obj = json.substr(pos, end - pos + 1);
        SWIMFlight f;
        f.flight_uid = extractJsonLong(obj, "flight_uid");
        f.callsign = extractJsonString(obj, "callsign");
        f.departure = extractJsonString(obj, "fp_dep_icao");
        f.destination = extractJsonString(obj, "fp_dest_icao");
        f.edct_utc = extractJsonString(obj, "edct_utc");
        f.ctot_utc = extractJsonString(obj, "controlled_time_of_departure");
        f.tmi_status = extractJsonString(obj, "tmi_status");
        f.aman_sequence = extractJsonInt(obj, "aman_sequence_number");
        f.aman_delay_sec = extractJsonInt(obj, "aman_delay_seconds");
        f.cdm_status = extractJsonString(obj, "cdm_status");
        f.flow_measure = extractJsonString(obj, "flow_measure_ident");

        if (!f.callsign.empty()) {
            out.push_back(f);
        }
        pos = end + 1;
    }
    return true;
}

bool SWIMClient::ParseProgramsJSON(const std::string& json, std::vector<SWIMProgram>& out) {
    size_t pos = 0;
    while ((pos = json.find('{', pos)) != std::string::npos) {
        size_t end = json.find('}', pos);
        if (end == std::string::npos) break;

        std::string obj = json.substr(pos, end - pos + 1);
        SWIMProgram p;
        p.program_id = extractJsonInt(obj, "program_id");
        p.program_type = extractJsonString(obj, "program_type");
        p.airport = extractJsonString(obj, "airport");
        p.status = extractJsonString(obj, "status");

        if (p.program_id > 0) {
            out.push_back(p);
        }
        pos = end + 1;
    }
    return true;
}
