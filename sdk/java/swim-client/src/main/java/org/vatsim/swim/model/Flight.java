package org.vatsim.swim.model;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import com.fasterxml.jackson.annotation.JsonProperty;

/**
 * Complete flight record
 */
@JsonIgnoreProperties(ignoreUnknown = true)
public class Flight {
    
    @JsonProperty("gufi")
    private String gufi;
    
    @JsonProperty("flight_uid")
    private long flightUid;
    
    @JsonProperty("flight_key")
    private String flightKey;
    
    @JsonProperty("identity")
    private FlightIdentity identity;
    
    @JsonProperty("flight_plan")
    private FlightPlan flightPlan;
    
    @JsonProperty("position")
    private FlightPosition position;
    
    @JsonProperty("progress")
    private FlightProgress progress;
    
    @JsonProperty("times")
    private FlightTimes times;
    
    @JsonProperty("tmi")
    private FlightTmi tmi;
    
    // Convenience getters
    public String getCallsign() {
        return identity != null ? identity.getCallsign() : "";
    }
    
    public String getDeparture() {
        return flightPlan != null ? flightPlan.getDeparture() : "";
    }
    
    public String getDestination() {
        return flightPlan != null ? flightPlan.getDestination() : "";
    }
    
    // Standard getters and setters
    public String getGufi() { return gufi; }
    public void setGufi(String gufi) { this.gufi = gufi; }
    
    public long getFlightUid() { return flightUid; }
    public void setFlightUid(long flightUid) { this.flightUid = flightUid; }
    
    public String getFlightKey() { return flightKey; }
    public void setFlightKey(String flightKey) { this.flightKey = flightKey; }
    
    public FlightIdentity getIdentity() { return identity; }
    public void setIdentity(FlightIdentity identity) { this.identity = identity; }
    
    public FlightPlan getFlightPlan() { return flightPlan; }
    public void setFlightPlan(FlightPlan flightPlan) { this.flightPlan = flightPlan; }
    
    public FlightPosition getPosition() { return position; }
    public void setPosition(FlightPosition position) { this.position = position; }
    
    public FlightProgress getProgress() { return progress; }
    public void setProgress(FlightProgress progress) { this.progress = progress; }
    
    public FlightTimes getTimes() { return times; }
    public void setTimes(FlightTimes times) { this.times = times; }
    
    public FlightTmi getTmi() { return tmi; }
    public void setTmi(FlightTmi tmi) { this.tmi = tmi; }
    
    @Override
    public String toString() {
        return String.format("Flight{callsign='%s', dep='%s', dest='%s'}", 
            getCallsign(), getDeparture(), getDestination());
    }
}
