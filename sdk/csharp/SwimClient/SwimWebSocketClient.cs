using System.Net.WebSockets;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace VatSim.Swim;

/// <summary>
/// WebSocket event types
/// </summary>
public static class SwimEventTypes
{
    public const string Connected = "connected";
    public const string FlightCreated = "flight.created";
    public const string FlightDeparted = "flight.departed";
    public const string FlightArrived = "flight.arrived";
    public const string FlightDeleted = "flight.deleted";
    public const string FlightPositions = "flight.positions";
    public const string TmiIssued = "tmi.issued";
    public const string TmiModified = "tmi.modified";
    public const string TmiReleased = "tmi.released";
    public const string SystemHeartbeat = "system.heartbeat";
    public const string Error = "error";
}

/// <summary>
/// WebSocket message
/// </summary>
public class SwimMessage
{
    [JsonPropertyName("type")]
    public string Type { get; set; } = string.Empty;

    [JsonPropertyName("timestamp")]
    public string? Timestamp { get; set; }

    [JsonPropertyName("data")]
    public JsonElement Data { get; set; }
}

/// <summary>
/// Flight event data
/// </summary>
public class FlightEventData
{
    [JsonPropertyName("callsign")]
    public string Callsign { get; set; } = string.Empty;

    [JsonPropertyName("flight_uid")]
    public long FlightUid { get; set; }

    [JsonPropertyName("dep")]
    public string? Dep { get; set; }

    [JsonPropertyName("arr")]
    public string? Arr { get; set; }

    [JsonPropertyName("off_utc")]
    public string? OffUtc { get; set; }

    [JsonPropertyName("in_utc")]
    public string? InUtc { get; set; }
}

/// <summary>
/// Position data
/// </summary>
public class PositionData
{
    [JsonPropertyName("callsign")]
    public string Callsign { get; set; } = string.Empty;

    [JsonPropertyName("flight_uid")]
    public long FlightUid { get; set; }

    [JsonPropertyName("latitude")]
    public double Latitude { get; set; }

    [JsonPropertyName("longitude")]
    public double Longitude { get; set; }

    [JsonPropertyName("altitude_ft")]
    public int AltitudeFt { get; set; }

    [JsonPropertyName("groundspeed_kts")]
    public int GroundspeedKts { get; set; }

    [JsonPropertyName("heading_deg")]
    public int HeadingDeg { get; set; }

    [JsonPropertyName("dep")]
    public string? Dep { get; set; }

    [JsonPropertyName("arr")]
    public string? Arr { get; set; }
}

/// <summary>
/// Positions batch data
/// </summary>
public class PositionsBatchData
{
    [JsonPropertyName("count")]
    public int Count { get; set; }

    [JsonPropertyName("positions")]
    public List<PositionData> Positions { get; set; } = new();
}

/// <summary>
/// TMI event data
/// </summary>
public class TmiEventData
{
    [JsonPropertyName("program_id")]
    public string ProgramId { get; set; } = string.Empty;

    [JsonPropertyName("program_type")]
    public string ProgramType { get; set; } = string.Empty;

    [JsonPropertyName("airport")]
    public string Airport { get; set; } = string.Empty;

    [JsonPropertyName("start_time")]
    public string? StartTime { get; set; }

    [JsonPropertyName("end_time")]
    public string? EndTime { get; set; }

    [JsonPropertyName("reason")]
    public string? Reason { get; set; }
}

/// <summary>
/// Heartbeat event data
/// </summary>
public class HeartbeatData
{
    [JsonPropertyName("connected_clients")]
    public int ConnectedClients { get; set; }

    [JsonPropertyName("uptime_seconds")]
    public long UptimeSeconds { get; set; }
}

/// <summary>
/// Connection info
/// </summary>
public class ConnectionInfo
{
    [JsonPropertyName("client_id")]
    public string ClientId { get; set; } = string.Empty;

    [JsonPropertyName("server_time")]
    public string? ServerTime { get; set; }

