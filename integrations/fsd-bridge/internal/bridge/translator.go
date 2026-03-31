package bridge

import (
	"encoding/json"
	"fmt"
	"log"

	"github.com/vatcscc/swim-bridge/internal/fsd"
	"github.com/vatcscc/swim-bridge/internal/swim"
)

// Translator converts SWIM events into FSD packets and dispatches them
type Translator struct {
	serverID string
	state    *State
	fsdSrv   *fsd.Server
}

// NewTranslator creates a new SWIM-to-FSD translator
func NewTranslator(serverID string, state *State, fsdSrv *fsd.Server) *Translator {
	return &Translator{
		serverID: serverID,
		state:    state,
		fsdSrv:   fsdSrv,
	}
}

// HandleEvent is the main SWIM event handler -- routes by event type prefix
func (t *Translator) HandleEvent(event swim.Event) {
	switch {
	case matchPrefix(event.Type, "cdm."):
		t.handleCDM(event)
	case matchPrefix(event.Type, "tmi."):
		t.handleTMI(event)
	case matchPrefix(event.Type, "aman."):
		t.handleAMAN(event)
	case matchPrefix(event.Type, "flight."):
		t.handleFlight(event)
	default:
		log.Printf("[Bridge] Unknown event type: %s", event.Type)
	}
}

// handleCDM translates CDM events (EDCT, CTOT) into FSD text messages
func (t *Translator) handleCDM(event swim.Event) {
	data, _ := json.Marshal(event.Data)
	var cdm swim.CDMEvent
	if err := json.Unmarshal(data, &cdm); err != nil {
		log.Printf("[Bridge] CDM parse error: %v", err)
		return
	}

	// Format as #TM text message directed at the pilot's callsign
	msg := fmt.Sprintf("[%s] %s", cdm.MessageType, cdm.MessageBody)
	packet := fsd.FormatTextMessage(t.serverID, cdm.Callsign, msg)

	if err := t.fsdSrv.SendTo(cdm.Callsign, packet); err != nil {
		// Client not directly connected -- broadcast to all ATC clients
		t.fsdSrv.Broadcast(packet)
	}

	// Update internal state
	if cdm.Callsign != "" {
		fs, _ := t.state.GetFlight(cdm.Callsign)
		fs.Callsign = cdm.Callsign
		fs.FlightUID = cdm.FlightUID
		switch cdm.MessageType {
		case "EDCT", "EDCT_AMENDED":
			fs.EDCT = cdm.TimeUTC
		case "CTOT":
			fs.CTOT = cdm.TimeUTC
		}
		t.state.UpdateFlight(cdm.Callsign, fs)
	}

	log.Printf("[Bridge] CDM->FSD: %s -> %s", cdm.MessageType, cdm.Callsign)
}

// handleTMI translates TMI events (GDP, GS, AFP) into broadcast text messages
func (t *Translator) handleTMI(event swim.Event) {
	data, _ := json.Marshal(event.Data)
	var tmi swim.TMIEvent
	if err := json.Unmarshal(data, &tmi); err != nil {
		log.Printf("[Bridge] TMI parse error: %v", err)
		return
	}

	// TMI events broadcast to all connected ATC clients
	msg := fmt.Sprintf("[TMI] %s %s at %s: %s",
		tmi.ProgramType, tmi.Status, tmi.Airport, tmi.Message)
	packet := fsd.FormatTextMessage(t.serverID, "*", msg)
	t.fsdSrv.Broadcast(packet)

	t.state.UpdateProgram(tmi.ProgramID, ProgramState{
		ProgramID:   tmi.ProgramID,
		ProgramType: tmi.ProgramType,
		Airport:     tmi.Airport,
		Status:      tmi.Status,
	})

	log.Printf("[Bridge] TMI->FSD: %s %s %s", tmi.ProgramType, tmi.Airport, tmi.Status)
}

// handleAMAN translates AMAN sequence events into $CR replies
func (t *Translator) handleAMAN(event swim.Event) {
	data, _ := json.Marshal(event.Data)
	var aman swim.AMANEvent
	if err := json.Unmarshal(data, &aman); err != nil {
		log.Printf("[Bridge] AMAN parse error: %v", err)
		return
	}

	// Send each AMAN sequence entry as a $CR AMAN reply
	for _, entry := range aman.Sequence {
		payload := fmt.Sprintf("%s:%d:%s:%s:%d",
			entry.Callsign, entry.SequenceNumber,
			entry.ETAUTC, entry.Fix, entry.DelaySeconds)
		packet := fsd.FormatClientReply(t.serverID, "*", "AMAN", payload)
		t.fsdSrv.Broadcast(packet)
	}

	log.Printf("[Bridge] AMAN->FSD: %s %s %d entries",
		aman.Airport, aman.Runway, len(aman.Sequence))
}

// handleFlight updates internal state from flight events
func (t *Translator) handleFlight(event swim.Event) {
	data, _ := json.Marshal(event.Data)
	var flight swim.FlightEvent
	if err := json.Unmarshal(data, &flight); err != nil {
		return
	}

	t.state.UpdateFlight(flight.Callsign, FlightState{
		FlightUID:   flight.FlightUID,
		Callsign:    flight.Callsign,
		Departure:   flight.Departure,
		Destination: flight.Destination,
	})
}

func matchPrefix(s, prefix string) bool {
	return len(s) >= len(prefix) && s[:len(prefix)] == prefix
}
