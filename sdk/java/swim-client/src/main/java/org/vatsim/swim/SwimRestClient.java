package org.vatsim.swim;

import com.fasterxml.jackson.databind.DeserializationFeature;
import com.fasterxml.jackson.databind.ObjectMapper;
import okhttp3.*;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.vatsim.swim.model.*;

import java.io.Closeable;
import java.io.IOException;
import java.util.ArrayList;
import java.util.List;
import java.util.concurrent.TimeUnit;

/**
 * SWIM REST API Client
 * 
 * <p>Example usage:</p>
 * <pre>{@code
 * try (SwimRestClient client = new SwimRestClient("your-api-key")) {
 *     List<Flight> flights = client.getFlights("KJFK", null, "active");
 *     for (Flight flight : flights) {
 *         System.out.println(flight.getCallsign() + ": " + 
 *             flight.getDeparture() + " -> " + flight.getDestination());
 *     }
 * }
 * }</pre>
 */
public class SwimRestClient implements Closeable {
    
    private static final Logger log = LoggerFactory.getLogger(SwimRestClient.class);
    private static final String DEFAULT_BASE_URL = "https://perti.vatcscc.org/api/swim/v1";
    
    private final String baseUrl;
    private final OkHttpClient httpClient;
    private final ObjectMapper objectMapper;
    
    /**
     * Creates a new SWIM REST client with default settings.
     * 
     * @param apiKey API key for authentication
     */
    public SwimRestClient(String apiKey) {
        this(apiKey, DEFAULT_BASE_URL, 30);
    }
    
    /**
     * Creates a new SWIM REST client with custom settings.
     * 
     * @param apiKey API key for authentication
     * @param baseUrl API base URL
     * @param timeoutSeconds Request timeout in seconds
     */
    public SwimRestClient(String apiKey, String baseUrl, int timeoutSeconds) {
        if (apiKey == null || apiKey.isEmpty()) {
            throw new IllegalArgumentException("API key is required");
        }
        
        this.baseUrl = baseUrl != null ? baseUrl.replaceAll("/+$", "") : DEFAULT_BASE_URL;
        
        this.httpClient = new OkHttpClient.Builder()
            .addInterceptor(chain -> {
                Request original = chain.request();
                Request request = original.newBuilder()
                    .header("Authorization", "Bearer " + apiKey)
                    .header("Accept", "application/json")
                    .header("Content-Type", "application/json")
                    .build();
                return chain.proceed(request);
            })
            .connectTimeout(timeoutSeconds, TimeUnit.SECONDS)
            .readTimeout(timeoutSeconds, TimeUnit.SECONDS)
            .writeTimeout(timeoutSeconds, TimeUnit.SECONDS)
            .build();
        
        this.objectMapper = new ObjectMapper()
            .configure(DeserializationFeature.FAIL_ON_UNKNOWN_PROPERTIES, false);
    }
    
    // =========================================================================
    // Flight Methods
    // =========================================================================
    
    /**
     * Get list of flights with optional filtering.
     * 
     * @param destIcao Destination airport ICAO (optional)
     * @param deptIcao Departure airport ICAO (optional)
     * @param status Flight status: "active", "completed", or "all" (default: "active")
     * @return List of flights
     * @throws SwimApiException if API request fails
     */
    public List<Flight> getFlights(String destIcao, String deptIcao, String status) 
            throws SwimApiException {
        return getFlights(destIcao, deptIcao, status, null, null, 1, 100);
    }
    
    /**
     * Get list of flights with full filtering options.
     * 
     * @param destIcao Destination airport ICAO (optional)
     * @param deptIcao Departure airport ICAO (optional)
     * @param status Flight status (optional)
     * @param artcc ARTCC filter (optional)
     * @param callsign Callsign pattern with wildcards (optional)
     * @param page Page number (1-indexed)
     * @param perPage Results per page (max 1000)
     * @return List of flights
     * @throws SwimApiException if API request fails
     */
    public List<Flight> getFlights(String destIcao, String deptIcao, String status,
            String artcc, String callsign, int page, int perPage) throws SwimApiException {
        
        HttpUrl.Builder urlBuilder = HttpUrl.parse(baseUrl + "/flights").newBuilder();
        
        if (status != null) urlBuilder.addQueryParameter("status", status);
        if (destIcao != null) urlBuilder.addQueryParameter("dest_icao", destIcao);
        if (deptIcao != null) urlBuilder.addQueryParameter("dept_icao", deptIcao);
        if (artcc != null) urlBuilder.addQueryParameter("artcc", artcc);
        if (callsign != null) urlBuilder.addQueryParameter("callsign", callsign);
        urlBuilder.addQueryParameter("page", String.valueOf(page));
        urlBuilder.addQueryParameter("per_page", String.valueOf(perPage));
        
        String json = get(urlBuilder.build().toString());
        
        try {
            FlightsResponse response = objectMapper.readValue(json, FlightsResponse.class);
            return response.getData();
        } catch (Exception e) {
            throw new SwimApiException(0, "Failed to parse response: " + e.getMessage());
        }
    }
    
