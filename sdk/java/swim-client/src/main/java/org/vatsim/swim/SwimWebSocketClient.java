package org.vatsim.swim;

import com.fasterxml.jackson.databind.DeserializationFeature;
import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.java_websocket.client.WebSocketClient;
import org.java_websocket.handshake.ServerHandshake;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.net.URI;
import java.util.*;
import java.util.concurrent.ConcurrentHashMap;
import java.util.function.BiConsumer;

/**
 * SWIM WebSocket Client for real-time flight data streaming.
 *
 * <p>Example usage:</p>
 * <pre>{@code
 * SwimWebSocketClient client = new SwimWebSocketClient("your-api-key");
 * 
 * client.on("flight.departed", (data, timestamp) -> {
 *     System.out.println("Departure: " + data.get("callsign"));
 * });
 * 
 * client.on("system.heartbeat", (data, timestamp) -> {
 *     System.out.println("Heartbeat: " + data.get("connected_clients") + " clients");
 * });
 * 
 * client.connect();
 * client.subscribe(Arrays.asList("flight.departed", "system.heartbeat"));
 * }</pre>
 */
public class SwimWebSocketClient {
    
    private static final Logger log = LoggerFactory.getLogger(SwimWebSocketClient.class);
    private static final String DEFAULT_WS_URL = "wss://perti.vatcscc.org/api/swim/v1/ws";
    
    private final String apiKey;
    private final String wsUrl;
    private final ObjectMapper objectMapper;
    private final Map<String, List<BiConsumer<JsonNode, String>>> handlers;
    
    private InternalWebSocketClient wsClient;
    private String clientId;
    private boolean autoReconnect;
    private int reconnectDelayMs;
    
    /**
     * Creates a new WebSocket client.
     *
     * @param apiKey API key for authentication
     */
    public SwimWebSocketClient(String apiKey) {
        this(apiKey, DEFAULT_WS_URL);
    }
    
    /**
     * Creates a new WebSocket client with custom URL.
     *
     * @param apiKey API key for authentication
     * @param wsUrl WebSocket URL
     */
    public SwimWebSocketClient(String apiKey, String wsUrl) {
        this.apiKey = apiKey;
        this.wsUrl = wsUrl;
        this.handlers = new ConcurrentHashMap<>();
        this.autoReconnect = true;
        this.reconnectDelayMs = 5000;
        
        this.objectMapper = new ObjectMapper()
            .configure(DeserializationFeature.FAIL_ON_UNKNOWN_PROPERTIES, false);
    }
    
    /**
     * Register an event handler.
     *
     * @param eventType Event type (e.g., "flight.departed", "system.heartbeat")
     * @param handler Handler function receiving (data, timestamp)
     * @return this client for chaining
     */
    public SwimWebSocketClient on(String eventType, BiConsumer<JsonNode, String> handler) {
        handlers.computeIfAbsent(eventType, k -> new ArrayList<>()).add(handler);
        return this;
    }
    
    /**
     * Remove event handlers.
     *
     * @param eventType Event type to remove handlers for
     */
    public void off(String eventType) {
        handlers.remove(eventType);
    }
    
    /**
     * Connect to the WebSocket server.
     *
     * @throws Exception if connection fails
     */
    public void connect() throws Exception {
        String url = wsUrl + "?api_key=" + apiKey;
        wsClient = new InternalWebSocketClient(URI.create(url));
        wsClient.connectBlocking();
    }
    
    /**
     * Connect to the WebSocket server asynchronously.
     */
    public void connectAsync() {
        try {
            String url = wsUrl + "?api_key=" + apiKey;
            wsClient = new InternalWebSocketClient(URI.create(url));
            wsClient.connect();
        } catch (Exception e) {
            log.error("Connection failed", e);
        }
    }
    
    /**
     * Disconnect from the server.
     */
    public void disconnect() {
        autoReconnect = false;
        if (wsClient != null) {
            wsClient.close();
        }
    }
    
    /**
     * Subscribe to event channels.
     *
     * @param channels List of channel names
     */
    public void subscribe(List<String> channels) {
        subscribe(channels, null);
    }
    
    /**
     * Subscribe to event channels with filters.
     *
     * @param channels List of channel names
     * @param filters Subscription filters (airports, artccs, etc.)
     */
    public void subscribe(List<String> channels, Map<String, Object> filters) {
        Map<String, Object> message = new HashMap<>();
        message.put("action", "subscribe");
        message.put("channels", channels);
        if (filters != null) {
            message.put("filters", filters);
        }
        send(message);
        log.info("Subscribed to: {}", channels);
    }
    
    /**
     * Unsubscribe from channels.
     *
     * @param channels Channels to unsubscribe from (null for all)
     */
    public void unsubscribe(List<String> channels) {
        Map<String, Object> message = new HashMap<>();
        message.put("action", "unsubscribe");
        if (channels != null) {
            message.put("channels", channels);
        }
        send(message);
    }
    
