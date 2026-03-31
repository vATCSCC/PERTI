package fsd

import (
	"bufio"
	"log"
	"net"
	"sync"
	"time"
)

// MessageHandler is called when a client sends an FSD packet
type MessageHandler func(client *Client, pkt *Packet)

// Server is the FSD TCP server that EuroScope/CRC clients connect to
type Server struct {
	addr     string
	serverID string
	listener net.Listener
	clients  map[string]*Client // callsign -> client
	mu       sync.RWMutex
	handler  MessageHandler
	done     chan struct{}
}

// NewServer creates a new FSD TCP server
func NewServer(addr, serverID string, handler MessageHandler) *Server {
	return &Server{
		addr:     addr,
		serverID: serverID,
		clients:  make(map[string]*Client),
		handler:  handler,
		done:     make(chan struct{}),
	}
}

// Start begins accepting TCP connections on the configured address
func (s *Server) Start() error {
	var err error
	s.listener, err = net.Listen("tcp", s.addr)
	if err != nil {
		return err
	}
	log.Printf("[FSD] Listening on %s", s.addr)

	go s.acceptLoop()
	go s.pingLoop()

	return nil
}

func (s *Server) acceptLoop() {
	for {
		conn, err := s.listener.Accept()
		if err != nil {
			select {
			case <-s.done:
				return
			default:
				log.Printf("[FSD] Accept error: %v", err)
				continue
			}
		}
		go s.handleConn(conn)
	}
}

func (s *Server) handleConn(conn net.Conn) {
	client := NewClient(conn)
	addr := client.RemoteAddr()
	log.Printf("[FSD] New connection from %s", addr)

	scanner := bufio.NewScanner(conn)
	for scanner.Scan() {
		line := scanner.Text()
		if line == "" {
			continue
		}

		pkt, err := ParsePacket(line)
		if err != nil {
			log.Printf("[FSD] Parse error from %s: %v", addr, err)
			continue
		}

		// Track client callsign from first message
		if client.Callsign == "" && pkt.From != "" {
			client.Callsign = pkt.From
			s.mu.Lock()
			s.clients[client.Callsign] = client
			s.mu.Unlock()
			log.Printf("[FSD] Client identified: %s from %s", client.Callsign, addr)
		}

		// Handle pings internally
		if pkt.Type == PktPing {
			_ = client.Send(FormatPong(s.serverID, pkt.From))
			client.UpdatePing()
			continue
		}

		if s.handler != nil {
			s.handler(client, pkt)
		}
	}

	// Client disconnected
	if client.Callsign != "" {
		s.mu.Lock()
		delete(s.clients, client.Callsign)
		s.mu.Unlock()
		log.Printf("[FSD] Client disconnected: %s", client.Callsign)
	}
	conn.Close()
}

// pingLoop periodically checks for timed-out clients (no ping in 90s)
func (s *Server) pingLoop() {
	ticker := time.NewTicker(30 * time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-ticker.C:
			s.mu.RLock()
			stale := make([]string, 0)
			for cs, client := range s.clients {
				if time.Since(client.LastPing()) > 90*time.Second {
					stale = append(stale, cs)
				}
			}
			s.mu.RUnlock()

			for _, cs := range stale {
				s.mu.Lock()
				if client, ok := s.clients[cs]; ok {
					log.Printf("[FSD] Ping timeout: %s", cs)
					client.Close()
					delete(s.clients, cs)
				}
				s.mu.Unlock()
			}
		case <-s.done:
			return
		}
	}
}

// Broadcast sends a packet to all connected clients
func (s *Server) Broadcast(packet string) {
	s.mu.RLock()
	defer s.mu.RUnlock()
	for _, client := range s.clients {
		if err := client.Send(packet); err != nil {
			log.Printf("[FSD] Send error to %s: %v", client.Callsign, err)
		}
	}
}

// SendTo sends a packet to a specific callsign
func (s *Server) SendTo(callsign string, packet string) error {
	s.mu.RLock()
	client, ok := s.clients[callsign]
	s.mu.RUnlock()
	if !ok {
		return &ClientNotConnectedError{Callsign: callsign}
	}
	return client.Send(packet)
}

// ClientCount returns the number of connected clients
func (s *Server) ClientCount() int {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return len(s.clients)
}

// ConnectedCallsigns returns all connected client callsigns
func (s *Server) ConnectedCallsigns() []string {
	s.mu.RLock()
	defer s.mu.RUnlock()
	cs := make([]string, 0, len(s.clients))
	for k := range s.clients {
		cs = append(cs, k)
	}
	return cs
}

// Stop gracefully shuts down the server
func (s *Server) Stop() {
	close(s.done)
	if s.listener != nil {
		s.listener.Close()
	}
	s.mu.Lock()
	for _, client := range s.clients {
		client.Close()
	}
	s.mu.Unlock()
}

// ClientNotConnectedError is returned when sending to a disconnected callsign
type ClientNotConnectedError struct {
	Callsign string
}

func (e *ClientNotConnectedError) Error() string {
	return "client not connected: " + e.Callsign
}
