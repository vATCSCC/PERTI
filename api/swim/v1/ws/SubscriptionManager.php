<?php
/**
 * SWIM API WebSocket Subscription Manager
 * 
 * Manages client subscriptions to event channels with filtering.
 * 
 * @package PERTI\SWIM\WebSocket
 * @version 1.0.0
 * @since 2026-01-16
 */

namespace PERTI\SWIM\WebSocket;

class SubscriptionManager
{
    /**
     * @var array Subscriptions indexed by client ID
     * Structure: [
     *   'client_id' => [
     *     'channels' => ['flight.position', 'tmi.*'],
     *     'filters' => ['airports' => ['KJFK'], ...]
     *   ]
     * ]
     */
    protected $subscriptions = [];

    /**
     * @var array Channel index for quick lookups
     * Structure: [
     *   'flight.position' => ['client_1', 'client_2'],
     *   'flight.*' => ['client_3'],
     * ]
     */
    protected $channelIndex = [];

    /**
     * Subscribe client to channels with filters
     * 
     * @param string $clientId Client identifier
     * @param array $channels Channel names to subscribe to
     * @param array $filters Filter criteria
     */
    public function subscribe(string $clientId, array $channels, array $filters = []): void
    {
        // Initialize or merge subscription
        if (!isset($this->subscriptions[$clientId])) {
            $this->subscriptions[$clientId] = [
                'channels' => [],
                'filters' => [],
            ];
        }
        
        // Add channels
        foreach ($channels as $channel) {
            if (!in_array($channel, $this->subscriptions[$clientId]['channels'])) {
                $this->subscriptions[$clientId]['channels'][] = $channel;
                
                // Update channel index
                if (!isset($this->channelIndex[$channel])) {
                    $this->channelIndex[$channel] = [];
                }
                if (!in_array($clientId, $this->channelIndex[$channel])) {
                    $this->channelIndex[$channel][] = $clientId;
                }
            }
        }
        
        // Merge filters
        $this->subscriptions[$clientId]['filters'] = array_merge(
            $this->subscriptions[$clientId]['filters'],
            $filters
        );
    }

    /**
     * Unsubscribe client from specific channels
     * 
     * @param string $clientId Client identifier
     * @param array $channels Channels to unsubscribe from
     */
    public function unsubscribe(string $clientId, array $channels): void
    {
        if (!isset($this->subscriptions[$clientId])) {
            return;
        }
        
        foreach ($channels as $channel) {
            // Remove from subscription
            $this->subscriptions[$clientId]['channels'] = array_filter(
                $this->subscriptions[$clientId]['channels'],
                fn($c) => $c !== $channel
            );
            
            // Remove from channel index
            if (isset($this->channelIndex[$channel])) {
                $this->channelIndex[$channel] = array_filter(
                    $this->channelIndex[$channel],
                    fn($id) => $id !== $clientId
                );
            }
        }
    }

    /**
     * Unsubscribe client from all channels
     * 
     * @param string $clientId Client identifier
     */
    public function unsubscribeAll(string $clientId): void
    {
        if (!isset($this->subscriptions[$clientId])) {
            return;
        }
        
        // Remove from all channel indexes
        foreach ($this->subscriptions[$clientId]['channels'] as $channel) {
            if (isset($this->channelIndex[$channel])) {
                $this->channelIndex[$channel] = array_filter(
                    $this->channelIndex[$channel],
                    fn($id) => $id !== $clientId
                );
            }
        }
        
        // Remove subscription
        unset($this->subscriptions[$clientId]);
    }

    /**
     * Get subscriptions for a client
     * 
     * @param string $clientId Client identifier
     * @return array|null Subscription data or null
     */
    public function getSubscriptions(string $clientId): ?array
    {
        return $this->subscriptions[$clientId] ?? null;
    }

