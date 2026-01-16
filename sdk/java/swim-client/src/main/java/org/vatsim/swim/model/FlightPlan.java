package org.vatsim.swim.model;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import com.fasterxml.jackson.annotation.JsonProperty;

/**
 * Flight plan information
 */
@JsonIgnoreProperties(ignoreUnknown = true)
public class FlightPlan {
    
    @JsonProperty("departure")
    private String departure;
    
    @JsonProperty("destination")
    private String destination;
    
    @JsonProperty("alternate")
    private String alternate;
    
    @JsonProperty("cruise_altitude")
    private Integer cruiseAltitude;
    
    @JsonProperty("cruise_speed")
    private Integer cruiseSpeed;
    
    @JsonProperty("route")
    private String route;
    
    @JsonProperty("flight_rules")
    private String flightRules;
    
    @JsonProperty("departure_artcc")
    private String departureArtcc;
    
    @JsonProperty("destination_artcc")
    private String destinationArtcc;
    
    @JsonProperty("arrival_fix")
    private String arrivalFix;
    
    @JsonProperty("arrival_procedure")
    private String arrivalProcedure;
    
    // Getters and setters
    public String getDeparture() { return departure; }
    public void setDeparture(String departure) { this.departure = departure; }
    
    public String getDestination() { return destination; }
    public void setDestination(String destination) { this.destination = destination; }
    
    public String getAlternate() { return alternate; }
    public void setAlternate(String alternate) { this.alternate = alternate; }
    
    public Integer getCruiseAltitude() { return cruiseAltitude; }
    public void setCruiseAltitude(Integer cruiseAltitude) { this.cruiseAltitude = cruiseAltitude; }
    
    public Integer getCruiseSpeed() { return cruiseSpeed; }
    public void setCruiseSpeed(Integer cruiseSpeed) { this.cruiseSpeed = cruiseSpeed; }
    
    public String getRoute() { return route; }
    public void setRoute(String route) { this.route = route; }
    
    public String getFlightRules() { return flightRules; }
    public void setFlightRules(String flightRules) { this.flightRules = flightRules; }
    
    public String getDepartureArtcc() { return departureArtcc; }
    public void setDepartureArtcc(String departureArtcc) { this.departureArtcc = departureArtcc; }
    
    public String getDestinationArtcc() { return destinationArtcc; }
    public void setDestinationArtcc(String destinationArtcc) { this.destinationArtcc = destinationArtcc; }
    
    public String getArrivalFix() { return arrivalFix; }
    public void setArrivalFix(String arrivalFix) { this.arrivalFix = arrivalFix; }
    
    public String getArrivalProcedure() { return arrivalProcedure; }
    public void setArrivalProcedure(String arrivalProcedure) { this.arrivalProcedure = arrivalProcedure; }
}
