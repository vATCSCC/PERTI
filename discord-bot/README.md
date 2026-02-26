# PERTI TMI Coordination Bot

Real-time Discord Gateway bot for processing TMI coordination reactions instantly.

## Overview

This bot connects to Discord via WebSocket (Gateway) and listens for reaction events on TMI coordination threads. When a facility representative adds a reaction to approve or deny a TMI proposal, the bot immediately calls the PHP API to process the vote.

**Benefits over polling:**
- Instant reaction processing (vs 15-60 second polling delay)
- No wasted API calls checking for reactions
- Scales to any volume without additional load

## Prerequisites

- Node.js 18+
- npm
- Discord Bot Token (from main VATUSA Discord server)

## Discord Server Requirements

The bot must be deployed to the **main VATUSA Discord server** (not the backup server) to access:

- The `#coordination` channel where TMI coordination threads are created
- Facility-specific emoji for approval reactions (e.g., :ZNY:, :ZDC:)
- DCC Staff and NTMO role verification for override permissions

### Required Credentials

Contact VATUSA/DCC staff to obtain:

1. **Bot Token** - From Discord Developer Portal (bot must be invited to main server)
2. **Guild ID** - The main VATUSA Discord server ID
3. **Coordination Channel ID** - The `#coordination` channel ID

## Deployment

### Production (Azure App Service)

The bot runs as part of the Azure App Service deployment. It is started automatically by `scripts/startup.sh` alongside all other daemons:

```bash
# In scripts/startup.sh:
cd /home/site/wwwroot/discord-bot && node bot.js >> /home/LogFiles/discord-bot.log 2>&1 &
```

No separate server is needed.

### Local Development

```bash
cd discord-bot
npm install
cp .env.example .env
# Edit .env with backup server credentials for testing
```

### Configure Environment

Edit `.env` with your values:

```env
# Discord Bot Token (from Discord Developer Portal)
# Bot must be invited to the main VATUSA Discord server
DISCORD_BOT_TOKEN=your_actual_bot_token_here

# Discord Guild (Server) ID - Main VATUSA Discord server
# Contact DCC staff for the production server ID
DISCORD_GUILD_ID=YOUR_GUILD_ID_HERE

# Coordination Channel ID (parent channel where threads are created)
# This is the #coordination channel in the main VATUSA Discord
COORDINATION_CHANNEL_ID=YOUR_COORDINATION_CHANNEL_ID_HERE

# PHP API Configuration
API_BASE_URL=https://perti.vatcscc.org
API_KEY=

# Logging level: debug, info, warn, error
LOG_LEVEL=info
```

### Development vs Production

| Environment             | Discord Server       | Notes                                               |
|-------------------------|----------------------|-----------------------------------------------------|
| **Development/Testing** | Backup Server        | [Join here](https://discord.gg/P5ZtKNzd)            |
| **Production**          | Main VATUSA Discord  | Requires DCC approval and credentials               |

The `.env.example` file contains backup server IDs for testing. For production deployment, you must obtain the main VATUSA Discord server credentials from DCC staff.

### Multi-Organization Discord Support

TMI advisories can be posted to multiple Discord servers simultaneously using `MultiDiscordAPI.php`. Each organization has its own webhook configuration:

```php
// In load/config.php
define('DISCORD_MULTI_ORG_ENABLED', true);
define('DISCORD_ORGANIZATIONS', [
    'VATUSA' => ['webhook_url' => '...', 'guild_id' => '...'],
    'VATCAN' => ['webhook_url' => '...', 'guild_id' => '...'],
]);
```

The bot's reaction processing calls `api/mgt/tmi/coordinate.php` via REST. The `cleanup-coordination.js` utility handles stale coordination threads.

### Test the Bot

```bash
# Run in foreground to test
npm start

# You should see:
# [timestamp] [INFO] Starting PERTI TMI Coordination Bot...
# [timestamp] [INFO] Bot logged in as vATCSCC TMU#7270
# [timestamp] [INFO] Watching coordination channel: 1466013550450577491
```

Test by adding a reaction to a coordination thread message.

## Monitoring

### View Logs (Azure App Service)

```bash
# Via Kudu SSH
tail -f /home/LogFiles/discord-bot.log

# Via Azure CLI
az webapp log tail --name vatcscc --resource-group perti-rg
```

## Troubleshooting

### Bot won't start

1. Check token is correct in `.env`
2. Verify bot has correct intents in Discord Developer Portal:
   - Server Members Intent (for role checking)
   - Message Content Intent (for message access)
3. Check logs: `sudo journalctl -u perti-bot -n 50`

### Reactions not processing

1. Verify bot is in the correct guild
2. Check coordination channel ID is correct
3. Verify threads are under that channel
4. Check API endpoint is reachable: `curl https://perti.vatcscc.org/api/mgt/tmi/coordinate.php?list=pending`

### API errors

1. Check API_BASE_URL is correct
2. Verify PHP API is accepting PUT requests
3. Check coordination_debug.log on the server

## Discord Developer Portal Settings

Ensure these are enabled for your bot at https://discord.com/developers/applications:

**Bot > Privileged Gateway Intents:**
- [x] Server Members Intent
- [x] Message Content Intent

**OAuth2 > URL Generator > Scopes:**
- [x] bot

**OAuth2 > URL Generator > Bot Permissions:**
- [x] Read Messages/View Channels
- [x] Read Message History
- [x] Add Reactions
