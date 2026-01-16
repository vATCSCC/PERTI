package org.vatsim.swim.model;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import com.fasterxml.jackson.annotation.JsonProperty;

/**
 * Flight identity information
 */
@JsonIgnoreProperties(ignoreUnknown = true)
public class FlightIdentity {
    
    @JsonProperty("callsign")
    private String callsign;
    
    @JsonProperty("cid")
    private Integer cid;
    
    @JsonProperty("aircraft_type")
    private String aircraftType;
    
    @JsonProperty("aircraft_icao")
    private String aircraftIcao;
    
    @JsonProperty("weight_class")
    private String weightClass;
    
    @JsonProperty("wake_category")
    private String wakeCategory;
    
    @JsonProperty("airline_icao")
    private String airlineIcao;
    
    @JsonProperty("airline_name")
    private String airlineName;
    
    // Getters and setters
    public String getCallsign() { return callsign; }
    public void setCallsign(String callsign) { this.callsign = callsign; }
    
    public Integer getCid() { return cid; }
    public void setCid(Integer cid) { this.cid = cid; }
    
    public String getAircraftType() { return aircraftType; }
    public void setAircraftType(String aircraftType) { this.aircraftType = aircraftType; }
    
    public String getAircraftIcao() { return aircraftIcao; }
    public void setAircraftIcao(String aircraftIcao) { this.aircraftIcao = aircraftIcao; }
    
    public String getWeightClass() { return weightClass; }
    public void setWeightClass(String weightClass) { this.weightClass = weightClass; }
    
    public String getWakeCategory() { return wakeCategory; }
    public void setWakeCategory(String wakeCategory) { this.wakeCategory = wakeCategory; }
    
    public String getAirlineIcao() { return airlineIcao; }
    public void setAirlineIcao(String airlineIcao) { this.airlineIcao = airlineIcao; }
    
    public String getAirlineName() { return airlineName; }
    public void setAirlineName(String airlineName) { this.airlineName = airlineName; }
}
