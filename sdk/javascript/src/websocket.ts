/**
 * SWIM WebSocket Client
 */

import type {
  EventType,
  ConnectionInfo,
  FlightEventData,
  PositionsBatch,
  TmiEventData,
  HeartbeatData,
  SubscriptionFilters,
  SwimMessage,
  SwimWebSocketClientOptions,
} from './types';

const DEFAULT_WS_URL = 'wss://perti.vatcscc.org/api/swim/v1/ws';

type EventHandler<T = unknown> = (data: T, timestamp: string, eventType?: string) => void;

/**
 * SWIM WebSocket Client for real-time flight data streaming
 * 
 * @example
 * ```typescript
 * const client = new SwimWebSocketClient('your-api-key');
 * 
 * client.on('flight.departed', (data, timestamp) => {
 *   console.log(`${data.callsign} departed ${data.dep}`);
 * });
 * 
 * client.on('system.heartbeat', (data, timestamp) => {
 *   console.log(`${data.connected_clients} clients connected`);
 * });
 * 
 * await client.connect();
 * client.subscribe(['flight.departed', 'system.heartbeat']);
 * ```
 */
export class SwimWebSocketClient {
  private readonly apiKey: string;
  private readonly wsUrl: string;
  private readonly reconnect: boolean;
  private readonly reconnectInterval: number;
  private readonly maxReconnectInterval: number;
  private readonly pingInterval: number;

  private ws: WebSocket | null = null;
  private handlers: Map<string, EventHandler[]> = new Map();
  private reconnectAttempts = 0;
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null;
  private pingTimer: ReturnType<typeof setInterval> | null = null;
  private running = false;

  private _clientId: string | null = null;
  private _channels: string[] = [];
  private _filters: SubscriptionFilters = {};

  constructor(apiKey: string, options: SwimWebSocketClientOptions = {}) {
    if (!apiKey) {
      throw new Error('API key is required');
    }

    this.apiKey = apiKey;
    this.wsUrl = options.wsUrl || DEFAULT_WS_URL;
    this.reconnect = options.reconnect ?? true;
    this.reconnectInterval = options.reconnectInterval || 5000;
    this.maxReconnectInterval = options.maxReconnectInterval || 60000;
    this.pingInterval = options.pingInterval || 30000;
  }

  /**
   * Get client ID assigned by server
   */
  get clientId(): string | null {
    return this._clientId;
  }

  /**
   * Check if connected
   */
  get connected(): boolean {
    return this.ws?.readyState === WebSocket.OPEN;
  }

  /**
   * Register an event handler
   */
  on<T = unknown>(eventType: EventType | string, handler: EventHandler<T>): this {
    const handlers = this.handlers.get(eventType) || [];
    handlers.push(handler as EventHandler);
    this.handlers.set(eventType, handlers);
    return this;
  }

  /**
   * Remove event handlers
   */
  off(eventType: string, handler?: EventHandler): this {
    if (!handler) {
      this.handlers.delete(eventType);
    } else {
      const handlers = this.handlers.get(eventType) || [];
      this.handlers.set(eventType, handlers.filter(h => h !== handler));
    }
    return this;
  }

  /**
   * Connect to the WebSocket server
   */
  async connect(): Promise<void> {
    return new Promise((resolve, reject) => {
      try {
        const url = `${this.wsUrl}?api_key=${this.apiKey}`;
        
        // Use ws package in Node.js, native WebSocket in browser
        if (typeof window === 'undefined') {
          // Node.js environment
          // eslint-disable-next-line @typescript-eslint/no-var-requires
          const WebSocketLib = require('ws');
          this.ws = new WebSocketLib(url);
        } else {
          // Browser environment
          this.ws = new WebSocket(url);
        }

        this.ws.onopen = () => {
          this.running = true;
          this.reconnectAttempts = 0;
          this.startPingInterval();
          
          // Re-subscribe if we had subscriptions
          if (this._channels.length > 0) {
            this.sendSubscribe();
          }
          
          resolve();
        };

        this.ws.onmessage = (event) => {
          this.handleMessage(typeof event.data === 'string' ? event.data : String(event.data));
        };

        this.ws.onclose = () => {
          this.handleDisconnect();
        };

        this.ws.onerror = (error) => {
          this.emit('error', { message: String(error) }, '');
          if (!this.connected) {
            reject(error);
          }
        };
      } catch (error) {
        reject(error);
      }
    });
  }

