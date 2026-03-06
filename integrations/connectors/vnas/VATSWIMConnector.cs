using System;
using System.Collections.Generic;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using System.Threading.Tasks;

namespace VATSWIM.Connectors.VNAS
{
    /// <summary>
    /// VATSWIM Connector for vNAS ERAM/STARS systems.
    /// Pushes track surveillance, ATC tags, and handoff data to VATSWIM.
    ///
    /// Usage:
    ///   var connector = new VATSWIMConnector("swim_sys_your_key_here", "https://perti.vatcscc.org");
    ///   await connector.SendTracksAsync("ZDC", "ERAM", tracks);
    /// </summary>
    public class VATSWIMConnector : IDisposable
    {
        private readonly HttpClient _client;
        private readonly string _baseUrl;
        private readonly string _apiKey;

        private const int MaxTrackBatch = 1000;
        private const int MaxTagsBatch = 500;
        private const int MaxHandoffBatch = 200;

        public VATSWIMConnector(string apiKey, string baseUrl = "https://perti.vatcscc.org")
        {
            _apiKey = apiKey ?? throw new ArgumentNullException(nameof(apiKey));
            _baseUrl = baseUrl.TrimEnd('/');

            _client = new HttpClient();
            _client.DefaultRequestHeaders.Authorization =
                new AuthenticationHeaderValue("Bearer", _apiKey);
            _client.DefaultRequestHeaders.Accept.Add(
                new MediaTypeWithQualityHeaderValue("application/json"));
            _client.Timeout = TimeSpan.FromSeconds(30);
        }

        /// <summary>
        /// Send track/surveillance data from ERAM or STARS.
        /// </summary>
        /// <param name="facilityId">Source facility (e.g., "ZDC")</param>
        /// <param name="systemType">System type: "ERAM" or "STARS"</param>
        /// <param name="tracks">Track updates (max 1000 per batch)</param>
        public async Task<IngestResult> SendTracksAsync(
            string facilityId, string systemType, List<TrackUpdate> tracks)
        {
            if (tracks.Count > MaxTrackBatch)
                throw new ArgumentException($"Batch exceeds max {MaxTrackBatch} tracks");

            var payload = new
            {
                facility_id = facilityId,
                system_type = systemType,
                timestamp = DateTime.UtcNow.ToString("o"),
                tracks
            };

            return await PostAsync("/api/swim/v1/ingest/vnas/track.php", payload);
        }

        /// <summary>
        /// Send ATC automation tag data.
        /// </summary>
        public async Task<IngestResult> SendTagsAsync(
            string facilityId, string systemType, List<TagUpdate> tags)
        {
            if (tags.Count > MaxTagsBatch)
                throw new ArgumentException($"Batch exceeds max {MaxTagsBatch} tags");

            var payload = new
            {
                facility_id = facilityId,
                system_type = systemType,
                tags
            };

            return await PostAsync("/api/swim/v1/ingest/vnas/tags.php", payload);
        }

        /// <summary>
        /// Send sector handoff data.
        /// </summary>
        public async Task<IngestResult> SendHandoffsAsync(
            string facilityId, List<HandoffUpdate> handoffs)
        {
            if (handoffs.Count > MaxHandoffBatch)
                throw new ArgumentException($"Batch exceeds max {MaxHandoffBatch} handoffs");

            var payload = new
            {
                facility_id = facilityId,
                handoffs
            };

            return await PostAsync("/api/swim/v1/ingest/vnas/handoff.php", payload);
        }

        private async Task<IngestResult> PostAsync(string path, object payload)
        {
            var json = JsonSerializer.Serialize(payload, new JsonSerializerOptions
            {
                PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower
            });

            var content = new StringContent(json, Encoding.UTF8, "application/json");
            var response = await _client.PostAsync($"{_baseUrl}{path}", content);
            var body = await response.Content.ReadAsStringAsync();

            return new IngestResult
            {
                Success = response.IsSuccessStatusCode,
                StatusCode = (int)response.StatusCode,
                Body = body
            };
        }

        public void Dispose() => _client?.Dispose();
    }

    // ── Data Models ──────────────────────────────────────────────────────

    public class TrackUpdate
    {
        public string Callsign { get; set; }
        public string Gufi { get; set; }
        public string BeaconCode { get; set; }
        public TrackPosition Position { get; set; }
        public TrackQuality TrackQuality { get; set; }
        public string Timestamp { get; set; }
    }

    public class TrackPosition
    {
        public double Latitude { get; set; }
        public double Longitude { get; set; }
        public int? AltitudeFt { get; set; }
        public int? GroundSpeedKts { get; set; }
        public double? TrackDeg { get; set; }
        public int? VerticalRateFpm { get; set; }
    }

    public class TrackQuality
    {
        public string Source { get; set; }  // "radar", "ads-b", "mlat", "mode-s"
        public bool? ModeC { get; set; }
        public bool? ModeS { get; set; }
        public bool? AdsB { get; set; }
        public int? PositionQuality { get; set; }  // 0-9
    }

    public class TagUpdate
    {
        public string Callsign { get; set; }
        public string ScratchPad1 { get; set; }
        public string ScratchPad2 { get; set; }
        public string AssignedAltitude { get; set; }
        public string AssignedSpeed { get; set; }
        public string AssignedHeading { get; set; }
        public string HandoffSector { get; set; }
    }

    public class HandoffUpdate
    {
        public string Callsign { get; set; }
        public string FromSector { get; set; }
        public string ToSector { get; set; }
        public string HandoffType { get; set; }  // "automated", "manual"
        public string Timestamp { get; set; }
    }

    public class IngestResult
    {
        public bool Success { get; set; }
        public int StatusCode { get; set; }
        public string Body { get; set; }
    }
}