    /**
     * Get all client IDs subscribed to an event type that match filters
     * 
     * @param string $eventType Event type (e.g., 'flight.position')
     * @param array $eventData Event data for filter matching
     * @return array Client IDs
     */
    public function getSubscribersForEvent(string $eventType, array $eventData): array
    {
        $subscribers = [];
        
        // Get direct subscribers
        if (isset($this->channelIndex[$eventType])) {
            $subscribers = array_merge($subscribers, $this->channelIndex[$eventType]);
        }
        
        // Get wildcard subscribers (e.g., 'flight.*')
        $parts = explode('.', $eventType);
        if (count($parts) === 2) {
            $wildcard = $parts[0] . '.*';
            if (isset($this->channelIndex[$wildcard])) {
                $subscribers = array_merge($subscribers, $this->channelIndex[$wildcard]);
            }
        }
        
        // Remove duplicates
        $subscribers = array_unique($subscribers);
        
        // Apply filters
        $filtered = [];
        foreach ($subscribers as $clientId) {
            if ($this->matchesFilters($clientId, $eventData)) {
                $filtered[] = $clientId;
            }
        }
        
        return $filtered;
    }

    /**
     * Check if event data matches client's filters
     * 
     * @param string $clientId Client identifier
     * @param array $eventData Event data
     * @return bool True if matches (or no filters)
     */
    protected function matchesFilters(string $clientId, array $eventData): bool
    {
        $filters = $this->subscriptions[$clientId]['filters'] ?? [];
        
        // No filters = match everything
        if (empty($filters)) {
            return true;
        }
        
        // Airport filter
        if (isset($filters['airports']) && !empty($filters['airports'])) {
            $dep = strtoupper($eventData['dep'] ?? $eventData['departure_aerodrome'] ?? '');
            $arr = strtoupper($eventData['arr'] ?? $eventData['arrival_aerodrome'] ?? '');
            
            if (!in_array($dep, $filters['airports']) && !in_array($arr, $filters['airports'])) {
                return false;
            }
        }
        
        // ARTCC filter
        if (isset($filters['artccs']) && !empty($filters['artccs'])) {
            $artcc = strtoupper($eventData['current_artcc'] ?? $eventData['artcc'] ?? '');
            
            if (!in_array($artcc, $filters['artccs'])) {
                return false;
            }
        }
        
        // Callsign prefix filter
        if (isset($filters['callsign_prefix']) && !empty($filters['callsign_prefix'])) {
            $callsign = strtoupper($eventData['callsign'] ?? '');
            
            $matched = false;
            foreach ($filters['callsign_prefix'] as $prefix) {
                if (strpos($callsign, $prefix) === 0) {
                    $matched = true;
                    break;
                }
            }
            
            if (!$matched) {
                return false;
            }
        }
        
        // Bounding box filter
        if (isset($filters['bbox'])) {
            $lat = $eventData['lat'] ?? $eventData['latitude'] ?? null;
            $lon = $eventData['lon'] ?? $eventData['longitude'] ?? null;
            
            if ($lat === null || $lon === null) {
                return false;
            }
            
            $bbox = $filters['bbox'];
            if ($lat < $bbox['south'] || $lat > $bbox['north'] ||
                $lon < $bbox['west'] || $lon > $bbox['east']) {
                return false;
            }
        }
        
        // All filters passed
        return true;
    }

    /**
     * Get subscription statistics
     */
    public function getStats(): array
    {
        $channelCounts = [];
        foreach ($this->channelIndex as $channel => $clients) {
            $channelCounts[$channel] = count($clients);
        }
        
        return [
            'total_subscriptions' => count($this->subscriptions),
            'channels' => $channelCounts,
        ];
    }

    /**
     * Get all subscribed channels
     */
    public function getAllChannels(): array
    {
        return array_keys($this->channelIndex);
    }

    /**
     * Check if any clients are subscribed to a channel
     */
    public function hasSubscribers(string $channel): bool
    {
        return !empty($this->channelIndex[$channel] ?? []);
    }
}
