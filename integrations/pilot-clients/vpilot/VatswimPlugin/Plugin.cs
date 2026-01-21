using System;
using System.Net.Http;
using System.Text;
using System.Threading.Tasks;
using Newtonsoft.Json;

namespace VatSwim.VPilot
{
    /// <summary>
    /// VATSWIM integration plugin for vPilot.
    /// Syncs flight data to VATSWIM when connecting to VATSIM.
    /// </summary>
    public class VatswimPlugin
    {
        private const string PluginVersion = "1.0.0";

        private readonly HttpClient _httpClient;
        private readonly VatswimConfig _config;
        private readonly SimbriefImporter _simbriefImporter;

        private string? _currentCallsign;
        private string? _departureIcao;
        private string? _destinationIcao;
        private bool _isConnected;

        public VatswimPlugin()
        {
            _config = VatswimConfig.Load();
            _httpClient = new HttpClient
            {
                Timeout = TimeSpan.FromSeconds(30)
            };
            _httpClient.DefaultRequestHeaders.Add("User-Agent", $"vPilot-VATSWIM/{PluginVersion}");

            _simbriefImporter = new SimbriefImporter();
        }

        /// <summary>
        /// Called when vPilot connects to VATSIM.
        /// </summary>
        public async Task OnConnectedAsync(string callsign, string realName, int cid)
        {
            _currentCallsign = callsign;
            _isConnected = true;

            Log($"Connected to VATSIM as {callsign} (CID: {cid})");

            // Try to import SimBrief data if configured
            if (_config.ImportSimbrief && !string.IsNullOrEmpty(_config.SimbriefUsername))
            {
                try
                {
                    var ofp = await _simbriefImporter.FetchOFPAsync(_config.SimbriefUsername);
                    if (ofp != null)
                    {
                        _departureIcao = ofp.Origin;
                        _destinationIcao = ofp.Destination;

                        // Submit SimBrief data to VATSWIM
                        await SubmitSimbriefDataAsync(callsign, cid, ofp);
                    }
                }
                catch (Exception ex)
                {
                    Log($"SimBrief import failed: {ex.Message}");
                }
            }

            // Notify MSFS/P3D plugin of connection
            NotifySimPlugin(callsign, _departureIcao ?? "", _destinationIcao ?? "");
        }

        /// <summary>
        /// Called when vPilot disconnects from VATSIM.
        /// </summary>
        public void OnDisconnected()
        {
            _isConnected = false;
            Log("Disconnected from VATSIM");
        }

        /// <summary>
        /// Called when flight plan is filed.
        /// </summary>
        public async Task OnFlightPlanFiledAsync(FlightPlan flightPlan)
        {
            _departureIcao = flightPlan.DepartureIcao;
            _destinationIcao = flightPlan.DestinationIcao;

            if (!_config.Enabled || string.IsNullOrEmpty(_config.ApiKey))
            {
                return;
            }

            try
            {
                var data = new
                {
                    callsign = _currentCallsign,
                    dept_icao = flightPlan.DepartureIcao,
                    dest_icao = flightPlan.DestinationIcao,
                    aircraft_type = flightPlan.AircraftType,
                    fp_route = flightPlan.Route,
                    fp_altitude_ft = flightPlan.CruiseAltitude,
                    fp_remarks = flightPlan.Remarks,
                    source = "vpilot"
                };

                await PostToVatswimAsync("/ingest/adl", data);
                Log($"Flight plan synced: {flightPlan.DepartureIcao} -> {flightPlan.DestinationIcao}");
            }
            catch (Exception ex)
            {
                Log($"Flight plan sync failed: {ex.Message}");
            }

            // Update MSFS/P3D plugin
            NotifySimPlugin(_currentCallsign!, _departureIcao, _destinationIcao);
        }

        /// <summary>
        /// Submit SimBrief OFP data to VATSWIM.
        /// </summary>
        private async Task SubmitSimbriefDataAsync(string callsign, int cid, SimbriefOFP ofp)
        {
            if (!_config.Enabled || string.IsNullOrEmpty(_config.ApiKey))
            {
                return;
            }

            try
            {
                var data = new
                {
                    callsign,
                    cid,
                    dept_icao = ofp.Origin,
                    dest_icao = ofp.Destination,
                    aircraft_type = ofp.AircraftIcao,
                    fp_route = ofp.Route,
                    fp_altitude_ft = ofp.CruiseAltitude,
                    cruise_mach = ofp.CruiseMach,
                    block_fuel_lbs = ofp.FuelPlanRamp,
                    cost_index = ofp.CostIndex,
                    // CDM predictions from SimBrief
                    lgtd_utc = ofp.ScheduledDeparture?.ToString("o"),
                    lgta_utc = ofp.ScheduledArrival?.ToString("o"),
                    source = "simbrief"
                };

                await PostToVatswimAsync("/ingest/adl", data);
                Log($"SimBrief OFP synced: {ofp.Origin} -> {ofp.Destination}");
            }
            catch (Exception ex)
            {
                Log($"SimBrief sync failed: {ex.Message}");
            }
        }

        /// <summary>
        /// Post data to VATSWIM API.
        /// </summary>
        private async Task<bool> PostToVatswimAsync(string endpoint, object data)
        {
            var url = _config.ApiBaseUrl.TrimEnd('/') + endpoint;
            var json = JsonConvert.SerializeObject(data, Formatting.None,
                new JsonSerializerSettings { NullValueHandling = NullValueHandling.Ignore });

            var request = new HttpRequestMessage(HttpMethod.Post, url)
            {
                Content = new StringContent(json, Encoding.UTF8, "application/json")
            };
            request.Headers.Add("Authorization", $"Bearer {_config.ApiKey}");
            request.Headers.Add("X-SWIM-Source", "vpilot");

            var response = await _httpClient.SendAsync(request);

            if (_config.VerboseLogging)
            {
                var responseBody = await response.Content.ReadAsStringAsync();
                Log($"POST {endpoint}: {(int)response.StatusCode} - {responseBody}");
            }

            return response.IsSuccessStatusCode;
        }

        /// <summary>
        /// Notify the simulator plugin of flight info.
        /// </summary>
        private void NotifySimPlugin(string callsign, string departure, string destination)
        {
            // Try to call the MSFS/P3D VATSWIM plugin's exported functions
            try
            {
                // This would use P/Invoke to call the DLL functions
                // In practice, you might use a shared memory or named pipe approach
                Log($"Notifying sim plugin: {callsign} {departure}->{destination}");
            }
            catch (Exception ex)
            {
                Log($"Failed to notify sim plugin: {ex.Message}");
            }
        }

        private void Log(string message)
        {
            Console.WriteLine($"[VATSWIM] {message}");
            // Also log to vPilot's log system if available
        }
    }

    /// <summary>
    /// Flight plan data.
    /// </summary>
    public class FlightPlan
    {
        public string DepartureIcao { get; set; } = "";
        public string DestinationIcao { get; set; } = "";
        public string AircraftType { get; set; } = "";
        public string Route { get; set; } = "";
        public int CruiseAltitude { get; set; }
        public string Remarks { get; set; } = "";
    }
}