    /**
     * Send ping to server.
     */
    public void ping() {
        send(Collections.singletonMap("action", "ping"));
    }
    
    /**
     * Check if connected.
     *
     * @return true if connected
     */
    public boolean isConnected() {
        return wsClient != null && wsClient.isOpen();
    }
    
    /**
     * Get client ID assigned by server.
     *
     * @return client ID or null if not connected
     */
    public String getClientId() {
        return clientId;
    }
    
    /**
     * Set auto-reconnect behavior.
     *
     * @param enabled true to enable auto-reconnect
     */
    public void setAutoReconnect(boolean enabled) {
        this.autoReconnect = enabled;
    }
    
    /**
     * Set reconnect delay.
     *
     * @param delayMs Delay in milliseconds
     */
    public void setReconnectDelay(int delayMs) {
        this.reconnectDelayMs = delayMs;
    }
    
    private void send(Map<String, Object> message) {
        if (wsClient == null || !wsClient.isOpen()) {
            log.warn("Cannot send - not connected");
            return;
        }
        try {
            String json = objectMapper.writeValueAsString(message);
            wsClient.send(json);
        } catch (Exception e) {
            log.error("Failed to send message", e);
        }
    }
    
    private void handleMessage(String json) {
        try {
            JsonNode msg = objectMapper.readTree(json);
            String type = msg.has("type") ? msg.get("type").asText() : "";
            String timestamp = msg.has("timestamp") ? msg.get("timestamp").asText() : "";
            JsonNode data = msg.get("data");
            
            log.debug("Received: {}", type);
            
            // Handle connected event
            if ("connected".equals(type) && data != null) {
                clientId = data.has("client_id") ? data.get("client_id").asText() : null;
            }
            
            // Emit to exact handlers
            List<BiConsumer<JsonNode, String>> exactHandlers = handlers.get(type);
            if (exactHandlers != null) {
                for (BiConsumer<JsonNode, String> handler : exactHandlers) {
                    try {
                        handler.accept(data, timestamp);
                    } catch (Exception e) {
                        log.error("Handler error for {}", type, e);
                    }
                }
            }
            
            // Emit to wildcard handlers (e.g., flight.*)
            String[] parts = type.split("\\.");
            if (parts.length == 2) {
                String wildcard = parts[0] + ".*";
                List<BiConsumer<JsonNode, String>> wildcardHandlers = handlers.get(wildcard);
                if (wildcardHandlers != null) {
                    for (BiConsumer<JsonNode, String> handler : wildcardHandlers) {
                        try {
                            handler.accept(data, timestamp);
                        } catch (Exception e) {
                            log.error("Handler error for {}", wildcard, e);
                        }
                    }
                }
            }
            
        } catch (Exception e) {
            log.error("Failed to parse message: {}", json, e);
        }
    }
    
    private void handleDisconnect() {
        clientId = null;
        
        // Notify handlers
        List<BiConsumer<JsonNode, String>> disconnectHandlers = handlers.get("disconnected");
        if (disconnectHandlers != null) {
            for (BiConsumer<JsonNode, String> handler : disconnectHandlers) {
                try {
                    handler.accept(null, null);
                } catch (Exception e) {
                    log.error("Handler error", e);
                }
            }
        }
        
        // Auto-reconnect
        if (autoReconnect) {
            log.info("Reconnecting in {} ms...", reconnectDelayMs);
            new Timer().schedule(new TimerTask() {
                @Override
                public void run() {
                    try {
                        connect();
                    } catch (Exception e) {
                        log.error("Reconnect failed", e);
                    }
                }
            }, reconnectDelayMs);
        }
    }
    
    /**
     * Internal WebSocket client implementation
     */
    private class InternalWebSocketClient extends WebSocketClient {
        
        public InternalWebSocketClient(URI serverUri) {
            super(serverUri);
        }
        
        @Override
        public void onOpen(ServerHandshake handshake) {
            log.info("WebSocket connected");
        }
        
        @Override
        public void onMessage(String message) {
            handleMessage(message);
        }
        
        @Override
        public void onClose(int code, String reason, boolean remote) {
            log.info("WebSocket closed: {} - {}", code, reason);
            handleDisconnect();
        }
        
        @Override
        public void onError(Exception ex) {
            log.error("WebSocket error", ex);
            
            List<BiConsumer<JsonNode, String>> errorHandlers = handlers.get("error");
            if (errorHandlers != null) {
                for (BiConsumer<JsonNode, String> handler : errorHandlers) {
                    try {
                        handler.accept(null, null);
                    } catch (Exception e) {
                        log.error("Error handler failed", e);
                    }
                }
            }
        }
    }
}
