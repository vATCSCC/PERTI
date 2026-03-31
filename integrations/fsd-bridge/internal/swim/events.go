package swim

import "time"

// Event represents a SWIM WebSocket event envelope
type Event struct {
	Type      string                 `json:"type"`
	Timestamp time.Time              `json:"timestamp"`
	Data      map[string]interface{} `json:"data"`
}

// FlightEvent contains flight identity and routing data
type FlightEvent struct {
	FlightUID    int64  `json:"flight_uid"`
	Callsign     string `json:"callsign"`
	Departure    string `json:"departure"`
	Destination  string `json:"destination"`
	Route        string `json:"route,omitempty"`
	Altitude     string `json:"altitude,omitempty"`
	AircraftType string `json:"aircraft_type,omitempty"`
}

// TMIEvent contains traffic management initiative data
type TMIEvent struct {
	ProgramID   int    `json:"program_id"`
	ProgramType string `json:"program_type"` // GDP, GS, AFP
	Airport     string `json:"airport"`
	Status      string `json:"status"`
	Message     string `json:"message,omitempty"`
}

// CDMEvent contains collaborative decision making messages
type CDMEvent struct {
	FlightUID   int64  `json:"flight_uid"`
	Callsign    string `json:"callsign"`
	MessageType string `json:"message_type"`
	MessageBody string `json:"message_body"`
	TimeUTC     string `json:"time_utc,omitempty"`
}

// AMANEvent contains arrival manager sequence data
type AMANEvent struct {
	Airport  string      `json:"airport"`
	Runway   string      `json:"runway,omitempty"`
	Sequence []AMANEntry `json:"sequence"`
}

// AMANEntry is a single flight in an AMAN sequence
type AMANEntry struct {
	Callsign       string `json:"callsign"`
	SequenceNumber int    `json:"sequence_number"`
	ETAUTC         string `json:"eta_utc"`
	STAUTC         string `json:"sta_utc,omitempty"`
	DelaySeconds   int    `json:"delay_seconds"`
	Fix            string `json:"fix,omitempty"`
	Status         string `json:"status"`
}
