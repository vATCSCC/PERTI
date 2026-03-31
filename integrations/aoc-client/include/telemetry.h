#ifndef TELEMETRY_H
#define TELEMETRY_H

#include <string>
#include <ctime>

// Flight phase (OOOI)
enum class FlightPhase {
    PREFLIGHT,    // Before pushback
    OUT,          // Pushback (out of gate)
    TAXI_OUT,     // Taxiing to runway
    OFF,          // Airborne (takeoff)
    ENROUTE,      // Cruise
    DESCENT,      // Descending
    ON,           // Landed (on ground)
    TAXI_IN,      // Taxiing to gate
    IN,           // At gate (in)
    UNKNOWN
};

struct Position {
    double latitude;
    double longitude;
    double altitude_ft;
    double ground_speed_kts;
    double heading;
    double vertical_speed_fpm;
    bool on_ground;
    time_t timestamp;
};

struct FlightData {
    std::string callsign;
    std::string aircraft_type;
    std::string departure;
    std::string destination;
    std::string route;
    Position position;
    FlightPhase phase;
    time_t out_time;
    time_t off_time;
    time_t on_time;
    time_t in_time;
};

const char* PhaseToString(FlightPhase phase);
FlightPhase StringToPhase(const std::string& s);

#endif // TELEMETRY_H
