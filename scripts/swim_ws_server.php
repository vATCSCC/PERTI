#!/usr/bin/env php
<?php
/**
 * SWIM API WebSocket Server Daemon
 * 
 * Long-running daemon that accepts WebSocket connections and distributes
 * real-time flight data events to subscribed clients.
 * 
 * Usage:
 *   php scripts/swim_ws_server.php              # Run in foreground
 *   php scripts/swim_ws_server.php --debug      # Run with debug logging
 *   nohup php scripts/swim_ws_server.php &      # Run detached
 *   systemctl start swim-ws                     # Via systemd
 * 
 * @package PERTI\SWIM\WebSocket
 * @version 1.0.0
 * @since 2026-01-16
 */

declare(strict_types=1);
error_reporting(E_ALL);
set_time_limit(0);
ini_set('memory_limit', '256M');

// ============================================================================
// AUTOLOADER
// ============================================================================

$scriptDir = __DIR__;
$wwwroot = dirname($scriptDir);

// Try Composer autoloader first
$composerAutoload = $wwwroot . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
} else {
    // Manual class loading for development
    spl_autoload_register(function ($class) use ($wwwroot) {
        $prefix = 'PERTI\\SWIM\\WebSocket\\';
        $baseDir = $wwwroot . '/api/swim/v1/ws/';
        
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        
        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        
        if (file_exists($file)) {
            require $file;
        }
    });
    
    echo "WARNING: Composer autoloader not found. Run 'composer install' first.\n";
    echo "Using manual class loading for development.\n\n";
}

use PERTI\SWIM\WebSocket\WebSocketServer;
use PERTI\SWIM\WebSocket\ClientConnection;
use PERTI\SWIM\WebSocket\SubscriptionManager;

// Check for Ratchet
if (!class_exists('\Ratchet\Server\IoServer')) {
    die("ERROR: Ratchet not installed.\n" .
        "Run: composer install\n" .
        "Or:  composer require cboden/ratchet\n");
}

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;

// ============================================================================
// CONFIGURATION
// ============================================================================

$config = [
    // Server
    'host' => '0.0.0.0',
    'port' => 8080,
    
    // Authentication
    'auth_enabled' => true,
    
    // Rate limiting
    'rate_limit_msg_per_sec' => 10,
    
    // Heartbeat interval (seconds)
    'heartbeat_interval' => 30,
    
    // Event polling interval (seconds) 
    'event_poll_interval' => 0.5,
    
    // Event file (IPC with ADL daemon)
    'event_file' => sys_get_temp_dir() . '/swim_ws_events.json',
    
    // Logging
    'log_file' => file_exists('/home/LogFiles') 
        ? '/home/LogFiles/swim_ws.log' 
        : $scriptDir . '/swim_ws.log',
    'log_to_file' => true,
    'log_to_stdout' => true,
    
    // Debug mode
    'debug' => in_array('--debug', $argv),
];

// ============================================================================
// LOGGING
// ============================================================================

function logMessage(string $level, string $message, array $context = []): void
{
    global $config;
    
    $timestamp = gmdate('Y-m-d H:i:s');
    $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
    $line = "[{$timestamp}Z] [{$level}] {$message}{$contextStr}\n";
    
    if ($config['log_to_stdout']) {
        echo $line;
        flush();
    }
    
    if ($config['log_to_file'] && !empty($config['log_file'])) {
        @file_put_contents($config['log_file'], $line, FILE_APPEND | LOCK_EX);
    }
}

function logInfo(string $msg, array $ctx = []): void { logMessage('INFO', $msg, $ctx); }
function logError(string $msg, array $ctx = []): void { logMessage('ERROR', $msg, $ctx); }
function logWarn(string $msg, array $ctx = []): void { logMessage('WARN', $msg, $ctx); }
function logDebug(string $msg, array $ctx = []): void { 
    global $config;
    if ($config['debug']) {
        logMessage('DEBUG', $msg, $ctx); 
    }
}

