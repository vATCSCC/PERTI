#include "telemetry.h"

const char* PhaseToString(FlightPhase phase) {
    switch (phase) {
        case FlightPhase::PREFLIGHT: return "PREFLIGHT";
        case FlightPhase::OUT:       return "OUT";
        case FlightPhase::TAXI_OUT:  return "TAXI_OUT";
        case FlightPhase::OFF:       return "OFF";
        case FlightPhase::ENROUTE:   return "ENROUTE";
        case FlightPhase::DESCENT:   return "DESCENT";
        case FlightPhase::ON:        return "ON";
        case FlightPhase::TAXI_IN:   return "TAXI_IN";
        case FlightPhase::IN:        return "IN";
        default:                     return "UNKNOWN";
    }
}

FlightPhase StringToPhase(const std::string& s) {
    if (s == "PREFLIGHT") return FlightPhase::PREFLIGHT;
    if (s == "OUT")       return FlightPhase::OUT;
    if (s == "TAXI_OUT")  return FlightPhase::TAXI_OUT;
    if (s == "OFF")       return FlightPhase::OFF;
    if (s == "ENROUTE")   return FlightPhase::ENROUTE;
    if (s == "DESCENT")   return FlightPhase::DESCENT;
    if (s == "ON")        return FlightPhase::ON;
    if (s == "TAXI_IN")   return FlightPhase::TAXI_IN;
    if (s == "IN")        return FlightPhase::IN;
    return FlightPhase::UNKNOWN;
}
