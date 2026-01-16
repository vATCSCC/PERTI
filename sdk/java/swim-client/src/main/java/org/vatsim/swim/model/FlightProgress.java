package org.vatsim.swim.model;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import com.fasterxml.jackson.annotation.JsonProperty;

/**
 * Flight progress information
 */
@JsonIgnoreProperties(ignoreUnknown = true)
public class FlightProgress {
    
    @JsonProperty("phase")
    private String phase;
    
    @JsonProperty("is_active")
    private boolean isActive;
    
    @JsonProperty("distance_remaining_nm")
    private Double distanceRemainingNm;
    
    @JsonProperty("pct_complete")
    private Double pctComplete;
    
    @JsonProperty("time_to_dest_min")
    private Double timeToDestMin;
    
    // Getters and setters
    public String getPhase() { return phase; }
    public void setPhase(String phase) { this.phase = phase; }
    
    public boolean isActive() { return isActive; }
    public void setActive(boolean active) { isActive = active; }
    
    public Double getDistanceRemainingNm() { return distanceRemainingNm; }
    public void setDistanceRemainingNm(Double distanceRemainingNm) { this.distanceRemainingNm = distanceRemainingNm; }
    
    public Double getPctComplete() { return pctComplete; }
    public void setPctComplete(Double pctComplete) { this.pctComplete = pctComplete; }
    
    public Double getTimeToDestMin() { return timeToDestMin; }
    public void setTimeToDestMin(Double timeToDestMin) { this.timeToDestMin = timeToDestMin; }
}
