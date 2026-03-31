#ifndef SWIM_CLIENT_H
#define SWIM_CLIENT_H

#include <string>
#include <vector>
#include <map>
#include <functional>

struct SWIMFlight {
    long long flight_uid;
    std::string callsign;
    std::string departure;
    std::string destination;
    std::string edct_utc;
    std::string ctot_utc;
    std::string tmi_status;
    int aman_sequence;
    int aman_delay_sec;
    std::string cdm_status;
    std::string flow_measure;
};

struct SWIMProgram {
    int program_id;
    std::string program_type;
    std::string airport;
    std::string status;
};

class SWIMClient {
public:
    SWIMClient();
    ~SWIMClient();

    void SetEndpoint(const std::string& baseUrl, const std::string& apiKey);
    bool FetchFlights(const std::string& airport, std::vector<SWIMFlight>& out);
    bool FetchPrograms(std::vector<SWIMProgram>& out);

    // Get cached flight data
    const SWIMFlight* GetFlight(const std::string& callsign) const;
    const std::vector<SWIMProgram>& GetPrograms() const { return m_programs; }

    bool IsConnected() const { return m_connected; }
    std::string GetLastError() const { return m_lastError; }

private:
    std::string m_baseUrl;
    std::string m_apiKey;
    bool m_connected;
    std::string m_lastError;
    std::map<std::string, SWIMFlight> m_flights;
    std::vector<SWIMProgram> m_programs;

    std::string HttpGet(const std::string& url);
    bool ParseFlightsJSON(const std::string& json, std::vector<SWIMFlight>& out);
    bool ParseProgramsJSON(const std::string& json, std::vector<SWIMProgram>& out);
};

#endif // SWIM_CLIENT_H
