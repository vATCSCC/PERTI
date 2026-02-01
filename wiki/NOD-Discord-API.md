# NOD Discord API

Discord integration for the NAS Operations Dashboard (NOD) TMI tracking.

---

## Overview

The NOD Discord API provides a simplified interface for Discord TMI operations, enabling automatic ingestion of TMI messages from Discord channels and two-way sync between PERTI and Discord.

**Base URL**: `/api/nod/discord.php`

**Prerequisites**: Discord bot token and channel configuration in `load/config.php`

---

## Quick Reference

| Action | Method | Description |
|--------|--------|-------------|
| `status` | GET | Check Discord integration status |
| `list` | GET | List Discord TMI entries from database |
| `active` | GET | List currently active TMIs |
| `refresh` | GET | Trigger manual refresh from Discord channel |
| `webhook` | POST | Receive Discord webhook events |
| `parse` | POST | Parse TMI message manually |
| `end` | POST | Mark a Discord TMI as ended |
| `send` | POST | Send a TMI message to Discord |

---

## Status & Configuration

### Check Status

Check if Discord integration is configured and ready.

```
GET /api/nod/discord.php?action=status
```

**Response (Configured)**

```json
{
  "configured": true,
  "status": "READY",
  "message": "Discord integration is configured and ready",
  "config_check": {
    "bot_token": true,
    "tmi_channel": true,
    "guild_id": true
  },
  "channels": {
    "tmi": "123456789012345678"
  }
}
```

**Response (Not Configured)**

```json
{
  "configured": false,
  "status": "NOT_CONFIGURED",
  "message": "Discord integration not configured. Add DISCORD_BOT_TOKEN and other credentials to load/config.php"
}
```

---

## TMI Queries

### List Discord TMIs

List all TMI entries ingested from Discord.

```
GET /api/nod/discord.php?action=list
```

**Optional Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `limit` | int | Maximum results (default: 50) |
| `offset` | int | Pagination offset |
| `status` | string | Filter by status (active, ended, all) |

**Response**

```json
{
  "success": true,
  "tmis": [
    {
      "id": 123,
      "discord_message_id": "1234567890123456789",
      "tmi_type": "GDP",
      "airport": "KJFK",
      "program_rate": 38,
      "start_time": "2026-02-01T14:00:00Z",
      "end_time": "2026-02-01T18:00:00Z",
      "scope": "BLANKET",
      "message_text": "GDP KJFK 38/HR 14Z-18Z BLANKET",
      "parsed_at": "2026-02-01T14:05:00Z",
      "status": "active"
    }
  ],
  "count": 1
}
```

---

### List Active TMIs

List currently active TMIs from Discord.

```
GET /api/nod/discord.php?action=active
```

**Response**

```json
{
  "success": true,
  "tmis": [
    {
      "id": 123,
      "tmi_type": "GDP",
      "airport": "KJFK",
      "program_rate": 38,
      "start_time": "2026-02-01T14:00:00Z",
      "end_time": "2026-02-01T18:00:00Z",
      "elapsed_minutes": 45
    }
  ],
  "count": 1
}
```

---

## Sync Operations

### Refresh from Discord

Trigger a manual refresh to fetch recent messages from the Discord TMI channel.

```
GET /api/nod/discord.php?action=refresh
```

**Response**

```json
{
  "success": true,
  "messages_fetched": 25,
  "new_tmis": 3,
  "updated_tmis": 1,
  "errors": []
}
```

---

### Send TMI to Discord

Send a TMI announcement to the Discord channel.

```
POST /api/nod/discord.php?action=send
Content-Type: application/json

{
  "tmi_type": "GDP",
  "airport": "KJFK",
  "program_rate": 38,
  "start_time": "2026-02-01T14:00:00Z",
  "end_time": "2026-02-01T18:00:00Z",
  "scope": "BLANKET",
  "reason": "WEATHER"
}
```

**Response**

```json
{
  "success": true,
  "message_id": "1234567890123456789",
  "message_text": "GDP KJFK 38/HR 14Z-18Z BLANKET - WEATHER"
}
```

---

## Message Handling

### Parse Message

Manually parse a TMI message to extract structured data.

```
POST /api/nod/discord.php?action=parse
Content-Type: application/json

{
  "message": "GDP KJFK 38/HR 14Z-18Z BLANKET - WEATHER"
}
```

**Response**

```json
{
  "success": true,
  "parsed": {
    "tmi_type": "GDP",
    "airport": "KJFK",
    "program_rate": 38,
    "start_time": "14:00Z",
    "end_time": "18:00Z",
    "scope": "BLANKET",
    "reason": "WEATHER"
  },
  "confidence": 0.95
}
```

---

### Webhook Handler

Receive Discord webhook events (legacy integration).

```
POST /api/nod/discord.php?action=webhook
Content-Type: application/json

{
  "type": "MESSAGE_CREATE",
  "channel_id": "123456789012345678",
  "message": {
    "id": "1234567890123456789",
    "content": "GDP KJFK 38/HR 14Z-18Z BLANKET",
    "author": {"username": "TMI-Bot"},
    "timestamp": "2026-02-01T14:00:00Z"
  }
}
```

---

### End Discord TMI

Mark a Discord-ingested TMI as ended.

```
POST /api/nod/discord.php?action=end
Content-Type: application/json

{
  "discord_message_id": "1234567890123456789"
}
```

or

```json
{
  "tmi_id": 123
}
```

**Response**

```json
{
  "success": true,
  "tmi_id": 123,
  "ended_at": "2026-02-01T18:00:00Z"
}
```

---

## Configuration

Discord integration requires these settings in `load/config.php`:

```php
define('DISCORD_BOT_TOKEN', 'your-bot-token');
define('DISCORD_GUILD_ID', '123456789012345678');
define('DISCORD_TMI_CHANNEL_ID', '123456789012345678');
```

---

## Error Handling

**Discord Not Configured**

```json
{
  "success": false,
  "error": "Discord not configured"
}
```

**Unknown Action**

```json
{
  "error": "Unknown action: invalid"
}
```

---

## See Also

- [[NOD Dashboard]] - NAS Operations Dashboard user guide
- [[TMI API]] - Core TMI API
- [[API Reference]] - Complete API reference
