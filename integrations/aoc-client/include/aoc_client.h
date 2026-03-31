#ifndef AOC_CLIENT_H
#define AOC_CLIENT_H

#include "swim_api.h"
#include "sim_interface.h"
#include "locale.h"
#include <string>
#include <atomic>

struct AOCConfig {
    std::string swim_url;
    std::string swim_api_key;
    std::string simulator;
    int poll_interval;
    std::string locale;
    bool debug;
    bool position_enabled;
    bool oooi_enabled;
    int position_interval;
    double altitude_threshold;
    double distance_threshold;
};

class AOCClient {
public:
    AOCClient();
    ~AOCClient();

    bool LoadConfig(const std::string& path);
    bool Initialize();
    void Run();
    void Stop();

private:
    AOCConfig m_config;
    SWIMApi m_api;
    SimInterface* m_sim;
    Locale m_locale;
    std::atomic<bool> m_running;
    FlightData m_lastData;
    time_t m_lastPositionPush;

    void ProcessTelemetry(const FlightData& data);
    bool ShouldReportPosition(const FlightData& current) const;
    void DetectPhaseChange(const FlightData& prev, const FlightData& current);
};

#endif // AOC_CLIENT_H