// ============================================================================
// EVENT POLLING
// ============================================================================

/**
 * Poll for events from the event file (IPC with ADL daemon)
 */
function pollEvents(WebSocketServer $wsServer, string $eventFile): void
{
    if (!file_exists($eventFile)) {
        return;
    }
    
    $content = @file_get_contents($eventFile);
    if (empty($content)) {
        return;
    }
    
    $events = json_decode($content, true);
    if (empty($events) || !is_array($events)) {
        return;
    }
    
    // Clear the event file
    @file_put_contents($eventFile, '[]');
    
    // Publish events
    $count = count($events);
    if ($count > 0) {
        logDebug("Processing {$count} events");
        $wsServer->publishEvents($events);
    }
}

// ============================================================================
// MAIN SERVER
// ============================================================================

function runServer(array $config): void
{
    logInfo("=== SWIM WebSocket Server Starting ===", [
        'host' => $config['host'],
        'port' => $config['port'],
        'debug' => $config['debug'],
    ]);
    
    // Create WebSocket server component
    $wsServer = new WebSocketServer([
        'auth_enabled' => $config['auth_enabled'],
        'rate_limit_msg_per_sec' => $config['rate_limit_msg_per_sec'],
        'debug' => $config['debug'],
    ]);
    
    // Set logger
    $wsServer->setLogger(function ($level, $message, $context) {
        logMessage($level, "[WS] " . $message, $context);
    });
    
    // Create React event loop
    $loop = Loop::get();
    
    // Add heartbeat timer
    $loop->addPeriodicTimer($config['heartbeat_interval'], function () use ($wsServer) {
        if ($wsServer->getClientCount() > 0) {
            $wsServer->sendHeartbeat();
            logDebug("Heartbeat sent", ['clients' => $wsServer->getClientCount()]);
        }
    });
    
    // Add event polling timer
    $loop->addPeriodicTimer($config['event_poll_interval'], function () use ($wsServer, $config) {
        pollEvents($wsServer, $config['event_file']);
    });
    
    // Add stats logging timer (every 5 minutes)
    $loop->addPeriodicTimer(300, function () use ($wsServer) {
        $stats = $wsServer->getStats();
        logInfo("=== Server Stats ===", $stats);
    });
    
    // Create socket server
    $socket = new SocketServer("{$config['host']}:{$config['port']}", [], $loop);
    
    // Create HTTP/WebSocket server stack
    $server = new IoServer(
        new HttpServer(
            new WsServer($wsServer)
        ),
        $socket,
        $loop
    );
    
    logInfo("WebSocket server listening", [
        'url' => "ws://{$config['host']}:{$config['port']}",
    ]);
    
    // Handle signals
    if (function_exists('pcntl_signal')) {
        $shutdown = function ($sig) use ($loop) {
            logInfo("Received signal {$sig}, shutting down...");
            $loop->stop();
        };
        pcntl_signal(SIGTERM, $shutdown);
        pcntl_signal(SIGINT, $shutdown);
        
        // Add periodic signal check
        $loop->addPeriodicTimer(1, function () {
            pcntl_signal_dispatch();
        });
    }
    
    // Run the server
    $server->run();
    
    logInfo("=== WebSocket Server Stopped ===");
}

// ============================================================================
// LOCK FILE
// ============================================================================

$lockFile = $scriptDir . '/swim_ws.lock';
$lockFp = @fopen($lockFile, 'c+');

if ($lockFp === false) {
    die("ERROR: Cannot open lock file: {$lockFile}\n");
}

if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    die("ERROR: Another instance is already running (lock file: {$lockFile})\n");
}

ftruncate($lockFp, 0);
fwrite($lockFp, (string)getmypid());
fflush($lockFp);

// ============================================================================
// RUN
// ============================================================================

try {
    runServer($config);
} catch (Exception $e) {
    logError("Server error: " . $e->getMessage());
    exit(1);
} finally {
    // Cleanup
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    @unlink($lockFile);
}
