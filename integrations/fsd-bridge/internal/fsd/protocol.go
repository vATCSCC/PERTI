package fsd

import (
	"fmt"
	"strings"
)

// FSD packet type prefixes
const (
	PktTextMessage  = "#TM" // Text message
	PktFlightPlan   = "$FP" // Flight plan
	PktClientQuery  = "$CQ" // Client query
	PktClientReply  = "$CR" // Client reply
	PktATIS         = "#AA" // ATIS info
	PktAddClient    = "#AP" // Add pilot client
	PktRemoveClient = "#DA" // Remove client
	PktPilotPos     = "@"   // Pilot position update
	PktATCPos       = "%"   // ATC position update
	PktPing         = "$PI" // Ping
	PktPong         = "$PO" // Pong
	PktKill         = "$!!" // Kill/disconnect
)

// Packet represents a parsed FSD protocol packet
type Packet struct {
	Raw    string
	Type   string
	From   string
	To     string
	Fields []string
}

// ParsePacket parses a raw FSD line into a Packet struct.
// FSD packets are colon-delimited ASCII lines terminated by \r\n.
func ParsePacket(line string) (*Packet, error) {
	line = strings.TrimRight(line, "\r\n")
	if len(line) < 3 {
		return nil, fmt.Errorf("packet too short: %q", line)
	}

	pkt := &Packet{Raw: line}

	// Determine packet type by prefix
	switch {
	case strings.HasPrefix(line, "#TM"):
		pkt.Type = PktTextMessage
		return parseColonPacket(pkt, line[3:])
	case strings.HasPrefix(line, "$FP"):
		pkt.Type = PktFlightPlan
		return parseColonPacket(pkt, line[3:])
	case strings.HasPrefix(line, "$CQ"):
		pkt.Type = PktClientQuery
		return parseColonPacket(pkt, line[3:])
	case strings.HasPrefix(line, "$CR"):
		pkt.Type = PktClientReply
		return parseColonPacket(pkt, line[3:])
	case strings.HasPrefix(line, "$PI"):
		pkt.Type = PktPing
		return parseColonPacket(pkt, line[3:])
	case strings.HasPrefix(line, "$PO"):
		pkt.Type = PktPong
		return parseColonPacket(pkt, line[3:])
	case strings.HasPrefix(line, "$!!"):
		pkt.Type = PktKill
		return parseColonPacket(pkt, line[3:])
	case strings.HasPrefix(line, "@"):
		pkt.Type = PktPilotPos
		return parseColonPacket(pkt, line[1:])
	case strings.HasPrefix(line, "%"):
		pkt.Type = PktATCPos
		return parseColonPacket(pkt, line[1:])
	case strings.HasPrefix(line, "#DA"):
		pkt.Type = PktRemoveClient
		return parseColonPacket(pkt, line[3:])
	case strings.HasPrefix(line, "#AA"):
		pkt.Type = PktATIS
		return parseColonPacket(pkt, line[3:])
	default:
		// Unknown prefix -- treat first 3 chars as type
		pkt.Type = line[:3]
		return parseColonPacket(pkt, line[3:])
	}
}

// parseColonPacket splits the body on colons to extract From, To, and Fields
func parseColonPacket(pkt *Packet, body string) (*Packet, error) {
	parts := strings.Split(body, ":")
	if len(parts) >= 2 {
		pkt.From = parts[0]
		pkt.To = parts[1]
		if len(parts) > 2 {
			pkt.Fields = parts[2:]
		}
	}
	return pkt, nil
}

// FormatTextMessage creates a #TM packet
func FormatTextMessage(from, to, message string) string {
	return fmt.Sprintf("#TM%s:%s:%s\r\n", from, to, message)
}

// FormatClientQuery creates a $CQ packet
func FormatClientQuery(from, to, queryType string, payload ...string) string {
	base := fmt.Sprintf("$CQ%s:%s:%s", from, to, queryType)
	if len(payload) > 0 {
		base += ":" + strings.Join(payload, ":")
	}
	return base + "\r\n"
}

// FormatClientReply creates a $CR packet
func FormatClientReply(from, to, queryType string, payload ...string) string {
	base := fmt.Sprintf("$CR%s:%s:%s", from, to, queryType)
	if len(payload) > 0 {
		base += ":" + strings.Join(payload, ":")
	}
	return base + "\r\n"
}

// FormatPing creates a $PI packet
func FormatPing(from, to string) string {
	return fmt.Sprintf("$PI%s:%s\r\n", from, to)
}

// FormatPong creates a $PO packet
func FormatPong(from, to string) string {
	return fmt.Sprintf("$PO%s:%s\r\n", from, to)
}
