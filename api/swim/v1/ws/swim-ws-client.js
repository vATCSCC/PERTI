/**
 * SWIM API WebSocket Client Library
 *
 * JavaScript client for connecting to the PERTI SWIM WebSocket server.
 *
 * Usage:
 *   const swim = new SWIMWebSocket('your-api-key');
 *   swim.connect();
 *   swim.subscribe(['flight.position', 'flight.departed'], { airports: ['KJFK'] });
 *   swim.on('flight.position', (data) => console.log(data));
 *
 * @version 1.0.0
 * @since 2026-01-16
 */

class SWIMWebSocket {
    /**
     * Create a new SWIM WebSocket client
     *
     * @param {string} apiKey - API key for authentication
     * @param {object} options - Connection options
     */
    constructor(apiKey, options = {}) {
        this.apiKey = apiKey;
        this.options = {
            url: options.url || 'wss://perti.vatcscc.org/api/swim/v1/ws',
            reconnect: options.reconnect !== false,
            reconnectInterval: options.reconnectInterval || 5000,
            maxReconnectInterval: options.maxReconnectInterval || 30000,
            debug: options.debug || false,
        };

        this.ws = null;
        this.handlers = {};
        this.subscriptions = [];
        this.filters = {};
        this.reconnectAttempts = 0;
        this.connected = false;
        this.clientId = null;
    }

    /**
     * Connect to the WebSocket server
     *
     * @returns {Promise} Resolves when connected
     */
    connect() {
        return new Promise((resolve, reject) => {
            const url = `${this.options.url}?api_key=${encodeURIComponent(this.apiKey)}`;

            this.log('Connecting to', url);

            this.ws = new WebSocket(url);

            this.ws.onopen = () => {
                this.connected = true;
                this.reconnectAttempts = 0;
                this.log('Connected');

                // Re-subscribe if we had subscriptions
                if (this.subscriptions.length > 0) {
                    this.subscribe(this.subscriptions, this.filters);
                }

                this.emit('connected');
                resolve();
            };

            this.ws.onmessage = (event) => {
                this.handleMessage(event.data);
            };

            this.ws.onclose = (event) => {
                this.connected = false;
                this.log('Disconnected', event.code, event.reason);
                this.emit('disconnected', { code: event.code, reason: event.reason });

                if (this.options.reconnect && event.code !== 4001) {
                    this.scheduleReconnect();
                }
            };

            this.ws.onerror = (error) => {
                this.log('Error', error);
                this.emit('error', error);
                if (!this.connected) {
                    reject(error);
                }
            };
        });
    }

    /**
     * Disconnect from the server
     */
    disconnect() {
        this.options.reconnect = false;
        if (this.ws) {
            this.ws.close(1000, 'Client disconnect');
        }
    }

    /**
     * Subscribe to event channels
     *
     * @param {string[]} channels - Channel names (e.g., ['flight.position', 'tmi.*'])
     * @param {object} filters - Filter criteria
     */
    subscribe(channels, filters = {}) {
        this.subscriptions = channels;
        this.filters = filters;

        if (this.connected) {
            this.send({
                action: 'subscribe',
                channels: channels,
                filters: filters,
            });
        }
    }

    /**
     * Unsubscribe from channels
     *
     * @param {string[]} channels - Channels to unsubscribe from (empty = all)
     */
    unsubscribe(channels = []) {
        if (channels.length === 0) {
            this.subscriptions = [];
            this.filters = {};
        } else {
            this.subscriptions = this.subscriptions.filter(c => !channels.includes(c));
        }

        if (this.connected) {
            this.send({
                action: 'unsubscribe',
                channels: channels,
            });
        }
    }

    /**
     * Register event handler
     *
     * @param {string} eventType - Event type to listen for
     * @param {function} handler - Handler function
     */
    on(eventType, handler) {
        if (!this.handlers[eventType]) {
            this.handlers[eventType] = [];
        }
        this.handlers[eventType].push(handler);
    }

    /**
     * Remove event handler
     *
     * @param {string} eventType - Event type
     * @param {function} handler - Handler to remove (or all if omitted)
     */
    off(eventType, handler) {
        if (!this.handlers[eventType]) {return;}

        if (handler) {
            this.handlers[eventType] = this.handlers[eventType].filter(h => h !== handler);
        } else {
            delete this.handlers[eventType];
        }
    }

    /**
     * Send ping to server
     */
    ping() {
        this.send({ action: 'ping' });
    }

    /**
     * Request connection status
     */
    status() {
        this.send({ action: 'status' });
    }

    /**
     * Check if connected
     *
     * @returns {boolean}
     */
    isConnected() {
        return this.connected;
    }

    /**
     * Get client ID
     *
     * @returns {string|null}
     */
    getClientId() {
        return this.clientId;
    }

    // ========== Private Methods ==========

    /**
     * Send message to server
     */
    send(data) {
        if (this.ws && this.connected) {
            this.ws.send(JSON.stringify(data));
        }
    }

    /**
     * Handle incoming message
     */
    handleMessage(data) {
        let msg;
        try {
            msg = JSON.parse(data);
        } catch (e) {
            this.log('Invalid JSON received', data);
            return;
        }

        this.log('Received', msg.type);

        // Handle special message types
        switch (msg.type) {
            case 'connected':
                this.clientId = msg.data?.client_id;
                break;

            case 'subscribed':
                this.emit('subscribed', msg);
                break;

            case 'pong':
                this.emit('pong', msg);
                break;

            case 'error':
                this.emit('error', msg);
                break;

            case 'system.heartbeat':
                this.emit('heartbeat', msg.data);
                break;
        }

        // Emit to handlers
        this.emit(msg.type, msg.data, msg.timestamp);

        // Also emit to wildcard handlers
        const parts = msg.type.split('.');
        if (parts.length === 2) {
            this.emit(parts[0] + '.*', msg.data, msg.timestamp, msg.type);
        }
    }

    /**
     * Emit event to handlers
     */
    emit(eventType, ...args) {
        const handlers = this.handlers[eventType] || [];
        handlers.forEach(handler => {
            try {
                handler(...args);
            } catch (e) {
                this.log('Handler error', eventType, e);
            }
        });
    }

    /**
     * Schedule reconnection attempt
     */
    scheduleReconnect() {
        this.reconnectAttempts++;

        // Exponential backoff
        const delay = Math.min(
            this.options.reconnectInterval * Math.pow(1.5, this.reconnectAttempts - 1),
            this.options.maxReconnectInterval,
        );

        this.log(`Reconnecting in ${Math.round(delay/1000)}s (attempt ${this.reconnectAttempts})`);

        setTimeout(() => {
            if (this.options.reconnect && !this.connected) {
                this.connect().catch(() => {});
            }
        }, delay);
    }

    /**
     * Log message (if debug enabled)
     */
    log(...args) {
        if (this.options.debug) {
            console.log('[SWIM WS]', ...args);
        }
    }
}

// Export for different environments
if (typeof module !== 'undefined' && module.exports) {
    module.exports = SWIMWebSocket;
} else if (typeof window !== 'undefined') {
    window.SWIMWebSocket = SWIMWebSocket;
}
