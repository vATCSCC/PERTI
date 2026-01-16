package org.vatsim.swim.model;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import com.fasterxml.jackson.annotation.JsonProperty;

/**
 * Current flight position
 */
@JsonIgnoreProperties(ignoreUnknown = true)
public class FlightPosition {
    
    @JsonProperty("latitude")
    private double latitude;
    
    @JsonProperty("longitude")
    private double longitude;
    
    @JsonProperty("altitude_ft")
    private int altitudeFt;
    
    @JsonProperty("heading")
    private int heading;
    
    @JsonProperty("ground_speed_kts")
    private int groundSpeedKts;
    
    @JsonProperty("vertical_rate_fpm")
    private int verticalRateFpm;
    
    @JsonProperty("current_artcc")
    private String currentArtcc;
    
    // Getters and setters
    public double getLatitude() { return latitude; }
    public void setLatitude(double latitude) { this.latitude = latitude; }
    
    public double getLongitude() { return longitude; }
    public void setLongitude(double longitude) { this.longitude = longitude; }
    
    public int getAltitudeFt() { return altitudeFt; }
    public void setAltitudeFt(int altitudeFt) { this.altitudeFt = altitudeFt; }
    
    public int getHeading() { return heading; }
    public void setHeading(int heading) { this.heading = heading; }
    
    public int getGroundSpeedKts() { return groundSpeedKts; }
    public void setGroundSpeedKts(int groundSpeedKts) { this.groundSpeedKts = groundSpeedKts; }
    
    public int getVerticalRateFpm() { return verticalRateFpm; }
    public void setVerticalRateFpm(int verticalRateFpm) { this.verticalRateFpm = verticalRateFpm; }
    
    public String getCurrentArtcc() { return currentArtcc; }
    public void setCurrentArtcc(String currentArtcc) { this.currentArtcc = currentArtcc; }
}
