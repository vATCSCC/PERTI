#ifndef SIM_INTERFACE_H
#define SIM_INTERFACE_H

#include "telemetry.h"
#include <functional>
#include <string>

// Abstract simulator interface
class SimInterface {
public:
    virtual ~SimInterface() = default;
    virtual bool Connect() = 0;
    virtual void Disconnect() = 0;
    virtual bool IsConnected() const = 0;
    virtual bool Poll(FlightData& data) = 0;
    virtual std::string GetSimName() const = 0;
};

// Factory
SimInterface* CreateSimInterface(const std::string& simType);

#endif // SIM_INTERFACE_H
