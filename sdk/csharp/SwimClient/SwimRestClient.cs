using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using System.Web;
using VatSim.Swim.Models;

namespace VatSim.Swim;

/// <summary>
/// Exception thrown for SWIM API errors
/// </summary>
public class SwimApiException : Exception
{
    public int StatusCode { get; }
    public string? ErrorCode { get; }

    public SwimApiException(int statusCode, string message, string? errorCode = null)
        : base($"[{statusCode}] {errorCode ?? "ERROR"}: {message}")
    {
        StatusCode = statusCode;
        ErrorCode = errorCode;
    }
}

/// <summary>
/// SWIM REST API Client
/// </summary>
/// <example>
/// <code>
/// using var client = new SwimRestClient("your-api-key");
/// var flights = await client.GetFlightsAsync(destIcao: "KJFK");
/// foreach (var flight in flights)
/// {
///     Console.WriteLine($"{flight.Callsign}: {flight.Departure} -> {flight.Destination}");
/// }
/// </code>
/// </example>
public class SwimRestClient : IDisposable
{
    private const string DefaultBaseUrl = "https://perti.vatcscc.org/api/swim/v1";
    
    private readonly HttpClient _httpClient;
    private readonly string _baseUrl;
    private readonly JsonSerializerOptions _jsonOptions;
    private bool _disposed;

    /// <summary>
    /// Creates a new SWIM REST API client
    /// </summary>
    /// <param name="apiKey">API key for authentication</param>
    /// <param name="baseUrl">API base URL (optional)</param>
    /// <param name="timeout">Request timeout (optional, default 30s)</param>
    public SwimRestClient(string apiKey, string? baseUrl = null, TimeSpan? timeout = null)
    {
        if (string.IsNullOrEmpty(apiKey))
            throw new ArgumentNullException(nameof(apiKey));

        _baseUrl = (baseUrl ?? DefaultBaseUrl).TrimEnd('/');
        
        _httpClient = new HttpClient
        {
            Timeout = timeout ?? TimeSpan.FromSeconds(30)
        };
        _httpClient.DefaultRequestHeaders.Authorization = 
            new AuthenticationHeaderValue("Bearer", apiKey);
        _httpClient.DefaultRequestHeaders.Accept.Add(
            new MediaTypeWithQualityHeaderValue("application/json"));

        _jsonOptions = new JsonSerializerOptions
        {
            PropertyNamingPolicy = JsonNamingPolicy.CamelCase,
            PropertyNameCaseInsensitive = true
        };
    }

    #region Flight Methods

    /// <summary>
    /// Get list of flights with optional filtering
    /// </summary>
    public async Task<List<Flight>> GetFlightsAsync(
        string status = "active",
        string? deptIcao = null,
        string? destIcao = null,
        string? artcc = null,
        string? callsign = null,
        bool? tmiControlled = null,
        string? phase = null,
        string format = "legacy",
        int page = 1,
        int perPage = 100,
        CancellationToken cancellationToken = default)
    {
        var response = await GetFlightsPaginatedAsync(
            status, deptIcao, destIcao, artcc, callsign, 
            tmiControlled, phase, format, page, perPage, cancellationToken);
        return response.Flights;
    }

    /// <summary>
    /// Get flights with pagination information
    /// </summary>
    public async Task<FlightsResponse> GetFlightsPaginatedAsync(
        string status = "active",
        string? deptIcao = null,
        string? destIcao = null,
        string? artcc = null,
        string? callsign = null,
        bool? tmiControlled = null,
        string? phase = null,
        string format = "legacy",
        int page = 1,
        int perPage = 100,
        CancellationToken cancellationToken = default)
    {
        var query = BuildQuery(
            ("status", status),
            ("dept_icao", deptIcao),
            ("dest_icao", destIcao),
            ("artcc", artcc),
            ("callsign", callsign),
            ("tmi_controlled", tmiControlled?.ToString().ToLower()),
            ("phase", phase),
            ("format", format),
            ("page", page.ToString()),
            ("per_page", perPage.ToString())
        );

        return await GetAsync<FlightsResponse>($"/flights{query}", cancellationToken);
    }

    /// <summary>
    /// Get single flight by GUFI or flight key
    /// </summary>
    public async Task<Flight?> GetFlightAsync(
        string? gufi = null,
        string? flightKey = null,
        string format = "legacy",
        CancellationToken cancellationToken = default)
    {
        if (gufi == null && flightKey == null)
            throw new ArgumentException("Must provide either gufi or flightKey");

        var query = BuildQuery(
            ("gufi", gufi),
            ("flight_key", flightKey),
            ("format", format)
        );

        try
        {
            var response = await GetAsync<ApiResponse<Flight>>($"/flight{query}", cancellationToken);
            return response.Data;
        }
        catch (SwimApiException ex) when (ex.StatusCode == 404)
        {
            return null;
        }
    }

    /// <summary>
    /// Get all flights across all pages
    /// </summary>
    public async Task<List<Flight>> GetAllFlightsAsync(
        string status = "active",
        string? deptIcao = null,
        string? destIcao = null,
        string? artcc = null,
        CancellationToken cancellationToken = default)
    {
        var allFlights = new List<Flight>();
        int page = 1;
        const int perPage = 1000;

        while (true)
        {
            var response = await GetFlightsPaginatedAsync(
                status, deptIcao, destIcao, artcc, null, null, null, 
                "legacy", page, perPage, cancellationToken);
            
            allFlights.AddRange(response.Flights);
            
            if (response.Pagination == null || !response.Pagination.HasMore)
                break;
            
            page++;
        }

        return allFlights;
    }

    #endregion

    #region Position Methods

