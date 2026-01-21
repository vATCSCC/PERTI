using System;
using System.IO;
using Newtonsoft.Json;

namespace VatSwim.VPilot
{
    /// <summary>
    /// Configuration for VATSWIM vPilot plugin.
    /// </summary>
    public class VatswimConfig
    {
        private const string ConfigFileName = "vatswim_config.json";

        public bool Enabled { get; set; } = true;
        public string ApiKey { get; set; } = "";
        public string ApiBaseUrl { get; set; } = "https://perti.vatcscc.org/api/swim/v1";
        public bool ImportSimbrief { get; set; } = true;
        public string SimbriefUsername { get; set; } = "";
        public bool VerboseLogging { get; set; } = false;
        public bool EnableTracking { get; set; } = true;
        public bool EnableOOOI { get; set; } = true;
        public int TrackIntervalMs { get; set; } = 1000;

        /// <summary>
        /// Load configuration from file.
        /// </summary>
        public static VatswimConfig Load()
        {
            var configPath = GetConfigPath();

            if (File.Exists(configPath))
            {
                try
                {
                    var json = File.ReadAllText(configPath);
                    return JsonConvert.DeserializeObject<VatswimConfig>(json) ?? new VatswimConfig();
                }
                catch
                {
                    // Return defaults if config fails to load
                }
            }

            // Create default config
            var config = new VatswimConfig();
            config.Save();
            return config;
        }

        /// <summary>
        /// Save configuration to file.
        /// </summary>
        public void Save()
        {
            var configPath = GetConfigPath();
            var directory = Path.GetDirectoryName(configPath);

            if (!string.IsNullOrEmpty(directory) && !Directory.Exists(directory))
            {
                Directory.CreateDirectory(directory);
            }

            var json = JsonConvert.SerializeObject(this, Formatting.Indented);
            File.WriteAllText(configPath, json);
        }

        /// <summary>
        /// Get the config file path.
        /// </summary>
        private static string GetConfigPath()
        {
            var appData = Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);
            return Path.Combine(appData, "vPilot", "Plugins", "VATSWIM", ConfigFileName);
        }
    }
}
