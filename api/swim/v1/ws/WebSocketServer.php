<?php
/**
 * SWIM API WebSocket Server
 * 
 * Main Ratchet WebSocket server component for real-time flight data distribution.
 * 
 * @package PERTI\SWIM\WebSocket
 * @version 1.0.0
 * @since 2026-01-16
 */

namespace PERTI\SWIM\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Exception;

class WebSocketServer implements MessageComponentInterface
{
    /** @var \SplObjectStorage Connected clients */
    protected $clients;
    
    /** @var SubscriptionManager Subscription handler */
    protected $subscriptions;
    
    /** @var array Configuration */
    protected $config;
    
    /** @var callable|null Logger function */
    protected $logger;
    
    /** @var int Server start time */
    protected $startTime;
    
    /** @var array Statistics */
    protected $stats;
    
    /** @var resource|null Database connection for auth */
    protected $dbConn;
    
    /** @var array Cached API keys (key => ['tier' => string, 'expires' => int]) */
    protected $keyCache = [];
    
    /** @var int Key cache TTL in seconds */
    protected $keyCacheTtl = 300;
    
    /** @var array Connection counts per tier */
    protected $connectionsByTier = [];
    
    /** @var array Max connections per tier */
    protected $tierLimits = [
        'public' => 5,
        'developer' => 50,
        'partner' => 500,
        'system' => 10000,
    ];

    /**
     * Close codes
     */
    const CLOSE_NORMAL = 1000;
    const CLOSE_GOING_AWAY = 1001;
    const CLOSE_PROTOCOL_ERROR = 1002;
    const CLOSE_UNSUPPORTED = 1003;
    const CLOSE_AUTH_FAILED = 4001;
    const CLOSE_RATE_LIMITED = 4002;
    const CLOSE_INVALID_PAYLOAD = 4003;

    /**
     * Constructor
     * 
     * @param array $config Server configuration
     */
    public function __construct(array $config = [])
    {
        $this->clients = new \SplObjectStorage();
        $this->subscriptions = new SubscriptionManager();
        $this->startTime = time();
        
        $this->config = array_merge([
            'auth_enabled' => true,
            'rate_limit_msg_per_sec' => 10,
            'heartbeat_interval' => 30,
            'max_message_size' => 65536,
            'allowed_origins' => ['*'],
            'debug' => false,
            'db_host' => null,
            'db_name' => null,
            'db_user' => null,
            'db_pass' => null,
        ], $config);
        
        $this->stats = [
            'connections_total' => 0,
            'messages_received' => 0,
            'messages_sent' => 0,
            'events_published' => 0,
            'auth_failures' => 0,
        ];
        
        // Initialize database connection for API key validation
        $this->initDatabase();
        
        $this->log('INFO', 'WebSocket server initialized');
    }
    
    /**
     * Initialize database connection
     */
    protected function initDatabase(): void
    {
        if (empty($this->config['db_host'])) {
            $this->log('WARN', 'No database config - using debug mode auth');
            return;
        }
        
        $connInfo = [
            'Database' => $this->config['db_name'],
            'Uid' => $this->config['db_user'],
            'PWD' => $this->config['db_pass'],
            'Encrypt' => true,
            'TrustServerCertificate' => false,
            'LoginTimeout' => 10,
            'ConnectionPooling' => true,
        ];
        
        $this->dbConn = @sqlsrv_connect($this->config['db_host'], $connInfo);
        
        if ($this->dbConn === false) {
            $errors = sqlsrv_errors();
            $this->log('ERROR', 'Database connection failed', ['errors' => $errors]);
            $this->dbConn = null;
        } else {
            $this->log('INFO', 'Database connected for auth');
        }
    }