    /// <summary>
    /// Get bulk flight positions as GeoJSON
    /// </summary>
    public async Task<PositionsResponse> GetPositionsAsync(
        string? deptIcao = null,
        string? destIcao = null,
        string? artcc = null,
        string? bounds = null,
        bool? tmiControlled = null,
        string? phase = null,
        bool includeRoute = false,
        CancellationToken cancellationToken = default)
    {
        var query = BuildQuery(
            ("dept_icao", deptIcao),
            ("dest_icao", destIcao),
            ("artcc", artcc),
            ("bounds", bounds),
            ("tmi_controlled", tmiControlled?.ToString().ToLower()),
            ("phase", phase),
            ("include_route", includeRoute ? "true" : null)
        );

        return await GetAsync<PositionsResponse>($"/positions{query}", cancellationToken);
    }

    /// <summary>
    /// Get positions within bounding box
    /// </summary>
    public async Task<PositionsResponse> GetPositionsBboxAsync(
        double north,
        double south,
        double east,
        double west,
        string? artcc = null,
        CancellationToken cancellationToken = default)
    {
        var bounds = $"{west},{south},{east},{north}";
        return await GetPositionsAsync(bounds: bounds, artcc: artcc, cancellationToken: cancellationToken);
    }

    #endregion

    #region TMI Methods

    /// <summary>
    /// Get active TMI programs
    /// </summary>
    public async Task<TmiPrograms> GetTmiProgramsAsync(
        string type = "all",
        string? airport = null,
        string? artcc = null,
        bool includeHistory = false,
        CancellationToken cancellationToken = default)
    {
        var query = BuildQuery(
            ("type", type),
            ("airport", airport),
            ("artcc", artcc),
            ("include_history", includeHistory ? "true" : null)
        );

        var response = await GetAsync<ApiResponse<TmiPrograms>>($"/tmi/programs{query}", cancellationToken);
        return response.Data ?? new TmiPrograms();
    }

    /// <summary>
    /// Get flights under TMI control
    /// </summary>
    public async Task<List<Flight>> GetTmiControlledFlightsAsync(
        string? airport = null,
        string? controlType = null,
        CancellationToken cancellationToken = default)
    {
        var query = BuildQuery(
            ("airport", airport),
            ("control_type", controlType)
        );

        var response = await GetAsync<ApiResponse<List<Flight>>>($"/tmi/controlled{query}", cancellationToken);
        return response.Data ?? new List<Flight>();
    }

    #endregion

    #region Ingest Methods

    /// <summary>
    /// Ingest flight data (requires write access)
    /// </summary>
    public async Task<IngestResult> IngestFlightsAsync(
        IEnumerable<FlightIngest> flights,
        CancellationToken cancellationToken = default)
    {
        var request = new FlightIngestRequest { Flights = flights.ToList() };
        var response = await PostAsync<ApiResponse<IngestResult>>("/ingest/adl", request, cancellationToken);
        return response.Data ?? new IngestResult();
    }

    /// <summary>
    /// Ingest track/position data (requires write access)
    /// </summary>
    public async Task<IngestResult> IngestTracksAsync(
        IEnumerable<TrackIngest> tracks,
        CancellationToken cancellationToken = default)
    {
        var request = new TrackIngestRequest { Tracks = tracks.ToList() };
        var response = await PostAsync<ApiResponse<IngestResult>>("/ingest/track", request, cancellationToken);
        return response.Data ?? new IngestResult();
    }

    #endregion

    #region Internal Methods

    private async Task<T> GetAsync<T>(string endpoint, CancellationToken cancellationToken)
    {
        var url = $"{_baseUrl}{endpoint}";
        var response = await _httpClient.GetAsync(url, cancellationToken);
        return await HandleResponse<T>(response);
    }

    private async Task<T> PostAsync<T>(string endpoint, object body, CancellationToken cancellationToken)
    {
        var url = $"{_baseUrl}{endpoint}";
        var json = JsonSerializer.Serialize(body, _jsonOptions);
        var content = new StringContent(json, Encoding.UTF8, "application/json");
        var response = await _httpClient.PostAsync(url, content, cancellationToken);
        return await HandleResponse<T>(response);
    }

    private async Task<T> HandleResponse<T>(HttpResponseMessage response)
    {
        var content = await response.Content.ReadAsStringAsync();

        if (!response.IsSuccessStatusCode)
        {
            try
            {
                var error = JsonSerializer.Deserialize<ApiResponse<object>>(content, _jsonOptions);
                throw new SwimApiException(
                    (int)response.StatusCode,
                    error?.Error?.Message ?? "Unknown error",
                    error?.Error?.Code);
            }
            catch (JsonException)
            {
                throw new SwimApiException((int)response.StatusCode, content);
            }
        }

        return JsonSerializer.Deserialize<T>(content, _jsonOptions) 
            ?? throw new SwimApiException((int)response.StatusCode, "Empty response");
    }

    private static string BuildQuery(params (string key, string? value)[] parameters)
    {
        var queryParts = parameters
            .Where(p => !string.IsNullOrEmpty(p.value))
            .Select(p => $"{HttpUtility.UrlEncode(p.key)}={HttpUtility.UrlEncode(p.value)}");

        var query = string.Join("&", queryParts);
        return string.IsNullOrEmpty(query) ? "" : $"?{query}";
    }

    #endregion

    #region IDisposable

    public void Dispose()
    {
        Dispose(true);
        GC.SuppressFinalize(this);
    }

    protected virtual void Dispose(bool disposing)
    {
        if (!_disposed)
        {
            if (disposing)
            {
                _httpClient.Dispose();
            }
            _disposed = true;
        }
    }

    #endregion
}
