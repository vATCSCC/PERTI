/**
 * Discord Coordination Channel Cleanup Script
 *
 * Deletes all threads and messages in the #coordination channel.
 * Run this to clear test data before production deployment.
 *
 * Usage:
 *   node cleanup-coordination.js
 *   node cleanup-coordination.js --dry-run    # Preview without deleting
 *
 * @version 1.0.0
 */

require('dotenv').config();

const { Client, GatewayIntentBits, ChannelType } = require('discord.js');

// Configuration
const CONFIG = {
    token: process.env.DISCORD_BOT_TOKEN,
    guildId: process.env.DISCORD_GUILD_ID || '1039586513689780224',
    coordinationChannelId: process.env.COORDINATION_CHANNEL_ID || '1466013550450577491',
};

const dryRun = process.argv.includes('--dry-run');

if (dryRun) {
    console.log('=== DRY RUN MODE - No changes will be made ===\n');
}

const client = new Client({
    intents: [
        GatewayIntentBits.Guilds,
        GatewayIntentBits.GuildMessages,
    ],
});

async function cleanup() {
    console.log('Discord Coordination Channel Cleanup');
    console.log('====================================\n');

    try {
        // Get the guild
        const guild = await client.guilds.fetch(CONFIG.guildId);
        console.log(`Connected to guild: ${guild.name}`);

        // Get the coordination channel
        const channel = await guild.channels.fetch(CONFIG.coordinationChannelId);
        if (!channel) {
            console.error(`Channel ${CONFIG.coordinationChannelId} not found`);
            process.exit(1);
        }
        console.log(`Found coordination channel: #${channel.name}\n`);

        // 1. Delete all threads (archived and active)
        console.log('Fetching threads...');

        let threadsDeleted = 0;

        // Fetch active threads
        const activeThreads = await channel.threads.fetchActive();
        console.log(`Found ${activeThreads.threads.size} active threads`);

        for (const [threadId, thread] of activeThreads.threads) {
            console.log(`  ${dryRun ? '[DRY RUN] Would delete' : 'Deleting'} thread: ${thread.name} (${threadId})`);
            if (!dryRun) {
                try {
                    await thread.delete('Cleanup script - clearing test data');
                    threadsDeleted++;
                    // Rate limit protection
                    await sleep(500);
                } catch (err) {
                    console.log(`    Error deleting thread: ${err.message}`);
                }
            } else {
                threadsDeleted++;
            }
        }

        // Fetch archived threads (public)
        let hasMore = true;
        let before = undefined;

        while (hasMore) {
            const archivedThreads = await channel.threads.fetchArchived({
                type: 'public',
                fetchAll: true,
                before: before
            });

            console.log(`Found ${archivedThreads.threads.size} archived threads`);

            if (archivedThreads.threads.size === 0) {
                hasMore = false;
                break;
            }

            for (const [threadId, thread] of archivedThreads.threads) {
                console.log(`  ${dryRun ? '[DRY RUN] Would delete' : 'Deleting'} archived thread: ${thread.name} (${threadId})`);
                if (!dryRun) {
                    try {
                        await thread.delete('Cleanup script - clearing test data');
                        threadsDeleted++;
                        await sleep(500);
                    } catch (err) {
                        console.log(`    Error deleting thread: ${err.message}`);
                    }
                } else {
                    threadsDeleted++;
                }
                before = threadId;
            }

            hasMore = archivedThreads.hasMore;
        }

        // 2. Delete recent messages in the channel itself (up to 100)
        console.log('\nFetching channel messages...');

        let messagesDeleted = 0;
        const messages = await channel.messages.fetch({ limit: 100 });
        console.log(`Found ${messages.size} messages in channel`);

        for (const [messageId, message] of messages) {
            // Skip pinned messages
            if (message.pinned) {
                console.log(`  Skipping pinned message: ${messageId}`);
                continue;
            }

            console.log(`  ${dryRun ? '[DRY RUN] Would delete' : 'Deleting'} message: ${messageId} (${message.content.substring(0, 50)}...)`);
            if (!dryRun) {
                try {
                    await message.delete();
                    messagesDeleted++;
                    await sleep(500);
                } catch (err) {
                    console.log(`    Error deleting message: ${err.message}`);
                }
            } else {
                messagesDeleted++;
            }
        }

        // Summary
        console.log('\n====================================');
        console.log('Cleanup Summary');
        console.log('====================================');
        console.log(`Threads ${dryRun ? 'found' : 'deleted'}: ${threadsDeleted}`);
        console.log(`Messages ${dryRun ? 'found' : 'deleted'}: ${messagesDeleted}`);

        if (dryRun) {
            console.log('\nThis was a dry run. Run without --dry-run to actually delete.');
        } else {
            console.log('\nCleanup complete!');
        }

    } catch (error) {
        console.error('Error during cleanup:', error);
    } finally {
        client.destroy();
        process.exit(0);
    }
}

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

// Validate config
if (!CONFIG.token) {
    console.error('Error: DISCORD_BOT_TOKEN not set in .env file');
    process.exit(1);
}

// Start
client.once('ready', () => {
    console.log(`Logged in as ${client.user.tag}\n`);
    cleanup();
});

client.on('error', (error) => {
    console.error('Discord client error:', error);
    process.exit(1);
});

console.log('Connecting to Discord...');
client.login(CONFIG.token);
