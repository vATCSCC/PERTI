using System;
using System.Net.Http;
using System.Threading.Tasks;
using System.Xml.Linq;

namespace VatSwim.VPilot
{
    /// <summary>
    /// Imports OFP data from SimBrief.
    /// </summary>
    public class SimbriefImporter
    {
        private const string SimbriefApiUrl = "https://www.simbrief.com/api/xml.fetcher.php";
        private readonly HttpClient _httpClient;

        public SimbriefImporter()
        {
            _httpClient = new HttpClient { Timeout = TimeSpan.FromSeconds(30) };
        }

        /// <summary>
        /// Fetch the latest OFP for a SimBrief user.
        /// </summary>
        public async Task<SimbriefOFP?> FetchOFPAsync(string username)
        {
            var url = $"{SimbriefApiUrl}?username={Uri.EscapeDataString(username)}";
            var response = await _httpClient.GetStringAsync(url);

            return ParseOFP(response);
        }

        /// <summary>
        /// Parse SimBrief XML response.
        /// </summary>
        private SimbriefOFP? ParseOFP(string xml)
        {
            try
            {
                var doc = XDocument.Parse(xml);
                var ofp = doc.Element("OFP");

                if (ofp == null) return null;

                var origin = ofp.Element("origin");
                var destination = ofp.Element("destination");
                var general = ofp.Element("general");
                var times = ofp.Element("times");
                var fuel = ofp.Element("fuel");
                var aircraft = ofp.Element("aircraft");

                return new SimbriefOFP
                {
                    Origin = origin?.Element("icao_code")?.Value ?? "",
                    Destination = destination?.Element("icao_code")?.Value ?? "",
                    AircraftIcao = aircraft?.Element("icaocode")?.Value ?? "",
                    Route = general?.Element("route")?.Value ?? "",
                    CruiseAltitude = ParseInt(general?.Element("initial_altitude")?.Value),
                    CruiseMach = ParseDouble(general?.Element("cruise_mach")?.Value),
                    CostIndex = ParseInt(general?.Element("costindex")?.Value),
                    FuelPlanRamp = ParseDouble(fuel?.Element("plan_ramp")?.Value),
                    EstTimeEnroute = ParseInt(times?.Element("est_time_enroute")?.Value),
                    ScheduledDeparture = ParseDateTime(times?.Element("sched_out")?.Value),
                    ScheduledArrival = ParseDateTime(times?.Element("sched_in")?.Value)
                };
            }
            catch
            {
                return null;
            }
        }

        private int ParseInt(string? value) =>
            int.TryParse(value, out var result) ? result : 0;

        private double ParseDouble(string? value) =>
            double.TryParse(value, out var result) ? result : 0;

        private DateTime? ParseDateTime(string? value)
        {
            if (string.IsNullOrEmpty(value)) return null;

            // SimBrief returns Unix timestamps
            if (long.TryParse(value, out var unixTime))
            {
                return DateTimeOffset.FromUnixTimeSeconds(unixTime).UtcDateTime;
            }

            if (DateTime.TryParse(value, out var dateTime))
            {
                return dateTime.ToUniversalTime();
            }

            return null;
        }
    }

    /// <summary>
    /// SimBrief OFP data.
    /// </summary>
    public class SimbriefOFP
    {
        public string Origin { get; set; } = "";
        public string Destination { get; set; } = "";
        public string AircraftIcao { get; set; } = "";
        public string Route { get; set; } = "";
        public int CruiseAltitude { get; set; }
        public double CruiseMach { get; set; }
        public int CostIndex { get; set; }
        public double FuelPlanRamp { get; set; }
        public int EstTimeEnroute { get; set; }
        public DateTime? ScheduledDeparture { get; set; }
        public DateTime? ScheduledArrival { get; set; }
    }
}
