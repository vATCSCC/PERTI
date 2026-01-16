using System.Text.Json.Serialization;

namespace VatSim.Swim.Models;

/// <summary>
/// Pagination information
/// </summary>
public class Pagination
{
    [JsonPropertyName("total")]
    public int Total { get; set; }

    [JsonPropertyName("page")]
    public int Page { get; set; }

    [JsonPropertyName("per_page")]
    public int PerPage { get; set; }

    [JsonPropertyName("total_pages")]
    public int TotalPages { get; set; }

    [JsonPropertyName("has_more")]
    public bool HasMore { get; set; }
}

/// <summary>
/// API response wrapper
/// </summary>
/// <typeparam name="T">Data type</typeparam>
public class ApiResponse<T>
{
    [JsonPropertyName("success")]
    public bool Success { get; set; }

    [JsonPropertyName("data")]
    public T? Data { get; set; }

    [JsonPropertyName("error")]
    public ApiError? Error { get; set; }

    [JsonPropertyName("timestamp")]
    public string? Timestamp { get; set; }
}

/// <summary>
/// API error details
/// </summary>
public class ApiError
{
    [JsonPropertyName("code")]
    public string? Code { get; set; }

    [JsonPropertyName("message")]
    public string? Message { get; set; }
}

/// <summary>
/// Flights list response
/// </summary>
public class FlightsResponse
{
    [JsonPropertyName("success")]
    public bool Success { get; set; }

    [JsonPropertyName("data")]
    public List<Flight> Flights { get; set; } = new();

    [JsonPropertyName("pagination")]
    public Pagination? Pagination { get; set; }

    [JsonPropertyName("timestamp")]
    public string? Timestamp { get; set; }
}

/// <summary>
/// GeoJSON Feature for position data
/// </summary>
public class GeoJsonFeature
{
    [JsonPropertyName("type")]
    public string Type { get; set; } = "Feature";

    [JsonPropertyName("id")]
    public long Id { get; set; }

    [JsonPropertyName("geometry")]
    public GeoJsonGeometry? Geometry { get; set; }

    [JsonPropertyName("properties")]
    public GeoJsonProperties? Properties { get; set; }

    // Convenience properties
    public double Latitude => Geometry?.Coordinates?.Count > 1 ? Geometry.Coordinates[1] : 0;
    public double Longitude => Geometry?.Coordinates?.Count > 0 ? Geometry.Coordinates[0] : 0;
    public int AltitudeFt => Geometry?.Coordinates?.Count > 2 ? (int)Geometry.Coordinates[2] : 0;
    public string Callsign => Properties?.Callsign ?? string.Empty;
}

/// <summary>
/// GeoJSON Geometry
/// </summary>
public class GeoJsonGeometry
{
    [JsonPropertyName("type")]
    public string Type { get; set; } = "Point";

    [JsonPropertyName("coordinates")]
    public List<double> Coordinates { get; set; } = new();
}

/// <summary>
/// GeoJSON Properties
/// </summary>
public class GeoJsonProperties
{
    [JsonPropertyName("flight_uid")]
    public long FlightUid { get; set; }

    [JsonPropertyName("callsign")]
    public string? Callsign { get; set; }

    [JsonPropertyName("aircraft")]
    public string? Aircraft { get; set; }

    [JsonPropertyName("departure")]
    public string? Departure { get; set; }

    [JsonPropertyName("destination")]
    public string? Destination { get; set; }

    [JsonPropertyName("phase")]
    public string? Phase { get; set; }

    [JsonPropertyName("altitude")]
    public int? Altitude { get; set; }

    [JsonPropertyName("heading")]
    public int? Heading { get; set; }

    [JsonPropertyName("groundspeed")]
    public int? Groundspeed { get; set; }

    [JsonPropertyName("distance_remaining_nm")]
    public double? DistanceRemainingNm { get; set; }

    [JsonPropertyName("tmi_status")]
    public string? TmiStatus { get; set; }
}

/// <summary>
/// Positions (GeoJSON FeatureCollection) response
/// </summary>
public class PositionsResponse
{
    [JsonPropertyName("type")]
    public string Type { get; set; } = "FeatureCollection";

    [JsonPropertyName("features")]
    public List<GeoJsonFeature> Features { get; set; } = new();

    [JsonPropertyName("metadata")]
    public PositionsMetadata? Metadata { get; set; }

    public int Count => Metadata?.Count ?? Features.Count;
}

/// <summary>
/// Positions metadata
/// </summary>
public class PositionsMetadata
{
    [JsonPropertyName("count")]
    public int Count { get; set; }

    [JsonPropertyName("timestamp")]
    public string? Timestamp { get; set; }

    [JsonPropertyName("source")]
    public string? Source { get; set; }
}

/// <summary>
/// Ingest operation result
/// </summary>
public class IngestResult
{
    [JsonPropertyName("processed")]
    public int Processed { get; set; }

    [JsonPropertyName("created")]
    public int Created { get; set; }

    [JsonPropertyName("updated")]
    public int Updated { get; set; }

    [JsonPropertyName("errors")]
    public int Errors { get; set; }

    [JsonPropertyName("error_details")]
    public List<string> ErrorDetails { get; set; } = new();
}
