package main

import (
	"flag"
	"fmt"
	"log"
	"os"
	"os/signal"
	"path/filepath"
	"syscall"

	"github.com/vatcscc/swim-bridge/internal/bridge"
	"github.com/vatcscc/swim-bridge/internal/fsd"
	"github.com/vatcscc/swim-bridge/internal/locale"
	"github.com/vatcscc/swim-bridge/internal/swim"
)

func main() {
	configPath := flag.String("config", "config.yaml", "Path to config file")
	flag.Parse()

	// Load configuration
	cfg, err := LoadConfig(*configPath)
	if err != nil {
		log.Fatalf("Config error: %v", err)
	}

	// Load locale bundle for i18n
	localeDir := filepath.Join(filepath.Dir(os.Args[0]), "locales")
	localeFile := filepath.Join(localeDir, cfg.Locale+".yaml")
	bundle, err := locale.Load(localeFile, cfg.Locale)
	if err != nil {
		log.Printf("Locale %s not found, falling back to en-US", cfg.Locale)
		bundle, _ = locale.Load(filepath.Join(localeDir, "en-US.yaml"), "en-US")
	}

	if bundle != nil {
		log.Printf("%s v%s", bundle.T("bridge.name"), bundle.T("bridge.version"))
	}

	// Initialize shared state
	state := bridge.NewState()

	// Create FSD TCP server
	fsdServer := fsd.NewServer(cfg.FSD.ListenAddr, cfg.FSD.ServerID, func(client *fsd.Client, pkt *fsd.Packet) {
		// Handle incoming FSD queries from EuroScope/CRC
		switch pkt.Type {
		case fsd.PktClientQuery:
			handleClientQuery(cfg, state, fsdServer, client, pkt)
		default:
			log.Printf("[FSD] Unhandled packet from %s: %s", client.Callsign, pkt.Type)
		}
	})

	if err := fsdServer.Start(); err != nil {
		log.Fatalf("FSD server error: %v", err)
	}

	// Create SWIM-to-FSD translator
	translator := bridge.NewTranslator(cfg.FSD.ServerID, state, fsdServer)

	// Create SWIM WebSocket consumer
	consumer := swim.NewConsumer(
		cfg.SWIM.URL,
		cfg.SWIM.APIKey,
		cfg.SWIM.Channels,
		translator.HandleEvent,
	)
	consumer.SetReconnect(cfg.Bridge.Reconnect, cfg.Bridge.MaxReconnect)

	if err := consumer.Start(); err != nil {
		log.Fatalf("SWIM consumer error: %v", err)
	}

	// Wait for shutdown signal (SIGINT or SIGTERM)
	sigChan := make(chan os.Signal, 1)
	signal.Notify(sigChan, syscall.SIGINT, syscall.SIGTERM)
	sig := <-sigChan

	log.Printf("Received %v, shutting down...", sig)
	consumer.Stop()
	fsdServer.Stop()
	log.Println("Shutdown complete")
}

// handleClientQuery responds to $CQ queries from EuroScope/CRC clients
func handleClientQuery(cfg *Config, state *bridge.State, srv *fsd.Server, client *fsd.Client, pkt *fsd.Packet) {
	if len(pkt.Fields) == 0 {
		return
	}
	queryType := pkt.Fields[0]

	switch queryType {
	case "EDCT":
		// Look up EDCT for a callsign
		if len(pkt.Fields) > 1 {
			cs := pkt.Fields[1]
			if fs, ok := state.GetFlight(cs); ok && fs.EDCT != "" {
				reply := fsd.FormatClientReply(cfg.FSD.ServerID, client.Callsign, "EDCT", cs, fs.EDCT)
				_ = client.Send(reply)
			}
		}
	case "CTOT":
		// Look up CTOT for a callsign
		if len(pkt.Fields) > 1 {
			cs := pkt.Fields[1]
			if fs, ok := state.GetFlight(cs); ok && fs.CTOT != "" {
				reply := fsd.FormatClientReply(cfg.FSD.ServerID, client.Callsign, "CTOT", cs, fs.CTOT)
				_ = client.Send(reply)
			}
		}
	case "STATUS":
		// Return bridge status
		reply := fsd.FormatClientReply(cfg.FSD.ServerID, client.Callsign, "STATUS",
			fmt.Sprintf("flights=%d", state.FlightCount()),
			fmt.Sprintf("clients=%d", srv.ClientCount()),
		)
		_ = client.Send(reply)
	}
}
