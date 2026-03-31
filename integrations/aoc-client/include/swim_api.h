#ifndef SWIM_API_H
#define SWIM_API_H

#include "telemetry.h"
#include <string>

class SWIMApi {
public:
    SWIMApi();
    ~SWIMApi();

    void SetEndpoint(const std::string& baseUrl, const std::string& apiKey);
    bool IngestTrack(const FlightData& data);
    bool IngestOOOI(const std::string& callsign, FlightPhase phase, time_t timestamp);
    bool FetchEDCT(const std::string& callsign, std::string& edct_out);
    bool IsConnected() const { return m_connected; }
    std::string GetLastError() const { return m_lastError; }

private:
    std::string m_baseUrl;
    std::string m_apiKey;
    bool m_connected;
    std::string m_lastError;

    std::string HttpPost(const std::string& url, const std::string& body);
    std::string HttpGet(const std::string& url);
};

#endif // SWIM_API_H
