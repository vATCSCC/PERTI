<?php
/**
 * SWIM API WebSocket Client Connection Wrapper
 * 
 * Wraps a Ratchet connection with SWIM-specific metadata.
 * 
 * @package PERTI\SWIM\WebSocket
 * @version 1.0.0
 * @since 2026-01-16
 */

namespace PERTI\SWIM\WebSocket;

use Ratchet\ConnectionInterface;

class ClientConnection
{
    /** @var ConnectionInterface Underlying Ratchet connection */
    protected $conn;
    
    /** @var string Unique client ID */
    protected $id;
    
    /** @var string|null API key used for authentication */
    protected $apiKey;
    
    /** @var string Client tier (free, basic, pro, enterprise) */
    protected $tier = 'free';
    
    /** @var string ISO timestamp of connection */
    protected $connectedAt;
    
    /** @var int Messages sent to this client */
    protected $messagesSent = 0;
    
    /** @var int Messages received from this client */
    protected $messagesReceived = 0;
    
    /** @var array Rate limiting: [timestamp => count] */
    protected $rateLimitWindow = [];
    
    /** @var int Rate limit window size in seconds */
    protected $rateLimitWindowSize = 1;

    /**
     * Tier configuration
     */
    const TIER_LIMITS = [
        'free' => [
            'connections' => 5,
            'rate_limit' => 10,
        ],
        'basic' => [
            'connections' => 50,
            'rate_limit' => 100,
        ],
        'pro' => [
            'connections' => 500,
            'rate_limit' => 1000,
        ],
        'enterprise' => [
            'connections' => PHP_INT_MAX,
            'rate_limit' => PHP_INT_MAX,
        ],
    ];

    /**
     * Constructor
     * 
     * @param ConnectionInterface $conn Ratchet connection
     */
    public function __construct(ConnectionInterface $conn)
    {
        $this->conn = $conn;
        $this->id = $this->generateId();
        $this->connectedAt = gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * Generate unique client ID
     */
    protected function generateId(): string
    {
        return 'client_' . bin2hex(random_bytes(8));
    }

    /**
     * Get client ID
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get underlying connection
     */
    public function getConnection(): ConnectionInterface
    {
        return $this->conn;
    }

    /**
     * Get remote address
     */
    public function getRemoteAddress(): string
    {
        return $this->conn->remoteAddress ?? 'unknown';
    }

    /**
     * Get connected timestamp
     */
    public function getConnectedAt(): string
    {
        return $this->connectedAt;
    }

    /**
     * Set API key
     */
    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Get API key
     */
    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    /**
     * Set tier
     */
    public function setTier(string $tier): void
    {
        if (isset(self::TIER_LIMITS[$tier])) {
            $this->tier = $tier;
        }
    }

    /**
     * Get tier
     */
    public function getTier(): string
    {
        return $this->tier;
    }

    /**
     * Get tier rate limit
     */
    public function getRateLimit(): int
    {
        return self::TIER_LIMITS[$this->tier]['rate_limit'];
    }

    /**
     * Check rate limit
     * 
     * @param int|null $limit Override limit (default: tier limit)
     * @return bool True if under limit
     */
    public function checkRateLimit(?int $limit = null): bool
    {
        $limit = $limit ?? $this->getRateLimit();
        $now = time();
        
        // Clean old entries
        $this->rateLimitWindow = array_filter(
            $this->rateLimitWindow,
            fn($ts) => $ts >= $now - $this->rateLimitWindowSize,
            ARRAY_FILTER_USE_KEY
        );
        
        // Count current window
        $count = array_sum($this->rateLimitWindow);
        
        if ($count >= $limit) {
            return false;
        }
        
        // Record this message
        if (!isset($this->rateLimitWindow[$now])) {
            $this->rateLimitWindow[$now] = 0;
        }
        $this->rateLimitWindow[$now]++;
        
        return true;
    }

    /**
     * Increment messages sent counter
     */
    public function incrementMessagesSent(): void
    {
        $this->messagesSent++;
    }

    /**
     * Increment messages received counter
     */
    public function incrementMessagesReceived(): void
    {
        $this->messagesReceived++;
    }

    /**
     * Get messages sent
     */
    public function getMessagesSent(): int
    {
        return $this->messagesSent;
    }

    /**
     * Get messages received
     */
    public function getMessagesReceived(): int
    {
        return $this->messagesReceived;
    }

    /**
     * Send message to client
     * 
     * @param array $message Message array (will be JSON encoded)
     */
    public function send(array $message): void
    {
        $this->messagesSent++;
        $this->conn->send(json_encode($message));
    }

    /**
     * Close connection
     * 
     * @param int $code Close code
     */
    public function close(int $code = 1000): void
    {
        $this->conn->close($code);
    }

    /**
     * Get client info array
     */
    public function getInfo(): array
    {
        return [
            'id' => $this->id,
            'tier' => $this->tier,
            'connected_at' => $this->connectedAt,
            'remote_address' => $this->getRemoteAddress(),
            'messages_sent' => $this->messagesSent,
            'messages_received' => $this->messagesReceived,
        ];
    }
}