  /**
   * Disconnect from the server
   */
  disconnect(): void {
    this.running = false;
    this.stopPingInterval();
    this.stopReconnectTimer();
    
    if (this.ws) {
      this.ws.close(1000, 'Client disconnect');
      this.ws = null;
    }
    
    this._clientId = null;
    this.emit('disconnected', {}, '');
  }

  /**
   * Subscribe to event channels
   */
  subscribe(
    channels: string[],
    filters?: SubscriptionFilters
  ): void {
    this._channels = channels;
    this._filters = filters || {};
    
    if (this.connected) {
      this.sendSubscribe();
    }
  }

  /**
   * Unsubscribe from channels
   */
  unsubscribe(channels?: string[]): void {
    if (channels) {
      this._channels = this._channels.filter(c => !channels.includes(c));
    } else {
      this._channels = [];
      this._filters = {};
    }

    if (this.connected) {
      this.send({
        action: 'unsubscribe',
        channels: channels || [],
      });
    }
  }

  /**
   * Send ping to server
   */
  ping(): void {
    this.send({ action: 'ping' });
  }

  /**
   * Request connection status
   */
  status(): void {
    this.send({ action: 'status' });
  }

  // ===========================================================================
  // Private Methods
  // ===========================================================================

  private sendSubscribe(): void {
    this.send({
      action: 'subscribe',
      channels: this._channels,
      filters: this._filters,
    });
  }

  private send(data: Record<string, unknown>): void {
    if (this.ws && this.connected) {
      this.ws.send(JSON.stringify(data));
    }
  }

  private handleMessage(json: string): void {
    try {
      const msg = JSON.parse(json) as SwimMessage;
      const { type, data, timestamp = '' } = msg;

      // Handle special messages
      switch (type) {
        case 'connected':
          this._clientId = (data as ConnectionInfo)?.client_id || null;
          this.emit('connected', data as ConnectionInfo, timestamp);
          break;

        case 'error':
          this.emit('error', { code: msg.code, message: msg.message }, timestamp);
          break;

        case 'flight.positions':
          this.emit('flight.positions', data as PositionsBatch, timestamp);
          break;

        case 'flight.departed':
        case 'flight.arrived':
        case 'flight.created':
        case 'flight.deleted':
          this.emit(type, data as FlightEventData, timestamp);
          break;

        case 'tmi.issued':
        case 'tmi.modified':
        case 'tmi.released':
          this.emit(type, data as TmiEventData, timestamp);
          break;

        case 'system.heartbeat':
          this.emit('system.heartbeat', data as HeartbeatData, timestamp);
          break;

        default:
          this.emit(type, data, timestamp);
      }

      // Handle wildcard subscriptions
      const parts = type.split('.');
      if (parts.length === 2) {
        const wildcard = `${parts[0]}.*`;
        this.emit(wildcard, data, timestamp, type);
      }
    } catch (error) {
      console.error('Failed to parse message:', json);
    }
  }

  private emit<T>(eventType: string, data: T, timestamp: string, originalType?: string): void {
    const handlers = this.handlers.get(eventType);
    if (handlers) {
      for (const handler of handlers) {
        try {
          handler(data, timestamp, originalType);
        } catch (error) {
          console.error(`Handler error for ${eventType}:`, error);
        }
      }
    }
  }

  private handleDisconnect(): void {
    this.stopPingInterval();
    this._clientId = null;
    
    this.emit('disconnected', {}, '');
    
    if (this.reconnect && this.running) {
      this.scheduleReconnect();
    }
  }

  private scheduleReconnect(): void {
    this.reconnectAttempts++;
    const delay = Math.min(
      this.reconnectInterval * Math.pow(1.5, this.reconnectAttempts - 1),
      this.maxReconnectInterval
    );
    
    console.log(`Reconnecting in ${delay}ms (attempt ${this.reconnectAttempts})`);
    
    this.reconnectTimer = setTimeout(async () => {
      try {
        await this.connect();
      } catch (error) {
        // Will retry via onclose handler
      }
    }, delay);
  }

  private stopReconnectTimer(): void {
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }
  }

  private startPingInterval(): void {
    this.stopPingInterval();
    this.pingTimer = setInterval(() => {
      if (this.connected) {
        this.ping();
      }
    }, this.pingInterval);
  }

  private stopPingInterval(): void {
    if (this.pingTimer) {
      clearInterval(this.pingTimer);
      this.pingTimer = null;
    }
  }
}
