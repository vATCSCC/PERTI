package bridge

import "sync"

// State holds the bridge's shared state (active flights, TMI programs, etc.)
type State struct {
	mu       sync.RWMutex
	flights  map[string]FlightState // callsign -> state
	programs map[int]ProgramState   // program_id -> state
}

// FlightState tracks a flight's current SWIM-derived state
type FlightState struct {
	FlightUID   int64
	Callsign    string
	Departure   string
	Destination string
	EDCT        string
	CTOT        string
	Status      string
}

// ProgramState tracks a TMI program's current state
type ProgramState struct {
	ProgramID   int
	ProgramType string
	Airport     string
	Status      string
}

// NewState creates an empty bridge state
func NewState() *State {
	return &State{
		flights:  make(map[string]FlightState),
		programs: make(map[int]ProgramState),
	}
}

// UpdateFlight sets or updates a flight's state by callsign
func (s *State) UpdateFlight(cs string, fs FlightState) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.flights[cs] = fs
}

// GetFlight returns a flight's state by callsign
func (s *State) GetFlight(cs string) (FlightState, bool) {
	s.mu.RLock()
	defer s.mu.RUnlock()
	f, ok := s.flights[cs]
	return f, ok
}

// RemoveFlight removes a flight from state
func (s *State) RemoveFlight(cs string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	delete(s.flights, cs)
}

// UpdateProgram sets or updates a TMI program's state
func (s *State) UpdateProgram(id int, ps ProgramState) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.programs[id] = ps
}

// GetProgram returns a TMI program's state
func (s *State) GetProgram(id int) (ProgramState, bool) {
	s.mu.RLock()
	defer s.mu.RUnlock()
	p, ok := s.programs[id]
	return p, ok
}

// FlightCount returns the number of tracked flights
func (s *State) FlightCount() int {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return len(s.flights)
}

// ProgramCount returns the number of tracked TMI programs
func (s *State) ProgramCount() int {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return len(s.programs)
}
