/**
 * PERTI TMI Coordination Bot
 *
 * Discord Gateway bot for real-time reaction processing.
 * Listens for reactions on coordination threads and calls PHP API.
 *
 * @version 1.0.0
 */

require('dotenv').config();

const { Client, GatewayIntentBits, Partials } = require('discord.js');

// =============================================================================
// CONFIGURATION
// =============================================================================

const CONFIG = {
    // Discord Bot Token (from .env)
    token: process.env.DISCORD_BOT_TOKEN,

    // PHP API endpoint for processing reactions
    apiBaseUrl: process.env.API_BASE_URL || 'https://perti.vatcscc.org',
    apiKey: process.env.API_KEY || '',

    // Coordination channel ID (reactions in threads under this channel)
    coordinationChannelId: process.env.COORDINATION_CHANNEL_ID || '1466013550450577491',

    // Guild (server) ID for the bot
    guildId: process.env.DISCORD_GUILD_ID || '1039586513689780224',

    // Logging level: 'debug', 'info', 'warn', 'error'
    logLevel: process.env.LOG_LEVEL || 'info',
};

// =============================================================================
// LOGGING
// =============================================================================

const LOG_LEVELS = { debug: 0, info: 1, warn: 2, error: 3 };
const currentLogLevel = LOG_LEVELS[CONFIG.logLevel] || 1;

function log(level, ...args) {
    if (LOG_LEVELS[level] >= currentLogLevel) {
        const timestamp = new Date().toISOString();
        console.log(`[${timestamp}] [${level.toUpperCase()}]`, ...args);
    }
}

// =============================================================================
// DISCORD CLIENT SETUP
// =============================================================================

const client = new Client({
    intents: [
        GatewayIntentBits.Guilds,
        GatewayIntentBits.GuildMembers,  // Required for fetching user roles
        GatewayIntentBits.GuildMessages,
        GatewayIntentBits.GuildMessageReactions,
        GatewayIntentBits.MessageContent,
    ],
    partials: [
        Partials.Message,
        Partials.Channel,
        Partials.Reaction,
    ],
});

// =============================================================================
// EVENT HANDLERS
// =============================================================================

client.once('ready', () => {
    log('info', `Bot logged in as ${client.user.tag}`);
    log('info', `Watching coordination channel: ${CONFIG.coordinationChannelId}`);
    log('info', `API endpoint: ${CONFIG.apiBaseUrl}`);
});

client.on('error', (error) => {
    log('error', 'Discord client error:', error.message);
});

client.on('warn', (warning) => {
    log('warn', 'Discord client warning:', warning);
});

/**
 * Handle reaction add events
 */
client.on('messageReactionAdd', async (reaction, user) => {
    try {
        // Ignore bot reactions
        if (user.bot) return;

        // Fetch partial data if needed
        if (reaction.partial) {
            try {
                await reaction.fetch();
            } catch (err) {
                log('error', 'Failed to fetch reaction:', err.message);
                return;
            }
        }

        const message = reaction.message;
        const channel = message.channel;

        // Check if this is a thread under the coordination channel
        if (!isCoordinationThread(channel)) {
            log('debug', `Ignoring reaction in non-coordination channel: ${channel.id}`);
            return;
        }

        log('info', `Reaction added: ${getEmojiString(reaction.emoji)} by ${user.username} on message ${message.id} in thread ${channel.id}`);

        // Process the reaction via PHP API
        await processReaction(reaction, user, 'add');

    } catch (error) {
        log('error', 'Error handling reaction add:', error.message);
    }
});

/**
 * Handle reaction remove events
 */
client.on('messageReactionRemove', async (reaction, user) => {
    try {
        // Ignore bot reactions
        if (user.bot) return;

        // Fetch partial data if needed
        if (reaction.partial) {
            try {
                await reaction.fetch();
            } catch (err) {
                log('debug', 'Failed to fetch removed reaction (may be deleted):', err.message);
                return;
            }
        }

        const message = reaction.message;
        const channel = message.channel;

        // Check if this is a thread under the coordination channel
        if (!isCoordinationThread(channel)) {
            return;
        }

        log('info', `Reaction removed: ${getEmojiString(reaction.emoji)} by ${user.username} on message ${message.id}`);

        // Process the reaction removal via PHP API
        await processReaction(reaction, user, 'remove');

    } catch (error) {
        log('error', 'Error handling reaction remove:', error.message);
    }
});

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Check if a channel is a coordination thread
 */
function isCoordinationThread(channel) {
    // Check if it's a thread
    if (!channel.isThread()) return false;

    // Check if parent is the coordination channel
    if (channel.parentId === CONFIG.coordinationChannelId) return true;

    // Also accept if the thread itself is being watched
    // (for threads created before this check)
    return false;
}

/**
 * Get emoji string for logging/API
 */
function getEmojiString(emoji) {
    if (emoji.id) {
        // Custom emoji: <:name:id> or name:id
        return `${emoji.name}:${emoji.id}`;
    }
    // Unicode emoji
    return emoji.name;
}

/**
 * Process a reaction via the PHP API
 */
async function processReaction(reaction, user, action) {
    const message = reaction.message;
    const channel = message.channel;
    const emoji = reaction.emoji;

    // Ignore reaction removals for now (API only handles adds)
    if (action === 'remove') {
        log('debug', 'Ignoring reaction removal (not implemented in API)');
        return;
    }

    // Get user's guild member for roles (send IDs for reliable matching)
    let userRoles = [];
    try {
        const guild = client.guilds.cache.get(CONFIG.guildId);
        if (guild) {
            const member = await guild.members.fetch(user.id);
            if (member) {
                userRoles = member.roles.cache.map(r => r.id);
            }
        }
    } catch (err) {
        log('debug', 'Could not fetch user roles:', err.message);
    }

    // Build API request payload - match coordinate.php expected fields
    const payload = {
        message_id: message.id,
        emoji: getEmojiString(emoji),
        emoji_id: emoji.id || null,
        discord_user_id: user.id,
        discord_username: user.username,
        user_roles: userRoles,
    };

    log('debug', 'Sending to API:', JSON.stringify(payload));

    try {
        const response = await fetch(`${CONFIG.apiBaseUrl}/api/mgt/tmi/coordinate.php`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-API-Key': CONFIG.apiKey,
                'X-Bot-Request': 'true',
            },
            body: JSON.stringify(payload),
        });

        const data = await response.json();

        if (data.success) {
            log('info', `API processed reaction: ${data.message || 'OK'}`);

            // If proposal status changed, log it
            if (data.proposal_status) {
                log('info', `Proposal status: ${data.proposal_status}`);
            }
            if (data.facility_updated) {
                log('info', `Facility ${data.facility_updated} updated to ${data.facility_status}`);
            }
        } else {
            log('warn', `API returned error: ${data.error || 'Unknown error'}`);
        }

    } catch (error) {
        log('error', 'API request failed:', error.message);
    }
}

// =============================================================================
// GRACEFUL SHUTDOWN
// =============================================================================

process.on('SIGINT', () => {
    log('info', 'Received SIGINT, shutting down...');
    client.destroy();
    process.exit(0);
});

process.on('SIGTERM', () => {
    log('info', 'Received SIGTERM, shutting down...');
    client.destroy();
    process.exit(0);
});

// =============================================================================
// START BOT
// =============================================================================

if (!CONFIG.token) {
    log('error', 'DISCORD_BOT_TOKEN not set in environment');
    process.exit(1);
}

log('info', 'Starting PERTI TMI Coordination Bot...');
client.login(CONFIG.token);
