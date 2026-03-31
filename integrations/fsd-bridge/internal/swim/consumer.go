package swim

import (
	"encoding/json"
	"fmt"
	"log"
	"math"
	"net/http"
	"sync"
	"time"

	"github.com/gorilla/websocket"
)

// EventHandler processes incoming SWIM events
type EventHandler func(event Event)

// Consumer connects to the SWIM WebSocket and dispatches events
type Consumer struct {
	url        string
	apiKey     string
	channels   []string
	handler    EventHandler
	reconnect  bool
	maxBackoff time.Duration
	conn       *websocket.Conn
	mu         sync.Mutex
	done       chan struct{}
	connected  bool
}

// NewConsumer creates a new SWIM WebSocket consumer
func NewConsumer(url, apiKey string, channels []string, handler EventHandler) *Consumer {
	return &Consumer{
		url:        url,
		apiKey:     apiKey,
		channels:   channels,
		handler:    handler,
		reconnect:  true,
		maxBackoff: 5 * time.Minute,
		done:       make(chan struct{}),
	}
}

// SetReconnect configures auto-reconnect behavior
func (c *Consumer) SetReconnect(enabled bool, maxBackoffSec int) {
	c.reconnect = enabled
	if maxBackoffSec > 0 {
		c.maxBackoff = time.Duration(maxBackoffSec) * time.Second
	}
}

// Start connects to the WebSocket and begins reading events.
// If auto-reconnect is enabled and the initial connection fails,
// it starts a background reconnect loop and returns nil.
func (c *Consumer) Start() error {
	if err := c.connect(); err != nil {
		if c.reconnect {
			log.Printf("[SWIM] Initial connection failed: %v; will retry", err)
			go c.reconnectLoop()
			return nil
		}
		return err
	}
	go c.readLoop()
	return nil
}

func (c *Consumer) connect() error {
	header := http.Header{}
	header.Set("X-API-Key", c.apiKey)

	conn, _, err := websocket.DefaultDialer.Dial(c.url, header)
	if err != nil {
		return fmt.Errorf("websocket dial: %w", err)
	}

	c.mu.Lock()
	c.conn = conn
	c.connected = true
	c.mu.Unlock()

	// Subscribe to configured channels
	sub := map[string]interface{}{
		"action":   "subscribe",
		"channels": c.channels,
	}
	if err := conn.WriteJSON(sub); err != nil {
		conn.Close()
		c.mu.Lock()
		c.connected = false
		c.mu.Unlock()
		return fmt.Errorf("subscribe: %w", err)
	}

	log.Printf("[SWIM] Connected to %s, subscribed to %v", c.url, c.channels)
	return nil
}

func (c *Consumer) readLoop() {
	defer func() {
		c.mu.Lock()
		c.connected = false
		if c.conn != nil {
			c.conn.Close()
		}
		c.mu.Unlock()

		if c.reconnect {
			go c.reconnectLoop()
		}
	}()

	for {
		select {
		case <-c.done:
			return
		default:
		}

		_, msg, err := c.conn.ReadMessage()
		if err != nil {
			log.Printf("[SWIM] Read error: %v", err)
			return
		}

		var event Event
		if err := json.Unmarshal(msg, &event); err != nil {
			log.Printf("[SWIM] Parse error: %v", err)
			continue
		}

		if c.handler != nil {
			c.handler(event)
		}
	}
}

func (c *Consumer) reconnectLoop() {
	attempt := 0
	for {
		select {
		case <-c.done:
			return
		default:
		}

		// Exponential backoff capped at maxBackoff
		delay := time.Duration(math.Min(
			float64(time.Second)*math.Pow(2, float64(attempt)),
			float64(c.maxBackoff),
		))
		log.Printf("[SWIM] Reconnecting in %v (attempt %d)", delay, attempt+1)

		select {
		case <-time.After(delay):
		case <-c.done:
			return
		}

		if err := c.connect(); err != nil {
			log.Printf("[SWIM] Reconnect failed: %v", err)
			attempt++
			continue
		}

		log.Printf("[SWIM] Reconnected successfully")
		go c.readLoop()
		return
	}
}

// IsConnected returns whether the WebSocket is connected
func (c *Consumer) IsConnected() bool {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.connected
}

// Stop shuts down the consumer and closes the WebSocket
func (c *Consumer) Stop() {
	close(c.done)
	c.mu.Lock()
	if c.conn != nil {
		c.conn.Close()
	}
	c.mu.Unlock()
}
