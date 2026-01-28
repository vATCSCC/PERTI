# PERTI TMI Coordination Bot

Real-time Discord Gateway bot for processing TMI coordination reactions instantly.

## Overview

This bot connects to Discord via WebSocket (Gateway) and listens for reaction events on TMI coordination threads. When a reaction is added, it immediately calls the PHP API to process the approval/denial.

**Benefits over polling:**
- Instant reaction processing (vs 15-60 second polling delay)
- No wasted API calls checking for reactions
- Scales to any volume without additional load

## Prerequisites

- Node.js 18+
- npm
- Discord Bot Token (same one used by PHP app)

## DigitalOcean Droplet Setup

### 1. Create Droplet

1. Log into [DigitalOcean](https://cloud.digitalocean.com/)
2. Create Droplet:
   - **Image:** Ubuntu 24.04 LTS
   - **Plan:** Basic, $5/mo (1GB RAM, 1 vCPU)
   - **Datacenter:** Choose closest to your users
   - **Authentication:** SSH Key (recommended) or Password
3. Note the droplet's IP address

### 2. Initial Server Setup

```bash
# SSH into your droplet
ssh root@YOUR_DROPLET_IP

# Update packages
apt update && apt upgrade -y

# Install Node.js 20.x
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install -y nodejs

# Verify installation
node --version  # Should show v20.x.x
npm --version

# Create a non-root user for the bot
adduser botuser
usermod -aG sudo botuser

# Switch to bot user
su - botuser
```

### 3. Deploy the Bot

```bash
# As botuser
cd ~

# Clone or copy the discord-bot folder
# Option A: If you have git access
git clone YOUR_REPO_URL perti
cd perti/discord-bot

# Option B: Copy files manually via SCP
# (from your local machine)
# scp -r discord-bot/* botuser@YOUR_DROPLET_IP:~/discord-bot/

# Install dependencies
npm install

# Create environment file
cp .env.example .env
nano .env
```

### 4. Configure Environment

Edit `.env` with your values:

```env
# Discord Bot Token (from Discord Developer Portal - same as PHP app uses)
DISCORD_BOT_TOKEN=your_actual_bot_token_here

# Discord Guild (Server) ID where coordination happens
DISCORD_GUILD_ID=1039586513689780224

# Coordination Channel ID (parent channel where threads are created)
COORDINATION_CHANNEL_ID=1466013550450577491

# PHP API Configuration
API_BASE_URL=https://perti.vatcscc.org
API_KEY=

# Logging level
LOG_LEVEL=info
```

### 5. Test the Bot

```bash
# Run in foreground to test
npm start

# You should see:
# [timestamp] [INFO] Starting PERTI TMI Coordination Bot...
# [timestamp] [INFO] Bot logged in as vATCSCC TMU#7270
# [timestamp] [INFO] Watching coordination channel: 1466013550450577491
```

Test by adding a reaction to a coordination thread message.

### 6. Run as a Service (systemd)

Create a systemd service for automatic startup and recovery:

```bash
# Create service file
sudo nano /etc/systemd/system/perti-bot.service
```

Paste this content:

```ini
[Unit]
Description=PERTI TMI Coordination Bot
After=network.target

[Service]
Type=simple
User=botuser
WorkingDirectory=/home/botuser/discord-bot
ExecStart=/usr/bin/node bot.js
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal
Environment=NODE_ENV=production

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
# Reload systemd
sudo systemctl daemon-reload

# Enable on boot
sudo systemctl enable perti-bot

# Start the service
sudo systemctl start perti-bot

# Check status
sudo systemctl status perti-bot

# View logs
sudo journalctl -u perti-bot -f
```

## Monitoring

### View Logs

```bash
# Live logs
sudo journalctl -u perti-bot -f

# Last 100 lines
sudo journalctl -u perti-bot -n 100

# Logs since today
sudo journalctl -u perti-bot --since today
```

### Service Commands

```bash
sudo systemctl status perti-bot   # Check status
sudo systemctl restart perti-bot  # Restart
sudo systemctl stop perti-bot     # Stop
sudo systemctl start perti-bot    # Start
```

## Updating

```bash
# Stop the service
sudo systemctl stop perti-bot

# Pull updates (if using git)
cd ~/discord-bot
git pull

# Or replace files manually

# Install any new dependencies
npm install

# Restart
sudo systemctl start perti-bot
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
