# GDT - Ground Delay Tool

The Ground Delay Tool (GDT) provides FSM-style traffic flow management capabilities for managing Ground Stops and Ground Delay Programs.

---

## Database

GDT uses the dedicated `VATSIM_TMI` database (Azure SQL) with unified program management:

| Table | Purpose |
|-------|---------|
| `tmi_programs` | Program registry (GS, GDP-DAS, GDP-GAAP, GDP-UDP, AFP) |
| `tmi_slots` | FSM-format arrival slots (KJFK.091530A) |
| `tmi_flight_control` | Per-flight EDCTs, CTAs, exemptions |
| `tmi_events` | Audit log for all TMI operations |
| `tmi_popup_queue` | Pop-up flight detection queue |

**API:** `/api/gdt/` endpoints - see [[TMI API]] for details.

---

## Overview

GDT enables Traffic Management personnel to:
- Issue, modify, and cancel Ground Stops
- Model affected traffic before activation
- Monitor compliance in real-time
- Visualize demand versus capacity

**Access:** Authenticated users with DCC role
**URL:** `/gdt.php`

---

## User Interface

### Main Display

The GDT interface consists of:

| Section | Purpose |
|---------|---------|
| **Airport Selector** | Choose target airport |
| **Demand Chart** | FSM-style bar graph of arrivals/departures by time |
| **Program Panel** | Active/proposed GS and GDP status |
| **Flight List** | Affected flights with EDCT assignments |
| **Timeline** | Visual representation of program duration |

### Demand Visualization

The demand chart displays:
- **Blue bars**: Scheduled arrivals per 15-minute interval
- **Green bars**: Scheduled departures per 15-minute interval
- **Red line**: Current acceptance rate (AAR)
- **Orange line**: Current departure rate (ADR)

When demand exceeds capacity, affected intervals are highlighted.

---

## Ground Stop Operations

### Creating a Ground Stop

1. Select the target airport
2. Click "New Ground Stop"
3. Configure parameters:

| Parameter | Description |
|-----------|-------------|
| **Reason** | Weather, Equipment, Security, Other |
| **Scope Tier** | Tier 1/2/3 - defines affected distance |
| **End Time** | Expected GS end time |
| **Notes** | Additional information |

4. Click "Create" to save as proposed

### Modeling Affected Traffic

Before activation, model the impact:

1. Click "Model" on a proposed GS
2. System identifies affected flights based on:
   - Current position
   - ETA to destination
   - Scope tier distance
3. Review affected flight list
4. Adjust scope if needed

### Activating a Ground Stop

1. Review modeled flights
2. Click "Activate"
3. System issues EDCTs to all affected flights
4. GS status changes to "Active"
5. Discord notification sent (if configured)

### Extending a Ground Stop

If conditions persist:

1. Click "Extend" on active GS
2. Enter new end time
3. Confirm extension
4. System updates EDCTs for affected flights

### Purging (Canceling) a Ground Stop

When conditions improve:

1. Click "Purge" on active GS
2. Confirm cancellation
3. All EDCTs are released
4. Flights return to normal status

---

## Ground Delay Program Operations

GDPs are used when delay distribution is preferable to a full ground stop.

### GDP Workflow

1. **Create**: Define program parameters
2. **Preview**: See slot allocation preview
3. **Activate**: Issue EDCTs with calculated delays
4. **Monitor**: Track compliance
5. **Modify**: Adjust rates as needed
6. **Cancel**: End program

### Key Differences from Ground Stop

| Aspect | Ground Stop | GDP |
|--------|-------------|-----|
| Departures | Held completely | Delayed (slots assigned) |
| Scope | Distance-based tiers | Rate-based |
| Duration | Until purge | Until canceled |
| Use Case | Complete traffic stop | Delay distribution |

---

## Flight List

The flight list displays affected flights with:

| Column | Description |
|--------|-------------|
| **Callsign** | Flight identifier |
| **Origin** | Departure airport |
| **Aircraft** | Aircraft type |
| **ETD** | Expected time of departure |
| **EDCT** | Assigned departure clearance time |
| **ETA** | Expected arrival time |
| **CTA** | Controlled time of arrival (slot) |
| **Status** | Compliance status |

### Compliance Status

| Status | Meaning |
|--------|---------|
| **Assigned** | EDCT issued, awaiting departure |
| **Departed** | Flight has departed |
| **Compliant** | Departed within tolerance |
| **Non-Compliant** | Departed outside tolerance |
| **Exempt** | Flight exempted from program |

---

## Pop-up Detection

The system automatically detects "pop-up" flights:
- New flight plans filed after GS activation
- Flights entering scope after activation
- Previously unknown traffic

Pop-ups are flagged for review and can be:
- Added to the program (issue EDCT)
- Exempted
- Monitored only

---

## Keyboard Shortcuts

| Key | Action |
|-----|--------|
| `R` | Refresh flight data |
| `M` | Model selected program |
| `A` | Activate selected program |
| `E` | Extend selected program |
| `P` | Purge/Cancel selected program |

---

## Best Practices

### Before Activating

- Verify current weather conditions
- Check adjacent facility coordination
- Review flight list for accuracy
- Consider scope tier appropriateness
- Coordinate with affected facilities

### During Active Program

- Monitor compliance regularly
- Watch for pop-ups
- Communicate with affected ATC
- Be prepared to extend or purge

### When Purging

- Confirm conditions have improved
- Consider residual delay effects
- Coordinate release with affected facilities
- Monitor post-purge traffic flow

---

## Integration with Other Tools

| Tool | Integration |
|------|-------------|
| **NOD** | Active TMIs displayed as rich cards with demand charts |
| **Route Plotter** | Affected flights highlighted |
| **JATOC** | Related incidents linked |
| **Discord** | Multi-organization notifications via `process_discord_queue.php` |
| **TMI Publish** | NTML advisory generation and Discord posting |
| **TMI Compliance** | Post-event compliance analysis with flow cones |
| **TMR** | Post-event Traffic Management Review reports |

---

## Troubleshooting

### Flights not appearing in list

- Verify flight plan is filed on VATSIM
- Check if ETA is within program window
- Confirm scope tier includes flight's position
- Refresh data with `R` key

### EDCT not issuing

- Verify program is activated (not just proposed)
- Check flight meets scope criteria
- Look for exemption flags

### Demand chart not updating

- Refresh the page
- Check browser console for errors
- Verify ADL daemon is running

---

## See Also

- [[NOD Dashboard]] - View active TMIs
- [[Demand Analysis]] - Detailed demand tools
- [[API Reference]] - TMI API endpoints
- [[Architecture]] - System data flow
