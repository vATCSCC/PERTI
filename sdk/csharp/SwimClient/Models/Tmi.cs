using System.Text.Json.Serialization;

namespace VatSim.Swim.Models;

/// <summary>
/// Ground Stop program
/// </summary>
public class GroundStop
{
    [JsonPropertyName("type")]
    public string Type { get; set; } = "ground_stop";

    [JsonPropertyName("airport")]
    public string Airport { get; set; } = string.Empty;

    [JsonPropertyName("airport_name")]
    public string? AirportName { get; set; }

    [JsonPropertyName("artcc")]
    public string? Artcc { get; set; }

    [JsonPropertyName("reason")]
    public string? Reason { get; set; }

    [JsonPropertyName("probability_of_extension")]
    public int? ProbabilityOfExtension { get; set; }

    [JsonPropertyName("start_time")]
    public string? StartTime { get; set; }

    [JsonPropertyName("end_time")]
    public string? EndTime { get; set; }

    [JsonPropertyName("is_active")]
    public bool IsActive { get; set; } = true;
}

/// <summary>
/// Ground Delay Program
/// </summary>
public class GdpProgram
{
    [JsonPropertyName("type")]
    public string Type { get; set; } = "gdp";

    [JsonPropertyName("program_id")]
    public string ProgramId { get; set; } = string.Empty;

    [JsonPropertyName("airport")]
    public string Airport { get; set; } = string.Empty;

    [JsonPropertyName("airport_name")]
    public string? AirportName { get; set; }

    [JsonPropertyName("artcc")]
    public string? Artcc { get; set; }

    [JsonPropertyName("reason")]
    public string? Reason { get; set; }

    [JsonPropertyName("program_rate")]
    public int? ProgramRate { get; set; }

    [JsonPropertyName("delay_limit_minutes")]
    public int? DelayLimitMinutes { get; set; }

    [JsonPropertyName("average_delay_minutes")]
    public int? AverageDelayMinutes { get; set; }

    [JsonPropertyName("maximum_delay_minutes")]
    public int? MaximumDelayMinutes { get; set; }

    [JsonPropertyName("total_flights")]
    public int? TotalFlights { get; set; }

    [JsonPropertyName("affected_flights")]
    public int? AffectedFlights { get; set; }

    [JsonPropertyName("is_active")]
    public bool IsActive { get; set; } = true;
}

/// <summary>
/// Active TMI programs response
/// </summary>
public class TmiPrograms
{
    [JsonPropertyName("ground_stops")]
    public List<GroundStop> GroundStops { get; set; } = new();

    [JsonPropertyName("gdp_programs")]
    public List<GdpProgram> GdpPrograms { get; set; } = new();

    [JsonPropertyName("active_ground_stops")]
    public int ActiveGroundStops { get; set; }

    [JsonPropertyName("active_gdp_programs")]
    public int ActiveGdpPrograms { get; set; }

    [JsonPropertyName("total_controlled_airports")]
    public int TotalControlledAirports { get; set; }
}