    /**
     * Set logger callback
     * 
     * @param callable $logger Logger function(level, message, context)
     */
    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Log message
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            ($this->logger)($level, $message, $context);
        } elseif ($this->config['debug']) {
            $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
            echo "[" . gmdate('Y-m-d H:i:s') . "Z] [{$level}] {$message}{$contextStr}\n";
        }
    }

    /**
     * Handle new connection
     */
    public function onOpen(ConnectionInterface $conn): void
    {
        $this->stats['connections_total']++;
        
        // Create client wrapper
        $client = new ClientConnection($conn);
        
        // Parse query parameters for API key
        $queryString = $conn->httpRequest->getUri()->getQuery();
        parse_str($queryString, $params);
        
        // Check for API key in query or headers
        $apiKey = $params['api_key'] ?? null;
        if (!$apiKey) {
            $headers = $conn->httpRequest->getHeaders();
            $apiKey = $headers['X-SWIM-API-Key'][0] ?? null;
        }
        
        // Authenticate if required
        if ($this->config['auth_enabled']) {
            if (!$apiKey || !$this->authenticate($apiKey, $client)) {
                $this->stats['auth_failures']++;
                $this->log('WARN', 'Auth failed', ['remote' => $client->getRemoteAddress()]);
                $this->sendError($conn, 'AUTH_FAILED', 'Invalid or missing API key');
                $conn->close(self::CLOSE_AUTH_FAILED);
                return;
            }
        }
        
        // Check tier connection limits
        $tier = $client->getTier() ?? 'public';
        $maxConnections = $this->tierLimits[$tier] ?? $this->tierLimits['public'];
        $currentConnections = $this->connectionsByTier[$tier] ?? 0;
        
        if ($currentConnections >= $maxConnections) {
            $this->log('WARN', 'Tier limit reached', [
                'tier' => $tier,
                'current' => $currentConnections,
                'max' => $maxConnections,
            ]);
            $this->sendError($conn, 'CONNECTION_LIMIT', "Connection limit reached for tier: {$tier}");
            $conn->close(self::CLOSE_RATE_LIMITED);
            return;
        }
        
        // Increment tier connection count
        $this->connectionsByTier[$tier] = $currentConnections + 1;
        
        // Store client
        $this->clients->attach($conn, $client);
        
        $this->log('INFO', 'Client connected', [
            'id' => $client->getId(),
            'tier' => $tier,
            'remote' => $client->getRemoteAddress(),
            'total' => $this->clients->count(),
        ]);
        
        // Send welcome message
        $this->send($conn, [
            'type' => 'connected',
            'data' => [
                'client_id' => $client->getId(),
                'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
                'version' => '1.0.0',
            ],
        ]);
    }

    /**
     * Handle incoming message
     */
    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $this->stats['messages_received']++;
        
        /** @var ClientConnection $client */
        $client = $this->clients[$from];
        
        // Check message size
        if (strlen($msg) > $this->config['max_message_size']) {
            $this->sendError($from, 'MESSAGE_TOO_LARGE', 'Message exceeds size limit');
            return;
        }
        
        // Check rate limit
        if (!$client->checkRateLimit($this->config['rate_limit_msg_per_sec'])) {
            $this->sendError($from, 'RATE_LIMITED', 'Too many messages');
            return;
        }
        
        // Parse JSON
        $data = json_decode($msg, true);
        if ($data === null) {
            $this->sendError($from, 'INVALID_JSON', 'Could not parse message as JSON');
            return;
        }
        
        // Handle action
        $action = $data['action'] ?? null;
        
        switch ($action) {
            case 'subscribe':
                $this->handleSubscribe($from, $client, $data);
                break;
                
            case 'unsubscribe':
                $this->handleUnsubscribe($from, $client, $data);
                break;
                
            case 'ping':
                $this->send($from, [
                    'type' => 'pong',
                    'timestamp' => gmdate('Y-m-d\TH:i:s.v\Z'),
                ]);
                break;
                
            case 'status':
                $this->handleStatus($from, $client);
                break;
                
            default:
                $this->sendError($from, 'UNKNOWN_ACTION', "Unknown action: {$action}");
        }
    }

    /**
     * Handle subscribe action
     */
    protected function handleSubscribe(ConnectionInterface $conn, ClientConnection $client, array $data): void
    {
        $channels = $data['channels'] ?? [];
        $filters = $data['filters'] ?? [];
        
        if (empty($channels)) {
            $this->sendError($conn, 'INVALID_SUBSCRIBE', 'No channels specified');
            return;
        }
        
        // Validate channels
        $validChannels = [
            'flight.position', 'flight.positions',
            'flight.departed', 'flight.arrived',
            'flight.created', 'flight.updated', 'flight.deleted',
            'tmi.issued', 'tmi.modified', 'tmi.released',
            'tmi.*', 'flight.*', 'system.*',
            'system.heartbeat',
        ];
        
        foreach ($channels as $channel) {
            if (!in_array($channel, $validChannels) && !preg_match('/^(flight|tmi|system)\.\*$/', $channel)) {
                $this->sendError($conn, 'INVALID_CHANNEL', "Unknown channel: {$channel}");
                return;
            }
        }
        
        // Validate filters
        $validatedFilters = $this->validateFilters($filters);
        if ($validatedFilters === false) {
            $this->sendError($conn, 'INVALID_FILTER', 'Invalid filter specification');
            return;
        }
        
        // Store subscription
        $this->subscriptions->subscribe($client->getId(), $channels, $validatedFilters);
        
        $this->log('INFO', 'Client subscribed', [
            'id' => $client->getId(),
            'channels' => $channels,
            'filters' => $validatedFilters,
        ]);
        
        // Confirm subscription
        $this->send($conn, [
            'type' => 'subscribed',
            'channels' => $channels,
            'filters' => $validatedFilters,
        ]);
    }

    /**
     * Handle unsubscribe action
     */
    protected function handleUnsubscribe(ConnectionInterface $conn, ClientConnection $client, array $data): void
    {
        $channels = $data['channels'] ?? [];
        
        if (empty($channels)) {
            // Unsubscribe from all
            $this->subscriptions->unsubscribeAll($client->getId());
        } else {
            $this->subscriptions->unsubscribe($client->getId(), $channels);
        }
        
        $this->send($conn, [
            'type' => 'unsubscribed',
            'channels' => $channels ?: ['all'],
        ]);
    }

    /**
     * Handle status request
     */
    protected function handleStatus(ConnectionInterface $conn, ClientConnection $client): void
    {
        $subs = $this->subscriptions->getSubscriptions($client->getId());
        
        $this->send($conn, [
            'type' => 'status',
            'data' => [
                'client_id' => $client->getId(),
                'connected_at' => $client->getConnectedAt(),
                'subscriptions' => $subs,
                'messages_sent' => $client->getMessagesSent(),
            ],
        ]);
    }

    /**
     * Validate subscription filters
     * 
     * @return array|false Validated filters or false on error
     */
    protected function validateFilters(array $filters)
    {
        $validated = [];
        
        // Airport filter
        if (isset($filters['airports'])) {
            if (!is_array($filters['airports'])) {
                return false;
            }
            $validated['airports'] = array_map('strtoupper', $filters['airports']);
        }
        
        // ARTCC filter
        if (isset($filters['artccs'])) {
            if (!is_array($filters['artccs'])) {
                return false;
            }
            $validated['artccs'] = array_map('strtoupper', $filters['artccs']);
        }
        
        // Callsign prefix filter
        if (isset($filters['callsign_prefix'])) {
            if (!is_array($filters['callsign_prefix'])) {
                return false;
            }
            $validated['callsign_prefix'] = array_map('strtoupper', $filters['callsign_prefix']);
        }
        
        // Bounding box filter
        if (isset($filters['bbox'])) {
            $bbox = $filters['bbox'];
            if (!isset($bbox['north'], $bbox['south'], $bbox['east'], $bbox['west'])) {
                return false;
            }
            if ($bbox['north'] <= $bbox['south'] || $bbox['east'] <= $bbox['west']) {
                return false;
            }
            $validated['bbox'] = [
                'north' => (float)$bbox['north'],
                'south' => (float)$bbox['south'],
                'east' => (float)$bbox['east'],
                'west' => (float)$bbox['west'],
            ];
        }
        
        return $validated;
    }

    /**
     * Handle connection close
     */
    public function onClose(ConnectionInterface $conn): void
    {
        if (!$this->clients->contains($conn)) {
            return;
        }
        
        /** @var ClientConnection $client */
        $client = $this->clients[$conn];
        
        // Decrement tier connection count
        $tier = $client->getTier() ?? 'public';
        if (isset($this->connectionsByTier[$tier]) && $this->connectionsByTier[$tier] > 0) {
            $this->connectionsByTier[$tier]--;
        }
        
        // Clean up subscriptions
        $this->subscriptions->unsubscribeAll($client->getId());
        
        // Remove client
        $this->clients->detach($conn);
        
        $this->log('INFO', 'Client disconnected', [
            'id' => $client->getId(),
            'tier' => $tier,
            'total' => $this->clients->count(),
        ]);
    }

    /**
     * Handle connection error
     */
    public function onError(ConnectionInterface $conn, Exception $e): void
    {
        $clientId = 'unknown';
        if ($this->clients->contains($conn)) {
            $clientId = $this->clients[$conn]->getId();
        }
        
        $this->log('ERROR', 'Connection error', [
            'id' => $clientId,
            'error' => $e->getMessage(),
        ]);
        
        $conn->close();
    }

    /**
     * Authenticate API key
     */
    protected function authenticate(string $apiKey, ClientConnection $client): bool
    {
        // Check cache first
        if (isset($this->keyCache[$apiKey])) {
            $cached = $this->keyCache[$apiKey];
            if ($cached['expires'] > time()) {
                $client->setApiKey($apiKey);
                $client->setTier($cached['tier']);
                $this->log('DEBUG', 'Auth from cache', ['tier' => $cached['tier']]);
                return true;
            }
            // Cache expired
            unset($this->keyCache[$apiKey]);
        }
        
        // If no database connection, fall back to debug mode
        if ($this->dbConn === null) {
            if ($this->config['debug'] && !empty($apiKey)) {
                $client->setApiKey($apiKey);
                $client->setTier('developer');
                $this->log('DEBUG', 'Auth via debug mode (no DB)');
                return true;
            }
            return false;
        }
        
        // Query database for API key
        $sql = "
            SELECT tier, is_active, expires_at
            FROM dbo.swim_api_keys
            WHERE api_key = ?
        ";
        
        $stmt = @sqlsrv_query($this->dbConn, $sql, [$apiKey]);
        if ($stmt === false) {
            $this->log('ERROR', 'Auth query failed', ['errors' => sqlsrv_errors()]);
            // Fall back to debug mode on DB error
            if ($this->config['debug'] && !empty($apiKey)) {
                $client->setApiKey($apiKey);
                $client->setTier('developer');
                return true;
            }
            return false;
        }
        
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        if (!$row) {
            $this->log('DEBUG', 'API key not found', ['key' => substr($apiKey, 0, 20) . '...']);
            return false;
        }
        
        // Check if active
        if (!$row['is_active']) {
            $this->log('DEBUG', 'API key inactive');
            return false;
        }
        
        // Check expiration
        if ($row['expires_at'] !== null) {
            $expiresAt = $row['expires_at'];
            if ($expiresAt instanceof \DateTime) {
                $expiresAt = $expiresAt->getTimestamp();
            } else {
                $expiresAt = strtotime($expiresAt);
            }
            if ($expiresAt < time()) {
                $this->log('DEBUG', 'API key expired');
                return false;
            }
        }
        
        $tier = $row['tier'] ?? 'public';
        
        // Cache the result
        $this->keyCache[$apiKey] = [
            'tier' => $tier,
            'expires' => time() + $this->keyCacheTtl,
        ];
        
        // Update last_used_at (non-blocking, fire-and-forget)
        $updateSql = "UPDATE dbo.swim_api_keys SET last_used_at = GETUTCDATE() WHERE api_key = ?";
        @sqlsrv_query($this->dbConn, $updateSql, [$apiKey]);
        
        $client->setApiKey($apiKey);
        $client->setTier($tier);
        
        $this->log('INFO', 'Auth successful', ['tier' => $tier]);
        return true;
    }

    /**
     * Publish events to subscribed clients
     * 
     * @param array $events Array of events to publish
     */
    public function publishEvents(array $events): void
    {
        foreach ($events as $event) {
            $this->publishEvent($event['type'], $event['data'] ?? []);
        }
    }

    /**
     * Publish single event to subscribed clients
     * 
     * @param string $eventType Event type (e.g., 'flight.position')
     * @param array $data Event data
     */
    public function publishEvent(string $eventType, array $data): void
    {
        $this->stats['events_published']++;
        
        $message = [
            'type' => $eventType,
            'timestamp' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'data' => $data,
        ];
        
        $subscribedClients = $this->subscriptions->getSubscribersForEvent($eventType, $data);
        
        foreach ($this->clients as $conn) {
            /** @var ClientConnection $client */
            $client = $this->clients[$conn];
            
            if (in_array($client->getId(), $subscribedClients)) {
                $this->send($conn, $message);
            }
        }
    }

    /**
     * Broadcast message to all connected clients
     */
    public function broadcast(array $message): void
    {
        foreach ($this->clients as $conn) {
            $this->send($conn, $message);
        }
    }

    /**
     * Send heartbeat to all clients
     */
    public function sendHeartbeat(): void
    {
        $message = [
            'type' => 'system.heartbeat',
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'data' => [
                'connected_clients' => $this->clients->count(),
                'uptime_seconds' => time() - $this->startTime,
            ],
        ];
        
        $this->broadcast($message);
    }

    /**
     * Send message to connection
     */
    protected function send(ConnectionInterface $conn, array $message): void
    {
        $this->stats['messages_sent']++;
        
        if ($this->clients->contains($conn)) {
            $this->clients[$conn]->incrementMessagesSent();
        }
        
        $conn->send(json_encode($message));
    }

    /**
     * Send error message
     */
    protected function sendError(ConnectionInterface $conn, string $code, string $message): void
    {
        $this->send($conn, [
            'type' => 'error',
            'code' => $code,
            'message' => $message,
        ]);
    }

    /**
     * Get server statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'connected_clients' => $this->clients->count(),
            'connections_by_tier' => $this->connectionsByTier,
            'uptime_seconds' => time() - $this->startTime,
            'subscriptions' => $this->subscriptions->getStats(),
        ]);
    }

    /**
     * Get connected client count
     */
    public function getClientCount(): int
    {
        return $this->clients->count();
    }
}