    /**
     * Get all flights across all pages.
     * 
     * @param destIcao Destination airport ICAO (optional)
     * @param deptIcao Departure airport ICAO (optional)
     * @param status Flight status (optional)
     * @return List of all flights
     * @throws SwimApiException if API request fails
     */
    public List<Flight> getAllFlights(String destIcao, String deptIcao, String status) 
            throws SwimApiException {
        List<Flight> allFlights = new ArrayList<>();
        int page = 1;
        int perPage = 1000;
        
        while (true) {
            HttpUrl.Builder urlBuilder = HttpUrl.parse(baseUrl + "/flights").newBuilder();
            if (status != null) urlBuilder.addQueryParameter("status", status);
            if (destIcao != null) urlBuilder.addQueryParameter("dest_icao", destIcao);
            if (deptIcao != null) urlBuilder.addQueryParameter("dept_icao", deptIcao);
            urlBuilder.addQueryParameter("page", String.valueOf(page));
            urlBuilder.addQueryParameter("per_page", String.valueOf(perPage));
            
            String json = get(urlBuilder.build().toString());
            
            try {
                FlightsResponse response = objectMapper.readValue(json, FlightsResponse.class);
                allFlights.addAll(response.getData());
                
                if (response.getPagination() == null || !response.getPagination().isHasMore()) {
                    break;
                }
                page++;
            } catch (Exception e) {
                throw new SwimApiException(0, "Failed to parse response: " + e.getMessage());
            }
        }
        
        return allFlights;
    }
    
    /**
     * Get single flight by GUFI.
     * 
     * @param gufi Globally Unique Flight Identifier
     * @return Flight or null if not found
     * @throws SwimApiException if API request fails
     */
    public Flight getFlightByGufi(String gufi) throws SwimApiException {
        return getFlightBy("gufi", gufi);
    }
    
    /**
     * Get single flight by flight key.
     * 
     * @param flightKey ADL flight key
     * @return Flight or null if not found
     * @throws SwimApiException if API request fails
     */
    public Flight getFlightByKey(String flightKey) throws SwimApiException {
        return getFlightBy("flight_key", flightKey);
    }
    
    private Flight getFlightBy(String param, String value) throws SwimApiException {
        String url = baseUrl + "/flight?" + param + "=" + value;
        
        try {
            String json = get(url);
            FlightResponse response = objectMapper.readValue(json, FlightResponse.class);
            return response.getData();
        } catch (SwimApiException e) {
            if (e.getStatusCode() == 404) {
                return null;
            }
            throw e;
        } catch (Exception e) {
            throw new SwimApiException(0, "Failed to parse response: " + e.getMessage());
        }
    }
    
    // =========================================================================
    // TMI Methods
    // =========================================================================
    
    /**
     * Get active TMI programs.
     * 
     * @return TMI programs
     * @throws SwimApiException if API request fails
     */
    public TmiPrograms getTmiPrograms() throws SwimApiException {
        return getTmiPrograms("all", null, null);
    }
    
    /**
     * Get TMI programs with filtering.
     * 
     * @param type Program type: "all", "gs", or "gdp"
     * @param airport Airport ICAO filter (optional)
     * @param artcc ARTCC filter (optional)
     * @return TMI programs
     * @throws SwimApiException if API request fails
     */
    public TmiPrograms getTmiPrograms(String type, String airport, String artcc) 
            throws SwimApiException {
        
        HttpUrl.Builder urlBuilder = HttpUrl.parse(baseUrl + "/tmi/programs").newBuilder();
        if (type != null) urlBuilder.addQueryParameter("type", type);
        if (airport != null) urlBuilder.addQueryParameter("airport", airport);
        if (artcc != null) urlBuilder.addQueryParameter("artcc", artcc);
        
        String json = get(urlBuilder.build().toString());
        
        try {
            TmiProgramsResponse response = objectMapper.readValue(json, TmiProgramsResponse.class);
            return response.getData();
        } catch (Exception e) {
            throw new SwimApiException(0, "Failed to parse response: " + e.getMessage());
        }
    }
    
    // =========================================================================
    // HTTP Methods
    // =========================================================================
    
    private String get(String url) throws SwimApiException {
        log.debug("GET {}", url);
        
        Request request = new Request.Builder()
            .url(url)
            .get()
            .build();
        
        return executeRequest(request);
    }
    
    private String post(String url, String body) throws SwimApiException {
        log.debug("POST {} body={}", url, body);
        
        Request request = new Request.Builder()
            .url(url)
            .post(RequestBody.create(body, MediaType.parse("application/json")))
            .build();
        
        return executeRequest(request);
    }
    
    private String executeRequest(Request request) throws SwimApiException {
        try (Response response = httpClient.newCall(request).execute()) {
            String body = response.body() != null ? response.body().string() : "";
            
            if (!response.isSuccessful()) {
                throw new SwimApiException(response.code(), body);
            }
            
            return body;
        } catch (IOException e) {
            throw new SwimApiException(0, "Network error: " + e.getMessage());
        }
    }
    
    @Override
    public void close() {
        httpClient.dispatcher().executorService().shutdown();
        httpClient.connectionPool().evictAll();
    }
}
