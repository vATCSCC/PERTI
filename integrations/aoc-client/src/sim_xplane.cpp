#include "sim_interface.h"

class XPlaneInterface : public SimInterface {
public:
    XPlaneInterface() : m_connected(false) {}
    ~XPlaneInterface() override { Disconnect(); }

    bool Connect() override {
        // In production, open UDP socket to X-Plane on port 49000
        m_connected = true;
        return true;
    }

    void Disconnect() override {
        m_connected = false;
    }

    bool IsConnected() const override { return m_connected; }

    bool Poll(FlightData& data) override {
        if (!m_connected) return false;
        // In production, read RREF/DREF UDP packets
        data.phase = FlightPhase::UNKNOWN;
        return false;
    }

    std::string GetSimName() const override { return "X-Plane"; }

private:
    bool m_connected;
};

// Factory implementation
SimInterface* CreateSimInterface(const std::string& simType) {
    if (simType == "xplane") return new XPlaneInterface();
    // Default to MSFS
    // Note: MSFSInterface is in sim_msfs.cpp, linked at build time
    return nullptr; // caller handles nullptr
}
