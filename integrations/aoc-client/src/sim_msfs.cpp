#include "sim_interface.h"
#include <cstring>

class MSFSInterface : public SimInterface {
public:
    MSFSInterface() : m_connected(false) {}
    ~MSFSInterface() override { Disconnect(); }

    bool Connect() override {
        // In production, call SimConnect_Open()
        // Stub: always succeeds for development
        m_connected = true;
        return true;
    }

    void Disconnect() override {
        m_connected = false;
    }

    bool IsConnected() const override { return m_connected; }

    bool Poll(FlightData& data) override {
        if (!m_connected) return false;
        // In production, call SimConnect_GetNextDispatch()
        // and read SIMCONNECT_RECV_SIMOBJECT_DATA
        // Stub: return empty data
        data.phase = FlightPhase::UNKNOWN;
        return false;
    }

    std::string GetSimName() const override { return "MSFS"; }

private:
    bool m_connected;
};
