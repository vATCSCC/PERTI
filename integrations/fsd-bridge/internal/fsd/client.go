package fsd

import (
	"bufio"
	"fmt"
	"net"
	"sync"
	"time"
)

// Client represents a connected EuroScope/CRC client
type Client struct {
	Conn     net.Conn
	Callsign string
	Facility string
	mu       sync.Mutex
	writer   *bufio.Writer
	lastPing time.Time
}

// NewClient creates a new Client wrapping a TCP connection
func NewClient(conn net.Conn) *Client {
	return &Client{
		Conn:     conn,
		writer:   bufio.NewWriter(conn),
		lastPing: time.Now(),
	}
}

// Send writes a raw FSD packet to the client (thread-safe)
func (c *Client) Send(packet string) error {
	c.mu.Lock()
	defer c.mu.Unlock()
	_, err := c.writer.WriteString(packet)
	if err != nil {
		return fmt.Errorf("write error: %w", err)
	}
	return c.writer.Flush()
}

// Close closes the client connection
func (c *Client) Close() error {
	return c.Conn.Close()
}

// RemoteAddr returns the client's remote address string
func (c *Client) RemoteAddr() string {
	return c.Conn.RemoteAddr().String()
}

// UpdatePing records the last ping time
func (c *Client) UpdatePing() {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.lastPing = time.Now()
}

// LastPing returns the last ping time
func (c *Client) LastPing() time.Time {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.lastPing
}
