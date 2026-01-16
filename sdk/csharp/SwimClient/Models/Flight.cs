using System.Text.Json.Serialization;

namespace VatSim.Swim.Models;

/// <summary>
/// Flight phase enumeration
/// </summary>
public enum FlightPhase
{
    Preflight,
    Departing,
    Climbing,
    Enroute,
    Descending,
    Approach,
    Landed,
    Arrived
}

/// <summary>
/// TMI (Traffic Management Initiative) type
/// </summary>
public enum TmiType
{
    [JsonPropertyName("GS")]
    GroundStop,
    [JsonPropertyName("GDP")]
    Gdp,
    [JsonPropertyName("MIT")]
    Mit,
    [JsonPropertyName("MINIT")]
    Minit,
    [JsonPropertyName("AFP")]
    Afp
}

/// <summary>
/// Complete flight record
/// </summary>
public class Flight
{
    [JsonPropertyName("gufi")]
    public string Gufi { get; set; } = string.Empty;

    [JsonPropertyName("flight_uid")]
    public long FlightUid { get; set; }

    [JsonPropertyName("flight_key")]
    public string FlightKey { get; set; } = string.Empty;

    [JsonPropertyName("identity")]
    public FlightIdentity? Identity { get; set; }

    [JsonPropertyName("flight_plan")]
    public FlightPlan? FlightPlan { get; set; }

    [JsonPropertyName("position")]
    public FlightPosition? Position { get; set; }

    [JsonPropertyName("progress")]
    public FlightProgress? Progress { get; set; }

    [JsonPropertyName("times")]
    public FlightTimes? Times { get; set; }

    [JsonPropertyName("tmi")]
    public FlightTmi? Tmi { get; set; }

    // Convenience properties
    public string Callsign => Identity?.Callsign ?? string.Empty;
    public string Departure => FlightPlan?.Departure ?? string.Empty;
    public string Destination => FlightPlan?.Destination ?? string.Empty;
}

/// <summary>
/// Flight identity information
/// </summary>
public class FlightIdentity
{
    [JsonPropertyName("callsign")]
    public string Callsign { get; set; } = string.Empty;

    [JsonPropertyName("cid")]
    public int? Cid { get; set; }

    [JsonPropertyName("aircraft_type")]
    public string? AircraftType { get; set; }

    [JsonPropertyName("aircraft_icao")]
    public string? AircraftIcao { get; set; }

    [JsonPropertyName("weight_class")]
    public string? WeightClass { get; set; }

    [JsonPropertyName("wake_category")]
    public string? WakeCategory { get; set; }

    [JsonPropertyName("airline_icao")]
    public string? AirlineIcao { get; set; }

    [JsonPropertyName("airline_name")]
    public string? AirlineName { get; set; }
}

/// <summary>
/// Flight plan information
/// </summary>
public class FlightPlan
{
    [JsonPropertyName("departure")]
    public string Departure { get; set; } = string.Empty;

    [JsonPropertyName("destination")]
    public string Destination { get; set; } = string.Empty;

    [JsonPropertyName("alternate")]
    public string? Alternate { get; set; }

    [JsonPropertyName("cruise_altitude")]
    public int? CruiseAltitude { get; set; }

    [JsonPropertyName("cruise_speed")]
    public int? CruiseSpeed { get; set; }

    [JsonPropertyName("route")]
    public string? Route { get; set; }

    [JsonPropertyName("flight_rules")]
    public string? FlightRules { get; set; }

    [JsonPropertyName("departure_artcc")]
    public string? DepartureArtcc { get; set; }

    [JsonPropertyName("destination_artcc")]
    public string? DestinationArtcc { get; set; }

    [JsonPropertyName("arrival_fix")]
    public string? ArrivalFix { get; set; }

    [JsonPropertyName("arrival_procedure")]
    public string? ArrivalProcedure { get; set; }
}

/// <summary>
/// Current flight position
/// </summary>
public class FlightPosition
{
    [JsonPropertyName("latitude")]
    public double Latitude { get; set; }

    [JsonPropertyName("longitude")]
    public double Longitude { get; set; }

    [JsonPropertyName("altitude_ft")]
    public int AltitudeFt { get; set; }

    [JsonPropertyName("heading")]
    public int Heading { get; set; }

    [JsonPropertyName("ground_speed_kts")]
    public int GroundSpeedKts { get; set; }

    [JsonPropertyName("vertical_rate_fpm")]
    public int VerticalRateFpm { get; set; }

    [JsonPropertyName("current_artcc")]
    public string? CurrentArtcc { get; set; }
}

/// <summary>
/// Flight progress information
/// </summary>
public class FlightProgress
{
    [JsonPropertyName("phase")]
    public string Phase { get; set; } = "UNKNOWN";

    [JsonPropertyName("is_active")]
    public bool IsActive { get; set; }

    [JsonPropertyName("distance_remaining_nm")]
    public double? DistanceRemainingNm { get; set; }

    [JsonPropertyName("pct_complete")]
    public double? PctComplete { get; set; }

    [JsonPropertyName("time_to_dest_min")]
    public double? TimeToDestMin { get; set; }
}

/// <summary>
/// Flight times (OOOI + ETA)
/// </summary>
public class FlightTimes
{
    [JsonPropertyName("eta")]
    public string? Eta { get; set; }

    [JsonPropertyName("eta_runway")]
    public string? EtaRunway { get; set; }

    [JsonPropertyName("out")]
    public string? Out { get; set; }

    [JsonPropertyName("off")]
    public string? Off { get; set; }

    [JsonPropertyName("on")]
    public string? On { get; set; }

    [JsonPropertyName("in")]
    public string? In { get; set; }
}

/// <summary>
/// Flight TMI control status
/// </summary>
public class FlightTmi
{
    [JsonPropertyName("is_controlled")]
    public bool IsControlled { get; set; }

    [JsonPropertyName("ground_stop_held")]
    public bool GroundStopHeld { get; set; }

    [JsonPropertyName("control_type")]
    public string? ControlType { get; set; }

    [JsonPropertyName("edct")]
    public string? Edct { get; set; }

    [JsonPropertyName("delay_minutes")]
    public int? DelayMinutes { get; set; }
}
