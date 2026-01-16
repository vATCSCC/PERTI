package org.vatsim.swim.model;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import com.fasterxml.jackson.annotation.JsonProperty;

/**
 * Flight TMI control status
 */
@JsonIgnoreProperties(ignoreUnknown = true)
public class FlightTmi {
    
    @JsonProperty("is_controlled")
    private boolean isControlled;
    
    @JsonProperty("ground_stop_held")
    private boolean groundStopHeld;
    
    @JsonProperty("control_type")
    private String controlType;
    
    @JsonProperty("edct")
    private String edct;
    
    @JsonProperty("delay_minutes")
    private Integer delayMinutes;
    
    // Getters and setters
    public boolean isControlled() { return isControlled; }
    public void setControlled(boolean controlled) { isControlled = controlled; }
    
    public boolean isGroundStopHeld() { return groundStopHeld; }
    public void setGroundStopHeld(boolean groundStopHeld) { this.groundStopHeld = groundStopHeld; }
    
    public String getControlType() { return controlType; }
    public void setControlType(String controlType) { this.controlType = controlType; }
    
    public String getEdct() { return edct; }
    public void setEdct(String edct) { this.edct = edct; }
    
    public Integer getDelayMinutes() { return delayMinutes; }
    public void setDelayMinutes(Integer delayMinutes) { this.delayMinutes = delayMinutes; }
}
