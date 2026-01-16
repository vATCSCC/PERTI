package org.vatsim.swim.model;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import com.fasterxml.jackson.annotation.JsonProperty;

/**
 * Flight times (OOOI + ETA)
 */
@JsonIgnoreProperties(ignoreUnknown = true)
public class FlightTimes {
    
    @JsonProperty("eta")
    private String eta;
    
    @JsonProperty("eta_runway")
    private String etaRunway;
    
    @JsonProperty("out")
    private String out;
    
    @JsonProperty("off")
    private String off;
    
    @JsonProperty("on")
    private String on;
    
    @JsonProperty("in")
    private String in;
    
    // Getters and setters
    public String getEta() { return eta; }
    public void setEta(String eta) { this.eta = eta; }
    
    public String getEtaRunway() { return etaRunway; }
    public void setEtaRunway(String etaRunway) { this.etaRunway = etaRunway; }
    
    public String getOut() { return out; }
    public void setOut(String out) { this.out = out; }
    
    public String getOff() { return off; }
    public void setOff(String off) { this.off = off; }
    
    public String getOn() { return on; }
    public void setOn(String on) { this.on = on; }
    
    public String getIn() { return in; }
    public void setIn(String in) { this.in = in; }
}
