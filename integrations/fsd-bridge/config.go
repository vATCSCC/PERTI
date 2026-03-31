package main

import (
	"os"

	"gopkg.in/yaml.v3"
)

// Config holds all bridge configuration
type Config struct {
	SWIM     SWIMConfig   `yaml:"swim"`
	FSD      FSDConfig    `yaml:"fsd"`
	Bridge   BridgeConfig `yaml:"bridge"`
	Locale   string       `yaml:"locale"`
	LogLevel string       `yaml:"log_level"`
}

// SWIMConfig holds SWIM WebSocket connection settings
type SWIMConfig struct {
	URL      string   `yaml:"url"`
	APIKey   string   `yaml:"api_key"`
	Channels []string `yaml:"channels"`
}

// FSDConfig holds FSD TCP server settings
type FSDConfig struct {
	ListenAddr string `yaml:"listen_addr"`
	ServerID   string `yaml:"server_id"`
	ServerName string `yaml:"server_name"`
}

// BridgeConfig holds bridge behavior settings
type BridgeConfig struct {
	Callsign     string `yaml:"callsign"`
	Facility     string `yaml:"facility"`
	Reconnect    bool   `yaml:"auto_reconnect"`
	MaxReconnect int    `yaml:"max_reconnect_delay_sec"`
}

// LoadConfig reads and parses a YAML config file with defaults
func LoadConfig(path string) (*Config, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}

	cfg := &Config{
		SWIM: SWIMConfig{
			URL:      "wss://perti.vatcscc.org/ws/swim/v1",
			Channels: []string{"tmi.*", "cdm.*", "aman.*", "flight.*"},
		},
		FSD: FSDConfig{
			ListenAddr: ":6809",
			ServerID:   "SWIM",
			ServerName: "VATSWIM Bridge",
		},
		Bridge: BridgeConfig{
			Reconnect:    true,
			MaxReconnect: 300,
		},
		Locale:   "en-US",
		LogLevel: "info",
	}

	if err := yaml.Unmarshal(data, cfg); err != nil {
		return nil, err
	}

	return cfg, nil
}
