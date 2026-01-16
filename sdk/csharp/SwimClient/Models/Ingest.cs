using System.Text.Json.Serialization;

namespace VatSim.Swim.Models;

/// <summary>
/// Flight data for ingest operations
/// </summary>
public class FlightIngest
{
    [JsonPropertyName("callsign")]
    public string Callsign { get; set; } = string.Empty;

    [JsonPropertyName("dept_icao")]
    public string DeptIcao { get; set; } = string.Empty;

    [JsonPropertyName("dest_icao")]
    public string DestIcao { get; set; } = string.Empty;

    [JsonPropertyName("cid")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public int? Cid { get; set; }

    [JsonPropertyName("aircraft_type")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public string? AircraftType { get; set; }

    [JsonPropertyName("route")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public string? Route { get; set; }

    [JsonPropertyName("phase")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public string? Phase { get; set; }

    [JsonPropertyName("is_active")]
    public bool IsActive { get; set; } = true;

    [JsonPropertyName("latitude")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public double? Latitude { get; set; }

    [JsonPropertyName("longitude")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public double? Longitude { get; set; }

    [JsonPropertyName("altitude_ft")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public int? AltitudeFt { get; set; }

    [JsonPropertyName("heading_deg")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public int? HeadingDeg { get; set; }

    [JsonPropertyName("groundspeed_kts")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public int? GroundspeedKts { get; set; }

    [JsonPropertyName("vertical_rate_fpm")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public int? VerticalRateFpm { get; set; }

    [JsonPropertyName("out_utc")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public string? OutUtc { get; set; }

    [JsonPropertyName("off_utc")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public string? OffUtc { get; set; }

    [JsonPropertyName("on_utc")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public string? OnUtc { get; set; }

    [JsonPropertyName("in_utc")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public string? InUtc { get; set; }

    [JsonPropertyName("eta_utc")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public string? EtaUtc { get; set; }

    [JsonPropertyName("etd_utc")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public string? EtdUtc { get; set; }
}

/// <summary>
/// Track/position data for ingest operations
/// </summary>
public class TrackIngest
{
    [JsonPropertyName("callsign")]
    public string Callsign { get; set; } = string.Empty;

    [JsonPropertyName("latitude")]
    public double Latitude { get; set; }

    [JsonPropertyName("longitude")]
    public double Longitude { get; set; }

    [JsonPropertyName("altitude_ft")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public int? AltitudeFt { get; set; }

    [JsonPropertyName("ground_speed_kts")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public int? GroundSpeedKts { get; set; }

    [JsonPropertyName("heading_deg")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public int? HeadingDeg { get; set; }

    [JsonPropertyName("vertical_rate_fpm")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public int? VerticalRateFpm { get; set; }

    [JsonPropertyName("squawk")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public string? Squawk { get; set; }

    [JsonPropertyName("track_source")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public string? TrackSource { get; set; }

    [JsonPropertyName("timestamp")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public string? Timestamp { get; set; }
}

/// <summary>
/// Ingest request wrapper
/// </summary>
public class FlightIngestRequest
{
    [JsonPropertyName("flights")]
    public List<FlightIngest> Flights { get; set; } = new();
}

/// <summary>
/// Track ingest request wrapper
/// </summary>
public class TrackIngestRequest
{
    [JsonPropertyName("tracks")]
    public List<TrackIngest> Tracks { get; set; } = new();
}
