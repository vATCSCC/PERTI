package org.vatsim.swim.model;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import com.fasterxml.jackson.annotation.JsonProperty;
import java.util.List;
import java.util.ArrayList;

/**
 * Active TMI programs response
 */
@JsonIgnoreProperties(ignoreUnknown = true)
public class TmiPrograms {
    
    @JsonProperty("ground_stops")
    private List<GroundStop> groundStops = new ArrayList<>();
    
    @JsonProperty("gdp_programs")
    private List<GdpProgram> gdpPrograms = new ArrayList<>();
    
    @JsonProperty("active_ground_stops")
    private int activeGroundStops;
    
    @JsonProperty("active_gdp_programs")
    private int activeGdpPrograms;
    
    @JsonProperty("total_controlled_airports")
    private int totalControlledAirports;
    
    // Getters and setters
    public List<GroundStop> getGroundStops() { return groundStops; }
    public void setGroundStops(List<GroundStop> groundStops) { this.groundStops = groundStops; }
    
    public List<GdpProgram> getGdpPrograms() { return gdpPrograms; }
    public void setGdpPrograms(List<GdpProgram> gdpPrograms) { this.gdpPrograms = gdpPrograms; }
    
    public int getActiveGroundStops() { return activeGroundStops; }
    public void setActiveGroundStops(int activeGroundStops) { this.activeGroundStops = activeGroundStops; }
    
    public int getActiveGdpPrograms() { return activeGdpPrograms; }
    public void setActiveGdpPrograms(int activeGdpPrograms) { this.activeGdpPrograms = activeGdpPrograms; }
    
    public int getTotalControlledAirports() { return totalControlledAirports; }
    public void setTotalControlledAirports(int totalControlledAirports) { this.totalControlledAirports = totalControlledAirports; }
}

/**
 * Ground Stop program
 */
@JsonIgnoreProperties(ignoreUnknown = true)
class GroundStop {
    
    @JsonProperty("type")
    private String type = "ground_stop";
    
    @JsonProperty("airport")
    private String airport;
    
    @JsonProperty("airport_name")
    private String airportName;
    
    @JsonProperty("artcc")
    private String artcc;
    
    @JsonProperty("reason")
    private String reason;
    
    @JsonProperty("probability_of_extension")
    private Integer probabilityOfExtension;
    
    @JsonProperty("start_time")
    private String startTime;
    
    @JsonProperty("end_time")
    private String endTime;
    
    @JsonProperty("is_active")
    private boolean isActive = true;
    
    // Getters and setters
    public String getType() { return type; }
    public void setType(String type) { this.type = type; }
    
    public String getAirport() { return airport; }
    public void setAirport(String airport) { this.airport = airport; }
    
    public String getAirportName() { return airportName; }
    public void setAirportName(String airportName) { this.airportName = airportName; }
    
    public String getArtcc() { return artcc; }
    public void setArtcc(String artcc) { this.artcc = artcc; }
    
    public String getReason() { return reason; }
    public void setReason(String reason) { this.reason = reason; }
    
    public Integer getProbabilityOfExtension() { return probabilityOfExtension; }
    public void setProbabilityOfExtension(Integer probabilityOfExtension) { this.probabilityOfExtension = probabilityOfExtension; }
    
    public String getStartTime() { return startTime; }
    public void setStartTime(String startTime) { this.startTime = startTime; }
    
    public String getEndTime() { return endTime; }
    public void setEndTime(String endTime) { this.endTime = endTime; }
    
    public boolean isActive() { return isActive; }
    public void setActive(boolean active) { isActive = active; }
}

/**
 * Ground Delay Program
 */
@JsonIgnoreProperties(ignoreUnknown = true)
class GdpProgram {
    
    @JsonProperty("type")
    private String type = "gdp";
    
    @JsonProperty("program_id")
    private String programId;
    
    @JsonProperty("airport")
    private String airport;
    
    @JsonProperty("airport_name")
    private String airportName;
    
    @JsonProperty("artcc")
    private String artcc;
    
    @JsonProperty("reason")
    private String reason;
    
    @JsonProperty("program_rate")
    private Integer programRate;
    
    @JsonProperty("delay_limit_minutes")
    private Integer delayLimitMinutes;
    
    @JsonProperty("average_delay_minutes")
    private Integer averageDelayMinutes;
    
    @JsonProperty("maximum_delay_minutes")
    private Integer maximumDelayMinutes;
    
    @JsonProperty("total_flights")
    private Integer totalFlights;
    
    @JsonProperty("affected_flights")
    private Integer affectedFlights;
    
    @JsonProperty("is_active")
    private boolean isActive = true;
    
    // Getters and setters
    public String getType() { return type; }
    public void setType(String type) { this.type = type; }
    
    public String getProgramId() { return programId; }
    public void setProgramId(String programId) { this.programId = programId; }
    
    public String getAirport() { return airport; }
    public void setAirport(String airport) { this.airport = airport; }
    
    public String getAirportName() { return airportName; }
    public void setAirportName(String airportName) { this.airportName = airportName; }
    
    public String getArtcc() { return artcc; }
    public void setArtcc(String artcc) { this.artcc = artcc; }
    
    public String getReason() { return reason; }
    public void setReason(String reason) { this.reason = reason; }
    
    public Integer getProgramRate() { return programRate; }
    public void setProgramRate(Integer programRate) { this.programRate = programRate; }
    
    public Integer getDelayLimitMinutes() { return delayLimitMinutes; }
    public void setDelayLimitMinutes(Integer delayLimitMinutes) { this.delayLimitMinutes = delayLimitMinutes; }
    
    public Integer getAverageDelayMinutes() { return averageDelayMinutes; }
    public void setAverageDelayMinutes(Integer averageDelayMinutes) { this.averageDelayMinutes = averageDelayMinutes; }
    
    public Integer getMaximumDelayMinutes() { return maximumDelayMinutes; }
    public void setMaximumDelayMinutes(Integer maximumDelayMinutes) { this.maximumDelayMinutes = maximumDelayMinutes; }
    
    public Integer getTotalFlights() { return totalFlights; }
    public void setTotalFlights(Integer totalFlights) { this.totalFlights = totalFlights; }
    
    public Integer getAffectedFlights() { return affectedFlights; }
    public void setAffectedFlights(Integer affectedFlights) { this.affectedFlights = affectedFlights; }
    
    public boolean isActive() { return isActive; }
    public void setActive(boolean active) { isActive = active; }
}