    [JsonPropertyName("version")]
    public string? Version { get; set; }
}

/// <summary>
/// Subscription filters
/// </summary>
public class SubscriptionFilters
{
    [JsonPropertyName("airports")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public List<string>? Airports { get; set; }

    [JsonPropertyName("artccs")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public List<string>? Artccs { get; set; }

    [JsonPropertyName("callsign_prefix")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public List<string>? CallsignPrefix { get; set; }

    [JsonPropertyName("bbox")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public BoundingBox? Bbox { get; set; }
}

/// <summary>
/// Geographic bounding box
/// </summary>
public class BoundingBox
{
    [JsonPropertyName("north")]
    public double North { get; set; }

    [JsonPropertyName("south")]
    public double South { get; set; }

    [JsonPropertyName("east")]
    public double East { get; set; }

    [JsonPropertyName("west")]
    public double West { get; set; }
}

/// <summary>
/// SWIM WebSocket Client for real-time flight data streaming
/// </summary>
/// <example>
/// <code>
/// await using var client = new SwimWebSocketClient("your-api-key");
/// 
/// client.OnFlightDeparted += (sender, e) =>
///     Console.WriteLine($"{e.Data.Callsign} departed {e.Data.Dep}");
/// 
/// client.OnHeartbeat += (sender, e) =>
///     Console.WriteLine($"{e.Data.ConnectedClients} clients connected");
/// 
/// await client.ConnectAsync();
/// await client.SubscribeAsync(new[] { "flight.departed", "system.heartbeat" });
/// 
/// // Listen for events
/// await client.RunAsync(cancellationToken);
/// </code>
/// </example>
public class SwimWebSocketClient : IAsyncDisposable
{
    private const string DefaultWebSocketUrl = "wss://perti.vatcscc.org/api/swim/v1/ws";
    
    private readonly string _apiKey;
    private readonly string _wsUrl;
    private readonly TimeSpan _reconnectInterval;
    private readonly bool _autoReconnect;
    private readonly JsonSerializerOptions _jsonOptions;
    
    private ClientWebSocket? _webSocket;
    private CancellationTokenSource? _cts;
    private bool _disposed;

    public string? ClientId { get; private set; }
    public bool IsConnected => _webSocket?.State == WebSocketState.Open;

    // Events
    public event EventHandler<SwimEventArgs<ConnectionInfo>>? OnConnected;
    public event EventHandler<SwimEventArgs<FlightEventData>>? OnFlightCreated;
    public event EventHandler<SwimEventArgs<FlightEventData>>? OnFlightDeparted;
    public event EventHandler<SwimEventArgs<FlightEventData>>? OnFlightArrived;
    public event EventHandler<SwimEventArgs<FlightEventData>>? OnFlightDeleted;
    public event EventHandler<SwimEventArgs<PositionsBatchData>>? OnFlightPositions;
    public event EventHandler<SwimEventArgs<TmiEventData>>? OnTmiIssued;
    public event EventHandler<SwimEventArgs<TmiEventData>>? OnTmiReleased;
    public event EventHandler<SwimEventArgs<HeartbeatData>>? OnHeartbeat;
    public event EventHandler<SwimErrorEventArgs>? OnError;
    public event EventHandler? OnDisconnected;

    public SwimWebSocketClient(
        string apiKey,
        string? wsUrl = null,
        TimeSpan? reconnectInterval = null,
        bool autoReconnect = true)
    {
        _apiKey = apiKey ?? throw new ArgumentNullException(nameof(apiKey));
        _wsUrl = wsUrl ?? DefaultWebSocketUrl;
        _reconnectInterval = reconnectInterval ?? TimeSpan.FromSeconds(5);
        _autoReconnect = autoReconnect;
        
        _jsonOptions = new JsonSerializerOptions
        {
            PropertyNamingPolicy = JsonNamingPolicy.CamelCase,
            PropertyNameCaseInsensitive = true
        };
    }

    /// <summary>
    /// Connect to the WebSocket server
    /// </summary>
    public async Task ConnectAsync(CancellationToken cancellationToken = default)
    {
        _webSocket?.Dispose();
        _webSocket = new ClientWebSocket();
        
        var uri = new Uri($"{_wsUrl}?api_key={_apiKey}");
        await _webSocket.ConnectAsync(uri, cancellationToken);
    }

    /// <summary>
    /// Subscribe to event channels
    /// </summary>
    public async Task SubscribeAsync(
        IEnumerable<string> channels,
        SubscriptionFilters? filters = null,
        CancellationToken cancellationToken = default)
    {
        var message = new
        {
            action = "subscribe",
            channels = channels.ToList(),
            filters = filters ?? new SubscriptionFilters()
        };
        
        await SendAsync(message, cancellationToken);
    }

    /// <summary>
    /// Unsubscribe from channels
    /// </summary>
    public async Task UnsubscribeAsync(
        IEnumerable<string>? channels = null,
        CancellationToken cancellationToken = default)
    {
        var message = new { action = "unsubscribe", channels = channels?.ToList() };
        await SendAsync(message, cancellationToken);
    }

    /// <summary>
    /// Send ping to server
    /// </summary>
    public async Task PingAsync(CancellationToken cancellationToken = default)
    {
        await SendAsync(new { action = "ping" }, cancellationToken);
    }

    /// <summary>
    /// Run the message loop
    /// </summary>
    public async Task RunAsync(CancellationToken cancellationToken = default)
    {
        _cts = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        var buffer = new byte[65536];

        while (!_cts.Token.IsCancellationRequested && IsConnected)
        {
            try
            {
                var result = await _webSocket!.ReceiveAsync(
                    new ArraySegment<byte>(buffer), _cts.Token);

                if (result.MessageType == WebSocketMessageType.Close)
                {
                    await HandleDisconnect(_cts.Token);
                    break;
                }

                if (result.MessageType == WebSocketMessageType.Text)
                {
                    var json = Encoding.UTF8.GetString(buffer, 0, result.Count);
                    ProcessMessage(json);
                }
            }
            catch (OperationCanceledException)
            {
                break;
            }
            catch (WebSocketException)
            {
                await HandleDisconnect(_cts.Token);
            }
        }
    }

    /// <summary>
    /// Disconnect from the server
    /// </summary>
    public async Task DisconnectAsync()
    {
        _cts?.Cancel();
        
        if (_webSocket?.State == WebSocketState.Open)
        {
            await _webSocket.CloseAsync(
                WebSocketCloseStatus.NormalClosure, 
                "Client disconnect", 
                CancellationToken.None);
        }
        
        OnDisconnected?.Invoke(this, EventArgs.Empty);
    }

    private async Task HandleDisconnect(CancellationToken cancellationToken)
    {
        OnDisconnected?.Invoke(this, EventArgs.Empty);
        
        if (_autoReconnect && !cancellationToken.IsCancellationRequested)
        {
            await Task.Delay(_reconnectInterval, cancellationToken);
            try
            {
                await ConnectAsync(cancellationToken);
            }
            catch
            {
                // Will retry on next loop
            }
        }
    }

    private void ProcessMessage(string json)
    {
        try
        {
            var message = JsonSerializer.Deserialize<SwimMessage>(json, _jsonOptions);
            if (message == null) return;

            switch (message.Type)
            {
                case SwimEventTypes.Connected:
                    var connInfo = message.Data.Deserialize<ConnectionInfo>(_jsonOptions);
                    ClientId = connInfo?.ClientId;
                    OnConnected?.Invoke(this, new SwimEventArgs<ConnectionInfo>(connInfo!, message.Timestamp));
                    break;

                case SwimEventTypes.FlightCreated:
                    var created = message.Data.Deserialize<FlightEventData>(_jsonOptions);
                    OnFlightCreated?.Invoke(this, new SwimEventArgs<FlightEventData>(created!, message.Timestamp));
                    break;

                case SwimEventTypes.FlightDeparted:
                    var departed = message.Data.Deserialize<FlightEventData>(_jsonOptions);
                    OnFlightDeparted?.Invoke(this, new SwimEventArgs<FlightEventData>(departed!, message.Timestamp));
                    break;

                case SwimEventTypes.FlightArrived:
                    var arrived = message.Data.Deserialize<FlightEventData>(_jsonOptions);
                    OnFlightArrived?.Invoke(this, new SwimEventArgs<FlightEventData>(arrived!, message.Timestamp));
                    break;

                case SwimEventTypes.FlightDeleted:
                    var deleted = message.Data.Deserialize<FlightEventData>(_jsonOptions);
                    OnFlightDeleted?.Invoke(this, new SwimEventArgs<FlightEventData>(deleted!, message.Timestamp));
                    break;

                case SwimEventTypes.FlightPositions:
                    var positions = message.Data.Deserialize<PositionsBatchData>(_jsonOptions);
                    OnFlightPositions?.Invoke(this, new SwimEventArgs<PositionsBatchData>(positions!, message.Timestamp));
                    break;

                case SwimEventTypes.TmiIssued:
                    var issued = message.Data.Deserialize<TmiEventData>(_jsonOptions);
                    OnTmiIssued?.Invoke(this, new SwimEventArgs<TmiEventData>(issued!, message.Timestamp));
                    break;

                case SwimEventTypes.TmiReleased:
                    var released = message.Data.Deserialize<TmiEventData>(_jsonOptions);
                    OnTmiReleased?.Invoke(this, new SwimEventArgs<TmiEventData>(released!, message.Timestamp));
                    break;

                case SwimEventTypes.SystemHeartbeat:
                    var heartbeat = message.Data.Deserialize<HeartbeatData>(_jsonOptions);
                    OnHeartbeat?.Invoke(this, new SwimEventArgs<HeartbeatData>(heartbeat!, message.Timestamp));
                    break;

                case SwimEventTypes.Error:
                    var code = message.Data.GetProperty("code").GetString();
                    var msg = message.Data.GetProperty("message").GetString();
                    OnError?.Invoke(this, new SwimErrorEventArgs(code, msg, message.Timestamp));
                    break;
            }
        }
        catch (JsonException ex)
        {
            OnError?.Invoke(this, new SwimErrorEventArgs("PARSE_ERROR", ex.Message, null));
        }
    }

    private async Task SendAsync(object message, CancellationToken cancellationToken)
    {
        if (_webSocket?.State != WebSocketState.Open)
            throw new InvalidOperationException("WebSocket is not connected");

        var json = JsonSerializer.Serialize(message, _jsonOptions);
        var bytes = Encoding.UTF8.GetBytes(json);
        await _webSocket.SendAsync(
            new ArraySegment<byte>(bytes), 
            WebSocketMessageType.Text, 
            true, 
            cancellationToken);
    }

    public async ValueTask DisposeAsync()
    {
        if (!_disposed)
        {
            await DisconnectAsync();
            _webSocket?.Dispose();
            _cts?.Dispose();
            _disposed = true;
        }
    }
}

/// <summary>
/// Event arguments for SWIM events
/// </summary>
public class SwimEventArgs<T> : EventArgs
{
    public T Data { get; }
    public string? Timestamp { get; }

    public SwimEventArgs(T data, string? timestamp)
    {
        Data = data;
        Timestamp = timestamp;
    }
}

/// <summary>
/// Event arguments for SWIM errors
/// </summary>
public class SwimErrorEventArgs : EventArgs
{
    public string? Code { get; }
    public string? Message { get; }
    public string? Timestamp { get; }

    public SwimErrorEventArgs(string? code, string? message, string? timestamp)
    {
        Code = code;
        Message = message;
        Timestamp = timestamp;
    }
}
