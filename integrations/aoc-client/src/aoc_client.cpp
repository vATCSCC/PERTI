#include "aoc_client.h"
#include <fstream>
#include <sstream>
#include <iostream>
#include <cmath>
#include <ctime>
#include <cstring>

#ifdef _WIN32
#include <windows.h>
#else
#include <unistd.h>
#endif

AOCClient::AOCClient()
    : m_sim(nullptr)
    , m_running(false)
    , m_lastPositionPush(0) {
    memset(&m_lastData, 0, sizeof(m_lastData));
    m_lastData.phase = FlightPhase::UNKNOWN;
}

AOCClient::~AOCClient() {
    delete m_sim;
}

bool AOCClient::LoadConfig(const std::string& path) {
    std::ifstream file(path);
    if (!file.is_open()) return false;

    std::string line, section;
    while (std::getline(file, line)) {
        size_t start = line.find_first_not_of(" \t\r\n");
        if (start == std::string::npos) continue;
        line = line.substr(start);
        if (line[0] == ';' || line[0] == '#') continue;
        if (line[0] == '[') {
            size_t end = line.find(']');
            if (end != std::string::npos) section = line.substr(1, end - 1);
            continue;
        }
        size_t eq = line.find('=');
        if (eq == std::string::npos) continue;

        std::string key = line.substr(0, eq);
        std::string val = line.substr(eq + 1);
        key.erase(key.find_last_not_of(" \t") + 1);
        size_t vs = val.find_first_not_of(" \t");
        if (vs != std::string::npos) val = val.substr(vs);
        val.erase(val.find_last_not_of(" \t\r\n") + 1);

        if (section == "swim") {
            if (key == "base_url") m_config.swim_url = val;
            else if (key == "api_key") m_config.swim_api_key = val;
        } else if (section == "client") {
            if (key == "simulator") m_config.simulator = val;
            else if (key == "poll_interval") m_config.poll_interval = std::stoi(val);
            else if (key == "locale") m_config.locale = val;
            else if (key == "debug") m_config.debug = (val == "true" || val == "1");
        } else if (section == "telemetry") {
            if (key == "position_enabled") m_config.position_enabled = (val == "true" || val == "1");
            else if (key == "oooi_enabled") m_config.oooi_enabled = (val == "true" || val == "1");
            else if (key == "position_interval") m_config.position_interval = std::stoi(val);
            else if (key == "altitude_threshold") m_config.altitude_threshold = std::stod(val);
            else if (key == "distance_threshold") m_config.distance_threshold = std::stod(val);
        }
    }
    return true;
}

bool AOCClient::Initialize() {
    m_api.SetEndpoint(m_config.swim_url, m_config.swim_api_key);

    // Load locale
    m_locale.Load(m_config.locale.empty() ? "en-US" : m_config.locale, ".");

    std::cout << m_locale.T("client.name") << " v" << m_locale.T("client.version") << std::endl;

    // Create simulator interface
    m_sim = CreateSimInterface(m_config.simulator);
    if (!m_sim) {
        std::cerr << m_locale.T("errors.sim_connect_failed", "simulator", m_config.simulator) << std::endl;
        return false;
    }

    std::cout << m_locale.T("status.connecting_sim", "simulator", m_sim->GetSimName()) << std::endl;
    if (!m_sim->Connect()) {
        std::cerr << m_locale.T("errors.sim_connect_failed", "simulator", m_sim->GetSimName()) << std::endl;
        return false;
    }
    std::cout << m_locale.T("status.connected_sim", "simulator", m_sim->GetSimName()) << std::endl;

    return true;
}

void AOCClient::Run() {
    m_running = true;
    while (m_running) {
        FlightData data;
        if (m_sim && m_sim->Poll(data)) {
            ProcessTelemetry(data);
        }

        // Sleep for poll interval
        #ifdef _WIN32
        Sleep(m_config.poll_interval * 1000);
        #else
        sleep(m_config.poll_interval);
        #endif
    }
}

void AOCClient::Stop() {
    m_running = false;
    if (m_sim) m_sim->Disconnect();
}

void AOCClient::ProcessTelemetry(const FlightData& data) {
    // Check for OOOI phase changes
    if (m_config.oooi_enabled) {
        DetectPhaseChange(m_lastData, data);
    }

    // Push position if enabled and threshold exceeded
    if (m_config.position_enabled && ShouldReportPosition(data)) {
        if (m_api.IngestTrack(data)) {
            m_lastPositionPush = time(nullptr);
            if (m_config.debug) {
                std::cout << m_locale.T("status.push_position") << std::endl;
            }
        }
    }

    m_lastData = data;
}

bool AOCClient::ShouldReportPosition(const FlightData& current) const {
    time_t now = time(nullptr);
    if (now - m_lastPositionPush < m_config.position_interval) return false;

    double altDiff = std::abs(current.position.altitude_ft - m_lastData.position.altitude_ft);
    if (altDiff > m_config.altitude_threshold) return true;

    // Simple distance approximation
    double dlat = current.position.latitude - m_lastData.position.latitude;
    double dlon = current.position.longitude - m_lastData.position.longitude;
    double distNm = std::sqrt(dlat * dlat + dlon * dlon) * 60.0;
    if (distNm > m_config.distance_threshold) return true;

    return (now - m_lastPositionPush >= m_config.position_interval);
}

void AOCClient::DetectPhaseChange(const FlightData& prev, const FlightData& current) {
    if (prev.phase != current.phase && current.phase != FlightPhase::UNKNOWN) {
        time_t now = time(nullptr);
        m_api.IngestOOOI(current.callsign, current.phase, now);
        if (m_config.debug) {
            std::cout << m_locale.T("status.push_oooi", "phase", PhaseToString(current.phase)) << std::endl;
        }
    }
}
